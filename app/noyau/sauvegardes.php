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
