<?php
// Maison Flamingo · ce que l'équipe fait : techniciens, chefs d'agence, direction.
//
// Chaque action commence par dire qui elle accepte (exiger_equipe) et lit ses
// données à travers la portée du compte (portee_clients) : un technicien ne
// voit que ses clients, un chef que son agence, la direction tout.

declare(strict_types=1);

/* ---------- Entrée, installation, connexion ---------- */

function a_etat(): void
{
    $u = session_equipe();
    $installee = (int) base()->query('SELECT COUNT(*) FROM utilisateurs')->fetchColumn() > 0;
    $etape = null;
    if ($u && double_facteur_requis($u) && (int) $u['totp_ok'] !== 1) {
        $etape = (int) $u['totp_actif'] === 1 ? 'totp' : 'totp_installer';
    }
    sortir(200, ['ok' => true, 'installee' => $installee, 'utilisateur' => ($u && !$etape) ? public_utilisateur($u) : null, 'etape' => $etape]);
}

function a_installer(): void
{
    // Une seule fois, et seulement avec le code que le gérant a déposé lui-même sur l'hébergement.
    exiger_post();
    $db = base();
    if ((int) $db->query('SELECT COUNT(*) FROM utilisateurs')->fetchColumn() > 0) {
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
    if (!filter_var($courriel, FILTER_VALIDATE_EMAIL) || $nom === '' || !motdepasse_valable($mdp)) {
        tracer('anonyme', 'installation refusée', 'champs invalides');
        sortir(200, ['ok' => false, 'erreur' => 'champs']);
    }
    $db->beginTransaction();
    if (!(int) $db->query('SELECT COUNT(*) FROM agences')->fetchColumn()) {
        $db->prepare('INSERT INTO agences (nom, ville, cree_le) VALUES (?,?,?)')->execute(['Nîmes', 'Nîmes', maintenant()]);
    }
    $db->prepare("INSERT INTO utilisateurs (courriel, nom, hash, cree_le, role, agence_id) VALUES (?,?,?,?, 'superadmin', NULL)")
        ->execute([$courriel, $nom, hacher($mdp), maintenant()]);
    $db->commit();
    @unlink($fichier);
    tracer($courriel, 'installation', 'compte direction créé');
    sortir(200, ['ok' => true]);
}

function a_connexion(): void
{
    exiger_post();
    $d = entree();
    $courriel = mb_strtolower(texte($d['courriel'] ?? '', 120));
    $ip = 'ip:' . empreinte_ip();
    $compte = cle_compte($courriel);
    if (freine($ip, ESSAIS_IP) || freine($compte, ESSAIS_COMPTE)) {
        tracer($courriel ?: 'anonyme', 'connexion freinée');
        sortir(429, ['ok' => false, 'erreur' => 'trop']);
    }
    $q = base()->prepare('SELECT * FROM utilisateurs WHERE courriel = ?');
    $q->execute([$courriel]);
    $u = $q->fetch();
    // Même réponse pour « compte inconnu », « désactivé » et « mauvais mot de passe » : rien à deviner.
    if (!$u || $u['hash'] === '' || (int) $u['actif'] !== 1 || !password_verify((string) ($d['motdepasse'] ?? ''), $u['hash'])) {
        noter_echec($ip);
        noter_echec($compte);
        tracer($courriel ?: 'anonyme', 'connexion refusée');
        sortir(200, ['ok' => false, 'erreur' => 'identifiants']);
    }
    if (password_needs_rehash($u['hash'], defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT)) {
        base()->prepare('UPDATE utilisateurs SET hash = ? WHERE id = ?')->execute([hacher((string) $d['motdepasse']), $u['id']]);
    }
    $df = double_facteur_requis($u);
    ouvrir_session_equipe($u, !$df);
    base()->prepare('UPDATE utilisateurs SET derniere_connexion = ? WHERE id = ?')->execute([maintenant(), $u['id']]);
    tracer($u, 'connexion', $df ? 'mot de passe bon, second facteur attendu' : '');
    sortir(200, ['ok' => true, 'etape' => $df ? ((int) $u['totp_actif'] === 1 ? 'totp' : 'totp_installer') : null]);
}

function session_en_attente_de_facteur(): array
{
    exiger_post();
    $u = session_equipe();
    if (!$u) {
        sortir(401, ['ok' => false, 'erreur' => 'connexion']);
    }
    return $u;
}

function a_totp_preparer(): void
{
    $u = session_en_attente_de_facteur();
    // Le secret ne se montre qu'avant son activation. Une fois actif, il ne se relit plus jamais :
    // il se remplace, par une réinitialisation faite par la direction.
    if ((int) $u['totp_actif'] === 1) {
        tracer($u, 'second facteur : relecture refusée');
        sortir(200, ['ok' => false, 'erreur' => 'deja']);
    }
    $secret = $u['totp_secret'];
    if ($secret === '') {
        $secret = base32_encoder(random_bytes(20));
        base()->prepare('UPDATE utilisateurs SET totp_secret = ? WHERE id = ?')->execute([$secret, $u['id']]);
    }
    $etiquette = rawurlencode('Maison Flamingo:' . $u['courriel']);
    sortir(200, ['ok' => true, 'secret' => trim(chunk_split($secret, 4, ' ')),
        'uri' => "otpauth://totp/$etiquette?secret=$secret&issuer=Maison%20Flamingo&digits=6&period=30"]);
}

function a_totp_valider(): void
{
    $u = session_en_attente_de_facteur();
    $cle = 'totp:' . $u['id'];
    if (freine($cle, 6)) {
        tracer($u, 'second facteur freiné');
        sortir(429, ['ok' => false, 'erreur' => 'trop']);
    }
    $pas = totp_verifier($u['totp_secret'], (string) (entree()['code'] ?? ''), (int) $u['totp_dernier']);
    if ($pas === null) {
        noter_echec($cle);
        tracer($u, 'second facteur refusé');
        sortir(200, ['ok' => false, 'erreur' => 'code']);
    }
    $db = base();
    $db->prepare('UPDATE utilisateurs SET totp_actif = 1, totp_dernier = ? WHERE id = ?')->execute([$pas, $u['id']]);
    $db->prepare('UPDATE sessions SET totp_ok = 1 WHERE jeton = ?')->execute([$u['session']]);
    tracer($u, (int) $u['totp_actif'] === 1 ? 'second facteur validé' : 'second facteur activé');
    sortir(200, ['ok' => true]);
}

function a_deconnexion(): void
{
    $c = $_COOKIE['flamingo_s'] ?? '';
    if (is_string($c) && $c !== '') {
        base()->prepare('DELETE FROM sessions WHERE jeton = ?')->execute([hash('sha256', $c)]);
    }
    poser_cookie('flamingo_s', '', time() - 3600);
    sortir(200, ['ok' => true]);
}

function a_motdepasse(): void
{
    $u = exiger_equipe();
    exiger_post();
    $d = entree();
    $q = base()->prepare('SELECT hash FROM utilisateurs WHERE id = ?');
    $q->execute([$u['id']]);
    if (!password_verify((string) ($d['ancien'] ?? ''), (string) $q->fetchColumn())) {
        tracer($u, 'changement de mot de passe refusé');
        sortir(200, ['ok' => false, 'erreur' => 'ancien']);
    }
    if (!motdepasse_valable((string) ($d['nouveau'] ?? ''))) {
        sortir(200, ['ok' => false, 'erreur' => 'court']);
    }
    $db = base();
    $db->prepare('UPDATE utilisateurs SET hash = ? WHERE id = ?')->execute([hacher((string) $d['nouveau']), $u['id']]);
    // « On m'a pris mon compte » : changer son mot de passe ferme toutes les autres sessions.
    $db->prepare('DELETE FROM sessions WHERE utilisateur_id = ? AND jeton <> ?')->execute([$u['id'], $u['session']]);
    tracer($u, 'mot de passe changé', 'autres sessions fermées');
    sortir(200, ['ok' => true]);
}

function dossier_photos_equipe(): string
{
    $d = dossier_donnees() . '/photos-equipe';
    if (!is_dir($d)) {
        @mkdir($d, 0700, true);
    }
    return $d;
}

// La personne qui peut poser une photo : soi-même, ou un membre que l'on gère.
function membre_pour_photo(array $u, int $id): ?array
{
    if ($id === 0 || $id === (int) $u['id']) {
        $q = base()->prepare('SELECT * FROM utilisateurs WHERE id = ?');
        $q->execute([$u['id']]);
        return $q->fetch() ?: null;
    }
    return in_array($u['role'], ['chef', 'superadmin'], true) ? membre_gerable($u, $id) : null;
}

function a_compte_modifier(): void
{
    // Chacun tient son propre numéro : c'est celui que le client verra.
    $u = exiger_equipe();
    exiger_post();
    $d = entree();
    $telephone = texte($d['telephone'] ?? '', 30);
    if ($telephone !== '' && !preg_match('/^[0-9+ .()\-]{6,30}$/', $telephone)) {
        sortir(200, ['ok' => false, 'erreur' => 'telephone']);
    }
    base()->prepare('UPDATE utilisateurs SET telephone = ? WHERE id = ?')->execute([$telephone, $u['id']]);
    tracer($u, 'coordonnées modifiées', 'téléphone');
    sortir(200, ['ok' => true]);
}

function a_photo_equipe_poser(): void
{
    $u = exiger_equipe();
    exiger_post();
    $m = membre_pour_photo($u, entier($_POST['id'] ?? 0));
    if (!$m) {
        tracer($u, 'photo refusée', (string) ($_POST['id'] ?? ''));
        sortir(404, ['ok' => false, 'erreur' => 'introuvable']);
    }
    $f = $_FILES['photo'] ?? null;
    $info = $f && is_uploaded_file((string) $f['tmp_name']) && (int) $f['size'] <= 2000000 ? @getimagesize((string) $f['tmp_name']) : false;
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
        sortir(200, ['ok' => false, 'erreur' => 'photo']);
    }
    // Un nom tiré au hasard à chaque dépôt : l'ancienne photo ne reste pas en cache chez le client.
    $nom = $m['id'] . '-' . jeton(8) . ($info[2] === IMAGETYPE_PNG ? '.png' : '.jpg');
    if (!move_uploaded_file((string) $f['tmp_name'], dossier_photos_equipe() . '/' . $nom)) {
        sortir(500, ['ok' => false, 'erreur' => 'ecriture']);
    }
    if ($m['photo'] !== '') {
        @unlink(dossier_photos_equipe() . '/' . basename($m['photo']));
    }
    base()->prepare('UPDATE utilisateurs SET photo = ? WHERE id = ?')->execute([$nom, $m['id']]);
    tracer($u, 'photo posée', (string) $m['id']);
    sortir(200, ['ok' => true]);
}

// La photo d'un membre se voit par l'équipe, par ses clients, et par qui tient un lien de rapport qu'il a signé.
// Jamais par une adresse nue : les visages de l'équipe ne se parcourent pas.
function a_photo_equipe(): void
{
    $q = base()->prepare("SELECT id, photo, agence_id, role FROM utilisateurs WHERE id = ? AND photo <> ''");
    $q->execute([entier($_GET['id'] ?? 0)]);
    $m = $q->fetch();
    $permis = false;
    if ($m) {
        $t = (string) ($_GET['t'] ?? '');
        if ($t !== '' && preg_match('/^[A-Za-z0-9_-]{24,64}$/', $t)) {
            $v = base()->prepare('SELECT v.technicien_id, c.technicien_id AS actuel, c.agence_id FROM visites v JOIN clients c ON c.id = v.client_id WHERE v.jeton = ?');
            $v->execute([$t]);
            $x = $v->fetch();
            $permis = $x && ((int) $x['technicien_id'] === (int) $m['id'] || (int) $x['actuel'] === (int) $m['id'] || ($m['role'] === 'chef' && (int) $x['agence_id'] === (int) $m['agence_id']));
        }
        if (!$permis) {
            $cl = session_client();
            $permis = $cl && ((int) $cl['technicien_id'] === (int) $m['id'] || (in_array($m['role'], ['chef', 'superadmin'], true) && ($m['role'] === 'superadmin' || (int) $cl['agence_id'] === (int) $m['agence_id'])));
        }
        if (!$permis) {
            $u = session_equipe();
            $permis = $u && (!double_facteur_requis($u) || (int) $u['totp_ok'] === 1);
        }
    }
    $chemin = $m ? dossier_photos_equipe() . '/' . basename($m['photo']) : '';
    if (!$permis || !is_file($chemin)) {
        sortir(404, ['ok' => false]);
    }
    header('Content-Type: ' . (str_ends_with($chemin, '.png') ? 'image/png' : 'image/jpeg'));
    header('Cache-Control: private, max-age=86400');
    readfile($chemin);
    exit;
}

// L'équipe d'un client, telle qu'il la verra : son technicien et le chef de son agence, avec visage et numéro.
function equipe_du_client(array $c, ?string $jeton = null): array
{
    $db = base();
    $suffixe = $jeton ? '&t=' . $jeton : '';
    $forme = fn ($m, $titre) => ['id' => (int) $m['id'], 'prenom' => explode(' ', (string) $m['nom'])[0], 'nom' => $m['nom'], 'titre' => $titre, 'telephone' => $m['telephone'],
        'photo' => $m['photo'] !== '' ? 'api.php?action=photo_equipe&id=' . $m['id'] . $suffixe : null];
    $sortie = [];
    if ($c['technicien_id']) {
        $t = $db->prepare("SELECT id, nom, telephone, photo FROM utilisateurs WHERE id = ? AND actif = 1 AND role = 'technicien'");
        $t->execute([$c['technicien_id']]);
        if ($m = $t->fetch()) {
            $sortie[] = $forme($m, 'Votre Flamingo');
        }
    }
    $ch = $db->prepare("SELECT id, nom, telephone, photo FROM utilisateurs WHERE actif = 1 AND hash <> '' AND role = 'chef' AND agence_id = ? ORDER BY id LIMIT 1");
    $ch->execute([$c['agence_id']]);
    if ($m = $ch->fetch()) {
        $sortie[] = $forme($m, 'Responsable d\'agence');
    }
    return $sortie;
}

/* ---------- Invitations (publiques, protégées par leur jeton) ---------- */

function a_invitation_lire(): void
{
    $i = lire_invitation((string) ($_GET['t'] ?? ''));
    if (!$i) {
        sortir(404, ['ok' => false, 'erreur' => 'invitation']);
    }
    $db = base();
    if ($i['genre'] === 'equipe') {
        $q = $db->prepare('SELECT u.nom, u.courriel, u.role, a.nom AS agence FROM utilisateurs u LEFT JOIN agences a ON a.id = u.agence_id WHERE u.id = ?');
        $q->execute([$i['cible_id']]);
        $m = $q->fetch();
        sortir(200, ['ok' => true, 'genre' => 'equipe', 'nom' => $m['nom'] ?? '', 'courriel' => $m['courriel'] ?? '', 'role' => $m['role'] ?? '', 'agence' => $m['agence'] ?? '']);
    }
    $q = $db->prepare('SELECT c.prenom, c.courriel, cc.courriel AS compte FROM clients c LEFT JOIN comptes_clients cc ON cc.client_id = c.id WHERE c.id = ?');
    $q->execute([$i['cible_id']]);
    $c = $q->fetch();
    sortir(200, ['ok' => true, 'genre' => 'client', 'prenom' => $c['prenom'] ?? '', 'courriel' => $c['compte'] ?: ($c['courriel'] ?? '')]);
}

function a_invitation_accepter(): void
{
    exiger_post();
    $ip = 'ip:' . empreinte_ip();
    if (freine($ip, ESSAIS_IP)) {
        sortir(429, ['ok' => false, 'erreur' => 'trop']);
    }
    $d = entree();
    $i = lire_invitation((string) ($d['t'] ?? ''));
    if (!$i) {
        noter_echec($ip);
        tracer('anonyme', 'invitation refusée', 'lien absent, utilisé ou expiré');
        sortir(200, ['ok' => false, 'erreur' => 'invitation']);
    }
    $mdp = (string) ($d['motdepasse'] ?? '');
    if (!motdepasse_valable($mdp)) {
        sortir(200, ['ok' => false, 'erreur' => 'court']);
    }
    $db = base();
    $db->beginTransaction();
    if ($i['genre'] === 'equipe') {
        $db->prepare('UPDATE utilisateurs SET hash = ? WHERE id = ? AND actif = 1')->execute([hacher($mdp), $i['cible_id']]);
        $db->prepare('DELETE FROM sessions WHERE utilisateur_id = ?')->execute([$i['cible_id']]);
        $qui = 'membre ' . $i['cible_id'];
    } else {
        $courriel = mb_strtolower(texte($d['courriel'] ?? '', 120));
        if (!filter_var($courriel, FILTER_VALIDATE_EMAIL)) {
            $db->rollBack();
            sortir(200, ['ok' => false, 'erreur' => 'courriel']);
        }
        $q = $db->prepare('SELECT client_id FROM comptes_clients WHERE courriel = ?');
        $q->execute([$courriel]);
        $autre = $q->fetchColumn();
        if ($autre !== false && (int) $autre !== (int) $i['cible_id']) {
            $db->rollBack();
            sortir(200, ['ok' => false, 'erreur' => 'courriel_pris']);
        }
        $db->prepare('INSERT INTO comptes_clients (client_id, courriel, hash, actif, cree_le) VALUES (?,?,?,1,?)
            ON CONFLICT(client_id) DO UPDATE SET courriel = excluded.courriel, hash = excluded.hash, actif = 1')
            ->execute([$i['cible_id'], $courriel, hacher($mdp), maintenant()]);
        $db->prepare('DELETE FROM sessions_clients WHERE compte_id = (SELECT id FROM comptes_clients WHERE client_id = ?)')->execute([$i['cible_id']]);
        $qui = 'client ' . $i['cible_id'];
    }
    $db->prepare('UPDATE invitations SET utilisee_le = ? WHERE jeton = ?')->execute([maintenant(), $i['jeton']]);
    $db->commit();
    tracer($qui, 'invitation acceptée', $i['genre']);
    sortir(200, ['ok' => true, 'genre' => $i['genre']]);
}

/* ---------- Clients ---------- */

function client_public(array $c): array
{
    return [
        'id' => (int) $c['id'], 'prenom' => $c['prenom'], 'nom' => $c['nom'], 'telephone' => $c['telephone'],
        'courriel' => $c['courriel'], 'adresse' => $c['adresse'], 'commune' => $c['commune'], 'statut' => $c['statut'],
        'offre' => $c['offre'], 'notes' => $c['notes'], 'origine' => $c['origine'], 'maj_le' => $c['maj_le'],
        'agence_id' => $c['agence_id'] !== null ? (int) $c['agence_id'] : null, 'technicien_id' => $c['technicien_id'] !== null ? (int) $c['technicien_id'] : null,
        'jour_passage' => $c['jour_passage'] !== null ? (int) $c['jour_passage'] : null, 'ordre' => (int) $c['ordre'], 'formule' => $c['formule'],
        'acces' => $c['acces'], 'lat' => $c['lat'] !== null ? (float) $c['lat'] : null, 'lon' => $c['lon'] !== null ? (float) $c['lon'] : null,
        'lead_recu_le' => $c['lead_recu_le'], 'lead_attribue_le' => $c['lead_attribue_le'], 'lead_contacte_le' => $c['lead_contacte_le'], 'lead_contact_par' => $c['lead_contact_par'],
        'statut_depuis' => $c['statut_depuis'], 'statut_motif' => $c['statut_motif'], 'venu_par' => $c['venu_par'] ?? '',
    ];
}

function a_clients(): void
{
    $u = exiger_equipe();
    [$w, $p] = portee_clients($u);
    $conditions = ["($w)"];
    $f = texte($_GET['q'] ?? '', 80);
    if ($f !== '') {
        $conditions[] = "(c.prenom || ' ' || c.nom || ' ' || c.commune || ' ' || c.telephone || ' ' || c.courriel) LIKE ?";
        $p[] = '%' . $f . '%';
    }
    $statut = texte($_GET['statut'] ?? '', 20);
    if (in_array($statut, STATUTS_CLIENT, true)) {
        $conditions[] = 'c.statut = ?';
        $p[] = $statut;
    } elseif ($statut === '') {
        // Par défaut, la liste cache ce qui ne vit plus : désinscrits et perdus restent accessibles par le filtre.
        $conditions[] = "c.statut NOT IN ('desinscrit', 'perdu')";
    }
    if ($u['role'] === 'superadmin' && entier($_GET['agence'] ?? 0) > 0) {
        $conditions[] = 'c.agence_id = ?';
        $p[] = entier($_GET['agence']);
    }
    $ou = implode(' AND ', $conditions);
    $db = base();
    $n = $db->prepare("SELECT COUNT(*) FROM clients c WHERE $ou");
    $n->execute($p);
    // Par pages de 300 : la liste reste légère sur un téléphone, même à deux mille clients.
    $debut = max(0, entier($_GET['debut'] ?? 0));
    $q = $db->prepare("SELECT c.*, t.nom AS technicien_nom, a.nom AS agence_nom,
            (SELECT MAX(quand) FROM visites v WHERE v.client_id = c.id) AS derniere
        FROM clients c LEFT JOIN utilisateurs t ON t.id = c.technicien_id LEFT JOIN agences a ON a.id = c.agence_id
        WHERE $ou ORDER BY c.maj_le DESC LIMIT 300 OFFSET $debut");
    $q->execute($p);
    sortir(200, ['ok' => true, 'total' => (int) $n->fetchColumn(), 'clients' => array_map(fn ($c) => client_public($c) + [
        'derniere_visite' => $c['derniere'], 'technicien_nom' => $c['technicien_nom'], 'agence_nom' => $c['agence_nom'],
    ], $q->fetchAll())]);
}

function a_client(): void
{
    $u = exiger_equipe();
    $c = exiger_client_autorise($u, entier($_GET['id'] ?? 0));
    $db = base();
    $v = $db->prepare('SELECT id, type, quand, auteur, jeton, envoye_le, duree_min, charge, alertes FROM visites WHERE client_id = ? ORDER BY quand DESC LIMIT 100');
    $v->execute([$c['id']]);
    $visites = array_map(fn ($x) => [
        'id' => (int) $x['id'], 'type' => $x['type'], 'quand' => $x['quand'], 'auteur' => $x['auteur'], 'envoye_le' => $x['envoye_le'],
        'duree_min' => $x['duree_min'] !== null ? (int) $x['duree_min'] : null, 'charge' => $x['charge'],
        'alertes' => $x['alertes'] !== null ? (int) $x['alertes'] : null, 'lien' => url_rapport($x['jeton']),
    ], $v->fetchAll());
    $dm = $db->prepare('SELECT id, texte, cree_le, traitee_le, traitee_par, reponse FROM demandes WHERE client_id = ? ORDER BY id DESC LIMIT 50');
    $dm->execute([$c['id']]);
    $cc = $db->prepare('SELECT courriel, actif, derniere_connexion FROM comptes_clients WHERE client_id = ?');
    $cc->execute([$c['id']]);
    $compte = $cc->fetch() ?: null;
    $plus = ['visites' => $visites, 'demandes' => $dm->fetchAll(), 'compte' => $compte, 'duree_estimee' => estimer_durees([(int) $c['id']])[(int) $c['id']]];
    if (in_array($u['role'], ['chef', 'superadmin'], true)) {
        $t = $db->prepare("SELECT id, nom FROM utilisateurs WHERE role = 'technicien' AND actif = 1 AND agence_id = ? ORDER BY nom");
        $t->execute([$c['agence_id']]);
        $plus['techniciens'] = $t->fetchAll();
    }
    if ($u['role'] === 'superadmin') {
        $plus['agences'] = $db->query('SELECT id, nom FROM agences WHERE actif = 1 ORDER BY nom')->fetchAll();
    }
    sortir(200, ['ok' => true, 'client' => client_public($c)] + $plus);
}

function technicien_valable(int $id, int $agence): ?int
{
    if ($id <= 0) {
        return null;
    }
    $q = base()->prepare("SELECT id FROM utilisateurs WHERE id = ? AND role = 'technicien' AND actif = 1 AND agence_id = ?");
    $q->execute([$id, $agence]);
    return $q->fetchColumn() !== false ? $id : null;
}

function a_client_enregistrer(): void
{
    $u = exiger_equipe();
    exiger_post();
    $d = entree();
    $id = entier($d['id'] ?? 0);
    $avant = $id > 0 ? exiger_client_autorise($u, $id) : null;
    $c = [];
    foreach (['prenom' => 60, 'nom' => 60, 'telephone' => 30, 'courriel' => 120, 'adresse' => 200, 'commune' => 60, 'offre' => 60, 'notes' => 4000, 'acces' => 1000] as $k => $max) {
        $c[$k] = texte($d[$k] ?? '', $max);
    }
    // Tout le monde peut dire d'où vient un client ; une valeur hors liste ne s'enregistre pas.
    $c['venu_par'] = array_key_exists((string) ($d['venu_par'] ?? ''), VENUS_PAR) ? (string) $d['venu_par'] : ($avant['venu_par'] ?? '');
    if ($c['prenom'] === '' && $c['nom'] === '') {
        sortir(200, ['ok' => false, 'erreur' => 'nom']);
    }
    $db = base();
    // L'agence : la direction choisit, les autres restent dans la leur.
    if ($u['role'] === 'superadmin') {
        $agence = entier($d['agence_id'] ?? ($avant['agence_id'] ?? 0));
        $ok = $db->prepare('SELECT id FROM agences WHERE id = ?');
        $ok->execute([$agence]);
        if ($ok->fetchColumn() === false) {
            $agence = (int) $db->query('SELECT MIN(id) FROM agences')->fetchColumn();
        }
    } else {
        $agence = (int) $u['agence_id'];
    }
    // Le planning (technicien, jour, statut) appartient au chef et à la direction ; le technicien ne le touche pas.
    if (in_array($u['role'], ['chef', 'superadmin'], true)) {
        $c['statut'] = in_array($d['statut'] ?? '', STATUTS_CLIENT, true) ? $d['statut'] : ($avant['statut'] ?? 'prospect');
        $c['statut_motif'] = texte($d['statut_motif'] ?? ($avant['statut_motif'] ?? ''), 200);
        $c['formule'] = texte($d['formule'] ?? '', 40);
        $c['technicien_id'] = technicien_valable(entier($d['technicien_id'] ?? 0), $agence);
        $jour = entier($d['jour_passage'] ?? 0);
        $c['jour_passage'] = ($jour >= 1 && $jour <= 7) ? $jour : null;
    } else {
        $c['statut'] = $avant['statut'] ?? 'prospect';
        $c['statut_motif'] = $avant['statut_motif'] ?? '';
        $c['formule'] = $avant['formule'] ?? '';
        $c['technicien_id'] = $avant ? $avant['technicien_id'] : (int) $u['id'];
        $c['jour_passage'] = $avant['jour_passage'] ?? null;
    }
    $c['agence_id'] = $agence;
    // L'adresse a changé : on la replace sur la carte. Si le service ne répond pas, la fiche s'enregistre quand même.
    $geo = null;
    $adresse_change = !$avant || $avant['adresse'] !== $c['adresse'] || $avant['commune'] !== $c['commune'];
    if ($c['adresse'] !== '' && ($adresse_change || $avant['lat'] === null)) {
        $geo = geocoder($c['adresse'], $c['commune']);
    }
    $c['lat'] = $geo ? $geo['lat'] : ($adresse_change ? null : $avant['lat']);
    $c['lon'] = $geo ? $geo['lon'] : ($adresse_change ? null : $avant['lon']);
    $c['geo_score'] = $geo ? $geo['score'] : ($adresse_change ? null : $avant['geo_score']);
    $c['maj_le'] = maintenant();
    // Un changement de statut se date, se trace, et a ses effets : la désinscription ferme l'espace client ;
    // un retour en client ne le rouvre pas tout seul (il faut un nouveau lien, en connaissance de cause).
    $change = !$avant || $avant['statut'] !== $c['statut'];
    $c['statut_depuis'] = $change ? maintenant() : $avant['statut_depuis'];
    if ($change && in_array($c['statut'], ['client', 'prospect'], true)) {
        $c['statut_motif'] = '';   // un motif appartient à une pause ou une sortie, pas à un retour
    }
    if ($avant && $change) {
        if ($c['statut'] === 'desinscrit') {
            $db->prepare('UPDATE comptes_clients SET actif = 0 WHERE client_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM sessions_clients WHERE compte_id IN (SELECT id FROM comptes_clients WHERE client_id = ?)')->execute([$id]);
        }
        tracer($u, 'statut client', "$id {$avant['statut']} → {$c['statut']}" . ($c['statut_motif'] !== '' ? ' : ' . $c['statut_motif'] : ''));
    }
    if ($avant && $avant['lead_recu_le'] && !$avant['lead_attribue_le'] && $c['technicien_id']) {
        $c['lead_attribue_le'] = maintenant();
        notifier_technicien_lead((int) $c['technicien_id'], $id, $c + ['interets' => $c['offre']]);
    }
    if ($avant) {
        $cols = implode(', ', array_map(fn ($k) => "$k = ?", array_keys($c)));
        $db->prepare("UPDATE clients SET $cols WHERE id = ?")->execute([...array_values($c), $id]);
    } else {
        $c['origine'] = 'saisie';
        $c['cree_le'] = maintenant();
        $cols = implode(', ', array_keys($c));
        $marques = implode(',', array_fill(0, count($c), '?'));
        $db->prepare("INSERT INTO clients ($cols) VALUES ($marques)")->execute(array_values($c));
        $id = (int) $db->lastInsertId();
    }
    tracer($u, 'client enregistré', (string) $id);
    sortir(200, ['ok' => true, 'id' => $id, 'carte' => $c['lat'] !== null]);
}

function a_client_supprimer(): void
{
    // Droit à l'effacement, et nettoyage des fiches d'essai : la fiche, ses visites, ses photos, son compte.
    $u = exiger_equipe(['chef', 'superadmin']);
    exiger_post();
    $c = exiger_client_autorise($u, entier(entree()['id'] ?? 0));
    $db = base();
    $v = $db->prepare('SELECT id FROM visites WHERE client_id = ?');
    $v->execute([$c['id']]);
    foreach ($v->fetchAll(PDO::FETCH_COLUMN) as $vid) {
        $dossier = dossier_donnees() . '/photos/' . (int) $vid;
        foreach (glob($dossier . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dossier);
    }
    foreach (glob(dossier_documents((int) $c['id']) . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir(dossier_documents((int) $c['id']));
    $db->prepare('DELETE FROM clients WHERE id = ?')->execute([$c['id']]);
    tracer($u, 'client supprimé', $c['id'] . ' ' . $c['prenom'] . ' ' . $c['nom']);
    sortir(200, ['ok' => true]);
}

function a_import_liste(): void
{
    // La liste d'attente du site est écrite à côté du site : on la relit sans rien recopier à la main.
    $u = exiger_equipe(['chef', 'superadmin']);
    exiger_post();
    $db = base();
    $fichier = dirname(dossier_donnees()) . '/donnees-flamingo/liste-attente.csv';
    if (!is_file($fichier)) {
        sortir(200, ['ok' => true, 'ajoutes' => 0, 'deja' => 0]);
    }
    $ajoutes = 0;
    $deja = 0;
    $f = fopen($fichier, 'r');
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
        $n = fn ($v) => ltrim(texte($v, 200), "'");
        $recu = strtotime($n($r['date'] ?? ''));
        $id = enregistrer_lead(['prenom' => $n($r['prenom'] ?? ''), 'nom' => $n($r['nom'] ?? ''), 'telephone' => $n($r['telephone'] ?? ''), 'courriel' => $n($r['courriel'] ?? ''),
            'commune' => $n($r['commune'] ?? ''), 'interets' => $n($r['interets'] ?? ''), 'notes' => 'Inscrit sur le site le ' . $n($r['date'] ?? ''),
            'recu_le' => $recu ? date('c', $recu) : null], 'liste d\'attente');
        if ($id === null) {
            $deja++;
            continue;
        }
        $ajoutes++;
    }
    fclose($f);
    tracer($u, 'import liste', "$ajoutes ajoutés, $deja déjà présents");
    sortir(200, ['ok' => true, 'ajoutes' => $ajoutes, 'deja' => $deja]);
}

/* ---------- Visites ---------- */

function a_visite_enregistrer(): void
{
    $u = exiger_equipe();
    exiger_post();
    $d = json_decode((string) ($_POST['visite'] ?? ''), true);
    if (!is_array($d)) {
        sortir(400, ['ok' => false, 'erreur' => 'donnees']);
    }
    $type = in_array($d['type'] ?? '', ['diagnostic', 'passage'], true) ? $d['type'] : '';
    $local = texte($d['identifiant_local'] ?? '', 60);
    if ($type === '' || $local === '') {
        sortir(400, ['ok' => false, 'erreur' => 'donnees']);
    }
    $client = exiger_client_autorise($u, entier($d['client_id'] ?? 0));
    $db = base();
    // Un envoi rejoué depuis la file du téléphone ne crée jamais deux visites.
    $existe = $db->prepare('SELECT id, jeton FROM visites WHERE identifiant_local = ?');
    $existe->execute([$local]);
    if ($r = $existe->fetch()) {
        sortir(200, ['ok' => true, 'id' => (int) $r['id'], 'lien' => url_rapport($r['jeton']), 'rejoue' => true]);
    }
    $contenu = is_array($d['contenu'] ?? null) ? $d['contenu'] : [];
    $json = json_encode($contenu, JSON_UNESCAPED_UNICODE);
    if ($json === false || strlen($json) > 100000) {
        sortir(400, ['ok' => false, 'erreur' => 'trop_long']);
    }
    // L'heure vient du téléphone (une visite peut partir des heures plus tard depuis la file) :
    // gardée si lisible et ni future ni trop ancienne.
    $t = strtotime(texte($d['quand'] ?? '', 40));
    $quand = ($t !== false && $t <= time() + 300 && $t > time() - 30 * 86400) ? date('c', $t) : maintenant();
    $duree = entier($d['duree_min'] ?? 0);
    $duree = ($duree >= 1 && $duree <= 600) ? $duree : null;
    $charge = in_array($d['charge'] ?? '', ['leger', 'normal', 'lourd'], true) ? $d['charge'] : '';
    $jeton = jeton(24);
    $db->beginTransaction();
    $db->prepare('INSERT INTO visites (client_id, type, quand, auteur, donnees, jeton, identifiant_local, technicien_id, alertes, duree_min, charge) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$client['id'], $type, $quand, $u['nom'], $json, $jeton, $local, $u['id'], compter_alertes($contenu), $duree, $charge]);
    $id = (int) $db->lastInsertId();
    $legendes = is_array($d['legendes'] ?? null) ? $d['legendes'] : [];
    $fichiers = $_FILES['photos'] ?? null;
    if ($fichiers && is_array($fichiers['tmp_name'])) {
        $dossier = dossier_donnees() . '/photos/' . $id;
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
    carnet_piscine_depuis_visite((int) $client['id'], $contenu, compter_alertes($contenu), $u['nom']);
    marquer_lead_contacte((int) $client['id'], $u['nom']);
    // Les notes d'accès suivent le client d'une visite à l'autre.
    if (isset($d['acces']) && is_string($d['acces'])) {
        $db->prepare('UPDATE clients SET acces = ? WHERE id = ?')->execute([texte($d['acces'], 1000), $client['id']]);
    }
    $db->prepare('UPDATE clients SET maj_le = ? WHERE id = ?')->execute([maintenant(), $client['id']]);
    $db->commit();
    tracer($u, 'visite enregistrée', "$type $id client {$client['id']}");
    sortir(200, ['ok' => true, 'id' => $id, 'lien' => url_rapport($jeton)]);
}

function visite_autorisee(array $u, int $id): array
{
    $q = base()->prepare('SELECT v.id, v.jeton, v.type, v.client_id FROM visites v WHERE v.id = ?');
    $q->execute([$id]);
    $v = $q->fetch();
    if (!$v) {
        sortir(404, ['ok' => false, 'erreur' => 'introuvable']);
    }
    $c = exiger_client_autorise($u, (int) $v['client_id']);
    return $v + ['prenom' => $c['prenom'], 'courriel' => $c['courriel']];
}

function a_visite_courriel(): void
{
    $u = exiger_equipe();
    exiger_post();
    $v = visite_autorisee($u, entier(entree()['id'] ?? 0));
    if (!filter_var($v['courriel'], FILTER_VALIDATE_EMAIL)) {
        tracer($u, 'envoi refusé', 'pas de courriel valide, visite ' . $v['id']);
        sortir(200, ['ok' => false, 'erreur' => 'courriel']);
    }
    $objet = $v['type'] === 'diagnostic' ? 'Le diagnostic de votre piscine' : 'Le rapport de passage de votre piscine';
    $corps = 'Bonjour ' . $v['prenom'] . ",\n\nVoici " . ($v['type'] === 'diagnostic' ? 'le diagnostic' : 'le rapport') . " de votre piscine :\n"
        . url_rapport($v['jeton']) . "\n\nÀ bientôt,\nVotre Flamingo\nMaison Flamingo · " . TELEPHONE;
    $entetes = implode("\r\n", ['From: Maison Flamingo <' . EXPEDITEUR . '>', 'Reply-To: ' . EXPEDITEUR, 'MIME-Version: 1.0', 'Content-Type: text/plain; charset=UTF-8']);
    $envoye = @mail($v['courriel'], '=?UTF-8?B?' . base64_encode($objet) . '?=', $corps, $entetes, '-f' . EXPEDITEUR);
    if ($envoye) {
        base()->prepare('UPDATE visites SET envoye_le = ? WHERE id = ?')->execute([maintenant(), $v['id']]);
    }
    tracer($u, $envoye ? 'rapport envoyé' : 'envoi échoué', (string) $v['id']);
    sortir(200, ['ok' => $envoye]);
}

function a_visite_marquer_envoyee(): void
{
    $u = exiger_equipe();
    exiger_post();
    $v = visite_autorisee($u, entier(entree()['id'] ?? 0));
    base()->prepare('UPDATE visites SET envoye_le = COALESCE(envoye_le, ?) WHERE id = ?')->execute([maintenant(), $v['id']]);
    sortir(200, ['ok' => true]);
}

function a_rapport(): void
{
    // Public, mais seulement avec le jeton du rapport : 24 octets au hasard, rien à deviner.
    $t = (string) ($_GET['t'] ?? '');
    if (!preg_match('/^[A-Za-z0-9_-]{24,64}$/', $t)) {
        sortir(404, ['ok' => false]);
    }
    $q = base()->prepare('SELECT v.id, v.type, v.quand, v.auteur, v.donnees, v.technicien_id, c.prenom, c.commune, c.agence_id, c.technicien_id AS technicien_actuel FROM visites v JOIN clients c ON c.id = v.client_id WHERE v.jeton = ?');
    $q->execute([$t]);
    $v = $q->fetch();
    if (!$v) {
        sortir(404, ['ok' => false]);
    }
    $contenu = json_decode($v['donnees'], true) ?: [];
    unset($contenu['interne']);   // les notes internes ne sortent jamais vers le client
    $p = base()->prepare('SELECT id, legende FROM photos WHERE visite_id = ? ORDER BY id');
    $p->execute([$v['id']]);
    $photos = array_map(fn ($x) => ['url' => 'api.php?action=photo&id=' . $x['id'] . '&t=' . $t, 'legende' => $x['legende']], $p->fetchAll());
    $equipe = equipe_du_client(['technicien_id' => $v['technicien_id'] ?: $v['technicien_actuel'], 'agence_id' => $v['agence_id']], $t);
    sortir(200, ['ok' => true, 'rapport' => ['type' => $v['type'], 'quand' => $v['quand'], 'auteur' => $v['auteur'], 'prenom' => $v['prenom'], 'commune' => $v['commune'], 'contenu' => $contenu, 'photos' => $photos, 'equipe' => $equipe]]);
}

function a_photo(): void
{
    $q = base()->prepare('SELECT p.fichier, p.visite_id, v.jeton, v.client_id FROM photos p JOIN visites v ON v.id = p.visite_id WHERE p.id = ?');
    $q->execute([entier($_GET['id'] ?? 0)]);
    $p = $q->fetch();
    $t = (string) ($_GET['t'] ?? '');
    $permis = $p && $t !== '' && hash_equals($p['jeton'], $t);
    if ($p && !$permis) {
        $u = session_equipe();
        $permis = $u && (!double_facteur_requis($u) || (int) $u['totp_ok'] === 1) && client_autorise($u, (int) $p['client_id']);
    }
    if (!$permis) {
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
}

/* ---------- Tournées et carte ---------- */

function technicien_vise(array $u): array
{
    if ($u['role'] === 'technicien') {
        return $u;
    }
    $id = entier($_GET['technicien'] ?? (entree()['technicien_id'] ?? 0));
    $q = base()->prepare("SELECT id, nom, agence_id FROM utilisateurs WHERE id = ? AND role = 'technicien'");
    $q->execute([$id]);
    $t = $q->fetch();
    if (!$t || ($u['role'] === 'chef' && (int) $t['agence_id'] !== (int) $u['agence_id'])) {
        tracer($u, 'tournée refusée', (string) $id);
        sortir(404, ['ok' => false, 'erreur' => 'technicien']);
    }
    return $t;
}

function a_tournee(): void
{
    $u = exiger_equipe();
    $t = technicien_vise($u);
    $jour = entier($_GET['jour'] ?? 0);
    if ($jour < 1 || $jour > 7) {
        $jour = (int) date('N');
    }
    sortir(200, ['ok' => true] + bilan_journee($t, $jour));
}

function a_tournee_ordonner(): void
{
    // Le technicien peut adopter l'ordre proposé pour ses propres journées ; le chef pour toute son équipe.
    $u = exiger_equipe();
    exiger_post();
    $d = entree();
    $t = technicien_vise($u);
    $jour = entier($d['jour'] ?? 0);
    $ids = is_array($d['ids'] ?? null) ? array_map('intval', $d['ids']) : [];
    if ($jour < 1 || $jour > 7 || !$ids) {
        sortir(400, ['ok' => false, 'erreur' => 'donnees']);
    }
    $db = base();
    $db->beginTransaction();
    $q = $db->prepare('UPDATE clients SET ordre = ? WHERE id = ? AND technicien_id = ? AND jour_passage = ?');
    foreach (array_values($ids) as $i => $id) {
        $q->execute([$i + 1, $id, $t['id'], $jour]);
    }
    $db->commit();
    tracer($u, 'tournée réordonnée', $t['nom'] . ' ' . (JOURS[$jour] ?? ''));
    sortir(200, ['ok' => true]);
}

function agence_visee(array $u): ?int
{
    if ($u['role'] === 'superadmin') {
        $a = entier($_GET['agence'] ?? 0);
        return $a > 0 ? $a : null;
    }
    return (int) $u['agence_id'];
}

function a_charge(): void
{
    // La semaine de chaque technicien, jour par jour, en heures et non en nombre de visites.
    $u = exiger_equipe(['chef', 'superadmin']);
    $agence = agence_visee($u) ?? (int) base()->query('SELECT MIN(id) FROM agences')->fetchColumn();
    $q = base()->prepare("SELECT id, nom, agence_id FROM utilisateurs WHERE role = 'technicien' AND actif = 1 AND agence_id = ? ORDER BY nom");
    $q->execute([$agence]);
    $lignes = [];
    foreach ($q->fetchAll() as $t) {
        $jours = [];
        for ($j = 1; $j <= 6; $j++) {
            $b = bilan_journee($t, $j);
            $jours[$j] = ['visites' => count($b['arrets']), 'total_min' => $b['propose']['total_min'], 'sur_place_min' => $b['actuel']['sur_place_min'], 'trajet_min' => $b['propose']['trajet_min']];
        }
        $lignes[] = ['id' => (int) $t['id'], 'nom' => $t['nom'], 'jours' => $jours];
    }
    sortir(200, ['ok' => true, 'agence' => $agence, 'jours' => array_slice(JOURS, 0, 6, true), 'techniciens' => $lignes]);
}

function a_longues(): void
{
    // Les piscines où l'on passe le plus de temps : la médiane des visites mesurées sur quatre mois.
    $u = exiger_equipe();
    [$w, $p] = portee_clients($u);
    $q = base()->prepare("SELECT c.id, c.prenom, c.nom, c.commune, c.jour_passage, t.nom AS technicien, v.duree_min, v.charge
        FROM visites v JOIN clients c ON c.id = v.client_id LEFT JOIN utilisateurs t ON t.id = c.technicien_id
        WHERE ($w) AND v.type = 'passage' AND v.duree_min IS NOT NULL AND v.quand >= ?");
    $q->execute([...$p, date('c', strtotime('-120 days'))]);
    $par = [];
    foreach ($q->fetchAll() as $l) {
        $id = (int) $l['id'];
        $par[$id] ??= ['id' => $id, 'nom' => trim($l['prenom'] . ' ' . $l['nom']), 'commune' => $l['commune'], 'technicien' => $l['technicien'],
            'jour' => JOURS[(int) $l['jour_passage']] ?? '', 'durees' => [], 'lourdes' => 0];
        $par[$id]['durees'][] = (int) $l['duree_min'];
        $par[$id]['lourdes'] += $l['charge'] === 'lourd' ? 1 : 0;
    }
    $sortie = [];
    foreach ($par as $c) {
        if (count($c['durees']) < 2) {
            continue;
        }
        sort($c['durees']);
        $c['mediane'] = $c['durees'][intdiv(count($c['durees']), 2)];
        $c['visites'] = count($c['durees']);
        unset($c['durees']);
        $sortie[] = $c;
    }
    usort($sortie, fn ($a, $b) => $b['mediane'] <=> $a['mediane']);
    sortir(200, ['ok' => true, 'clients' => array_slice($sortie, 0, 30)]);
}

function a_geocoder_manquants(): void
{
    $u = exiger_equipe(['chef', 'superadmin']);
    exiger_post();
    [$w, $p] = portee_clients($u);
    $q = base()->prepare("SELECT id, adresse, commune FROM clients c WHERE ($w) AND c.lat IS NULL AND c.adresse <> '' LIMIT 40");
    $q->execute($p);
    $places = 0;
    $restes = 0;
    $maj = base()->prepare('UPDATE clients SET lat = ?, lon = ?, geo_score = ? WHERE id = ?');
    foreach ($q->fetchAll() as $c) {
        $g = geocoder($c['adresse'], $c['commune']);
        if ($g) {
            $maj->execute([$g['lat'], $g['lon'], $g['score'], $c['id']]);
            $places++;
        } else {
            $restes++;
        }
    }
    tracer($u, 'adresses placées', "$places placées, $restes introuvables");
    sortir(200, ['ok' => true, 'places' => $places, 'introuvables' => $restes]);
}

/* ---------- Équipe et agences ---------- */

function a_equipe(): void
{
    $u = exiger_equipe(['chef', 'superadmin']);
    $sql = "SELECT u.id, u.nom, u.courriel, u.telephone, u.photo, u.role, u.agence_id, u.actif, u.totp_actif, u.derniere_connexion, u.hash = '' AS en_attente, a.nom AS agence,
            (SELECT COUNT(*) FROM clients c WHERE c.technicien_id = u.id AND c.statut IN ('client', 'prospect', 'veille')) AS clients,
            (SELECT COUNT(*) FROM secteurs s WHERE s.technicien_id = u.id) AS secteurs
        FROM utilisateurs u LEFT JOIN agences a ON a.id = u.agence_id";
    if ($u['role'] === 'chef') {
        $q = base()->prepare("$sql WHERE u.agence_id = ? ORDER BY u.role, u.nom");
        $q->execute([$u['agence_id']]);
    } else {
        $q = base()->query("$sql ORDER BY a.nom, u.role, u.nom");
    }
    $agences = $u['role'] === 'superadmin' ? base()->query('SELECT id, nom FROM agences WHERE actif = 1 ORDER BY nom')->fetchAll() : [];
    sortir(200, ['ok' => true, 'membres' => array_map(fn ($m) => [
        'id' => (int) $m['id'], 'nom' => $m['nom'], 'courriel' => $m['courriel'], 'telephone' => $m['telephone'], 'photo' => $m['photo'] !== '', 'role' => $m['role'], 'agence' => $m['agence'],
        'clients' => (int) $m['clients'], 'secteurs' => (int) $m['secteurs'],
        'agence_id' => $m['agence_id'] !== null ? (int) $m['agence_id'] : null, 'actif' => (int) $m['actif'] === 1,
        'double_facteur' => (int) $m['totp_actif'] === 1, 'derniere_connexion' => $m['derniere_connexion'], 'en_attente' => (int) $m['en_attente'] === 1,
    ], $q->fetchAll()), 'agences' => $agences]);
}

function a_equipe_inviter(): void
{
    $u = exiger_equipe(['chef', 'superadmin']);
    exiger_post();
    $d = entree();
    $nom = texte($d['nom'] ?? '', 80);
    $courriel = mb_strtolower(texte($d['courriel'] ?? '', 120));
    // Un chef n'invite que des techniciens, chez lui ; la direction invite chefs et techniciens.
    // Personne n'invite un compte de direction depuis l'écran.
    $role = $u['role'] === 'superadmin' && ($d['role'] ?? '') === 'chef' ? 'chef' : 'technicien';
    $agence = $u['role'] === 'superadmin' ? entier($d['agence_id'] ?? 0) : (int) $u['agence_id'];
    $db = base();
    $a = $db->prepare('SELECT id FROM agences WHERE id = ? AND actif = 1');
    $a->execute([$agence]);
    if ($nom === '' || !filter_var($courriel, FILTER_VALIDATE_EMAIL) || $a->fetchColumn() === false) {
        sortir(200, ['ok' => false, 'erreur' => 'champs']);
    }
    $e = $db->prepare('SELECT id FROM utilisateurs WHERE courriel = ?');
    $e->execute([$courriel]);
    if ($e->fetchColumn() !== false) {
        sortir(200, ['ok' => false, 'erreur' => 'courriel_pris']);
    }
    $db->prepare("INSERT INTO utilisateurs (courriel, nom, hash, cree_le, role, agence_id, actif) VALUES (?,?,'',?,?,?,1)")
        ->execute([$courriel, $nom, maintenant(), $role, $agence]);
    $id = (int) $db->lastInsertId();
    $lien = url_base() . '/#/invitation/' . creer_invitation('equipe', $id, (int) $u['id']);
    tracer($u, 'membre invité', "$role $courriel");
    sortir(200, ['ok' => true, 'id' => $id, 'lien' => $lien]);
}

function a_equipe_modifier(): void
{
    $u = exiger_equipe(['chef', 'superadmin']);
    exiger_post();
    $d = entree();
    $m = membre_gerable($u, entier($d['id'] ?? 0));
    if (!$m) {
        tracer($u, 'modification de membre refusée', (string) ($d['id'] ?? ''));
        sortir(404, ['ok' => false, 'erreur' => 'introuvable']);
    }
    $db = base();
    $nom = texte($d['nom'] ?? $m['nom'], 80) ?: $m['nom'];
    $telephone = texte($d['telephone'] ?? $m['telephone'], 30);
    $courriel = isset($d['courriel']) ? mb_strtolower(texte($d['courriel'], 120)) : $m['courriel'];
    if (!filter_var($courriel, FILTER_VALIDATE_EMAIL)) {
        sortir(200, ['ok' => false, 'erreur' => 'courriel']);
    }
    if ($courriel !== $m['courriel']) {
        $e = $db->prepare('SELECT id FROM utilisateurs WHERE courriel = ? AND id <> ?');
        $e->execute([$courriel, $m['id']]);
        if ($e->fetchColumn() !== false) {
            sortir(200, ['ok' => false, 'erreur' => 'courriel_pris']);
        }
    }
    $actif = array_key_exists('actif', $d) ? ($d['actif'] ? 1 : 0) : (int) $m['actif'];
    $role = $m['role'];
    $agence = $m['agence_id'];
    if ($u['role'] === 'superadmin') {
        $role = in_array($d['role'] ?? '', ['chef', 'technicien'], true) ? $d['role'] : $role;
        $agence = entier($d['agence_id'] ?? $agence) ?: $agence;
    }
    // Le départ se date, pour que le pilotage compte les départs de l'année ; un retour l'efface.
    $parti = $actif ? null : ((int) $m['actif'] === 1 ? maintenant() : $m['parti_le']);
    $db->prepare('UPDATE utilisateurs SET nom = ?, telephone = ?, courriel = ?, actif = ?, role = ?, agence_id = ?, parti_le = ? WHERE id = ?')->execute([$nom, $telephone, $courriel, $actif, $role, $agence, $parti, $m['id']]);
    $orphelins = 0;
    if (!$actif || (int) $agence !== (int) $m['agence_id'] || $role !== $m['role']) {
        // Un départ ou un changement de poste ferme ses sessions tout de suite.
        $db->prepare('DELETE FROM sessions WHERE utilisateur_id = ?')->execute([$m['id']]);
        $o = $db->prepare('SELECT COUNT(*) FROM clients WHERE technicien_id = ?');
        $o->execute([$m['id']]);
        $orphelins = (int) $o->fetchColumn();
    }
    tracer($u, 'membre modifié', "{$m['courriel']}" . ($courriel !== $m['courriel'] ? " → $courriel" : '') . " actif=$actif role=$role agence=$agence");
    sortir(200, ['ok' => true, 'clients_a_reattribuer' => $orphelins]);
}

function a_equipe_lien(): void
{
    // Mot de passe oublié ou compte douteux : l'ancien mot de passe cesse aussitôt, un lien neuf le remplace.
    $u = exiger_equipe(['chef', 'superadmin']);
    exiger_post();
    $d = entree();
    $m = membre_gerable($u, entier($d['id'] ?? 0));
    if (!$m) {
        tracer($u, 'nouveau lien refusé', (string) ($d['id'] ?? ''));
        sortir(404, ['ok' => false, 'erreur' => 'introuvable']);
    }
    $db = base();
    $db->prepare("UPDATE utilisateurs SET hash = '' WHERE id = ?")->execute([$m['id']]);
    if (!empty($d['double_facteur'])) {
        // Téléphone perdu : le second facteur repart de zéro à la prochaine connexion.
        $db->prepare("UPDATE utilisateurs SET totp_actif = 0, totp_secret = '', totp_dernier = 0 WHERE id = ?")->execute([$m['id']]);
    }
    $db->prepare('DELETE FROM sessions WHERE utilisateur_id = ?')->execute([$m['id']]);
    $lien = url_base() . '/#/invitation/' . creer_invitation('equipe', (int) $m['id'], (int) $u['id']);
    tracer($u, 'nouveau lien de connexion', $m['courriel'] . (!empty($d['double_facteur']) ? ', second facteur remis à zéro' : ''));
    sortir(200, ['ok' => true, 'lien' => $lien]);
}

// Un technicien part, un autre arrive : ses clients, ses jours de passage, ses secteurs et ses leads
// ouverts passent au remplaçant en une fois. Les visites déjà faites restent signées de l'ancien :
// le carnet du client dit qui est vraiment passé.
function a_equipe_remplacer(): void
{
    $u = exiger_equipe(['chef', 'superadmin']);
    exiger_post();
    $d = entree();
    $ancien = membre_gerable($u, entier($d['ancien_id'] ?? 0));
    if (!$ancien || $ancien['role'] !== 'technicien') {
        tracer($u, 'remplacement refusé', (string) ($d['ancien_id'] ?? ''));
        sortir(404, ['ok' => false, 'erreur' => 'introuvable']);
    }
    $db = base();
    $nouveau_id = entier($d['nouveau_id'] ?? 0);
    $lien = null;
    if ($nouveau_id > 0) {
        $nouveau = membre_gerable($u, $nouveau_id);
        if (!$nouveau || $nouveau['role'] !== 'technicien' || (int) $nouveau['agence_id'] !== (int) $ancien['agence_id'] || (int) $nouveau['actif'] !== 1) {
            sortir(200, ['ok' => false, 'erreur' => 'nouveau']);
        }
    } else {
        // Le remplaçant n'a pas encore de compte : on le crée dans la même agence, et on rend son lien d'accès.
        $nom = texte($d['nom'] ?? '', 80);
        $courriel = mb_strtolower(texte($d['courriel'] ?? '', 120));
        if ($nom === '' || !filter_var($courriel, FILTER_VALIDATE_EMAIL)) {
            sortir(200, ['ok' => false, 'erreur' => 'champs']);
        }
        $e = $db->prepare('SELECT id FROM utilisateurs WHERE courriel = ?');
        $e->execute([$courriel]);
        if ($e->fetchColumn() !== false) {
            sortir(200, ['ok' => false, 'erreur' => 'courriel_pris']);
        }
        $db->prepare("INSERT INTO utilisateurs (courriel, nom, telephone, hash, cree_le, role, agence_id, actif) VALUES (?,?,?,'',?,'technicien',?,1)")
            ->execute([$courriel, $nom, texte($d['telephone'] ?? '', 30), maintenant(), $ancien['agence_id']]);
        $nouveau_id = (int) $db->lastInsertId();
        $lien = url_base() . '/#/invitation/' . creer_invitation('equipe', $nouveau_id, (int) $u['id']);
    }
    $db->beginTransaction();
    $c = $db->prepare('UPDATE clients SET technicien_id = ?, maj_le = ? WHERE technicien_id = ?');
    $c->execute([$nouveau_id, maintenant(), $ancien['id']]);
    $clients = $c->rowCount();
    $s = $db->prepare('UPDATE secteurs SET technicien_id = ?, maj_le = ? WHERE technicien_id = ?');
    $s->execute([$nouveau_id, maintenant(), $ancien['id']]);
    $secteurs = $s->rowCount();
    if (!empty($d['desactiver'])) {
        $db->prepare('UPDATE utilisateurs SET actif = 0, parti_le = ? WHERE id = ?')->execute([maintenant(), $ancien['id']]);
        $db->prepare('DELETE FROM sessions WHERE utilisateur_id = ?')->execute([$ancien['id']]);
    }
    $db->commit();
    tracer($u, 'technicien remplacé', "{$ancien['courriel']} → $nouveau_id : $clients clients, $secteurs secteurs" . (!empty($d['desactiver']) ? ', ancien désactivé' : ''));
    sortir(200, ['ok' => true, 'nouveau_id' => $nouveau_id, 'clients' => $clients, 'secteurs' => $secteurs, 'lien' => $lien]);
}

function a_agences(): void
{
    exiger_equipe(['superadmin']);
    $l = base()->query("SELECT a.*, (SELECT COUNT(*) FROM clients c WHERE c.agence_id = a.id AND c.statut = 'client') AS clients,
        (SELECT COUNT(*) FROM utilisateurs u WHERE u.agence_id = a.id AND u.actif = 1) AS membres FROM agences a ORDER BY a.nom")->fetchAll();
    sortir(200, ['ok' => true, 'agences' => array_map(fn ($a) => [
        'id' => (int) $a['id'], 'nom' => $a['nom'], 'ville' => $a['ville'], 'adresse' => $a['adresse'], 'actif' => (int) $a['actif'] === 1,
        'clients' => (int) $a['clients'], 'membres' => (int) $a['membres'], 'carte' => $a['lat'] !== null,
    ], $l)]);
}

function a_agence_enregistrer(): void
{
    $u = exiger_equipe(['superadmin']);
    exiger_post();
    $d = entree();
    $nom = texte($d['nom'] ?? '', 60);
    if ($nom === '') {
        sortir(200, ['ok' => false, 'erreur' => 'nom']);
    }
    $ville = texte($d['ville'] ?? '', 60);
    $adresse = texte($d['adresse'] ?? '', 200);
    $g = $adresse !== '' ? geocoder($adresse, $ville) : null;
    $db = base();
    $id = entier($d['id'] ?? 0);
    try {
        if ($id > 0) {
            $db->prepare('UPDATE agences SET nom = ?, ville = ?, adresse = ?, lat = COALESCE(?, lat), lon = COALESCE(?, lon), actif = ? WHERE id = ?')
                ->execute([$nom, $ville, $adresse, $g['lat'] ?? null, $g['lon'] ?? null, empty($d['ferme']) ? 1 : 0, $id]);
        } else {
            $db->prepare('INSERT INTO agences (nom, ville, adresse, lat, lon, cree_le) VALUES (?,?,?,?,?,?)')->execute([$nom, $ville, $adresse, $g['lat'] ?? null, $g['lon'] ?? null, maintenant()]);
            $id = (int) $db->lastInsertId();
        }
    } catch (PDOException $e) {
        sortir(200, ['ok' => false, 'erreur' => 'nom_pris']);
    }
    tracer($u, 'agence enregistrée', "$id $nom");
    sortir(200, ['ok' => true, 'id' => $id, 'carte' => $g !== null]);
}

/* ---------- Tableau de bord et exports ---------- */

function a_tableau(): void
{
    $u = exiger_equipe(['chef', 'superadmin']);
    $agence = agence_visee($u);
    $db = base();
    $w = $agence ? 'c.agence_id = ?' : '1=1';
    $p = $agence ? [$agence] : [];
    $il_y_a = fn (int $j) => date('c', strtotime("-$j days"));
    $lundi = date('c', strtotime('monday this week'));
    $un = function (string $sql, array $params) use ($db) {
        $q = $db->prepare($sql);
        $q->execute($params);
        return $q->fetch(PDO::FETCH_NUM);
    };
    $statuts = array_fill_keys(STATUTS_CLIENT, 0);
    $q = $db->prepare("SELECT statut, COUNT(*) FROM clients c WHERE $w GROUP BY statut");
    $q->execute($p);
    foreach ($q->fetchAll(PDO::FETCH_NUM) as [$s, $n]) {
        $statuts[$s] = (int) $n;
    }
    [$v30, $diag30, $env30, $alertes30, $mesurees30, $duree30] = $un("SELECT COUNT(*), SUM(v.type = 'diagnostic'), SUM(v.envoye_le IS NOT NULL), SUM(v.alertes > 0),
        SUM(v.duree_min IS NOT NULL), AVG(CASE WHEN v.type = 'passage' THEN v.duree_min END) FROM visites v JOIN clients c ON c.id = v.client_id WHERE $w AND v.quand >= ?", [...$p, $il_y_a(30)]);
    [$prevus] = $un("SELECT COUNT(*) FROM clients c WHERE $w AND c.statut = 'client' AND c.jour_passage IS NOT NULL AND c.technicien_id IS NOT NULL", $p);
    [$faits] = $un("SELECT COUNT(DISTINCT v.client_id) FROM visites v JOIN clients c ON c.id = v.client_id WHERE $w AND v.type = 'passage' AND v.quand >= ?", [...$p, $lundi]);
    [$attente] = $un("SELECT COUNT(*) FROM demandes d JOIN clients c ON c.id = d.client_id WHERE $w AND d.traitee_le IS NULL", $p);
    [$diagnostiques, $convertis] = $un("SELECT COUNT(*), SUM(c.statut = 'client') FROM clients c WHERE $w AND EXISTS (SELECT 1 FROM visites v WHERE v.client_id = c.id AND v.type = 'diagnostic')", $p);
    [$comptes] = $un("SELECT COUNT(*) FROM comptes_clients cc JOIN clients c ON c.id = cc.client_id WHERE $w AND cc.actif = 1", $p);
    [$sans_jour] = $un("SELECT COUNT(*) FROM clients c WHERE $w AND c.statut = 'client' AND (c.jour_passage IS NULL OR c.technicien_id IS NULL)", $p);
    [$desinscrits30, $veilles30] = $un("SELECT SUM(c.statut = 'desinscrit' AND c.statut_depuis >= ?), SUM(c.statut = 'veille' AND c.statut_depuis >= ?) FROM clients c WHERE $w", [$il_y_a(30), $il_y_a(30), ...$p]);
    // Les points qui reviennent le plus : là où sont les travaux à proposer.
    // Les clés partent telles quelles : l'écran les traduit avec les libellés des formulaires.
    $points = [];
    $q = $db->prepare("SELECT v.donnees FROM visites v JOIN clients c ON c.id = v.client_id WHERE $w AND v.quand >= ? AND v.alertes > 0 ORDER BY v.quand DESC LIMIT 3000");
    $q->execute([...$p, $il_y_a(90)]);
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $json) {
        $c = json_decode($json, true) ?: [];
        foreach (PLAGES as $k => [$vert]) {
            $x = $c['valeurs'][$k] ?? null;
            if (is_numeric($x) && ((float) $x < $vert[0] || (float) $x > $vert[1])) {
                $points[$k] = ($points[$k] ?? 0) + 1;
            }
        }
        foreach ((array) ($c['etats'] ?? []) as $k => $e) {
            if (is_array($e) && in_array($e['etat'] ?? '', ['surveiller', 'prevoir'], true)) {
                $points[(string) $k] = ($points[(string) $k] ?? 0) + 1;
            }
        }
    }
    arsort($points);
    // Le détail : par agence pour la direction, par technicien dans une agence.
    $detail = [];
    if ($u['role'] === 'superadmin' && !$agence) {
        foreach ($db->query('SELECT id, nom FROM agences ORDER BY nom')->fetchAll() as $a) {
            $x = $un("SELECT (SELECT COUNT(*) FROM clients c WHERE c.agence_id = ? AND c.statut = 'client'),
                (SELECT COUNT(*) FROM visites v JOIN clients c ON c.id = v.client_id WHERE c.agence_id = ? AND v.quand >= ?),
                (SELECT COUNT(*) FROM clients c WHERE c.agence_id = ? AND c.statut = 'client' AND c.jour_passage IS NOT NULL AND c.technicien_id IS NOT NULL),
                (SELECT COUNT(DISTINCT v.client_id) FROM visites v JOIN clients c ON c.id = v.client_id WHERE c.agence_id = ? AND v.type = 'passage' AND v.quand >= ?),
                (SELECT COUNT(*) FROM demandes d JOIN clients c ON c.id = d.client_id WHERE c.agence_id = ? AND d.traitee_le IS NULL)",
                [$a['id'], $a['id'], $il_y_a(30), $a['id'], $a['id'], $lundi, $a['id']]);
            $detail[] = ['id' => (int) $a['id'], 'nom' => $a['nom'], 'clients' => (int) $x[0], 'visites_30j' => (int) $x[1], 'prevus' => (int) $x[2], 'faits' => (int) $x[3], 'demandes' => (int) $x[4]];
        }
    } else {
        $t = $db->prepare("SELECT id, nom FROM utilisateurs WHERE role = 'technicien' AND actif = 1 AND agence_id = ? ORDER BY nom");
        $t->execute([$agence]);
        foreach ($t->fetchAll() as $m) {
            $x = $un("SELECT (SELECT COUNT(*) FROM clients WHERE technicien_id = ? AND statut IN ('client', 'prospect', 'veille')),
                (SELECT COUNT(*) FROM visites WHERE technicien_id = ? AND quand >= ?),
                (SELECT COUNT(*) FROM clients WHERE technicien_id = ? AND statut = 'client' AND jour_passage IS NOT NULL),
                (SELECT COUNT(DISTINCT client_id) FROM visites WHERE technicien_id = ? AND type = 'passage' AND quand >= ?),
                (SELECT AVG(duree_min) FROM visites WHERE technicien_id = ? AND type = 'passage' AND quand >= ?)",
                [$m['id'], $m['id'], $il_y_a(30), $m['id'], $m['id'], $lundi, $m['id'], $il_y_a(30)]);
            $detail[] = ['id' => (int) $m['id'], 'nom' => $m['nom'], 'clients' => (int) $x[0], 'visites_30j' => (int) $x[1], 'prevus' => (int) $x[2], 'faits' => (int) $x[3], 'duree_moyenne' => $x[4] !== null ? (int) round((float) $x[4]) : null];
        }
    }
    $kpi = kpi_leads($w, $p, ($u['role'] === 'superadmin' && !$agence) ? 'agence' : 'technicien');
    foreach ($detail as &$ligne) {
        $ligne['leads'] = $kpi[(string) $ligne['id']] ?? ['recus' => 0, 'contactes' => 0, 'taux_reponse' => null, 'delai_moyen_h' => null, 'en_retard' => 0, 'devenus_clients' => 0];
    }
    unset($ligne);
    [$leads30, $contactes30, $delai30, $retard, $sansTech] = $un("SELECT COUNT(*), SUM(c.lead_contacte_le IS NOT NULL),
        AVG(CASE WHEN c.lead_contacte_le IS NOT NULL THEN (julianday(c.lead_contacte_le) - julianday(c.lead_attribue_le)) * 24 END),
        SUM(c.lead_contacte_le IS NULL AND julianday('now') - julianday(COALESCE(c.lead_attribue_le, c.lead_recu_le)) > ?),
        SUM(c.lead_contacte_le IS NULL AND c.technicien_id IS NULL)
        FROM clients c WHERE $w AND c.lead_recu_le >= ?", [LEAD_RETARD_H / 24, ...$p, $il_y_a(30)]);
    $totalLeads = (int) base()->query("SELECT COUNT(*) FROM clients WHERE lead_recu_le >= '" . $il_y_a(30) . "'")->fetchColumn();
    $sortie = [
        'ok' => true, 'agence' => $agence, 'statuts' => $statuts,
        'leads' => ['recus_30j' => (int) $leads30, 'contactes_30j' => (int) $contactes30, 'taux_reponse' => $leads30 ? round($contactes30 / $leads30, 2) : null,
            'delai_moyen_h' => $delai30 !== null ? round((float) $delai30, 1) : null, 'en_retard' => (int) $retard, 'sans_technicien' => (int) $sansTech,
            'part_du_total' => $totalLeads ? round($leads30 / $totalLeads, 2) : null, 'retard_h' => LEAD_RETARD_H],
        'visites_30j' => (int) $v30, 'diagnostics_30j' => (int) $diag30, 'envoyes_30j' => (int) $env30, 'avec_points_30j' => (int) $alertes30,
        'mesurees_30j' => (int) $mesurees30, 'duree_passage_moyenne' => $duree30 !== null ? (int) round((float) $duree30) : null,
        'semaine' => ['prevus' => (int) $prevus, 'faits' => (int) $faits], 'demandes_en_attente' => (int) $attente,
        'conversion' => ['diagnostiques' => (int) $diagnostiques, 'devenus_clients' => (int) $convertis],
        'comptes_clients' => (int) $comptes, 'clients_sans_tournee' => (int) $sans_jour, 'desinscrits_30j' => (int) $desinscrits30, 'veilles_30j' => (int) $veilles30,
        'points_frequents' => array_slice(array_map(fn ($k, $n) => ['point' => $k, 'nombre' => $n], array_keys($points), $points), 0, 8),
        'detail' => $detail, 'detail_par' => ($u['role'] === 'superadmin' && !$agence) ? 'agence' : 'technicien',
    ];
    if ($u['role'] === 'superadmin') {
        $s = derniere_sauvegarde();
        $sortie['sauvegarde'] = $s ? ['quand' => $s['quand'], 'octets' => (int) $s['octets'], 'en_retard' => strtotime($s['quand']) < time() - SAUVEGARDE_AGE_MAX] : null;
        $sortie['agences'] = $db->query('SELECT id, nom FROM agences ORDER BY nom')->fetchAll();
    }
    sortir(200, $sortie);
}

function a_export(): void
{
    $u = exiger_equipe(['chef', 'superadmin']);
    [$w, $p] = portee_clients($u);
    $genre = ($_GET['genre'] ?? '') === 'visites' ? 'visites' : 'clients';
    tracer($u, 'export', $genre);
    $db = base();
    $jour = date('Y-m-d');
    if ($genre === 'clients') {
        $q = $db->prepare("SELECT c.*, a.nom AS agence, t.nom AS technicien FROM clients c LEFT JOIN agences a ON a.id = c.agence_id LEFT JOIN utilisateurs t ON t.id = c.technicien_id WHERE $w ORDER BY a.nom, c.nom");
        $q->execute($p);
        envoyer_csv("clients-$jour.csv", ['Agence', 'Prénom', 'Nom', 'Téléphone', 'Courriel', 'Adresse', 'Commune', 'Statut', 'Depuis le', 'Motif', 'Formule', 'Technicien', 'Jour', 'Créé le'],
            array_map(fn ($c) => [$c['agence'], $c['prenom'], $c['nom'], $c['telephone'], $c['courriel'], $c['adresse'], $c['commune'], $c['statut'], substr((string) $c['statut_depuis'], 0, 10), $c['statut_motif'], $c['formule'], $c['technicien'], JOURS[(int) $c['jour_passage']] ?? '', substr($c['cree_le'], 0, 10)], $q->fetchAll()));
    }
    // Les liens de rapport ne sortent pas : un tableur qui circule ne doit pas ouvrir les rapports.
    $q = $db->prepare("SELECT v.quand, v.type, v.auteur, v.alertes, v.duree_min, v.charge, v.envoye_le, v.donnees, c.prenom, c.nom, c.commune, a.nom AS agence
        FROM visites v JOIN clients c ON c.id = v.client_id LEFT JOIN agences a ON a.id = c.agence_id WHERE ($w) AND v.quand >= ? ORDER BY v.quand DESC");
    $q->execute([...$p, date('c', strtotime('-365 days'))]);
    envoyer_csv("visites-$jour.csv", ['Date', 'Type', 'Agence', 'Client', 'Commune', 'Technicien', 'Points à reprendre', 'Durée (min)', 'Charge', 'Envoyé', 'pH', 'Chlore libre', 'TAC', 'Signé', 'Raison du refus', 'À 179 €', 'Quartier', 'Formule proposée', 'Prix proposé'],
        array_map(function ($v) {
            $c = json_decode($v['donnees'], true) ?: [];
            $m = $c['valeurs'] ?? [];
            $i = $c['interne'] ?? [];
            $pr = $c['proposition'] ?? [];
            return [substr($v['quand'], 0, 16), $v['type'], $v['agence'], trim($v['prenom'] . ' ' . $v['nom']), $v['commune'], $v['auteur'], $v['alertes'], $v['duree_min'], $v['charge'], $v['envoye_le'] ? 'oui' : 'non', $m['ph'] ?? '', $m['chlore_libre'] ?? '', $m['tac'] ?? '',
                $i['signe'] ?? '', $i['raison_refus'] ?? '', $i['a_179'] ?? '', $i['quartier'] ?? '', $pr['formule'] ?? '', $pr['prix'] ?? ''];
        }, $q->fetchAll()));
}

/* ---------- Demandes des clients, espace client, journal, sauvegardes ---------- */

function a_demandes(): void
{
    $u = exiger_equipe();
    [$w, $p] = portee_clients($u);
    $toutes = ($_GET['toutes'] ?? '') === '1';
    $q = base()->prepare("SELECT d.*, c.prenom, c.nom, c.commune, c.telephone FROM demandes d JOIN clients c ON c.id = d.client_id
        WHERE ($w)" . ($toutes ? '' : ' AND d.traitee_le IS NULL') . ' ORDER BY d.id DESC LIMIT 200');
    $q->execute($p);
    sortir(200, ['ok' => true, 'demandes' => $q->fetchAll()]);
}

function a_demande_traiter(): void
{
    $u = exiger_equipe();
    exiger_post();
    $d = entree();
    $q = base()->prepare('SELECT * FROM demandes WHERE id = ?');
    $q->execute([entier($d['id'] ?? 0)]);
    $dm = $q->fetch();
    if (!$dm) {
        sortir(404, ['ok' => false]);
    }
    exiger_client_autorise($u, (int) $dm['client_id']);
    base()->prepare('UPDATE demandes SET traitee_le = ?, traitee_par = ?, reponse = ? WHERE id = ?')
        ->execute([maintenant(), $u['nom'], texte($d['reponse'] ?? '', 1000), $dm['id']]);
    tracer($u, 'demande traitée', (string) $dm['id']);
    sortir(200, ['ok' => true]);
}

function a_client_inviter(): void
{
    $u = exiger_equipe();
    exiger_post();
    $c = exiger_client_autorise($u, entier(entree()['id'] ?? 0));
    if ($c['statut'] === 'desinscrit') {
        tracer($u, 'espace client refusé', $c['id'] . ' désinscrit');
        sortir(200, ['ok' => false, 'erreur' => 'desinscrit']);
    }
    $lien = url_base() . '/espace.html#/invitation/' . creer_invitation('client', (int) $c['id'], (int) $u['id']);
    tracer($u, 'espace client : lien créé', (string) $c['id']);
    sortir(200, ['ok' => true, 'lien' => $lien]);
}

function a_client_compte_couper(): void
{
    $u = exiger_equipe(['chef', 'superadmin']);
    exiger_post();
    $c = exiger_client_autorise($u, entier(entree()['id'] ?? 0));
    $db = base();
    $db->prepare('UPDATE comptes_clients SET actif = 0 WHERE client_id = ?')->execute([$c['id']]);
    $db->prepare('DELETE FROM sessions_clients WHERE compte_id IN (SELECT id FROM comptes_clients WHERE client_id = ?)')->execute([$c['id']]);
    tracer($u, 'espace client fermé', (string) $c['id']);
    sortir(200, ['ok' => true]);
}

function a_journal(): void
{
    $u = exiger_equipe(['chef', 'superadmin']);
    if ($u['role'] === 'chef') {
        $q = base()->prepare('SELECT quand, qui, role, action, detail FROM journal WHERE agence_id = ? ORDER BY id DESC LIMIT 300');
        $q->execute([$u['agence_id']]);
    } else {
        $q = base()->query('SELECT quand, qui, role, action, detail FROM journal ORDER BY id DESC LIMIT 300');
    }
    sortir(200, ['ok' => true, 'lignes' => $q->fetchAll()]);
}

function a_sauvegardes(): void
{
    exiger_equipe(['superadmin']);
    $l = base()->query('SELECT id, quand, fichier, octets, statut, origine, detail FROM sauvegardes ORDER BY id DESC LIMIT 60')->fetchAll();
    foreach ($l as &$s) {
        $s['present'] = $s['statut'] === 'ok' && is_file(dossier_sauvegardes() . '/' . basename($s['fichier']));
    }
    sortir(200, ['ok' => true, 'sauvegardes' => $l, 'age_max_h' => SAUVEGARDE_AGE_MAX / 3600]);
}

function a_sauvegarde_lancer(): void
{
    $u = exiger_equipe(['superadmin']);
    exiger_post();
    tracer($u, 'sauvegarde demandée');
    sortir(200, faire_sauvegarde('à la demande'));
}

function a_sauvegarde_telecharger(): void
{
    // Toute la base sort par ici : seulement la direction, avec son second facteur, et c'est tracé.
    $u = exiger_equipe(['superadmin']);
    $q = base()->prepare("SELECT fichier FROM sauvegardes WHERE id = ? AND statut = 'ok'");
    $q->execute([entier($_GET['id'] ?? 0)]);
    $f = $q->fetchColumn();
    $chemin = $f ? dossier_sauvegardes() . '/' . basename((string) $f) : '';
    if (!$f || !is_file($chemin)) {
        sortir(404, ['ok' => false]);
    }
    tracer($u, 'sauvegarde téléchargée', (string) $f);
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . basename((string) $f) . '"');
    header('Content-Length: ' . filesize($chemin));
    readfile($chemin);
    exit;
}
