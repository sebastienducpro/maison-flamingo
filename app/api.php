<?php
// Maison Flamingo · l'API de l'outil terrain.
//
// Une seule porte d'entrée, en JSON. Tout ce qui touche aux clients exige une
// session ouverte, vérifiée ici, côté serveur : une porte dans le navigateur
// n'est pas une porte. Aucun secret dans ce fichier : le dépôt est public.
// Les données vivent hors du dossier public, à côté de public_html.

declare(strict_types=1);

date_default_timezone_set('Europe/Paris');
// Un avertissement PHP glissé dans la réponse casse le JSON côté téléphone, et
// peut montrer un chemin du serveur : les erreurs vont au journal, jamais à l'écran.
ini_set('display_errors', '0');

const DUREE_SESSION = 2592000;          // 30 jours : un technicien ne se reconnecte pas à chaque passage
const ESSAIS_MAX = 8;                   // tentatives de connexion par quart d'heure et par adresse
const TAILLE_PHOTO_MAX = 4000000;
const PHOTOS_MAX = 6;
const EXPEDITEUR = 'contact@maisonflamingo.fr';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');

function sortir(int $code, array $corps): void
{
    http_response_code($code);
    echo json_encode($corps, JSON_UNESCAPED_UNICODE);
    exit;
}

function dossier_donnees(): string
{
    // app/ est dans public_html/ : deux niveaux au-dessus, on est hors du web.
    return dirname(__DIR__, 2) . '/donnees-flamingo-app';
}

function base(): PDO
{
    static $db = null;
    if ($db) {
        return $db;
    }
    $d = dossier_donnees();
    if (!is_dir($d) && !@mkdir($d, 0700, true)) {
        sortir(500, ['ok' => false, 'erreur' => 'dossier', 'detail' => 'Le dossier de données ne peut pas être créé.']);
    }
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        sortir(500, ['ok' => false, 'erreur' => 'sqlite', 'detail' => 'Le module PDO SQLite n\'est pas activé sur l\'hébergement.']);
    }
    $db = new PDO('sqlite:' . $d . '/flamingo.sqlite');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec('PRAGMA foreign_keys = ON');
    $db->exec('PRAGMA journal_mode = WAL');
    $db->exec("
        CREATE TABLE IF NOT EXISTS utilisateurs (id INTEGER PRIMARY KEY, courriel TEXT UNIQUE NOT NULL, nom TEXT NOT NULL, hash TEXT NOT NULL, cree_le TEXT NOT NULL);
        CREATE TABLE IF NOT EXISTS sessions (jeton TEXT PRIMARY KEY, utilisateur_id INTEGER NOT NULL REFERENCES utilisateurs(id) ON DELETE CASCADE, expire INTEGER NOT NULL);
        CREATE TABLE IF NOT EXISTS tentatives (empreinte TEXT NOT NULL, quand INTEGER NOT NULL);
        CREATE TABLE IF NOT EXISTS clients (
            id INTEGER PRIMARY KEY, prenom TEXT NOT NULL DEFAULT '', nom TEXT NOT NULL DEFAULT '', telephone TEXT NOT NULL DEFAULT '',
            courriel TEXT NOT NULL DEFAULT '', adresse TEXT NOT NULL DEFAULT '', commune TEXT NOT NULL DEFAULT '',
            statut TEXT NOT NULL DEFAULT 'prospect', offre TEXT NOT NULL DEFAULT '', notes TEXT NOT NULL DEFAULT '',
            origine TEXT NOT NULL DEFAULT '', cree_le TEXT NOT NULL, maj_le TEXT NOT NULL);
        CREATE TABLE IF NOT EXISTS visites (
            id INTEGER PRIMARY KEY, client_id INTEGER NOT NULL REFERENCES clients(id) ON DELETE CASCADE,
            type TEXT NOT NULL, quand TEXT NOT NULL, auteur TEXT NOT NULL, donnees TEXT NOT NULL,
            jeton TEXT UNIQUE NOT NULL, identifiant_local TEXT UNIQUE, envoye_le TEXT);
        CREATE TABLE IF NOT EXISTS photos (id INTEGER PRIMARY KEY, visite_id INTEGER NOT NULL REFERENCES visites(id) ON DELETE CASCADE, fichier TEXT NOT NULL, legende TEXT NOT NULL DEFAULT '');
        CREATE TABLE IF NOT EXISTS journal (id INTEGER PRIMARY KEY, quand TEXT NOT NULL, qui TEXT NOT NULL, action TEXT NOT NULL, detail TEXT NOT NULL DEFAULT '');
    ");
    return $db;
}

function maintenant(): string
{
    return date('c');
}

function tracer(string $qui, string $action, string $detail = ''): void
{
    base()->prepare('INSERT INTO journal (quand, qui, action, detail) VALUES (?,?,?,?)')
        ->execute([maintenant(), $qui, $action, mb_substr($detail, 0, 500)]);
}

function jeton(int $octets = 24): string
{
    return rtrim(strtr(base64_encode(random_bytes($octets)), '+/', '-_'), '=');
}

function texte($v, int $max = 300): string
{
    if (!is_string($v) && !is_numeric($v)) {
        return '';
    }
    $v = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', (string) $v) ?? '');
    return mb_substr($v, 0, $max);
}

function entree(): array
{
    $brut = (string) file_get_contents('php://input', false, null, 0, 200000);
    $d = json_decode($brut, true);
    return is_array($d) ? $d : $_POST;
}

function empreinte_ip(): string
{
    return hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|flamingo');
}

function poser_cookie(string $valeur, int $expire): void
{
    setcookie('flamingo_s', $valeur, [
        'expires' => $expire, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Strict',
    ]);
}

function utilisateur(): ?array
{
    $c = $_COOKIE['flamingo_s'] ?? '';
    if (!is_string($c) || strlen($c) < 20) {
        return null;
    }
    $q = base()->prepare('SELECT u.id, u.nom, u.courriel FROM sessions s JOIN utilisateurs u ON u.id = s.utilisateur_id WHERE s.jeton = ? AND s.expire > ?');
    $q->execute([hash('sha256', $c), time()]);
    $u = $q->fetch();
    return $u ?: null;
}

function exiger_utilisateur(): array
{
    // Une requête d'écriture doit porter cet en-tête : un autre site ne peut pas
    // l'ajouter sans passer par une vérification que le navigateur refusera.
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' && ($_SERVER['HTTP_X_FLAMINGO'] ?? '') !== '1') {
        sortir(403, ['ok' => false, 'erreur' => 'origine']);
    }
    $u = utilisateur();
    if (!$u) {
        sortir(401, ['ok' => false, 'erreur' => 'connexion']);
    }
    return $u;
}

function installee(): bool
{
    return (int) base()->query('SELECT COUNT(*) FROM utilisateurs')->fetchColumn() > 0;
}

function client_public(array $c): array
{
    return [
        'id' => (int) $c['id'], 'prenom' => $c['prenom'], 'nom' => $c['nom'], 'telephone' => $c['telephone'],
        'courriel' => $c['courriel'], 'adresse' => $c['adresse'], 'commune' => $c['commune'], 'statut' => $c['statut'],
        'offre' => $c['offre'], 'notes' => $c['notes'], 'origine' => $c['origine'], 'maj_le' => $c['maj_le'],
    ];
}

function url_rapport(string $jeton): string
{
    $hote = $_SERVER['HTTP_HOST'] ?? 'app.maisonflamingo.fr';
    $chemin = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/');
    return 'https://' . $hote . $chemin . '/rapport.html?t=' . $jeton;
}

$action = (string) ($_GET['action'] ?? '');

try {
    switch ($action) {

        case 'etat':
            $u = utilisateur();
            sortir(200, ['ok' => true, 'installee' => installee(), 'utilisateur' => $u ? ['nom' => $u['nom'], 'courriel' => $u['courriel']] : null]);

        case 'installer':
            // Ne marche qu'une fois, et seulement avec le code que le gérant a déposé
            // lui-même sur l'hébergement : personne d'autre ne peut créer le premier compte.
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || ($_SERVER['HTTP_X_FLAMINGO'] ?? '') !== '1') {
                sortir(405, ['ok' => false, 'erreur' => 'methode']);
            }
            if (installee()) {
                tracer('anonyme', 'installation refusée', 'déjà installée');
                sortir(200, ['ok' => false, 'erreur' => 'deja']);
            }
            $fichier = dossier_donnees() . '/code-installation.txt';
            $attendu = is_file($fichier) ? trim((string) file_get_contents($fichier)) : '';
            $d = entree();
            if ($attendu === '' || strlen($attendu) < 8 || !hash_equals($attendu, texte($d['code'] ?? '', 200))) {
                tracer('anonyme', 'installation refusée', 'code absent ou faux');
                sortir(200, ['ok' => false, 'erreur' => 'code']);
            }
            $courriel = mb_strtolower(texte($d['courriel'] ?? '', 120));
            $nom = texte($d['nom'] ?? '', 80);
            $mdp = (string) ($d['motdepasse'] ?? '');
            if (!filter_var($courriel, FILTER_VALIDATE_EMAIL) || $nom === '' || strlen($mdp) < 12) {
                tracer('anonyme', 'installation refusée', 'champs invalides');
                sortir(200, ['ok' => false, 'erreur' => 'champs']);
            }
            base()->prepare('INSERT INTO utilisateurs (courriel, nom, hash, cree_le) VALUES (?,?,?,?)')
                ->execute([$courriel, $nom, password_hash($mdp, PASSWORD_DEFAULT), maintenant()]);
            @unlink($fichier);
            tracer($courriel, 'installation', 'premier compte créé');
            sortir(200, ['ok' => true]);

        case 'connexion':
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || ($_SERVER['HTTP_X_FLAMINGO'] ?? '') !== '1') {
                sortir(405, ['ok' => false, 'erreur' => 'methode']);
            }
            $db = base();
            $ip = empreinte_ip();
            $db->prepare('DELETE FROM tentatives WHERE quand < ?')->execute([time() - 900]);
            $n = $db->prepare('SELECT COUNT(*) FROM tentatives WHERE empreinte = ?');
            $n->execute([$ip]);
            if ((int) $n->fetchColumn() >= ESSAIS_MAX) {
                tracer('anonyme', 'connexion freinée', $ip);
                sortir(429, ['ok' => false, 'erreur' => 'trop']);
            }
            $d = entree();
            $courriel = mb_strtolower(texte($d['courriel'] ?? '', 120));
            $q = $db->prepare('SELECT * FROM utilisateurs WHERE courriel = ?');
            $q->execute([$courriel]);
            $u = $q->fetch();
            if (!$u || !password_verify((string) ($d['motdepasse'] ?? ''), $u['hash'])) {
                $db->prepare('INSERT INTO tentatives (empreinte, quand) VALUES (?,?)')->execute([$ip, time()]);
                tracer($courriel ?: 'anonyme', 'connexion refusée');
                sortir(200, ['ok' => false, 'erreur' => 'identifiants']);
            }
            $brut = jeton(32);
            $expire = time() + DUREE_SESSION;
            $db->prepare('INSERT INTO sessions (jeton, utilisateur_id, expire) VALUES (?,?,?)')->execute([hash('sha256', $brut), $u['id'], $expire]);
            $db->prepare('DELETE FROM sessions WHERE expire < ?')->execute([time()]);
            poser_cookie($brut, $expire);
            tracer($courriel, 'connexion');
            sortir(200, ['ok' => true, 'utilisateur' => ['nom' => $u['nom'], 'courriel' => $u['courriel']]]);

        case 'deconnexion':
            $c = $_COOKIE['flamingo_s'] ?? '';
            if (is_string($c) && $c !== '') {
                base()->prepare('DELETE FROM sessions WHERE jeton = ?')->execute([hash('sha256', $c)]);
            }
            poser_cookie('', time() - 3600);
            sortir(200, ['ok' => true]);

        case 'clients':
            exiger_utilisateur();
            $lignes = base()->query("SELECT c.*, (SELECT MAX(quand) FROM visites v WHERE v.client_id = c.id) AS derniere FROM clients c ORDER BY c.maj_le DESC")->fetchAll();
            sortir(200, ['ok' => true, 'clients' => array_map(function ($c) {
                return client_public($c) + ['derniere_visite' => $c['derniere']];
            }, $lignes)]);

        case 'client':
            exiger_utilisateur();
            $q = base()->prepare('SELECT * FROM clients WHERE id = ?');
            $q->execute([(int) ($_GET['id'] ?? 0)]);
            $c = $q->fetch();
            if (!$c) {
                sortir(404, ['ok' => false, 'erreur' => 'introuvable']);
            }
            $v = base()->prepare('SELECT id, type, quand, auteur, jeton, envoye_le FROM visites WHERE client_id = ? ORDER BY quand DESC');
            $v->execute([$c['id']]);
            $visites = array_map(function ($x) {
                return ['id' => (int) $x['id'], 'type' => $x['type'], 'quand' => $x['quand'], 'auteur' => $x['auteur'], 'envoye_le' => $x['envoye_le'], 'lien' => url_rapport($x['jeton'])];
            }, $v->fetchAll());
            sortir(200, ['ok' => true, 'client' => client_public($c), 'visites' => $visites]);

        case 'client_enregistrer':
            $u = exiger_utilisateur();
            $d = entree();
            $champs = [];
            foreach (['prenom' => 60, 'nom' => 60, 'telephone' => 30, 'courriel' => 120, 'adresse' => 200, 'commune' => 60, 'offre' => 30, 'notes' => 4000] as $k => $max) {
                $champs[$k] = texte($d[$k] ?? '', $max);
            }
            $statut = in_array($d['statut'] ?? '', ['prospect', 'client', 'perdu'], true) ? $d['statut'] : 'prospect';
            if ($champs['prenom'] === '' && $champs['nom'] === '') {
                sortir(200, ['ok' => false, 'erreur' => 'nom']);
            }
            $id = (int) ($d['id'] ?? 0);
            $db = base();
            if ($id > 0) {
                $db->prepare('UPDATE clients SET prenom=?, nom=?, telephone=?, courriel=?, adresse=?, commune=?, offre=?, notes=?, statut=?, maj_le=? WHERE id=?')
                    ->execute([...array_values($champs), $statut, maintenant(), $id]);
            } else {
                $db->prepare('INSERT INTO clients (prenom, nom, telephone, courriel, adresse, commune, offre, notes, statut, origine, cree_le, maj_le) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute([...array_values($champs), $statut, 'saisie', maintenant(), maintenant()]);
                $id = (int) $db->lastInsertId();
            }
            tracer($u['courriel'], 'client enregistré', (string) $id);
            sortir(200, ['ok' => true, 'id' => $id]);

        case 'import_liste':
            // La liste d'attente du site est écrite à côté du site : on la relit sans rien recopier à la main.
            $u = exiger_utilisateur();
            $fichier = dirname(__DIR__, 2) . '/donnees-flamingo/liste-attente.csv';
            if (!is_file($fichier)) {
                sortir(200, ['ok' => true, 'ajoutes' => 0, 'deja' => 0, 'detail' => 'Aucune inscription pour l\'instant.']);
            }
            $db = base();
            $ajoutes = 0;
            $deja = 0;
            $f = fopen($fichier, 'r');
            // Mêmes réglages que l'écriture du site, déclarés explicitement (PHP 8.4 avertit sinon).
            $entete = fgetcsv($f, 0, ';', '"', '\\');
            while (($l = fgetcsv($f, 0, ';', '"', '\\')) !== false) {
                if (!$entete || count($l) !== count($entete)) {
                    continue;
                }
                $r = array_combine($entete, $l);
                $tel = preg_replace('/\D/', '', (string) ($r['telephone'] ?? ''));
                if ($tel === '') {
                    continue;
                }
                $q = $db->prepare("SELECT id FROM clients WHERE REPLACE(REPLACE(REPLACE(telephone,' ',''),'.',''),'-','') = ?");
                $q->execute([$tel]);
                if ($q->fetch()) {
                    $deja++;
                    continue;
                }
                $nettoie = function ($v) { return ltrim(texte($v, 200), "'"); };
                $db->prepare('INSERT INTO clients (prenom, nom, telephone, courriel, commune, offre, notes, statut, origine, cree_le, maj_le) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute([$nettoie($r['prenom'] ?? ''), $nettoie($r['nom'] ?? ''), $nettoie($r['telephone'] ?? ''), $nettoie($r['courriel'] ?? ''),
                        $nettoie($r['commune'] ?? ''), $nettoie($r['interets'] ?? ''), 'Inscrit sur le site le ' . $nettoie($r['date'] ?? ''), 'prospect', 'liste d\'attente', maintenant(), maintenant()]);
                $ajoutes++;
            }
            fclose($f);
            tracer($u['courriel'], 'import liste', "$ajoutes ajoutés, $deja déjà présents");
            sortir(200, ['ok' => true, 'ajoutes' => $ajoutes, 'deja' => $deja]);

        case 'visite_enregistrer':
            $u = exiger_utilisateur();
            $d = json_decode((string) ($_POST['visite'] ?? ''), true);
            if (!is_array($d)) {
                sortir(400, ['ok' => false, 'erreur' => 'donnees']);
            }
            $client = (int) ($d['client_id'] ?? 0);
            $type = in_array($d['type'] ?? '', ['diagnostic', 'passage'], true) ? $d['type'] : '';
            $local = texte($d['identifiant_local'] ?? '', 60);
            $q = base()->prepare('SELECT id FROM clients WHERE id = ?');
            $q->execute([$client]);
            if (!$q->fetch() || $type === '' || $local === '') {
                sortir(400, ['ok' => false, 'erreur' => 'donnees']);
            }
            $db = base();
            // Un envoi rejoué depuis la file du téléphone ne crée jamais deux visites.
            $existe = $db->prepare('SELECT id, jeton FROM visites WHERE identifiant_local = ?');
            $existe->execute([$local]);
            if ($r = $existe->fetch()) {
                sortir(200, ['ok' => true, 'id' => (int) $r['id'], 'lien' => url_rapport($r['jeton']), 'rejoue' => true]);
            }
            $contenu = json_encode($d['contenu'] ?? [], JSON_UNESCAPED_UNICODE);
            if ($contenu === false || strlen($contenu) > 100000) {
                sortir(400, ['ok' => false, 'erreur' => 'trop_long']);
            }
            // L'heure vient du téléphone (une visite peut partir des heures plus tard
            // depuis la file) : on la garde si elle est lisible et pas dans le futur.
            $t = strtotime(texte($d['quand'] ?? '', 40));
            $quand = ($t !== false && $t <= time() + 300 && $t > time() - 30 * 86400) ? date('c', $t) : maintenant();
            $jeton = jeton(24);
            $db->beginTransaction();
            $db->prepare('INSERT INTO visites (client_id, type, quand, auteur, donnees, jeton, identifiant_local) VALUES (?,?,?,?,?,?,?)')
                ->execute([$client, $type, $quand, $u['nom'], $contenu, $jeton, $local]);
            $id = (int) $db->lastInsertId();
            $dossier = dossier_donnees() . '/photos/' . $id;
            $legendes = is_array($d['legendes'] ?? null) ? $d['legendes'] : [];
            $fichiers = $_FILES['photos'] ?? null;
            if ($fichiers && is_array($fichiers['tmp_name'])) {
                if (!is_dir($dossier)) {
                    @mkdir($dossier, 0700, true);
                }
                $n = 0;
                foreach ($fichiers['tmp_name'] as $i => $tmp) {
                    if ($n >= PHOTOS_MAX || !is_uploaded_file($tmp) || $fichiers['size'][$i] > TAILLE_PHOTO_MAX) {
                        continue;
                    }
                    $info = @getimagesize($tmp);
                    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
                        continue;
                    }
                    $nom = ($n + 1) . image_type_to_extension($info[2]);
                    if (move_uploaded_file($tmp, $dossier . '/' . $nom)) {
                        $db->prepare('INSERT INTO photos (visite_id, fichier, legende) VALUES (?,?,?)')->execute([$id, $nom, texte($legendes[$i] ?? '', 80)]);
                        $n++;
                    }
                }
            }
            $db->prepare('UPDATE clients SET maj_le = ? WHERE id = ?')->execute([maintenant(), $client]);
            $db->commit();
            tracer($u['courriel'], 'visite enregistrée', "$type $id client $client");
            sortir(200, ['ok' => true, 'id' => $id, 'lien' => url_rapport($jeton)]);

        case 'visite_courriel':
            $u = exiger_utilisateur();
            $d = entree();
            $q = base()->prepare('SELECT v.id, v.jeton, v.type, c.prenom, c.courriel FROM visites v JOIN clients c ON c.id = v.client_id WHERE v.id = ?');
            $q->execute([(int) ($d['id'] ?? 0)]);
            $v = $q->fetch();
            if (!$v || !filter_var($v['courriel'], FILTER_VALIDATE_EMAIL)) {
                tracer($u['courriel'], 'envoi refusé', 'pas de courriel valide pour la visite ' . (int) ($d['id'] ?? 0));
                sortir(200, ['ok' => false, 'erreur' => 'courriel']);
            }
            $objet = $v['type'] === 'diagnostic' ? 'Le diagnostic de votre piscine' : 'Le rapport de passage de votre piscine';
            $corps = "Bonjour " . $v['prenom'] . ",\n\nVoici " . ($v['type'] === 'diagnostic' ? 'le diagnostic' : 'le rapport') . " de votre piscine :\n" . url_rapport($v['jeton']) . "\n\nÀ bientôt,\nVotre Flamingo\nMaison Flamingo · 06 74 85 90 78";
            $entetes = implode("\r\n", ['From: Maison Flamingo <' . EXPEDITEUR . '>', 'Reply-To: ' . EXPEDITEUR, 'MIME-Version: 1.0', 'Content-Type: text/plain; charset=UTF-8']);
            $envoye = @mail($v['courriel'], '=?UTF-8?B?' . base64_encode($objet) . '?=', $corps, $entetes, '-f' . EXPEDITEUR);
            if ($envoye) {
                base()->prepare('UPDATE visites SET envoye_le = ? WHERE id = ?')->execute([maintenant(), $v['id']]);
            }
            tracer($u['courriel'], $envoye ? 'rapport envoyé' : 'envoi échoué', (string) $v['id']);
            sortir(200, ['ok' => $envoye]);

        case 'visite_marquer_envoyee':
            $u = exiger_utilisateur();
            $d = entree();
            base()->prepare('UPDATE visites SET envoye_le = COALESCE(envoye_le, ?) WHERE id = ?')->execute([maintenant(), (int) ($d['id'] ?? 0)]);
            sortir(200, ['ok' => true]);

        case 'rapport':
            // Public, mais seulement avec le jeton du rapport : 24 octets au hasard, rien à deviner.
            $t = (string) ($_GET['t'] ?? '');
            if (!preg_match('/^[A-Za-z0-9_-]{24,64}$/', $t)) {
                sortir(404, ['ok' => false]);
            }
            $q = base()->prepare('SELECT v.id, v.type, v.quand, v.auteur, v.donnees, c.prenom, c.commune FROM visites v JOIN clients c ON c.id = v.client_id WHERE v.jeton = ?');
            $q->execute([$t]);
            $v = $q->fetch();
            if (!$v) {
                sortir(404, ['ok' => false]);
            }
            $contenu = json_decode($v['donnees'], true) ?: [];
            unset($contenu['interne']);   // les notes internes ne sortent jamais vers le client
            $p = base()->prepare('SELECT id, legende FROM photos WHERE visite_id = ? ORDER BY id');
            $p->execute([$v['id']]);
            $photos = array_map(function ($x) use ($t) {
                return ['url' => 'api.php?action=photo&id=' . $x['id'] . '&t=' . $t, 'legende' => $x['legende']];
            }, $p->fetchAll());
            sortir(200, ['ok' => true, 'rapport' => ['type' => $v['type'], 'quand' => $v['quand'], 'auteur' => $v['auteur'], 'prenom' => $v['prenom'], 'commune' => $v['commune'], 'contenu' => $contenu, 'photos' => $photos]]);

        case 'photo':
            $q = base()->prepare('SELECT p.fichier, p.visite_id, v.jeton FROM photos p JOIN visites v ON v.id = p.visite_id WHERE p.id = ?');
            $q->execute([(int) ($_GET['id'] ?? 0)]);
            $p = $q->fetch();
            $t = (string) ($_GET['t'] ?? '');
            if (!$p || !($t !== '' && hash_equals($p['jeton'], $t)) && !utilisateur()) {
                sortir(404, ['ok' => false]);
            }
            $chemin = dossier_donnees() . '/photos/' . (int) $p['visite_id'] . '/' . basename($p['fichier']);
            if (!is_file($chemin)) {
                sortir(404, ['ok' => false]);
            }
            $types = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
            header('Content-Type: ' . ($types[strtolower(pathinfo($chemin, PATHINFO_EXTENSION))] ?? 'application/octet-stream'));
            header('Cache-Control: private, max-age=86400');
            readfile($chemin);
            exit;

        default:
            sortir(404, ['ok' => false, 'erreur' => 'action']);
    }
} catch (Throwable $e) {
    error_log('Flamingo app : ' . $e->getMessage());
    sortir(500, ['ok' => false, 'erreur' => 'serveur']);
}
