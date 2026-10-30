<?php
// Maison Flamingo · réception de la liste d'attente.
//
// Aucun secret ici : le dépôt est public. L'envoi passe par la fonction mail()
// de l'hébergement, depuis la boîte du domaine vers elle-même, ce qui ne demande
// aucun mot de passe. Chaque inscription est aussi copiée dans un fichier hors
// du dossier public : si un courriel se perd, la personne, elle, ne se perd pas.

declare(strict_types=1);

const DESTINATAIRE = 'contact@maisonflamingo.fr';
const COMMUNES = ['Manduel', 'Bouillargues', 'Marguerittes', 'Caissargues', 'Rodilhan', 'Garons', 'Nîmes', 'Autre commune'];
const INTERETS = ['Piscine', 'Maison', 'Veille de résidence', 'Maison fermée'];
// Une page restée en cache envoie encore l'ancien nom de la veille : on le traduit au lieu de le perdre
const ANCIENS_INTERETS = ['Maison fermée' => 'Veille de résidence'];
const MAX_PAR_HEURE = 5;

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function repondre(int $code, array $corps): void
{
    http_response_code($code);
    echo json_encode($corps, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    repondre(405, ['ok' => false, 'erreur' => 'methode']);
}

$brut = (string) file_get_contents('php://input', false, null, 0, 8192);
$d = json_decode($brut, true);
if (!is_array($d)) {
    $d = $_POST;
}

// Les retours à la ligne sont retirés de tout ce qui vient du visiteur :
// sans eux, rien ne peut s'injecter dans les en-têtes du courriel.
function champ(array $d, string $cle, int $max): string
{
    $v = $d[$cle] ?? '';
    if (!is_string($v)) {
        return '';
    }
    $v = trim((string) preg_replace('/[\r\n\t\x00-\x1F]+/u', ' ', $v));
    return function_exists('mb_substr') ? mb_substr($v, 0, $max) : substr($v, 0, $max);
}

// Un robot remplit le champ piège : on lui répond comme à tout le monde, et on n'envoie rien.
if (champ($d, 'site', 200) !== '') {
    repondre(200, ['ok' => true]);
}

$prenom = champ($d, 'prenom', 60);
$nom = champ($d, 'nom', 60);
$telephone = champ($d, 'telephone', 30);
$courriel = champ($d, 'courriel', 120);
$commune = champ($d, 'commune', 40);
$interets = array_values(array_intersect(INTERETS, is_array($d['interets'] ?? null) ? $d['interets'] : []));
$interets = array_values(array_unique(array_map(fn ($x) => ANCIENS_INTERETS[$x] ?? $x, $interets)));
$accord = ($d['consentement'] ?? false) === true;

$erreurs = [];
if ($prenom === '') { $erreurs[] = 'prenom'; }
if ($nom === '') { $erreurs[] = 'nom'; }
if (!preg_match('/^[0-9+ .()\-]{6,30}$/', $telephone)) { $erreurs[] = 'telephone'; }
if ($courriel !== '' && !filter_var($courriel, FILTER_VALIDATE_EMAIL)) { $erreurs[] = 'courriel'; }
if (!in_array($commune, COMMUNES, true)) { $erreurs[] = 'commune'; }
if (!$accord) { $erreurs[] = 'consentement'; }
if ($erreurs) {
    repondre(422, ['ok' => false, 'erreur' => 'champs', 'champs' => $erreurs]);
}

// Le dossier de données vit à côté de public_html, jamais dedans : il n'a pas d'adresse sur le web.
$dossier = dirname(__DIR__) . '/donnees-flamingo';
if (!is_dir($dossier)) {
    @mkdir($dossier, 0700, true);
}
$archivable = is_dir($dossier) && is_writable($dossier);

// Freiner qui insiste : cinq inscriptions par heure et par adresse suffisent à une famille.
if ($archivable) {
    $trace = $dossier . '/frein-' . hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . 'flamingo') . '.txt';
    $maintenant = time();
    $passages = is_file($trace) ? array_filter(array_map('intval', file($trace) ?: []), fn ($t) => $t > $maintenant - 3600) : [];
    if (count($passages) >= MAX_PAR_HEURE) {
        repondre(429, ['ok' => false, 'erreur' => 'trop']);
    }
    $passages[] = $maintenant;
    @file_put_contents($trace, implode("\n", $passages));
}

$date = date('Y-m-d H:i');

// Une cellule qui commence par = + - @ serait lue comme une formule par un tableur.
function cellule(string $v): string
{
    return preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v;
}

$archive = false;
if ($archivable) {
    $fichier = $dossier . '/liste-attente.csv';
    $nouveau = !is_file($fichier);
    $f = @fopen($fichier, 'a');
    if ($f && flock($f, LOCK_EX)) {
        if ($nouveau) {
            fputcsv($f, ['date', 'prenom', 'nom', 'telephone', 'courriel', 'commune', 'interets'], ';', '"', '\\');
        }
        fputcsv($f, array_map('cellule', [$date, $prenom, $nom, $telephone, $courriel, $commune, implode(', ', $interets)]), ';', '"', '\\');
        flock($f, LOCK_UN);
        $archive = true;
    }
    if ($f) {
        fclose($f);
    }
}

$sujet = '=?UTF-8?B?' . base64_encode('Place fondateur · ' . $prenom . ' ' . $nom . ' · ' . $commune) . '?=';
$corps = implode("\n", [
    'Nouvelle inscription sur la liste d\'attente des fondateurs.',
    '',
    'Prénom : ' . $prenom,
    'Nom : ' . $nom,
    'Téléphone : ' . $telephone,
    'Courriel : ' . ($courriel !== '' ? $courriel : '-'),
    'Commune : ' . $commune,
    'Intérêt : ' . ($interets ? implode(', ', $interets) : '-'),
    'Date : ' . $date,
    '',
    'Accord donné pour être recontacté au sujet de l\'ouverture.',
]);
$entetes = [
    'From: Maison Flamingo <' . DESTINATAIRE . '>',
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'Content-Transfer-Encoding: 8bit',
];
if ($courriel !== '') {
    $entetes[] = 'Reply-To: ' . $courriel;
}
$envoye = @mail(DESTINATAIRE, $sujet, $corps, implode("\r\n", $entetes), '-f' . DESTINATAIRE);

// L'inscription entre aussi dans l'outil terrain : attribuée à une agence et un technicien
// selon la commune, le chrono du premier contact démarre. Si l'outil n'est pas là, le site vit sans lui.
$dans_outil = false;
$noyau = __DIR__ . '/app/noyau/base.php';
if (is_file($noyau)) {
    try {
        require_once $noyau;
        require_once __DIR__ . '/app/noyau/acces.php';
        require_once __DIR__ . '/app/noyau/leads.php';
        $dans_outil = enregistrer_lead(['prenom' => $prenom, 'nom' => $nom, 'telephone' => $telephone, 'courriel' => $courriel,
            'commune' => $commune, 'interets' => implode(', ', $interets), 'notes' => 'Inscrit sur le site le ' . $date . ', accord donné pour être recontacté.'], 'site') !== null;
    } catch (Throwable $e) {
        error_log('Flamingo inscription : ' . $e->getMessage());
    }
}

if (!$envoye && !$archive && !$dans_outil) {
    repondre(500, ['ok' => false, 'erreur' => 'envoi']);
}
repondre(200, ['ok' => true]);
