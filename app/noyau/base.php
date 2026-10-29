<?php
// Maison Flamingo · le socle du serveur : réglages, base de données, migrations, outils.
//
// Aucun secret ici : le dépôt est public. Les données vivent à côté de
// public_html, hors du web. Ce fichier ne produit rien s'il est appelé seul,
// et le dossier noyau/ est de toute façon fermé au web par son .htaccess.

declare(strict_types=1);

date_default_timezone_set('Europe/Paris');
// Un avertissement glissé dans une réponse casse le JSON et peut montrer un chemin du serveur.
ini_set('display_errors', '0');

const VERSION_SCHEMA = 5;
const TAILLE_PHOTO_MAX = 4000000;
const PHOTOS_MAX = 6;
const EXPEDITEUR = 'contact@maisonflamingo.fr';
const TELEPHONE = '06 74 85 90 78';

// Trois rôles d'équipe. Tout rôle absent de cette liste ne reçoit rien (refus par défaut).
const ROLES_EQUIPE = ['superadmin', 'chef', 'technicien'];
// Le cycle de vie d'une fiche. Seul « client » tourne ; les autres restent en base, avec leur histoire.
//   prospect   : contact en cours, diagnostic possible, pas de passage prévu
//   client     : actif, dans les tournées, espace client ouvert
//   veille     : formule en pause (absence longue, travaux, impayé) ; hors tournées, espace client ouvert
//   desinscrit : a résilié ; hors tournées, espace client fermé, carnet conservé
//   perdu      : prospect qui n'a pas signé
const STATUTS_CLIENT = ['prospect', 'client', 'veille', 'desinscrit', 'perdu'];
// Qui voit plusieurs clients, ou toutes les agences, ne se connecte jamais sans second facteur.
const ROLES_DOUBLE_FACTEUR = ['superadmin', 'chef'];
// Un technicien ne se reconnecte pas à chaque passage ; un compte qui voit tout, si.
const DUREES_SESSION = ['technicien' => 2592000, 'chef' => 604800, 'superadmin' => 604800];
const DUREE_SESSION_CLIENT = 7776000;
const DUREE_INVITATION = 604800;
// Sans historique, une visite est estimée à ces durées ; dès trois visites mesurées, la médiane réelle prend le relais.
const DUREE_DEFAUT = ['passage' => 30, 'diagnostic' => 60];
const VITESSE_KMH = 35;          // moyenne en périphérie nîmoise, feux et lotissements compris
const DETOUR_ROUTE = 1.3;        // la route fait environ 30 % de plus que le vol d'oiseau
const ESSAIS_IP = 10;        // échecs de connexion par quart d'heure et par adresse
const ESSAIS_COMPTE = 8;     // échecs par quart d'heure et par compte, même depuis plusieurs adresses
const JOURS = [1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi', 4 => 'Jeudi', 5 => 'Vendredi', 6 => 'Samedi', 7 => 'Dimanche'];
// Les liens envoyés aux clients ne se fabriquent qu'avec ces adresses : un en-tête Host
// trafiqué ne peut pas glisser un autre domaine dans un SMS.
const HOTES = ['app.maisonflamingo.fr', 'maisonflamingo.fr', 'www.maisonflamingo.fr', 'localhost:8793'];

// Les mêmes repères que commun.js, pour compter les points à reprendre côté serveur
// (le tableau de bord ne doit pas croire le téléphone sur parole).
const PLAGES = [
    'ph' => [[7.0, 7.4], [6.8, 7.6]], 'chlore_libre' => [[1, 3], [0.5, 5]], 'tac' => [[80, 150], [60, 200]],
    'th' => [[15, 30], [10, 40]], 'stabilisant' => [[20, 50], [10, 75]], 'sel' => [[3, 5], [2.5, 6]],
];

function sortir(int $code, array $corps): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($corps, JSON_UNESCAPED_UNICODE);
    exit;
}

function dossier_donnees(): string
{
    // noyau/ est dans app/, lui-même dans public_html/ : trois niveaux plus haut, on est hors du web.
    return dirname(__DIR__, 3) . '/donnees-flamingo-app';
}

function maintenant(): string
{
    return date('c');
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

function entier($v): int
{
    return is_numeric($v) ? (int) $v : 0;
}

function entree(): array
{
    $brut = (string) file_get_contents('php://input', false, null, 0, 200000);
    $d = json_decode($brut, true);
    return is_array($d) ? $d : $_POST;
}

function url_base(): string
{
    $hote = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if (!in_array($hote, HOTES, true)) {
        $hote = 'app.maisonflamingo.fr';
    }
    $schema = str_starts_with($hote, 'localhost') ? 'http' : 'https';
    $chemin = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return $schema . '://' . $hote . $chemin;
}

function url_rapport(string $jeton): string
{
    return url_base() . '/rapport.html?t=' . $jeton;
}

/* ---------- La base ---------- */

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
    // Deux techniciens qui enregistrent à la même seconde attendent leur tour au lieu d'échouer.
    $db->exec('PRAGMA busy_timeout = 5000');
    migrer($db);
    return $db;
}

function ajouter_colonne(PDO $db, string $table, string $colonne, string $definition): void
{
    $existantes = array_column($db->query("PRAGMA table_info($table)")->fetchAll(), 'name');
    if (!in_array($colonne, $existantes, true)) {
        $db->exec("ALTER TABLE $table ADD COLUMN $colonne $definition");
    }
}

function migrer(PDO $db): void
{
    if ((int) $db->query('PRAGMA user_version')->fetchColumn() >= VERSION_SCHEMA) {
        return;
    }
    // Une base déjà remplie est copiée telle quelle avant d'être transformée : si la migration
    // tournait mal, la version d'avant reste à côté, intacte.
    if ((int) $db->query("SELECT COUNT(*) FROM sqlite_master WHERE name = 'utilisateurs'")->fetchColumn() > 0) {
        $dossier = dossier_donnees() . '/sauvegardes';
        if (!is_dir($dossier)) {
            @mkdir($dossier, 0700, true);
        }
        $db->prepare('VACUUM INTO ?')->execute([$dossier . '/avant-migration-v' . VERSION_SCHEMA . '-' . date('Ymd-His') . '.sqlite']);
    }
    // Les tables de la première version, telles qu'elles existent déjà en ligne...
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
    // ... complétées colonne par colonne : une base vide et une base déjà remplie arrivent au même schéma.
    ajouter_colonne($db, 'utilisateurs', 'role', "TEXT NOT NULL DEFAULT 'technicien'");
    ajouter_colonne($db, 'utilisateurs', 'agence_id', 'INTEGER');
    ajouter_colonne($db, 'utilisateurs', 'actif', 'INTEGER NOT NULL DEFAULT 1');
    ajouter_colonne($db, 'utilisateurs', 'telephone', "TEXT NOT NULL DEFAULT ''");
    ajouter_colonne($db, 'utilisateurs', 'totp_secret', "TEXT NOT NULL DEFAULT ''");
    ajouter_colonne($db, 'utilisateurs', 'totp_actif', 'INTEGER NOT NULL DEFAULT 0');
    ajouter_colonne($db, 'utilisateurs', 'totp_dernier', 'INTEGER NOT NULL DEFAULT 0');
    ajouter_colonne($db, 'utilisateurs', 'derniere_connexion', 'TEXT');
    ajouter_colonne($db, 'sessions', 'totp_ok', 'INTEGER NOT NULL DEFAULT 0');
    ajouter_colonne($db, 'sessions', 'cree_le', "TEXT NOT NULL DEFAULT ''");
    ajouter_colonne($db, 'clients', 'agence_id', 'INTEGER');
    ajouter_colonne($db, 'clients', 'technicien_id', 'INTEGER');
    ajouter_colonne($db, 'clients', 'jour_passage', 'INTEGER');
    ajouter_colonne($db, 'clients', 'ordre', 'INTEGER NOT NULL DEFAULT 0');
    ajouter_colonne($db, 'clients', 'formule', "TEXT NOT NULL DEFAULT ''");
    // La carte et l'équilibre des journées : où est le client, ce qu'il faut savoir pour entrer,
    // et combien de temps chaque visite a réellement pris.
    ajouter_colonne($db, 'clients', 'lat', 'REAL');
    ajouter_colonne($db, 'clients', 'lon', 'REAL');
    ajouter_colonne($db, 'clients', 'geo_score', 'REAL');
    ajouter_colonne($db, 'clients', 'acces', "TEXT NOT NULL DEFAULT ''");
    ajouter_colonne($db, 'visites', 'duree_min', 'INTEGER');
    ajouter_colonne($db, 'visites', 'charge', "TEXT NOT NULL DEFAULT ''");
    ajouter_colonne($db, 'visites', 'technicien_id', 'INTEGER');
    // Version 4 : les leads. Quand il est arrivé, quand il a été confié, quand on l'a rappelé, et par qui.
    ajouter_colonne($db, 'clients', 'lead_recu_le', 'TEXT');
    ajouter_colonne($db, 'clients', 'lead_attribue_le', 'TEXT');
    ajouter_colonne($db, 'clients', 'lead_contacte_le', 'TEXT');
    ajouter_colonne($db, 'clients', 'lead_contact_par', "TEXT NOT NULL DEFAULT ''");
    // Version 5 : depuis quand la fiche est dans son statut, et pourquoi (veille, désinscription, perte).
    ajouter_colonne($db, 'clients', 'statut_depuis', 'TEXT');
    ajouter_colonne($db, 'clients', 'statut_motif', "TEXT NOT NULL DEFAULT ''");
    $db->exec('UPDATE clients SET statut_depuis = COALESCE(statut_depuis, maj_le, cree_le)');
    ajouter_colonne($db, 'visites', 'alertes', 'INTEGER');
    ajouter_colonne($db, 'journal', 'role', "TEXT NOT NULL DEFAULT ''");
    ajouter_colonne($db, 'journal', 'agence_id', 'INTEGER');
    $db->exec("
        CREATE TABLE IF NOT EXISTS agences (id INTEGER PRIMARY KEY, nom TEXT UNIQUE NOT NULL, ville TEXT NOT NULL DEFAULT '', adresse TEXT NOT NULL DEFAULT '', lat REAL, lon REAL, actif INTEGER NOT NULL DEFAULT 1, cree_le TEXT NOT NULL);
        CREATE TABLE IF NOT EXISTS invitations (jeton TEXT PRIMARY KEY, genre TEXT NOT NULL, cible_id INTEGER NOT NULL, cree_par INTEGER, cree_le TEXT NOT NULL, expire INTEGER NOT NULL, utilisee_le TEXT);
        CREATE TABLE IF NOT EXISTS comptes_clients (id INTEGER PRIMARY KEY, client_id INTEGER UNIQUE NOT NULL REFERENCES clients(id) ON DELETE CASCADE, courriel TEXT UNIQUE NOT NULL, hash TEXT NOT NULL, actif INTEGER NOT NULL DEFAULT 1, cree_le TEXT NOT NULL, derniere_connexion TEXT);
        CREATE TABLE IF NOT EXISTS sessions_clients (jeton TEXT PRIMARY KEY, compte_id INTEGER NOT NULL REFERENCES comptes_clients(id) ON DELETE CASCADE, expire INTEGER NOT NULL);
        CREATE TABLE IF NOT EXISTS demandes (id INTEGER PRIMARY KEY, client_id INTEGER NOT NULL REFERENCES clients(id) ON DELETE CASCADE, texte TEXT NOT NULL, cree_le TEXT NOT NULL, traitee_le TEXT, traitee_par TEXT NOT NULL DEFAULT '', reponse TEXT NOT NULL DEFAULT '');
        CREATE TABLE IF NOT EXISTS sauvegardes (id INTEGER PRIMARY KEY, quand TEXT NOT NULL, fichier TEXT NOT NULL, octets INTEGER NOT NULL DEFAULT 0, statut TEXT NOT NULL, origine TEXT NOT NULL DEFAULT '', detail TEXT NOT NULL DEFAULT '');
        CREATE INDEX IF NOT EXISTS i_clients_portee ON clients(agence_id, technicien_id, jour_passage);
        CREATE INDEX IF NOT EXISTS i_visites_client ON visites(client_id, quand);
        CREATE INDEX IF NOT EXISTS i_visites_quand ON visites(quand);
        CREATE INDEX IF NOT EXISTS i_photos_visite ON photos(visite_id);
        CREATE INDEX IF NOT EXISTS i_tentatives ON tentatives(empreinte, quand);
        CREATE INDEX IF NOT EXISTS i_demandes ON demandes(client_id, traitee_le);
        CREATE INDEX IF NOT EXISTS i_journal ON journal(quand);
    ");
    // Version 3 : le carnet de la maison. Ce qui a été fait, ce qui arrive, et la preuve.
    $db->exec("
        CREATE TABLE IF NOT EXISTS carnet_postes (id INTEGER PRIMARY KEY, client_id INTEGER NOT NULL REFERENCES clients(id) ON DELETE CASCADE, cle TEXT NOT NULL, etat TEXT NOT NULL DEFAULT '', detail TEXT NOT NULL DEFAULT '', maj_le TEXT NOT NULL, maj_par TEXT NOT NULL DEFAULT '', UNIQUE(client_id, cle));
        CREATE TABLE IF NOT EXISTS carnet_evenements (id INTEGER PRIMARY KEY, client_id INTEGER NOT NULL REFERENCES clients(id) ON DELETE CASCADE, quand TEXT, echeance TEXT NOT NULL DEFAULT '', titre TEXT NOT NULL, detail TEXT NOT NULL DEFAULT '', organise TEXT NOT NULL DEFAULT 'nous', statut TEXT NOT NULL DEFAULT 'prevu', cree_le TEXT NOT NULL);
        CREATE TABLE IF NOT EXISTS carnet_equipements (id INTEGER PRIMARY KEY, client_id INTEGER NOT NULL REFERENCES clients(id) ON DELETE CASCADE, nom TEXT NOT NULL, detail TEXT NOT NULL DEFAULT '', maj_le TEXT NOT NULL);
        CREATE TABLE IF NOT EXISTS carnet_documents (id INTEGER PRIMARY KEY, client_id INTEGER NOT NULL REFERENCES clients(id) ON DELETE CASCADE, titre TEXT NOT NULL, fichier TEXT NOT NULL, type TEXT NOT NULL, octets INTEGER NOT NULL DEFAULT 0, cree_le TEXT NOT NULL, cree_par TEXT NOT NULL DEFAULT '');
        CREATE INDEX IF NOT EXISTS i_evenements ON carnet_evenements(client_id, quand);
        CREATE TABLE IF NOT EXISTS secteurs (id INTEGER PRIMARY KEY, commune TEXT UNIQUE NOT NULL, libelle TEXT NOT NULL, agence_id INTEGER NOT NULL REFERENCES agences(id), technicien_id INTEGER, maj_le TEXT NOT NULL);
        CREATE INDEX IF NOT EXISTS i_leads ON clients(lead_contacte_le, lead_attribue_le);
    ");
    $version = (int) $db->query('PRAGMA user_version')->fetchColumn();
    $db->beginTransaction();
    // Une base de la première version a un seul compte, le gérant : il devient la direction,
    // et ses clients rejoignent une première agence. Rien n'est perdu ni recopié à la main.
    $nb = (int) $db->query('SELECT (SELECT COUNT(*) FROM utilisateurs) + (SELECT COUNT(*) FROM clients)')->fetchColumn();
    if ($version < 2 && $nb > 0) {
        if (!(int) $db->query('SELECT COUNT(*) FROM agences')->fetchColumn()) {
            $db->prepare('INSERT INTO agences (nom, ville, cree_le) VALUES (?,?,?)')->execute(['Nîmes', 'Nîmes', maintenant()]);
        }
        $agence = (int) $db->query('SELECT MIN(id) FROM agences')->fetchColumn();
        $db->exec("UPDATE utilisateurs SET role = 'superadmin', agence_id = NULL WHERE id = (SELECT MIN(id) FROM utilisateurs)");
        $db->prepare('UPDATE clients SET agence_id = ? WHERE agence_id IS NULL')->execute([$agence]);
    }
    $db->exec('PRAGMA user_version = ' . VERSION_SCHEMA);
    $db->commit();
}

function tracer($qui, string $action, string $detail = ''): void
{
    // $qui est un compte (tableau) ou un libellé : chaque trace dit qui, avec quel rôle, dans quelle agence.
    $nom = is_array($qui) ? (string) ($qui['courriel'] ?? 'inconnu') : (string) $qui;
    $role = is_array($qui) ? (string) ($qui['role'] ?? '') : '';
    $agence = is_array($qui) && isset($qui['agence_id']) ? (int) $qui['agence_id'] : null;
    base()->prepare('INSERT INTO journal (quand, qui, action, detail, role, agence_id) VALUES (?,?,?,?,?,?)')
        ->execute([maintenant(), mb_substr($nom, 0, 120), $action, mb_substr($detail, 0, 500), $role, $agence]);
}

function compter_alertes(array $contenu): int
{
    $v = is_array($contenu['valeurs'] ?? null) ? $contenu['valeurs'] : [];
    $n = 0;
    foreach (PLAGES as $cle => [$vert, $orange]) {
        if (isset($v[$cle]) && is_numeric($v[$cle])) {
            $x = (float) $v[$cle];
            if ($x < $vert[0] || $x > $vert[1]) {
                $n++;
            }
        }
    }
    if (isset($v['chlore_libre'], $v['chlore_total']) && is_numeric($v['chlore_libre']) && is_numeric($v['chlore_total'])
        && (float) $v['chlore_total'] - (float) $v['chlore_libre'] > 0.6) {
        $n++;
    }
    foreach ((is_array($contenu['etats'] ?? null) ? $contenu['etats'] : []) as $e) {
        if (is_array($e) && in_array($e['etat'] ?? '', ['surveiller', 'prevoir'], true)) {
            $n++;
        }
    }
    return $n;
}

function cellule_csv($v): string
{
    // Une cellule qui commence par = + - @ deviendrait une formule dans un tableur.
    $v = (string) $v;
    return preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
}

function envoyer_csv(string $nom, array $entete, array $lignes): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $nom . '"');
    header('Cache-Control: no-store');
    $f = fopen('php://output', 'w');
    fwrite($f, "\xEF\xBB\xBF");   // Excel lit alors les accents correctement
    fputcsv($f, $entete, ';', '"', '\\');
    foreach ($lignes as $l) {
        fputcsv($f, array_map('cellule_csv', $l), ';', '"', '\\');
    }
    fclose($f);
    exit;
}
