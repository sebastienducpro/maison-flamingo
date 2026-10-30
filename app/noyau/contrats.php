<?php
// Maison Flamingo · les contrats clients : préparés par l'équipe, signés dans l'espace client, gardés avec leur preuve.
//
// Signature électronique simple (articles 1366 et 1367 du Code civil) : le client est authentifié par son compte, il
// relit le document, coche, tape son nom et signe. On garde le document figé, son empreinte, l'heure, l'adresse IP et le
// navigateur : c'est à nous de prouver, le jour venu, qui a signé quoi.
// Rien ne part chez un client tant que l'identité de la société n'est pas complète et que le modèle n'a pas été relu
// par un juriste : un contrat faux engage plus qu'un contrat absent.

declare(strict_types=1);

const TARIFS = ['Piscine' => 149, 'Piscine + Maison fermée' => 179, 'Maison fermée' => 49];
const ENGAGEMENT_MOIS = ['Piscine' => 12, 'Piscine + Maison fermée' => 12, 'Maison fermée' => 0];
const RETRACTATION_JOURS = 14;          // article L221-18 du Code de la consommation
const RAPPEL_RECONDUCTION = [30, 90];   // article L215-1 : prévenir entre trois mois et un mois avant l'échéance
const MODELE_CONTRAT = 'v2 du 30 octobre 2026';
// La taille du bassin fixe le prix avant la signature : on la mesure au diagnostic, on ne la découvre pas en cours de saison.
const SURFACE_BASE_M2 = 50;          // jusqu'à 50 m² d'eau (environ 10 × 5 m) : le prix de la formule
const SURFACE_MAJOREE_M2 = 80;       // de 50 à 80 m² : la majoration ; au-delà, ou à débordement : sur devis
const MAJORATION_PCT = 20;
const REMISE_ANNUELLE_PCT = 5;       // payer l'année d'avance : de la trésorerie avant la saison, moins d'impayés
const MODALITES = ['mensuel' => 'Mensuel', 'annuel' => 'Annuel, payé d\'avance'];
const FORMULES_PISCINE = ['Piscine', 'Piscine + Maison fermée'];

// L'identité de la société, lisible par l'équipe : rien ici n'est un secret.
const PARAMETRES_SOCIETE = [
    'raison_sociale' => 'Raison sociale', 'forme' => 'Forme et capital', 'siret' => 'SIRET', 'rcs' => 'Immatriculation (RCS ou RNE)',
    'adresse' => 'Adresse du siège', 'courriel' => 'Courriel de contact', 'telephone' => 'Téléphone', 'mention_tva' => 'Mention de TVA',
    'mediateur_nom' => 'Médiateur de la consommation', 'mediateur_site' => 'Site du médiateur', 'assureur' => 'Assureur en responsabilité civile',
    'police' => 'Numéro de police', 'juriste_nom' => 'Juriste qui a relu le modèle', 'juriste_le' => 'Date de la relecture',
    // La révision des prix : deux indices liés au métier (l'article L112-2 du Code monétaire et financier interdit d'indexer
    // sur le niveau général des prix), leur dernière valeur publiée et sa période. Mis à jour à chaque publication.
    'indice_travail_nom' => 'Indice du coût du travail (nom exact)', 'indice_travail_valeur' => 'Sa dernière valeur publiée', 'indice_travail_periode' => 'Sa période',
    'indice_produits_nom' => 'Indice des produits (nom exact)', 'indice_produits_valeur' => 'Sa dernière valeur publiée', 'indice_produits_periode' => 'Sa période',
];
// P = P0 × (part fixe + part travail × S / S0 + part produits × C / C0) : les parts suivent le coût réel d'un passage.
const REVISION_PARTS = ['fixe' => 0.15, 'travail' => 0.70, 'produits' => 0.15];

function parametres_societe(): array
{
    $v = array_fill_keys(array_keys(PARAMETRES_SOCIETE), '');
    foreach (base()->query("SELECT cle, valeur FROM parametres WHERE cle LIKE 'societe.%'")->fetchAll() as $l) {
        $k = substr($l['cle'], 8);
        if (array_key_exists($k, $v)) {
            $v[$k] = $l['valeur'];
        }
    }
    return $v;
}

// Ce qui manque avant le premier contrat : refus par défaut tant que la liste n'est pas vide.
function manques_societe(array $s): array
{
    return array_values(array_map(fn ($k) => PARAMETRES_SOCIETE[$k], array_filter(array_keys(PARAMETRES_SOCIETE), fn ($k) => trim($s[$k]) === '')));
}

function a_societe(): void
{
    $u = exiger_equipe(['chef', 'superadmin']);
    $s = parametres_societe();
    sortir(200, ['ok' => true, 'societe' => $s, 'libelles' => PARAMETRES_SOCIETE, 'manques' => manques_societe($s), 'modele' => MODELE_CONTRAT,
        'modifiable' => $u['role'] === 'superadmin', 'tarifs' => TARIFS]);
}

function a_societe_enregistrer(): void
{
    $u = exiger_equipe(['superadmin']);
    exiger_post();
    $d = entree();
    $q = base()->prepare('INSERT INTO parametres (cle, valeur, maj_le, maj_par) VALUES (?,?,?,?) ON CONFLICT(cle) DO UPDATE SET valeur = excluded.valeur, maj_le = excluded.maj_le, maj_par = excluded.maj_par');
    foreach (PARAMETRES_SOCIETE as $k => $libelle) {
        if (array_key_exists($k, $d)) {
            $v = texte($d[$k], $k === 'adresse' ? 200 : 120);
            if ($k === 'juriste_le' && $v !== '' && !date_valide($v)) {
                sortir(200, ['ok' => false, 'erreur' => 'date']);
            }
            if (str_ends_with($k, '_valeur') && $v !== '' && (!is_numeric(str_replace(',', '.', $v)) || (float) str_replace(',', '.', $v) <= 0)) {
                sortir(200, ['ok' => false, 'erreur' => 'indice']);
            }
            $q->execute(['societe.' . $k, $v, maintenant(), $u['courriel']]);
        }
    }
    tracer($u, 'identité de la société modifiée');
    sortir(200, ['ok' => true, 'manques' => manques_societe(parametres_societe())]);
}

/* ---------- Le document ---------- */

function esc(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function date_longue(string $iso): string
{
    $mois = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    $t = strtotime($iso);
    return (int) date('j', $t) . ' ' . $mois[(int) date('n', $t) - 1] . ' ' . date('Y', $t);
}

// Le document est rendu une fois, à la préparation, puis figé : la version signée est celle que le client a lue.
function rendre_contrat(array $s, array $c, array $k): string
{
    $prix = number_format($k['prix_mensuel'], 2, ',', ' ');
    $engage = (int) $k['engagement_mois'] > 0;
    $fondateur = (int) $k['fondateur'] === 1;
    $services = [
        'Piscine' => 'un passage hebdomadaire d\'avril à octobre par le même technicien, le même jour ; l\'hivernage et la remise en route ; les produits et les analyses d\'eau ; un rapport photo après chaque passage ; le dépannage de l\'eau en saison.',
        'Maison fermée' => 'une visite mensuelle complète de la maison ; un passage après un épisode de vent ou de pluie exceptionnel ; un rapport photo après chaque visite.',
        'Piscine + Maison fermée' => 'l\'ensemble des prestations Piscine et Maison fermée, dans la même visite quand c\'est possible ; la préparation de la maison avant votre arrivée ; une intervention prioritaire sous 24 heures ; un bilan annuel.',
    ];
    $h = [];
    $h[] = '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Contrat ' . esc($k['numero']) . '</title><link rel="stylesheet" href="contrat.css?v=2"></head><body><main class="contrat">';
    $h[] = '<header><p class="marque">Maison <strong>Flamingo</strong></p><h1>Contrat d\'abonnement n° ' . esc($k['numero']) . '</h1><p class="sous">' . esc($k['formule']) . ' · modèle ' . esc(MODELE_CONTRAT) . '</p></header>';
    $h[] = '<h2>1. Les parties</h2><p><strong>' . esc($s['raison_sociale']) . '</strong>, ' . esc($s['forme']) . ', SIRET ' . esc($s['siret']) . ', ' . esc($s['rcs']) . ', dont le siège est ' . esc($s['adresse']) . ' (' . esc($s['courriel']) . ', ' . esc($s['telephone']) . '), ci-après « Maison Flamingo ».</p>';
    $h[] = '<p>Et <strong>' . esc(trim($c['prenom'] . ' ' . $c['nom'])) . '</strong>, demeurant ' . esc(trim($c['adresse'] . ', ' . $c['commune'], ', ')) . ($c['courriel'] ? ' (' . esc($c['courriel']) . ')' : '') . ', ci-après « le client ».</p>';
    $h[] = '<h2>2. Ce que comprend l\'abonnement</h2><p>Formule <strong>' . esc($k['formule']) . '</strong> : ' . esc($services[$k['formule']] ?? '') . '</p><p>Le jour de passage est convenu avec le client et indiqué dans son espace. Un remplacement ponctuel par un autre membre de l\'équipe est annoncé au client avant le passage.</p>';
    $euros = fn ($n) => number_format((float) $n, (float) $n == floor((float) $n) ? 0 : 2, ',', ' ') . ' €';
    $h[] = '<h2>3. Prix et paiement</h2>';
    $p = ['Formule ' . esc($k['formule']) . ' : ' . $euros($k['prix_base'] ?? $k['prix_mensuel']) . ' TTC par mois.'];
    if (!empty($k['surface_m2'])) {
        $bassin = 'Bassin de ' . esc(rtrim(rtrim(number_format((float) $k['surface_m2'], 1, ',', ''), '0'), ',')) . ' m² d\'eau' . (!empty($k['debordement']) ? ', à débordement' : '');
        $p[] = !empty($k['sur_devis'])
            ? $bassin . ' : prix établi sur devis après diagnostic, soit <strong>' . $euros($k['prix_mensuel']) . ' TTC par mois</strong>.'
            : ((float) $k['majoration_pct'] > 0
                ? $bassin . ' : majoration grand bassin de ' . (int) $k['majoration_pct'] . ' % (de ' . SURFACE_BASE_M2 . ' à ' . SURFACE_MAJOREE_M2 . ' m²), soit <strong>' . $euros($k['prix_mensuel']) . ' TTC par mois</strong>.'
                : $bassin . ' : sans majoration (jusqu\'à ' . SURFACE_BASE_M2 . ' m²).');
    }
    $h[] = '<p>' . implode('<br>', $p) . ' ' . esc($s['mention_tva']) . '.</p>';
    $h[] = ($k['modalite'] ?? 'mensuel') === 'annuel'
        ? '<p><strong>Paiement annuel, d\'avance : ' . $euros($k['montant_annuel']) . ' TTC pour douze mois</strong>, soit ' . (int) REMISE_ANNUELLE_PCT . ' % de remise sur ' . (int) $k['mois_payants'] . ' mensualités de ' . $euros($k['prix_mensuel']) . ($fondateur ? ' (le premier mois est offert)' : '') . '. Il est prélevé en une fois au début de chaque période de douze mois, par prélèvement SEPA sur mandat signé par le client. En cas de rétractation, ou de résiliation pour un motif légitime, les mois non commencés sont remboursés au prorata.</p>'
        : '<p><strong>Paiement mensuel</strong> : ' . $euros($k['prix_mensuel']) . ' TTC prélevés chaque mois, par prélèvement SEPA sur mandat signé par le client.' . ($fondateur ? ' Le premier mois est offert.' : '') . '</p>';
    if ($fondateur) {
        $h[] = '<p><strong>Offre fondateur</strong> : ce prix est garanti jusqu\'au ' . esc(date_longue($k['prix_bloque_jusquau'])) . '.</p>';
    }
    $parts = array_map(fn ($x) => number_format($x, 2, ',', ''), REVISION_PARTS);
    $h[] = '<p><strong>Révision du prix.</strong> Le prix est révisé une fois par an, à la date anniversaire, à la hausse comme à la baisse, selon la formule :</p>'
        . '<p class="formule">P = P0 × (' . $parts['fixe'] . ' + ' . $parts['travail'] . ' × S / S0 + ' . $parts['produits'] . ' × C / C0)</p>'
        . '<p>P0 est le prix en vigueur avant la révision ; S est l\'indice « ' . esc($s['indice_travail_nom']) . ' » et C l\'indice « ' . esc($s['indice_produits_nom']) . ' », publiés par l\'INSEE, dans leur dernière valeur connue à la date anniversaire ; S0 et C0 sont les mêmes indices dans leur dernière valeur connue à la date de la signature, puis à la date de la révision précédente. '
        . 'Valeurs de référence du présent contrat : S0 = ' . esc($k['indice_travail_ref'] ?? $s['indice_travail_valeur']) . ' (' . esc($k['indice_travail_periode'] ?? $s['indice_travail_periode']) . '), C0 = ' . esc($k['indice_produits_ref'] ?? $s['indice_produits_valeur']) . ' (' . esc($k['indice_produits_periode'] ?? $s['indice_produits_periode']) . '). '
        . 'Si un indice cesse d\'être publié, l\'indice qui le remplace officiellement s\'applique. Le client est informé par écrit du nouveau prix un mois avant son application.'
        . ($fondateur ? ' Pendant la garantie de prix fondateur, aucune révision ne s\'applique ; S0 et C0 sont alors les valeurs connues à la fin de la garantie.' : '') . '</p>';
    $h[] = '<h2>4. Durée</h2><p>Le contrat prend effet le <strong>' . esc(date_longue($k['debut'])) . '</strong>.</p>';
    $h[] = $engage
        ? '<p>Il est conclu pour une saison de ' . (int) $k['engagement_mois'] . ' mois, puis reconduit tacitement par périodes de ' . (int) $k['engagement_mois'] . ' mois. Maison Flamingo informe le client par écrit, au plus tôt trois mois et au plus tard un mois avant la fin de chaque période, de sa faculté de ne pas reconduire le contrat (article L215-1 du Code de la consommation).</p>'
        : '<p>Il est conclu sans durée minimale et peut être résilié à tout moment, avec effet à la fin du mois en cours.</p>';
    if ($fondateur && $engage) {
        $h[] = '<p><strong>Satisfait ou résilié</strong> : pendant les deux premiers mois, le client peut résilier sans motif ni frais, avec effet à la fin du mois en cours.</p>';
    }
    $h[] = '<h2>5. Droit de rétractation</h2><p>Le client dispose de ' . RETRACTATION_JOURS . ' jours à compter de la signature pour se rétracter, sans motif ni frais (article L221-18 du Code de la consommation), depuis son espace client, par courriel, ou avec le formulaire joint en annexe. Si le client a expressément demandé que le service commence avant la fin de ce délai, il paie la part du service déjà fournie (article L221-25).</p>';
    $h[] = '<p>Demande d\'exécution anticipée : <strong>' . ((int) $k['execution_anticipee'] === 1 ? 'oui, le client a demandé que le service commence avant la fin du délai' : 'non, le service commence après la fin du délai de rétractation') . '</strong>.</p>';
    $h[] = '<h2>6. Résiliation</h2><p>Le client peut résilier en ligne, depuis son espace client, rubrique « Mon contrat » (article L215-1-1 du Code de la consommation) ; Maison Flamingo accuse réception et indique la date d\'effet. ' . ($engage ? 'Pendant la période d\'engagement, la résiliation prend effet à la fin du mois en cours en cas de motif légitime (vente ou déménagement, décès, perte d\'emploi) ; hors de ces cas, elle prend effet à la fin de la période en cours.' : 'Elle prend effet à la fin du mois en cours.') . '</p>';
    $h[] = '<h2>7. Accès, clés et assurance</h2><p>Les clés et codes confiés sont conservés sous scellé, identifiés par un numéro qui ne porte pas l\'adresse, et restitués sur simple demande. Maison Flamingo est assurée en responsabilité civile professionnelle auprès de ' . esc($s['assureur']) . ' (police n° ' . esc($s['police']) . ').</p>';
    $h[] = '<h2>8. Données personnelles</h2><p>Les données du client servent à exécuter le contrat (passages, rapports, facturation) et ne sont ni vendues ni cédées. Le client peut y accéder, les faire corriger ou effacer en écrivant à ' . esc($s['courriel']) . '. Elles sont conservées pendant le contrat, puis le temps imposé par les obligations comptables.</p>';
    $h[] = '<h2>9. Médiation</h2><p>En cas de litige non résolu avec Maison Flamingo, le client peut saisir gratuitement le médiateur de la consommation : ' . esc($s['mediateur_nom']) . ', ' . esc($s['mediateur_site']) . ' (article L612-1 du Code de la consommation).</p>';
    $h[] = '<h2>10. Signature électronique</h2><p>Le contrat est signé électroniquement depuis l\'espace client, après authentification par courriel et mot de passe. Maison Flamingo conserve le document signé et son dossier de preuve : date et heure, adresse IP, navigateur, empreinte SHA-256 du document (articles 1366 et 1367 du Code civil).</p>';
    $h[] = '<section class="annexe"><h2>Annexe · Formulaire de rétractation</h2><p>À compléter et renvoyer uniquement si vous souhaitez vous rétracter du contrat.</p><p>À l\'attention de ' . esc($s['raison_sociale']) . ', ' . esc($s['adresse']) . ', ' . esc($s['courriel']) . ' :</p><p>Je vous notifie par la présente ma rétractation du contrat portant sur la prestation de services ci-dessous :<br>Contrat n° ' . esc($k['numero']) . ', conclu le : ……………<br>Nom du client : ……………<br>Adresse du client : ……………<br>Signature du client (uniquement en cas de notification du présent formulaire sur papier) : ……………<br>Date : ……………</p></section>';
    $h[] = '</main></body></html>';
    return implode("\n", $h);
}

function dossier_contrats(): string
{
    $d = dossier_donnees() . '/contrats';
    if (!is_dir($d)) {
        @mkdir($d, 0700, true);
    }
    return $d;
}

function contrat_public(array $k): array
{
    $retract_fin = $k['signe_le'] ? date('Y-m-d', strtotime($k['signe_le'] . ' +' . RETRACTATION_JOURS . ' days')) : null;
    $echeance = (int) $k['engagement_mois'] > 0 ? prochaine_echeance($k['debut'], (int) $k['engagement_mois']) : null;
    return ['id' => (int) $k['id'], 'numero' => $k['numero'], 'formule' => $k['formule'], 'prix_mensuel' => (float) $k['prix_mensuel'], 'debut' => $k['debut'],
        'engagement_mois' => (int) $k['engagement_mois'], 'fondateur' => (int) $k['fondateur'] === 1, 'statut' => $k['statut'], 'cree_le' => $k['cree_le'],
        'signe_le' => $k['signe_le'], 'signe_nom' => $k['signe_nom'], 'execution_anticipee' => (int) $k['execution_anticipee'] === 1,
        'retractation_jusquau' => $retract_fin, 'peut_se_retracter' => $k['statut'] === 'signe' && $retract_fin && date('Y-m-d') <= $retract_fin,
        'resilie_le' => $k['resilie_le'], 'resiliation_effet' => $k['resiliation_effet'], 'echeance' => $echeance, 'rappel_le' => $k['rappel_reconduction_le'],
        'modalite' => $k['modalite'] ?? 'mensuel', 'montant_annuel' => $k['montant_annuel'] !== null ? (float) $k['montant_annuel'] : null,
        'surface_m2' => $k['surface_m2'] !== null ? (float) $k['surface_m2'] : null, 'majoration_pct' => (float) ($k['majoration_pct'] ?? 0), 'sur_devis' => (int) ($k['sur_devis'] ?? 0) === 1,
        'prix_revise' => prix_revise($k)];
}

// Le prix que donnerait la formule avec les dernières valeurs d'indices saisies : un repère pour préparer la lettre
// de révision, pas un prix appliqué. Rien n'est calculé si une valeur manque.
function prix_revise(array $k): ?float
{
    $s = parametres_societe();
    $n = fn ($v) => is_numeric(str_replace(',', '.', (string) $v)) ? (float) str_replace(',', '.', (string) $v) : 0.0;
    [$s0, $c0, $s1, $c1] = [$n($k['indice_travail_ref'] ?? ''), $n($k['indice_produits_ref'] ?? ''), $n($s['indice_travail_valeur']), $n($s['indice_produits_valeur'])];
    if ($s0 <= 0 || $c0 <= 0 || $s1 <= 0 || $c1 <= 0) {
        return null;
    }
    return round((float) $k['prix_mensuel'] * (REVISION_PARTS['fixe'] + REVISION_PARTS['travail'] * $s1 / $s0 + REVISION_PARTS['produits'] * $c1 / $c0), 2);
}

// La prochaine fin de période d'un contrat avec engagement, à partir de sa date de début.
function prochaine_echeance(string $debut, int $mois): string
{
    $e = $debut;
    do {
        $e = date('Y-m-d', strtotime($e . " +$mois months"));
    } while ($e <= date('Y-m-d'));
    return $e;
}

function a_contrats(): void
{
    $u = exiger_equipe(['chef', 'superadmin', 'technicien']);
    $c = exiger_client_autorise($u, entier($_GET['client_id'] ?? 0));
    $q = base()->prepare('SELECT * FROM contrats WHERE client_id = ? ORDER BY id DESC');
    $q->execute([$c['id']]);
    $s = parametres_societe();
    sortir(200, ['ok' => true, 'contrats' => array_map('contrat_public', $q->fetchAll()), 'manques' => manques_societe($s), 'tarifs' => TARIFS,
        'peut_preparer' => $u['role'] !== 'technicien', 'formules_piscine' => FORMULES_PISCINE, 'modalites' => MODALITES,
        'regle' => ['base_m2' => SURFACE_BASE_M2, 'majoree_m2' => SURFACE_MAJOREE_M2, 'majoration_pct' => MAJORATION_PCT, 'remise_pct' => REMISE_ANNUELLE_PCT],
        'surface_diagnostic' => surface_diagnostic((int) $c['id'])]);
}

// La surface relevée au dernier diagnostic (longueur × largeur) : le prix se fixe sur une mesure, pas sur une impression.
function surface_diagnostic(int $client): ?float
{
    $q = base()->prepare("SELECT donnees FROM visites WHERE client_id = ? AND type = 'diagnostic' ORDER BY quand DESC LIMIT 1");
    $q->execute([$client]);
    $v = json_decode((string) $q->fetchColumn(), true)['valeurs'] ?? [];
    $l = str_replace(',', '.', (string) ($v['longueur'] ?? ''));
    $w = str_replace(',', '.', (string) ($v['largeur'] ?? ''));
    return is_numeric($l) && is_numeric($w) && (float) $l > 0 && (float) $w > 0 ? round((float) $l * (float) $w, 1) : null;
}

// Le prix d'un contrat, calculé ici et nulle part ailleurs : l'écran l'affiche, il ne le calcule pas.
function calculer_tarif(string $formule, $surface, bool $debordement, string $modalite, bool $fondateur, $prix_devis): array
{
    if (!isset(TARIFS[$formule]) || !isset(MODALITES[$modalite])) {
        return ['ok' => false, 'erreur' => 'champs'];
    }
    $base = (float) TARIFS[$formule];
    $t = ['ok' => true, 'prix_base' => $base, 'surface_m2' => null, 'debordement' => false, 'majoration_pct' => 0.0, 'sur_devis' => false, 'prix_mensuel' => $base,
        'modalite' => $modalite, 'mois_payants' => null, 'montant_annuel' => null, 'remise_pct' => 0];
    if (in_array($formule, FORMULES_PISCINE, true)) {
        $surface = is_numeric($surface) ? round((float) $surface, 1) : 0.0;
        if ($surface <= 0 || $surface > 2000) {
            return ['ok' => false, 'erreur' => 'surface'];
        }
        $t['surface_m2'] = $surface;
        $t['debordement'] = $debordement;
        if ($debordement || $surface > SURFACE_MAJOREE_M2) {
            // Sur devis : le prix vient du diagnostic, jamais en dessous de la majoration du palier précédent.
            $minimum = round($base * (1 + MAJORATION_PCT / 100));
            $devis = is_numeric($prix_devis) ? round((float) $prix_devis, 2) : 0.0;
            if ($devis < $minimum || $devis > 2000) {
                return ['ok' => false, 'erreur' => 'devis', 'minimum' => $minimum];
            }
            $t['sur_devis'] = true;
            $t['prix_mensuel'] = $devis;
            $t['majoration_pct'] = round(($devis / $base - 1) * 100, 1);
        } elseif ($surface > SURFACE_BASE_M2) {
            $t['majoration_pct'] = (float) MAJORATION_PCT;
            $t['prix_mensuel'] = round($base * (1 + MAJORATION_PCT / 100));   // à l'euro : un prix qu'on dit au client
        }
    }
    if ($modalite === 'annuel') {
        // Le fondateur a son premier mois offert : il paie onze mois, remise comprise.
        $t['mois_payants'] = $fondateur ? 11 : 12;
        $t['remise_pct'] = REMISE_ANNUELLE_PCT;
        $t['montant_annuel'] = round($t['prix_mensuel'] * $t['mois_payants'] * (1 - REMISE_ANNUELLE_PCT / 100));
    }
    return $t;
}

function a_contrat_tarif(): void
{
    exiger_equipe(['chef', 'superadmin']);
    sortir(200, calculer_tarif((string) ($_GET['formule'] ?? ''), $_GET['surface'] ?? null, !empty($_GET['debordement']), (string) ($_GET['modalite'] ?? 'mensuel'), !empty($_GET['fondateur']), $_GET['prix_devis'] ?? null));
}

function a_contrat_preparer(): void
{
    $u = exiger_equipe(['chef', 'superadmin']);
    exiger_post();
    $d = entree();
    $c = exiger_client_autorise($u, entier($d['client_id'] ?? 0));
    $s = parametres_societe();
    if (manques_societe($s)) {
        tracer($u, 'contrat refusé', "client {$c['id']} : identité de la société incomplète");
        sortir(200, ['ok' => false, 'erreur' => 'societe', 'manques' => manques_societe($s)]);
    }
    $formule = (string) ($d['formule'] ?? '');
    $debut = date_valide($d['debut'] ?? '');
    $fondateur = !empty($d['fondateur']) ? 1 : 0;
    $t = calculer_tarif($formule, $d['surface'] ?? null, !empty($d['debordement']), (string) ($d['modalite'] ?? 'mensuel'), (bool) $fondateur, $d['prix_devis'] ?? null);
    if (!$t['ok']) {
        sortir(200, $t);
    }
    if (!$debut || $debut < date('Y-m-d') || $debut > date('Y-m-d', strtotime('+180 days'))) {
        sortir(200, ['ok' => false, 'erreur' => 'champs']);
    }
    $prix = $t['prix_mensuel'];
    $ouvert = base()->prepare("SELECT COUNT(*) FROM contrats WHERE client_id = ? AND statut IN ('a_signer', 'signe')");
    $ouvert->execute([$c['id']]);
    if ((int) $ouvert->fetchColumn() > 0) {
        sortir(200, ['ok' => false, 'erreur' => 'deja']);
    }
    $db = base();
    $db->beginTransaction();
    $annee = date('Y', strtotime($debut));
    $n = $db->prepare('SELECT COUNT(*) FROM contrats WHERE numero LIKE ?');
    $n->execute(["MF-$annee-%"]);
    // Une numérotation continue par année : un trou ou un doublon se verrait au premier contrôle.
    $numero = sprintf('MF-%s-%04d', $annee, (int) $n->fetchColumn() + 1);
    $k = ['numero' => $numero, 'formule' => $formule, 'prix_mensuel' => $prix, 'debut' => $debut, 'engagement_mois' => ENGAGEMENT_MOIS[$formule],
        'fondateur' => $fondateur, 'prix_bloque_jusquau' => $fondateur ? date('Y-m-d', strtotime($debut . ' +3 years')) : null, 'execution_anticipee' => 0,
        'prix_base' => $t['prix_base'], 'surface_m2' => $t['surface_m2'], 'debordement' => $t['debordement'] ? 1 : 0, 'majoration_pct' => $t['majoration_pct'], 'sur_devis' => $t['sur_devis'] ? 1 : 0,
        'modalite' => $t['modalite'], 'mois_payants' => $t['mois_payants'], 'montant_annuel' => $t['montant_annuel'],
        // Les indices de référence sont figés au contrat : c'est à eux que la révision se compare.
        'indice_travail_ref' => $s['indice_travail_valeur'], 'indice_travail_periode' => $s['indice_travail_periode'],
        'indice_produits_ref' => $s['indice_produits_valeur'], 'indice_produits_periode' => $s['indice_produits_periode']];
    $html = rendre_contrat($s, $c, $k);
    $fichier = $numero . '-a-signer.html';
    file_put_contents(dossier_contrats() . '/' . $fichier, $html, LOCK_EX);
    $db->prepare("INSERT INTO contrats (client_id, numero, formule, prix_mensuel, debut, engagement_mois, fondateur, prix_bloque_jusquau, statut, fichier, empreinte, modele, cree_le, cree_par,
            prix_base, surface_m2, debordement, majoration_pct, sur_devis, modalite, mois_payants, montant_annuel, indice_travail_ref, indice_travail_periode, indice_produits_ref, indice_produits_periode)
        VALUES (?,?,?,?,?,?,?,?, 'a_signer', ?,?,?,?,?, ?,?,?,?,?,?,?,?, ?,?,?,?)")
        ->execute([$c['id'], $numero, $formule, $prix, $debut, $k['engagement_mois'], $fondateur, $k['prix_bloque_jusquau'], $fichier, hash('sha256', $html), MODELE_CONTRAT, maintenant(), $u['courriel'],
            $k['prix_base'], $k['surface_m2'], $k['debordement'], $k['majoration_pct'], $k['sur_devis'], $k['modalite'], $k['mois_payants'], $k['montant_annuel'],
            $k['indice_travail_ref'], $k['indice_travail_periode'], $k['indice_produits_ref'], $k['indice_produits_periode']]);
    $id = (int) $db->lastInsertId();
    $db->commit();
    tracer($u, 'contrat préparé', "$numero client {$c['id']} $formule $prix € par mois, " . $k['modalite'] . ($k['montant_annuel'] ? ' (' . $k['montant_annuel'] . ' € par an)' : ''));
    sortir(200, ['ok' => true, 'id' => $id, 'numero' => $numero]);
}

function a_contrat_annuler(): void
{
    $u = exiger_equipe(['chef', 'superadmin']);
    exiger_post();
    $k = contrat_par_id(entier(entree()['id'] ?? 0));
    if (!$k || !client_autorise($u, (int) $k['client_id'])) {
        sortir(404, ['ok' => false, 'erreur' => 'introuvable']);
    }
    // Un contrat signé ne s'annule pas d'un clic côté équipe : il se résilie, ou le client se rétracte.
    if ($k['statut'] !== 'a_signer') {
        sortir(200, ['ok' => false, 'erreur' => 'statut']);
    }
    base()->prepare("UPDATE contrats SET statut = 'annule' WHERE id = ?")->execute([$k['id']]);
    tracer($u, 'contrat annulé avant signature', $k['numero']);
    sortir(200, ['ok' => true]);
}

function contrat_par_id(int $id): ?array
{
    $q = base()->prepare('SELECT * FROM contrats WHERE id = ?');
    $q->execute([$id]);
    return $q->fetch() ?: null;
}

// Le document, pour l'équipe qui voit ce client ou pour le client lui-même. Le fichier est relu et comparé à son empreinte.
function a_contrat_document(): void
{
    $k = contrat_par_id(entier($_GET['id'] ?? 0));
    $client = session_client();
    $u = $client ? null : session_equipe();
    $autorise = $k && (($client && (int) $client['id'] === (int) $k['client_id']) || ($u && (!double_facteur_requis($u) || (int) $u['totp_ok'] === 1) && client_autorise($u, (int) $k['client_id'])));
    if (!$autorise) {
        sortir(404, ['ok' => false, 'erreur' => 'introuvable']);
    }
    $html = (string) @file_get_contents(dossier_contrats() . '/' . basename($k['fichier']));
    if ($html === '' || !hash_equals($k['empreinte'], hash('sha256', $html))) {
        tracer($u ?? 'client', 'contrat altéré', $k['numero']);
        sortir(409, ['ok' => false, 'erreur' => 'empreinte']);
    }
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: private, no-store');
    echo $html;
    exit;
}

/* ---------- Côté client : signer, se rétracter, résilier ---------- */

function a_espace_contrat(): void
{
    $c = exiger_client_connecte();
    $q = base()->prepare("SELECT * FROM contrats WHERE client_id = ? AND statut <> 'annule' ORDER BY id DESC");
    $q->execute([$c['id']]);
    sortir(200, ['ok' => true, 'contrats' => array_map('contrat_public', $q->fetchAll()), 'retractation_jours' => RETRACTATION_JOURS]);
}

function contrat_du_client(array $c, int $id): array
{
    $k = contrat_par_id($id);
    if (!$k || (int) $k['client_id'] !== (int) $c['id']) {
        tracer('client ' . $c['id'], 'accès refusé', "contrat $id");
        sortir(404, ['ok' => false, 'erreur' => 'introuvable']);
    }
    return $k;
}

function a_espace_contrat_signer(): void
{
    $c = exiger_client_connecte();
    exiger_post();
    $d = entree();
    $k = contrat_du_client($c, entier($d['id'] ?? 0));
    $nom = texte($d['nom'] ?? '', 120);
    if ($k['statut'] !== 'a_signer') {
        sortir(200, ['ok' => false, 'erreur' => 'statut']);
    }
    // Le nom tapé doit être celui du client : une signature d'un autre ne prouve rien.
    $attendu = mb_strtolower(trim($c['prenom'] . ' ' . $c['nom']));
    if (empty($d['lu']) || $nom === '' || mb_strtolower(preg_replace('/\s+/', ' ', $nom)) !== preg_replace('/\s+/', ' ', $attendu)) {
        sortir(200, ['ok' => false, 'erreur' => 'champs', 'attendu' => trim($c['prenom'] . ' ' . $c['nom'])]);
    }
    $s = parametres_societe();
    $anticipee = !empty($d['execution_anticipee']) ? 1 : 0;
    $k['execution_anticipee'] = $anticipee;
    // Le document est rendu une dernière fois avec le choix du client sur l'exécution anticipée, puis figé avec la preuve.
    $html = rendre_contrat($s, $c, $k);
    $quand = maintenant();
    $preuve = '<section class="preuve"><h2>Signature</h2><p>Signé électroniquement par <strong>' . esc($nom) . '</strong> le ' . esc(date_longue($quand)) . ' à ' . esc(date('H:i:s', strtotime($quand)))
        . ', depuis l\'espace client ouvert avec le courriel ' . esc($c['compte_courriel']) . '.<br>Adresse IP ' . esc($_SERVER['REMOTE_ADDR'] ?? '') . ' · navigateur ' . esc(mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 160))
        . '<br>Empreinte SHA-256 du contrat avant signature : ' . esc(hash('sha256', $html)) . '</p></section>';
    $signe = str_replace('</main></body></html>', $preuve . '</main></body></html>', $html);
    $fichier = $k['numero'] . '-signe.html';
    file_put_contents(dossier_contrats() . '/' . $fichier, $signe, LOCK_EX);
    $db = base();
    $db->prepare("UPDATE contrats SET statut = 'signe', fichier = ?, empreinte = ?, signe_le = ?, signe_ip = ?, signe_agent = ?, signe_nom = ?, signe_compte = ?, execution_anticipee = ? WHERE id = ? AND statut = 'a_signer'")
        ->execute([$fichier, hash('sha256', $signe), $quand, (string) ($_SERVER['REMOTE_ADDR'] ?? ''), mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300), $nom, $c['compte_courriel'], $anticipee, $k['id']]);
    // L'équipe l'apprend dans ses demandes, comme tout ce que le client fait depuis son espace.
    $db->prepare('INSERT INTO demandes (client_id, texte, cree_le) VALUES (?,?,?)')->execute([$c['id'], 'Contrat ' . $k['numero'] . ' signé en ligne. Exécution anticipée : ' . ($anticipee ? 'oui' : 'non') . '.', $quand]);
    tracer('client ' . $c['id'], 'contrat signé', $k['numero']);
    sortir(200, ['ok' => true]);
}

function a_espace_contrat_retracter(): void
{
    $c = exiger_client_connecte();
    exiger_post();
    $k = contrat_du_client($c, entier(entree()['id'] ?? 0));
    $p = contrat_public($k);
    if (!$p['peut_se_retracter']) {
        sortir(200, ['ok' => false, 'erreur' => 'delai']);
    }
    base()->prepare("UPDATE contrats SET statut = 'retracte', resilie_le = ? WHERE id = ?")->execute([maintenant(), $k['id']]);
    base()->prepare('INSERT INTO demandes (client_id, texte, cree_le) VALUES (?,?,?)')->execute([$c['id'], 'Rétractation du contrat ' . $k['numero'] . ', faite en ligne. À accuser par écrit, et à rembourser si un paiement a eu lieu.', maintenant()]);
    tracer('client ' . $c['id'], 'contrat : rétractation', $k['numero']);
    sortir(200, ['ok' => true]);
}

// La résiliation en ligne (article L215-1-1) : la demande est enregistrée à la seconde, l'accusé de réception s'affiche
// tout de suite avec la date d'effet, et l'équipe la retrouve dans ses demandes.
function a_espace_resilier(): void
{
    $c = exiger_client_connecte();
    exiger_post();
    $d = entree();
    $k = contrat_du_client($c, entier($d['id'] ?? 0));
    if ($k['statut'] !== 'signe') {
        sortir(200, ['ok' => false, 'erreur' => 'statut']);
    }
    $motif = in_array($d['motif'] ?? '', ['vente', 'deces', 'emploi', 'autre'], true) ? $d['motif'] : 'autre';
    $fin_mois = date('Y-m-t');
    $deux_mois = date('Y-m-d', strtotime($k['debut'] . ' +2 months'));
    $engage = (int) $k['engagement_mois'] > 0;
    $sans_frais = !$engage || $motif !== 'autre' || ((int) $k['fondateur'] === 1 && date('Y-m-d') < $deux_mois);
    $effet = $sans_frais ? $fin_mois : date('Y-m-d', strtotime(prochaine_echeance($k['debut'], (int) $k['engagement_mois']) . ' -1 day'));
    $quand = maintenant();
    base()->prepare("UPDATE contrats SET statut = 'resilie', resilie_le = ?, resiliation_effet = ?, resiliation_motif = ? WHERE id = ?")->execute([$quand, $effet, $motif, $k['id']]);
    base()->prepare('INSERT INTO demandes (client_id, texte, cree_le) VALUES (?,?,?)')->execute([$c['id'], 'Résiliation en ligne du contrat ' . $k['numero'] . ' (motif : ' . $motif . '), effet au ' . date('d/m/Y', strtotime($effet)) . '. Accusé de réception affiché au client.', $quand]);
    tracer('client ' . $c['id'], 'contrat résilié en ligne', $k['numero'] . ' effet ' . $effet);
    if ($c['courriel'] !== '') {
        envoyer_courriel($c['courriel'], 'Maison Flamingo · votre résiliation est enregistrée', "Bonjour {$c['prenom']},\n\nNous avons bien reçu le " . date('d/m/Y à H:i', strtotime($quand)) . " votre demande de résiliation du contrat {$k['numero']}.\nElle prend effet le " . date('d/m/Y', strtotime($effet)) . ".\n\nMerci pour votre confiance.\nMaison Flamingo");
    }
    sortir(200, ['ok' => true, 'recu_le' => $quand, 'effet' => $effet]);
}

function a_contrat_rappel_noter(): void
{
    $u = exiger_equipe(['chef', 'superadmin']);
    exiger_post();
    $k = contrat_par_id(entier(entree()['id'] ?? 0));
    if (!$k || !client_autorise($u, (int) $k['client_id']) || $k['statut'] !== 'signe') {
        sortir(404, ['ok' => false, 'erreur' => 'introuvable']);
    }
    base()->prepare('UPDATE contrats SET rappel_reconduction_le = ? WHERE id = ?')->execute([maintenant(), $k['id']]);
    tracer($u, 'rappel de reconduction envoyé', $k['numero']);
    sortir(200, ['ok' => true]);
}

// Les contrats dont la reconduction approche et dont le client n'a pas encore été prévenu : un oubli la rend contestable.
function rappels_reconduction(?int $agence): array
{
    $q = base()->prepare("SELECT k.*, c.prenom, c.nom AS nom_client, c.agence_id FROM contrats k JOIN clients c ON c.id = k.client_id
        WHERE k.statut = 'signe' AND k.engagement_mois > 0" . ($agence ? ' AND c.agence_id = ?' : ''));
    $q->execute($agence ? [$agence] : []);
    $sortie = [];
    foreach ($q->fetchAll() as $k) {
        $e = prochaine_echeance($k['debut'], (int) $k['engagement_mois']);
        $jours = (int) round((strtotime($e) - strtotime(date('Y-m-d'))) / 86400);
        $deja = $k['rappel_reconduction_le'] && $k['rappel_reconduction_le'] >= date('Y-m-d', strtotime($e . ' -' . RAPPEL_RECONDUCTION[1] . ' days'));
        if ($jours <= RAPPEL_RECONDUCTION[1] && !$deja) {
            $sortie[] = ['numero' => $k['numero'], 'client' => trim($k['prenom'] . ' ' . $k['nom_client']), 'echeance' => $e, 'jours' => $jours, 'en_retard' => $jours < RAPPEL_RECONDUCTION[0]];
        }
    }
    return $sortie;
}
