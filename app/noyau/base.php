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

const VERSION_SCHEMA = 14;
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
// Comment un client est arrivé. Le voisin et le parrain coûtent presque rien et raccourcissent les tournées :
// c'est leur part que le pilotage surveille.
const VENUS_PAR = ['site' => 'Le site', 'voisin' => 'Un voisin client', 'parrainage' => 'Parrainage', 'porte' => 'Porte-à-porte',
    'apporteur' => 'Un apporteur', 'reseau' => 'Réseau personnel', 'autre' => 'Autre'];
// Qui voit plusieurs clients, ou toutes les agences, ne se connecte jamais sans second facteur.
const ROLES_DOUBLE_FACTEUR = ['superadmin', 'chef'];
// Un technicien ne se reconnecte pas à chaque passage ; un compte qui voit tout, si.
const DUREES_SESSION = ['technicien' => 2592000, 'chef' => 604800, 'superadmin' => 604800];
const DUREE_SESSION_CLIENT = 7776000;
const DUREE_INVITATION = 604800;
// Sans historique, une visite est estimée à ces durées ; dès trois visites mesurées, la médiane réelle prend le relais.
const DUREE_DEFAUT = ['passage' => 30, 'diagnostic' => 60];
// Avant trois passages mesurés, la durée se devine selon la formule : une maison fermée seule se visite plus vite
// qu'une piscine, une maison avec piscine plus lentement. Dès que l'horloge a parlé, elle a raison.
// Les formules, une seule fois : la société qui les porte, leur engagement, leur prix par défaut (modifiable dans la grille
// de l'écran Société), et ce qu'elles pèsent dans une tournée. Le libellé est celui de la fiche client.
// Deux sociétés sous la même marque : la piscine n'ouvre droit à aucun crédit d'impôt, la Maison relève des services à la
// personne, qui exigent une activité exclusive. Une piscine ne se facture jamais par la société Maison.
const FORMULES = [
    'Piscine' => ['cle' => 'piscine', 'societe' => 'piscine', 'engagement' => 12, 'piscine' => true, 'prix' => 179, 'fondateur' => 149, 'duree' => 30, 'part' => 1],
    'Piscine équipée, quinzaine hors été' => ['cle' => 'quinzaine', 'societe' => 'piscine', 'engagement' => 12, 'piscine' => true, 'prix' => 149, 'fondateur' => 125, 'duree' => 30, 'part' => 1],
    'Maison' => ['cle' => 'maison', 'societe' => 'maison', 'engagement' => 0, 'piscine' => false, 'prix' => 45, 'fondateur' => 45, 'duree' => 60, 'part' => 0.25],
    'Maison renforcée' => ['cle' => 'maison_renforcee', 'societe' => 'maison', 'engagement' => 0, 'piscine' => false, 'prix' => 100, 'fondateur' => 100, 'duree' => 120, 'part' => 0.25],
    'Veille de résidence' => ['cle' => 'veille', 'societe' => 'maison', 'engagement' => 0, 'piscine' => false, 'prix' => 49, 'fondateur' => 49, 'duree' => 20, 'part' => 0.25],
    // Le pack : deux contrats, deux sociétés ; la remise porte sur la ligne piscine, jamais sur la Maison éligible au crédit d'impôt.
    'Piscine + Maison' => ['cle' => 'pack', 'pack' => ['Piscine', 'Maison'], 'duree' => 45, 'part' => 1],
];
const REMISE_PACK = 12;
// L'équilibre d'une journée, en minutes, trajets compris : ni casser les gens, ni payer des journées creuses.
const JOURNEE_CIBLE_MIN = 420;   // sept heures
const JOURNEE_MAX_MIN = 450;     // sept heures et demie, jamais au-delà
const JOURNEE_CREUSE_MIN = 300;  // moins de cinq heures : une journée à regrouper ou à remplir
// Les règles de Google interdisent de ne solliciter que les clients contents : tout client reçoit la demande, une fois,
// au même moment, quoi que disent ses rapports. Ni contrepartie, ni objectif chiffré pour l'équipe.
const AVIS_APRES_PASSAGES = 4;
const PISCINES_JOUR_MAX = 10;    // même dans le temps, au-delà c'est trop physique
// Ce qu'on vérifie avant de confier une maison à un remplaçant. Tant qu'une case manque, il n'entre chez personne.
const VIVIER_CONTROLES = ['entretien' => 'Entretien en personne', 'casier' => 'Extrait de casier judiciaire vu', 'permis' => 'Permis B vu',
    'essai' => 'Journée d\'essai en binôme', 'securite' => 'Sécurité des produits expliquée', 'cles' => 'Règles des clés signées'];
const MISSION_JOURS_MAX = 92;     // un accès temporaire ne dure jamais plus d'une saison
// Au-delà, l'écart entre deux rapports contient une pause : il ne compte pas dans les moyennes.
const ECART_PAUSE_MIN = 90;
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
    // Version 6 : le visage et le numéro du technicien, que le client voit. C'est rassurant, et c'est la marque.
    ajouter_colonne($db, 'utilisateurs', 'photo', "TEXT NOT NULL DEFAULT ''");
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
    // Version 7 : le pilotage. D'où vient un client, quand un membre est parti, et les chiffres du mois
    // que l'outil ne connaît pas (trésorerie, marge, note Google, ressenti de l'équipe). agence_id = 0 : toute l'entreprise.
    ajouter_colonne($db, 'clients', 'venu_par', "TEXT NOT NULL DEFAULT ''");
    ajouter_colonne($db, 'utilisateurs', 'parti_le', 'TEXT');
    $db->exec("
        CREATE TABLE IF NOT EXISTS pilotage_saisies (id INTEGER PRIMARY KEY, mois TEXT NOT NULL, agence_id INTEGER NOT NULL DEFAULT 0, cle TEXT NOT NULL,
            valeur REAL NOT NULL, saisi_par TEXT NOT NULL DEFAULT '', saisi_le TEXT NOT NULL, UNIQUE(mois, agence_id, cle));
    ");
    // Version 8 : les absences, les remplacements d'un jour, le vivier de remplaçants et leurs accès qui s'éteignent seuls.
    ajouter_colonne($db, 'utilisateurs', 'expire_le', 'TEXT');
    ajouter_colonne($db, 'utilisateurs', 'vivier_id', 'INTEGER');
    $db->exec("
        CREATE TABLE IF NOT EXISTS absences (id INTEGER PRIMARY KEY, utilisateur_id INTEGER NOT NULL REFERENCES utilisateurs(id), du TEXT NOT NULL, au TEXT NOT NULL,
            motif TEXT NOT NULL DEFAULT '', cree_par TEXT NOT NULL DEFAULT '', cree_le TEXT NOT NULL);
        CREATE INDEX IF NOT EXISTS i_absences ON absences(utilisateur_id, du, au);
        CREATE TABLE IF NOT EXISTS remplacements (id INTEGER PRIMARY KEY, date TEXT NOT NULL, client_id INTEGER NOT NULL REFERENCES clients(id) ON DELETE CASCADE,
            technicien_id INTEGER NOT NULL REFERENCES utilisateurs(id), cree_par TEXT NOT NULL DEFAULT '', cree_le TEXT NOT NULL, UNIQUE(date, client_id));
        CREATE INDEX IF NOT EXISTS i_remplacements ON remplacements(technicien_id, date);
        CREATE TABLE IF NOT EXISTS vivier (id INTEGER PRIMARY KEY, agence_id INTEGER NOT NULL REFERENCES agences(id), prenom TEXT NOT NULL DEFAULT '', nom TEXT NOT NULL DEFAULT '',
            telephone TEXT NOT NULL DEFAULT '', courriel TEXT NOT NULL DEFAULT '', source TEXT NOT NULL DEFAULT '', disponibilites TEXT NOT NULL DEFAULT '', notes TEXT NOT NULL DEFAULT '',
            entretien INTEGER NOT NULL DEFAULT 0, casier INTEGER NOT NULL DEFAULT 0, permis INTEGER NOT NULL DEFAULT 0, essai INTEGER NOT NULL DEFAULT 0,
            securite INTEGER NOT NULL DEFAULT 0, cles INTEGER NOT NULL DEFAULT 0, ecarte INTEGER NOT NULL DEFAULT 0, utilisateur_id INTEGER, cree_le TEXT NOT NULL, maj_le TEXT NOT NULL);
    ");
    // Version 9 : la fiche du technicien (date d'entrée, contact d'urgence, tailles de tenue), ses habilitations et formations
    // avec leur échéance, et ses demandes de congés, qui deviennent des absences une fois acceptées.
    foreach (['entree_le' => 'TEXT', 'urgence_nom' => "TEXT NOT NULL DEFAULT ''", 'urgence_tel' => "TEXT NOT NULL DEFAULT ''",
        'taille_haut' => "TEXT NOT NULL DEFAULT ''", 'taille_bas' => "TEXT NOT NULL DEFAULT ''", 'pointure' => "TEXT NOT NULL DEFAULT ''"] as $c => $def) {
        ajouter_colonne($db, 'utilisateurs', $c, $def);
    }
    $db->exec("
        CREATE TABLE IF NOT EXISTS qualifications (id INTEGER PRIMARY KEY, utilisateur_id INTEGER NOT NULL REFERENCES utilisateurs(id), type TEXT NOT NULL,
            intitule TEXT NOT NULL DEFAULT '', obtenue_le TEXT, expire_le TEXT, note TEXT NOT NULL DEFAULT '', cree_par TEXT NOT NULL DEFAULT '', maj_le TEXT NOT NULL);
        CREATE INDEX IF NOT EXISTS i_qualifications ON qualifications(utilisateur_id, expire_le);
        CREATE TABLE IF NOT EXISTS demandes_conges (id INTEGER PRIMARY KEY, utilisateur_id INTEGER NOT NULL REFERENCES utilisateurs(id), du TEXT NOT NULL, au TEXT NOT NULL,
            motif TEXT NOT NULL DEFAULT 'Congés', message TEXT NOT NULL DEFAULT '', statut TEXT NOT NULL DEFAULT 'attente', reponse TEXT NOT NULL DEFAULT '',
            decide_par TEXT NOT NULL DEFAULT '', decide_le TEXT, absence_id INTEGER, cree_le TEXT NOT NULL);
        CREATE INDEX IF NOT EXISTS i_conges ON demandes_conges(statut, utilisateur_id);
    ");
    // Version 10 : les visites se cherchent par technicien et par heure (la journée mesurée, le temps réel d'un client).
    // Sans cet index, mesurer une journée relisait toute la table : 6 secondes pour la Semaine à 5 000 clients.
    $db->exec('CREATE INDEX IF NOT EXISTS i_visites_tech ON visites(technicien_id, quand)');
    // Version 11 : chaque sauvegarde dit si sa copie chiffrée est bien arrivée hors de l'hébergeur.
    ajouter_colonne($db, 'sauvegardes', 'externe_le', 'TEXT');
    ajouter_colonne($db, 'sauvegardes', 'externe_statut', "TEXT NOT NULL DEFAULT ''");
    ajouter_colonne($db, 'sauvegardes', 'externe_detail', "TEXT NOT NULL DEFAULT ''");
    // Et chaque responsable choisit de recevoir, ou non, le relevé du lundi.
    ajouter_colonne($db, 'utilisateurs', 'releve_lundi', 'INTEGER NOT NULL DEFAULT 1');
    // L'avis Google : le lien de chaque agence, et pour chaque client si la demande est partie (une fois, jamais deux).
    ajouter_colonne($db, 'agences', 'lien_avis', "TEXT NOT NULL DEFAULT ''");
    ajouter_colonne($db, 'clients', 'avis_statut', "TEXT NOT NULL DEFAULT ''");
    ajouter_colonne($db, 'clients', 'avis_le', 'TEXT');
    // Les contrats : l'identité de la société (paramètres lisibles, pas des secrets), et chaque contrat avec sa preuve de signature.
    $db->exec("
        CREATE TABLE IF NOT EXISTS parametres (cle TEXT PRIMARY KEY, valeur TEXT NOT NULL DEFAULT '', maj_le TEXT NOT NULL, maj_par TEXT NOT NULL DEFAULT '');
        CREATE TABLE IF NOT EXISTS contrats (id INTEGER PRIMARY KEY, client_id INTEGER NOT NULL REFERENCES clients(id), numero TEXT UNIQUE NOT NULL, formule TEXT NOT NULL,
            prix_mensuel REAL NOT NULL, debut TEXT NOT NULL, engagement_mois INTEGER NOT NULL DEFAULT 0, fondateur INTEGER NOT NULL DEFAULT 0, prix_bloque_jusquau TEXT,
            statut TEXT NOT NULL DEFAULT 'a_signer', fichier TEXT NOT NULL, empreinte TEXT NOT NULL, modele TEXT NOT NULL DEFAULT '', cree_le TEXT NOT NULL, cree_par TEXT NOT NULL DEFAULT '',
            signe_le TEXT, signe_ip TEXT, signe_agent TEXT, signe_nom TEXT, signe_compte TEXT, execution_anticipee INTEGER NOT NULL DEFAULT 0,
            resilie_le TEXT, resiliation_effet TEXT, resiliation_motif TEXT, rappel_reconduction_le TEXT);
        CREATE INDEX IF NOT EXISTS i_contrats_client ON contrats(client_id, statut);
        CREATE TABLE IF NOT EXISTS mandats (id INTEGER PRIMARY KEY, client_id INTEGER NOT NULL REFERENCES clients(id), demande TEXT, reference TEXT,
            statut TEXT NOT NULL DEFAULT 'en_attente', cree_le TEXT NOT NULL, maj_le TEXT NOT NULL);
        CREATE INDEX IF NOT EXISTS i_mandats_client ON mandats(client_id, statut);
        CREATE TABLE IF NOT EXISTS factures (id INTEGER PRIMARY KEY, client_id INTEGER NOT NULL REFERENCES clients(id), contrat_id INTEGER NOT NULL REFERENCES contrats(id),
            numero TEXT UNIQUE NOT NULL, periode TEXT NOT NULL, montant INTEGER NOT NULL, emise_le TEXT NOT NULL, fichier TEXT NOT NULL, empreinte TEXT NOT NULL,
            statut TEXT NOT NULL DEFAULT 'emise', cree_par TEXT NOT NULL DEFAULT '', paiement TEXT, prelevement_le TEXT, paye_le TEXT, rejet_motif TEXT NOT NULL DEFAULT '',
            UNIQUE(contrat_id, periode));
    ");
    // Version 12 : le prix d'un contrat se compose (formule, taille du bassin, devis) et se paie au mois ou à l'année.
    foreach (['prix_base' => 'REAL', 'surface_m2' => 'REAL', 'debordement' => 'INTEGER NOT NULL DEFAULT 0', 'majoration_pct' => 'REAL NOT NULL DEFAULT 0',
        'sur_devis' => 'INTEGER NOT NULL DEFAULT 0', 'modalite' => "TEXT NOT NULL DEFAULT 'mensuel'", 'mois_payants' => 'INTEGER', 'montant_annuel' => 'REAL',
        'indice_travail_ref' => 'TEXT', 'indice_travail_periode' => 'TEXT', 'indice_produits_ref' => 'TEXT', 'indice_produits_periode' => 'TEXT'] as $col => $def) {
        ajouter_colonne($db, 'contrats', $col, $def);
    }
    // Version 13 : les nouvelles formules et les deux sociétés. Les anciens libellés sont renommés ; l'identité saisie
    // jusqu'ici devient celle de la société Piscine, et la relecture et les indices deviennent communs aux deux.
    ajouter_colonne($db, 'contrats', 'societe', "TEXT NOT NULL DEFAULT 'piscine'");
    ajouter_colonne($db, 'contrats', 'lien_contrat', 'INTEGER');
    ajouter_colonne($db, 'contrats', 'prix_hors_pack', 'REAL');
    ajouter_colonne($db, 'contrats', 'remise_pack', 'REAL NOT NULL DEFAULT 0');
    ajouter_colonne($db, 'mandats', 'societe', "TEXT NOT NULL DEFAULT 'piscine'");
    ajouter_colonne($db, 'factures', 'societe', "TEXT NOT NULL DEFAULT 'piscine'");
    $db->exec("UPDATE clients SET formule = 'Veille de résidence' WHERE formule = 'Maison fermée'");
    $db->exec("UPDATE clients SET formule = 'Piscine + Maison' WHERE formule = 'Piscine + Maison fermée'");
    foreach (['raison_sociale', 'forme', 'siret', 'rcs', 'adresse', 'courriel', 'telephone', 'mention_tva', 'mediateur_nom', 'mediateur_site', 'assureur', 'police'] as $k) {
        $db->prepare("INSERT OR IGNORE INTO parametres (cle, valeur, maj_le, maj_par) SELECT 'societe.piscine.' || ?, valeur, maj_le, maj_par FROM parametres WHERE cle = 'societe.' || ?")->execute([$k, $k]);
    }
    foreach (['juriste_nom', 'juriste_le', 'indice_travail_nom', 'indice_travail_valeur', 'indice_travail_periode', 'indice_produits_nom', 'indice_produits_valeur', 'indice_produits_periode'] as $k) {
        $db->prepare("INSERT OR IGNORE INTO parametres (cle, valeur, maj_le, maj_par) SELECT 'contrats.' || ?, valeur, maj_le, maj_par FROM parametres WHERE cle = 'societe.' || ?")->execute([$k, $k]);
    }
    // Version 14 : la corbeille, où va ce qui est effacé ou remplacé dans la fiche et le carnet d'un client.
    $db->exec("CREATE TABLE IF NOT EXISTS corbeille (id INTEGER PRIMARY KEY, quand TEXT NOT NULL, qui TEXT NOT NULL, role TEXT NOT NULL DEFAULT '', agence_id INTEGER,
        client_id INTEGER NOT NULL, quoi TEXT NOT NULL, ligne_id INTEGER NOT NULL, nature TEXT NOT NULL, libelle TEXT NOT NULL DEFAULT '', contenu TEXT NOT NULL,
        champs TEXT NOT NULL DEFAULT '[]', fichier TEXT, remis_le TEXT, remis_par TEXT);
        CREATE INDEX IF NOT EXISTS i_corbeille_client ON corbeille (client_id, id)");
    $db->exec("INSERT OR IGNORE INTO parametres (cle, valeur, maj_le, maj_par) SELECT 'facturation.piscine.taux_tva', valeur, maj_le, maj_par FROM parametres WHERE cle = 'facturation.taux_tva'");
    // L'accès GoCardless posé avant la v13 était celui de la seule société : il devient celui de la société Piscine.
    $ancien = dossier_donnees() . '/paiement-gocardless.json';
    if (is_file($ancien) && !is_file(dossier_donnees() . '/paiement-gocardless-piscine.json')) {
        @rename($ancien, dossier_donnees() . '/paiement-gocardless-piscine.json');
    }
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
        ->execute([maintenant(), mb_substr($nom, 0, 120), $action, mb_substr($detail, 0, 1500), $role, $agence]);
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
