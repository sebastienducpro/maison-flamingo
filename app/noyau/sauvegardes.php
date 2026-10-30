<?php
// Maison Flamingo · les sauvegardes de la base.
//
// Une copie complète chaque jour, relue avant d'être déclarée bonne : une
// sauvegarde qu'on n'a jamais rouverte n'est pas une sauvegarde. On garde les
// trente dernières, plus la première de chaque mois pendant un an.
// Les photos ne sont pas dans ces copies : elles sont dans les sauvegardes
// quotidiennes de l'hébergement, et peuvent se retélécharger depuis leur dossier.

declare(strict_types=1);

const SAUVEGARDES_JOURS = 30;
const SAUVEGARDES_MOIS = 12;
const SAUVEGARDE_AGE_MAX = 129600;   // 36 h : au-delà, le tableau de bord passe au rouge

function dossier_sauvegardes(): string
{
    $d = dossier_donnees() . '/sauvegardes';
    if (!is_dir($d)) {
        @mkdir($d, 0700, true);
    }
    return $d;
}

function faire_sauvegarde(string $origine): array
{
    $db = base();
    $nom = 'flamingo-' . date('Ymd-His') . '.sqlite';
    // Deux copies dans la même seconde (une copie à la main, puis celle qui précède une restauration) : la seconde prend un suffixe.
    for ($i = 2; is_file(dossier_sauvegardes() . '/' . $nom); $i++) {
        $nom = 'flamingo-' . date('Ymd-His') . '-' . $i . '.sqlite';
    }
    $chemin = dossier_sauvegardes() . '/' . $nom;
    try {
        // VACUUM INTO écrit une copie cohérente sans arrêter les écritures en cours.
        $db->prepare('VACUUM INTO ?')->execute([$chemin]);
        $copie = new PDO('sqlite:' . $chemin);
        $copie->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $verdict = (string) $copie->query('PRAGMA integrity_check')->fetchColumn();
        $nb = (int) $copie->query('SELECT COUNT(*) FROM clients')->fetchColumn();
        $copie = null;
        if ($verdict !== 'ok') {
            throw new RuntimeException('copie illisible : ' . $verdict);
        }
        $octets = (int) filesize($chemin);
        $db->prepare('INSERT INTO sauvegardes (quand, fichier, octets, statut, origine, detail) VALUES (?,?,?,?,?,?)')
            ->execute([maintenant(), $nom, $octets, 'ok', $origine, "$nb clients, copie relue"]);
        tracer('systeme', 'sauvegarde', "$nom, $octets octets, $origine");
        elaguer_sauvegardes();
        return ['ok' => true, 'fichier' => $nom, 'octets' => $octets];
    } catch (Throwable $e) {
        @unlink($chemin);
        $db->prepare('INSERT INTO sauvegardes (quand, fichier, octets, statut, origine, detail) VALUES (?,?,?,?,?,?)')
            ->execute([maintenant(), $nom, 0, 'echec', $origine, mb_substr($e->getMessage(), 0, 300)]);
        tracer('systeme', 'sauvegarde échouée', $e->getMessage());
        return ['ok' => false, 'erreur' => 'sauvegarde'];
    }
}

function elaguer_sauvegardes(): void
{
    $fichiers = glob(dossier_sauvegardes() . '/flamingo-*.sqlite') ?: [];
    rsort($fichiers);
    $garder = array_slice($fichiers, 0, SAUVEGARDES_JOURS);
    $mois = [];
    foreach ($fichiers as $f) {
        $m = substr(basename($f), 9, 6);
        $mois[$m] = $f;   // trié du plus récent au plus ancien : on garde le plus ancien du mois
    }
    $garder = array_merge($garder, array_slice(array_values($mois), 0, SAUVEGARDES_MOIS));
    foreach ($fichiers as $f) {
        if (!in_array($f, $garder, true)) {
            @unlink($f);
        }
    }
}

function derniere_sauvegarde(): ?array
{
    $r = base()->query("SELECT * FROM sauvegardes WHERE statut = 'ok' ORDER BY id DESC LIMIT 1")->fetch();
    return $r ?: null;
}

// Filet si la tâche planifiée n'est pas posée ou tombe : la première requête de
// l'équipe après 24 h sans copie en fait une. Une base de quelques mégaoctets se copie en une fraction de seconde.
function sauvegarde_si_due(): void
{
    $d = derniere_sauvegarde();
    if (!$d || strtotime($d['quand']) < time() - 86400) {
        faire_sauvegarde('rattrapage');
    }
}

/* ---------- Revenir à une sauvegarde ---------- */

// Ce qu'une restauration remet à la date de la copie : le travail de l'équipe sur les clients. Une ligne qui existait
// dans la copie revient telle qu'elle était (modifiée ou effacée depuis) ; une ligne créée depuis reste. On ne perd ni
// le lead arrivé du site ce matin, ni le rapport envoyé hier : on défait les dégâts, pas le travail.
const TABLES_RESTAUREES = ['clients', 'visites', 'photos', 'carnet_postes', 'carnet_evenements', 'carnet_equipements', 'carnet_documents', 'demandes',
    'absences', 'remplacements', 'demandes_conges', 'qualifications', 'vivier', 'secteurs', 'pilotage_saisies'];
// Ce qu'elle ne touche jamais, avec la raison :
// - journal, corbeille : la trace de ce qui s'est passé ne remonte pas le temps, sinon elle effacerait ce qu'on cherche ;
// - contrats, factures, mandats : des documents signés ou émis, numérotés, déjà partis chez le client ou chez GoCardless ;
//   revenir en arrière réutiliserait des numéros et écraserait des fichiers ;
// - utilisateurs, invitations, sessions : une restauration ne rend pas l'accès à quelqu'un dont on l'a retiré depuis ;
// - parametres, agences : réglés par la direction seule, personne d'autre n'a pu les abîmer ;
// - comptes_clients : on ne revient pas sur le mot de passe qu'un client a changé ; un compte effacé depuis revient.
// Une table qui n'est dans aucune des deux listes n'est pas touchée : une table ajoutée plus tard ne se restaure pas par mégarde.
// Une ligne effacée peut libérer son numéro pour la suivante : la colonne de création dit si c'est la même ligne.
// Deux lignes différentes sous un même numéro, et la restauration refuse plutôt que de mélanger deux clients.
const IDENTITE_RESTAURATION = ['clients' => 'cree_le', 'visites' => 'identifiant_local', 'photos' => 'fichier', 'carnet_evenements' => 'cree_le', 'carnet_documents' => 'cree_le',
    'demandes' => 'cree_le', 'absences' => 'cree_le', 'remplacements' => 'cree_le', 'demandes_conges' => 'cree_le', 'vivier' => 'cree_le'];
// Les autres clés uniques : la version de la copie l'emporte sur une ligne recréée depuis pour la même clé.
const UNIQUES_RESTAURATION = ['carnet_postes' => ['client_id', 'cle'], 'remplacements' => ['date', 'client_id'], 'secteurs' => ['commune'], 'pilotage_saisies' => ['mois', 'agence_id', 'cle']];
const TABLES_GARDEES = ['journal', 'corbeille', 'contrats', 'factures', 'mandats', 'utilisateurs', 'invitations', 'sessions', 'sessions_clients', 'tentatives',
    'sauvegardes', 'parametres', 'agences', 'comptes_clients'];

function a_sauvegarde_restaurer(): void
{
    $u = exiger_equipe(['superadmin']);
    exiger_post();
    $d = entree();
    $motif = texte($d['motif'] ?? '', 300);
    // Un geste qui remet toute l'équipe à une autre date se confirme en toutes lettres, avec sa raison.
    if (($d['confirmation'] ?? '') !== 'RESTAURER' || $motif === '') {
        sortir(200, ['ok' => false, 'erreur' => 'confirmation']);
    }
    $q = base()->prepare("SELECT * FROM sauvegardes WHERE id = ? AND statut = 'ok'");
    $q->execute([entier($d['id'] ?? 0)]);
    $s = $q->fetch();
    $q->closeCursor();   // une lecture restée ouverte empêche la copie (VACUUM) qui précède la restauration
    if (!$s || !is_file(dossier_sauvegardes() . '/' . basename($s['fichier']))) {
        sortir(404, ['ok' => false, 'erreur' => 'introuvable']);
    }
    $r = restaurer_sauvegarde($s, $u, $motif);
    sortir(200, $r);
}

function restaurer_sauvegarde(array $s, array $u, string $motif): array
{
    $source = dossier_sauvegardes() . '/' . basename($s['fichier']);
    // On travaille sur une copie de la sauvegarde : la sauvegarde elle-même reste intacte, quoi qu'il arrive.
    $tmp = dossier_sauvegardes() . '/restauration-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.sqlite';
    if (!@copy($source, $tmp)) {
        return ['ok' => false, 'erreur' => 'copie'];
    }
    try {
        $b = new PDO('sqlite:' . $tmp);
        $b->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        if ((string) $b->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
            throw new RuntimeException('sauvegarde illisible');
        }
        $v = (int) $b->query('PRAGMA user_version')->fetchColumn();
        if ($v > VERSION_SCHEMA) {
            throw new RuntimeException('sauvegarde d\'une version plus récente que l\'outil');
        }
        // Une copie d'avant une mise à jour de l'outil est d'abord mise à jour, comme la base l'a été.
        if ($v < VERSION_SCHEMA) {
            migrer($b);
        }
        $b = null;
    } catch (Throwable $e) {
        @unlink($tmp);
        tracer($u, 'restauration refusée', basename($s['fichier']) . ' : ' . $e->getMessage());
        return ['ok' => false, 'erreur' => 'sauvegarde', 'detail' => $e->getMessage()];
    }
    // L'état d'aujourd'hui est copié avant d'être remplacé : rien de ce qu'il contenait n'est perdu, il reste téléchargeable.
    $avant = faire_sauvegarde('avant restauration');
    if (!$avant['ok']) {
        @unlink($tmp);
        return ['ok' => false, 'erreur' => 'copie_avant'];
    }
    $db = base();
    $db->exec('PRAGMA foreign_keys = OFF');   // sinon effacer un client effacerait en cascade ses contrats, qu'on garde
    $db->prepare('ATTACH DATABASE ? AS b')->execute([$tmp]);
    $compte = [];
    try {
        $db->exec('BEGIN IMMEDIATE');
        $dans = fn (string $schema, string $t) => (int) $db->query("SELECT COUNT(*) FROM $schema.sqlite_master WHERE type = 'table' AND name = " . $db->quote($t))->fetchColumn() > 0;
        $colonnes = fn (string $schema, string $t) => array_column($db->query("PRAGMA $schema.table_info($t)")->fetchAll(), 'name');
        foreach (TABLES_RESTAUREES as $t) {
            if (!$dans('main', $t) || !$dans('b', $t)) {
                continue;
            }
            if (isset(IDENTITE_RESTAURATION[$t])) {
                $k = IDENTITE_RESTAURATION[$t];
                $n = (int) $db->query("SELECT COUNT(*) FROM main.$t m JOIN b.$t x ON x.id = m.id WHERE m.$k IS NOT x.$k")->fetchColumn();
                if ($n > 0) {
                    throw new RuntimeException("$n ligne(s) de $t réutilisent un numéro d'une ligne de la copie");
                }
            }
            $cols = implode(', ', array_intersect($colonnes('main', $t), $colonnes('b', $t)));
            $avant_n = (int) $db->query("SELECT COUNT(*) FROM main.$t")->fetchColumn();
            $db->exec("DELETE FROM main.$t WHERE id IN (SELECT id FROM b.$t)");
            if (isset(UNIQUES_RESTAURATION[$t])) {
                $egal = implode(' AND ', array_map(fn ($c) => "x.$c IS main.$t.$c", UNIQUES_RESTAURATION[$t]));
                $db->exec("DELETE FROM main.$t WHERE EXISTS (SELECT 1 FROM b.$t x WHERE $egal)");
            }
            $db->exec("INSERT INTO main.$t ($cols) SELECT $cols FROM b.$t");
            $compte[$t] = (int) $db->query("SELECT COUNT(*) FROM main.$t")->fetchColumn() - $avant_n;
        }
        // Un compte client effacé depuis la copie revient ; ceux d'aujourd'hui ne bougent pas (un mot de passe changé reste changé).
        $cols = implode(', ', array_intersect($colonnes('main', 'comptes_clients'), $colonnes('b', 'comptes_clients')));
        $db->exec("INSERT OR IGNORE INTO main.comptes_clients ($cols) SELECT $cols FROM b.comptes_clients");
        $db->exec('COMMIT');
    } catch (Throwable $e) {
        try {
            $db->exec('ROLLBACK');
        } catch (Throwable $x) {
            // la transaction n'avait pas commencé (base occupée) : rien à défaire
        }
        $db->exec('DETACH DATABASE b');
        $db->exec('PRAGMA foreign_keys = ON');
        @unlink($tmp);
        tracer($u, 'restauration échouée', basename($s['fichier']) . ' : ' . $e->getMessage() . ' (rien n\'a changé)');
        return ['ok' => false, 'erreur' => 'restauration', 'detail' => $e->getMessage()];
    }
    $db->exec('DETACH DATABASE b');
    $db->exec('PRAGMA foreign_keys = ON');
    @unlink($tmp);
    tracer($u, 'base restaurée', 'depuis la copie du ' . date('d/m/Y à H:i', strtotime($s['quand'])) . ' (' . basename($s['fichier']) . ') ; état d\'avant gardé dans ' . $avant['fichier'] . ' ; motif : ' . $motif);
    return ['ok' => true, 'depuis' => $s['quand'], 'copie_avant' => $avant['fichier'], 'revenus' => array_filter($compte)];
}
