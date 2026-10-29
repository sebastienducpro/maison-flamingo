<?php
// Lancé chaque nuit par la tâche planifiée de l'hébergement :
//   php /home/<compte>/domains/maisonflamingo.fr/public_html/app/noyau/sauvegarde-quotidienne.php
// Jamais par le web : le dossier noyau/ est fermé, et ce fichier refuse tout autre appel.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/base.php';
require __DIR__ . '/sauvegardes.php';
$r = faire_sauvegarde('tâche planifiée');
echo json_encode($r, JSON_UNESCAPED_UNICODE), "\n";
exit($r['ok'] ? 0 : 1);
