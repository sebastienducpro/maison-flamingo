<?php
// Maison Flamingo · la seule porte d'entrée du serveur, en JSON.
//
// Ce fichier ne fait qu'aiguiller. Chaque action vérifie elle-même qui l'appelle
// (noyau/acces.php) : une action absente de la table ci-dessous n'existe pas.

declare(strict_types=1);

require __DIR__ . '/noyau/base.php';
require __DIR__ . '/noyau/acces.php';
require __DIR__ . '/noyau/tournees.php';
require __DIR__ . '/noyau/sauvegardes.php';
require __DIR__ . '/noyau/externe.php';
require __DIR__ . '/noyau/equipe.php';
require __DIR__ . '/noyau/espace.php';
require __DIR__ . '/noyau/carnet.php';
require __DIR__ . '/noyau/leads.php';
require __DIR__ . '/noyau/pilotage.php';
require __DIR__ . '/noyau/absences.php';
require __DIR__ . '/noyau/personnel.php';
require __DIR__ . '/noyau/releve.php';
require __DIR__ . '/noyau/contrats.php';
require __DIR__ . '/noyau/paiements.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');

const ACTIONS = [
    'etat', 'installer', 'connexion', 'totp_preparer', 'totp_valider', 'deconnexion', 'motdepasse',
    'invitation_lire', 'invitation_accepter',
    'clients', 'client', 'client_enregistrer', 'client_supprimer', 'import_liste',
    'visite_enregistrer', 'visite_courriel', 'visite_marquer_envoyee', 'rapport', 'photo',
    'tournee', 'tournee_ordonner', 'charge', 'longues', 'geocoder_manquants',
    'equipe', 'equipe_inviter', 'equipe_modifier', 'equipe_lien', 'equipe_remplacer', 'compte_modifier', 'photo_equipe_poser', 'photo_equipe', 'agences', 'agence_enregistrer',
    'tableau', 'export', 'demandes', 'demande_traiter', 'client_inviter', 'client_compte_couper', 'journal',
    'sauvegardes', 'sauvegarde_lancer', 'sauvegarde_telecharger', 'stockage_etat', 'stockage_configurer', 'stockage_essayer',
    'espace_etat', 'espace_connexion', 'espace_deconnexion', 'espace_accueil', 'espace_demande', 'espace_motdepasse',
    'carnet', 'carnet_poste', 'carnet_evenement', 'carnet_evenement_supprimer', 'carnet_equipement', 'carnet_equipement_supprimer',
    'carnet_document', 'carnet_document_supprimer', 'document', 'espace_carnet', 'espace_creneau', 'carnet_saison',
    'leads', 'lead_contacte', 'lead_creer', 'secteurs', 'secteur_enregistrer', 'a_faire', 'motdepasse_oublie',
    'pilotage', 'pilotage_saisie',
    'absences', 'absence_enregistrer', 'absence_supprimer', 'remplacement_poser', 'remplacement_retirer',
    'vivier', 'vivier_enregistrer', 'vivier_activer', 'vivier_terminer',
    'fiche', 'fiche_enregistrer', 'qualification_enregistrer', 'qualification_supprimer', 'conges', 'conge_demander', 'conge_annuler', 'conge_decider',
    'releve_essai', 'releve_preference', 'avis_noter',
    'societe', 'societe_enregistrer', 'contrats', 'contrat_preparer', 'contrat_annuler', 'contrat_document', 'contrat_rappel_noter',
    'espace_contrat', 'espace_contrat_signer', 'espace_contrat_retracter', 'espace_resilier',
    'espace_mandat', 'espace_factures', 'gocardless_webhook', 'facturation', 'facturation_configurer', 'facturation_emettre', 'facturation_automatique', 'facture_document',
];

$action = (string) ($_GET['action'] ?? '');

try {
    if (!in_array($action, ACTIONS, true)) {
        sortir(404, ['ok' => false, 'erreur' => 'action']);
    }
    // Filet de sauvegarde : si la copie quotidienne manque, l'équipe la déclenche sans le savoir.
    if (!in_array($action, ['rapport', 'photo', 'etat', 'document', 'a_faire', 'photo_equipe'], true) && !str_starts_with($action, 'espace_') && session_equipe()) {
        sauvegarde_si_due();
    }
    ('a_' . $action)();
} catch (Throwable $e) {
    error_log('Flamingo app : ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (base()->inTransaction()) {
        base()->rollBack();
    }
    sortir(500, ['ok' => false, 'erreur' => 'serveur']);
}
