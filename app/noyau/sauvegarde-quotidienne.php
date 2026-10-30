<?php
// Lancé chaque nuit par la tâche planifiée de l'hébergement :
//   php /home/<compte>/domains/maisonflamingo.fr/public_html/app/noyau/sauvegarde-quotidienne.php
// Jamais par le web : le dossier noyau/ est fermé, et ce fichier refuse tout autre appel.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
// Les mêmes fichiers que l'API : le relevé du lundi refait le calcul du pilotage, qui s'appuie sur tous.
foreach (['base', 'acces', 'tournees', 'sauvegardes', 'externe', 'equipe', 'espace', 'carnet', 'leads', 'pilotage', 'absences', 'personnel', 'releve', 'contrats', 'paiements', 'corbeille'] as $f) {
    require __DIR__ . '/' . $f . '.php';
}
$r = faire_sauvegarde('tâche planifiée');
// La copie hors de l'hébergeur part seulement d'ici, jamais pendant une requête de l'équipe : une grosse base
// mettrait plusieurs minutes, et un technicien n'a pas à attendre un envoi qu'il n'a pas demandé.
$r['hors_hebergeur'] = $r['ok'] ? envoyer_hors_hebergeur($r['fichier']) : ['ok' => false, 'erreur' => 'pas de copie'];
$r['releves'] = releves_du_lundi();
$r['facturation'] = facturation_automatique_du_mois();
$r['corbeille'] = purger_corbeille();
echo json_encode($r, JSON_UNESCAPED_UNICODE), "\n";
exit($r['ok'] ? 0 : 1);
