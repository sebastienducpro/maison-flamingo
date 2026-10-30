<?php
// Maison Flamingo · les factures mensuelles et leur prélèvement SEPA par GoCardless.
//
// Chaque mois, une facture par contrat signé en cours, numérotée sans trou, figée avec son empreinte ; puis un
// prélèvement sur le mandat du client. GoCardless garde les IBAN et les mandats : l'outil n'en voit jamais un.
// Les notifications de GoCardless (paiement confirmé, rejeté) mettent les factures à jour d'elles-mêmes, après
// vérification de leur signature.
// Rien ne part tant que l'identité de la société est incomplète ou que les accès GoCardless ne sont pas posés,
// et l'accès se pose d'abord en bac à sable : on ne prélève pas de vrais clients avec un circuit jamais éprouvé.
// Deux sociétés, deux créanciers : chacune a son compte GoCardless, sa numérotation de factures, et un client du pack
// signe deux mandats, car un mandat SEPA n'appartient qu'à un seul créancier.

declare(strict_types=1);

const PAIEMENT_FICHIER = 'paiement-gocardless-%s.json';
const GOCARDLESS_VERSION = '2015-07-06';
const GOCARDLESS_POINTS = ['sandbox' => 'https://api-sandbox.gocardless.com', 'live' => 'https://api.gocardless.com'];

function paiement_config(string $soc): ?array
{
    if (!isset(SOCIETES[$soc])) {
        return null;
    }
    $f = dossier_donnees() . '/' . sprintf(PAIEMENT_FICHIER, $soc);
    if (!is_file($f)) {
        return null;
    }
    $c = json_decode((string) file_get_contents($f), true);
    if (!is_array($c) || !isset(GOCARDLESS_POINTS[$c['environnement'] ?? '']) || ($c['jeton'] ?? '') === '' || ($c['secret_webhook'] ?? '') === '') {
        return null;
    }
    // Le banc d'essai remplace l'adresse par un faux GoCardless local ; en ligne, seules les deux adresses officielles servent.
    $c['point'] = preg_match('#^http://(localhost|127\.0\.0\.1)(:\d+)?$#', (string) ($c['point'] ?? '')) ? $c['point'] : GOCARDLESS_POINTS[$c['environnement']];
    return $c;
}

function gocardless(array $c, string $methode, string $chemin, ?array $corps = null, ?string $idempotence = null): array
{
    $ch = curl_init($c['point'] . $chemin);
    $entetes = ['Authorization: Bearer ' . $c['jeton'], 'GoCardless-Version: ' . GOCARDLESS_VERSION, 'Accept: application/json', 'Content-Type: application/json'];
    if ($idempotence) {
        // Rejouer la même demande ne crée jamais deux prélèvements.
        $entetes[] = 'Idempotency-Key: ' . $idempotence;
    }
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $methode, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => $entetes]);
    if ($corps !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($corps));
    }
    $brut = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $d = json_decode($brut, true);
    if ($code < 200 || $code >= 300 || !is_array($d)) {
        $msg = is_array($d) ? (string) ($d['error']['message'] ?? 'erreur') : 'injoignable';
        throw new RuntimeException("GoCardless $code : " . mb_substr($msg, 0, 200));
    }
    return $d;
}

// Le taux de TVA de chaque société : vide en franchise, la mention de la société s'affiche alors.
function taux_tva(string $soc): ?float
{
    $v = trim(parametre("facturation.$soc.taux_tva"));
    return $v === '' ? null : (float) str_replace(',', '.', $v);
}

/* ---------- Le mandat : posé par le client sur la page de GoCardless ---------- */

function mandat_actif(int $client, string $soc): ?array
{
    $q = base()->prepare("SELECT * FROM mandats WHERE client_id = ? AND societe = ? AND statut = 'actif' ORDER BY id DESC LIMIT 1");
    $q->execute([$client, $soc]);
    return $q->fetch() ?: null;
}

function ouvrir_mandat(array $c, string $soc): string
{
    $g = paiement_config($soc);
    if (!$g) {
        throw new RuntimeException('prélèvement non configuré');
    }
    $br = gocardless($g, 'POST', '/billing_requests', ['billing_requests' => ['mandate_request' => ['scheme' => 'sepa_core'], 'metadata' => ['client' => (string) $c['id'], 'societe' => $soc]]], 'mandat-' . $soc . '-' . $c['id'] . '-' . date('YmdH'));
    $id = $br['billing_requests']['id'];
    $flux = gocardless($g, 'POST', '/billing_request_flows', ['billing_request_flows' => [
        'redirect_uri' => url_base() . '/espace.html#/contrat', 'exit_uri' => url_base() . '/espace.html#/contrat',
        'prefilled_customer' => array_filter(['given_name' => $c['prenom'], 'family_name' => $c['nom'], 'email' => $c['courriel']]),
        'links' => ['billing_request' => $id]]]);
    base()->prepare("INSERT INTO mandats (client_id, demande, statut, cree_le, maj_le, societe) VALUES (?,?, 'en_attente', ?,?,?)")->execute([$c['id'], $id, maintenant(), maintenant(), $soc]);
    return $flux['billing_request_flows']['authorisation_url'];
}

function a_espace_mandat(): void
{
    $c = exiger_client_connecte();
    exiger_post();
    $soc = (string) (entree()['societe'] ?? '');
    if (!isset(SOCIETES[$soc])) {
        sortir(200, ['ok' => false, 'erreur' => 'societe']);
    }
    if (mandat_actif((int) $c['id'], $soc)) {
        sortir(200, ['ok' => false, 'erreur' => 'deja']);
    }
    try {
        $url = ouvrir_mandat($c, $soc);
    } catch (Throwable $e) {
        tracer('client ' . $c['id'], 'mandat non ouvert', $e->getMessage());
        sortir(200, ['ok' => false, 'erreur' => 'indisponible']);
    }
    tracer('client ' . $c['id'], 'mandat SEPA demandé', SOCIETES[$soc]);
    sortir(200, ['ok' => true, 'url' => $url]);
}

/* ---------- Les factures ---------- */

// Le montant d'un contrat pour un mois : rien avant son début, rien après sa fin, rien le premier mois d'un fondateur.
function a_facturer(array $k, string $mois): ?int
{
    $debut_mois = $mois . '-01';
    $fin_mois = date('Y-m-t', strtotime($debut_mois));
    if ($k['debut'] > $fin_mois || in_array($k['statut'], ['a_signer', 'annule', 'retracte'], true)) {
        return null;
    }
    if ($k['statut'] === 'resilie' && $k['resiliation_effet'] && $k['resiliation_effet'] < $debut_mois) {
        return null;
    }
    // Payé à l'année : une seule facture, le mois du début puis chaque mois anniversaire ; le premier mois offert est déjà compté dedans.
    if (($k['modalite'] ?? 'mensuel') === 'annuel') {
        if (substr($k['debut'], 5, 2) !== substr($mois, 5, 2) || substr($k['debut'], 0, 7) > $mois) {
            return null;
        }
        // La première année est celle du contrat, mois offert compris ; les suivantes comptent douze mois, au prix du moment
        // (une piscine dont la Maison a pris fin repasse au prix hors pack).
        return substr($k['debut'], 0, 7) === $mois ? (int) round((float) $k['montant_annuel'] * 100)
            : (int) round(round(prix_du_mois($k, $mois) * 12 * (1 - REMISE_ANNUELLE_PCT / 100)) * 100);
    }
    if ((int) $k['fondateur'] === 1 && substr($k['debut'], 0, 7) === $mois) {
        return null;
    }
    return (int) round(prix_du_mois($k, $mois) * 100);
}

// La piscine d'un pack garde sa remise tant que le contrat Maison court ; s'il prend fin, elle repasse à son prix
// hors pack à l'échéance suivante de la piscine, comme le contrat le dit.
function prix_du_mois(array $k, string $mois): float
{
    if ((float) ($k['remise_pack'] ?? 0) <= 0 || !$k['lien_contrat']) {
        return (float) $k['prix_mensuel'];
    }
    $m = contrat_par_id((int) $k['lien_contrat']);
    $fin = !$m ? $k['debut'] : ($m['statut'] === 'retracte' ? $m['debut'] : ($m['statut'] === 'resilie' ? $m['resiliation_effet'] : null));
    if (!$fin) {
        return (float) $k['prix_mensuel'];
    }
    // L'échéance de la piscine qui suit la fin de la Maison.
    $e = $k['debut'];
    do {
        $e = date('Y-m-d', strtotime($e . ' +' . max(1, (int) $k['engagement_mois']) . ' months'));
    } while ($e <= $fin);
    return $mois . '-01' >= substr($e, 0, 7) . '-01' ? (float) $k['prix_hors_pack'] : (float) $k['prix_mensuel'];
}

function rendre_facture(array $s, array $c, array $k, array $f, ?float $tva): string
{
    $ttc = $f['montant'] / 100;
    $ht = $tva !== null ? $ttc / (1 + $tva / 100) : null;
    $fmt = fn ($n) => number_format($n, 2, ',', ' ') . ' €';
    $mois = date_longue($f['periode'] . '-01');
    $h = ['<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Facture ' . esc($f['numero']) . '</title><link rel="stylesheet" href="contrat.css?v=2"></head><body><main class="contrat">'];
    $h[] = '<header><p class="marque">Maison <strong>Flamingo</strong></p><h1>Facture n° ' . esc($f['numero']) . '</h1><p class="sous">Émise le ' . esc(date_longue($f['emise_le'])) . '</p></header>';
    $h[] = '<p><strong>' . esc($s['raison_sociale']) . '</strong>, ' . esc($s['forme']) . '<br>' . esc($s['adresse']) . '<br>SIRET ' . esc($s['siret']) . ' · ' . esc($s['rcs']) . '</p>';
    $h[] = '<p>Facturé à <strong>' . esc(trim($c['prenom'] . ' ' . $c['nom'])) . '</strong><br>' . esc(trim($c['adresse'] . ', ' . $c['commune'], ', ')) . '</p>';
    if (($k['modalite'] ?? 'mensuel') === 'annuel') {
        $du = substr($k['debut'], 0, 4) === substr($f['periode'], 0, 4) ? $k['debut'] : $f['periode'] . substr($k['debut'], 7);
        $au = date('Y-m-d', strtotime($du . ' +12 months -1 day'));
        $h[] = '<h2>Désignation</h2><p>Abonnement annuel ' . esc($k['formule']) . ', contrat n° ' . esc($k['numero']) . ', du ' . esc(date_longue($du)) . ' au ' . esc(date_longue($au)) . ', payé d\'avance avec ' . (int) REMISE_ANNUELLE_PCT . ' % de remise.</p>';
    } else {
        $h[] = '<h2>Désignation</h2><p>Abonnement ' . esc($k['formule']) . ', contrat n° ' . esc($k['numero']) . ', mois de ' . esc(substr($mois, strpos($mois, ' ') + 1)) . '.</p>';
    }
    $h[] = $ht !== null
        ? '<p>Montant hors taxes : ' . $fmt($ht) . '<br>TVA à ' . esc(rtrim(rtrim(number_format($tva, 2, ',', ''), '0'), ',')) . ' % : ' . $fmt($ttc - $ht) . '<br><strong>Total TTC : ' . $fmt($ttc) . '</strong></p>'
        : '<p><strong>Total : ' . $fmt($ttc) . '</strong><br>' . esc($s['mention_tva']) . '</p>';
    if (($k['societe'] ?? 'piscine') === 'maison' && trim($s['sap_numero'] ?? '') !== '') {
        $h[] = '<p>Services à la personne, déclaration n° ' . esc($s['sap_numero']) . '. Selon la législation en vigueur, ces sommes ouvrent droit au crédit d\'impôt de l\'article 199 sexdecies du Code général des impôts ; une attestation fiscale annuelle est délivrée.</p>';
    }
    $h[] = '<p>Réglée par prélèvement SEPA sur le mandat du client, à l\'échéance indiquée dans son espace.</p>';
    $h[] = '</main></body></html>';
    return implode("\n", $h);
}

function dossier_factures(): string
{
    $d = dossier_donnees() . '/factures';
    if (!is_dir($d)) {
        @mkdir($d, 0700, true);
    }
    return $d;
}

// Prépare (sans rien émettre) ou émet les factures d'un mois. Une facture par contrat et par mois, jamais deux.
function factures_du_mois(string $mois, bool $emettre, string $par): array
{
    $db = base();
    $q = $db->query("SELECT k.*, c.prenom, c.nom, c.adresse, c.commune, c.courriel FROM contrats k JOIN clients c ON c.id = k.client_id
        WHERE k.statut IN ('signe', 'resilie') ORDER BY k.numero");
    $lignes = [];
    foreach ($q->fetchAll() as $k) {
        $soc = $k['societe'] ?? 'piscine';
        $deja = $db->prepare('SELECT numero FROM factures WHERE contrat_id = ? AND periode = ?');
        $deja->execute([$k['id'], $mois]);
        $n = $deja->fetchColumn();
        $montant = a_facturer($k, $mois);
        if ($montant === null) {
            continue;
        }
        $manques = manques_societe($soc);
        $ligne = ['contrat' => $k['numero'], 'societe' => $soc, 'client' => trim($k['prenom'] . ' ' . $k['nom']), 'montant' => $montant, 'facture' => $n ?: null,
            'mandat' => (bool) mandat_actif((int) $k['client_id'], $soc), 'societe_incomplete' => (bool) $manques];
        // Refus par défaut : une société à l'identité incomplète n'émet rien, l'autre continue.
        if ($emettre && !$n && !$manques) {
            $db->beginTransaction();
            $c = $db->prepare("SELECT COUNT(*) FROM factures WHERE numero LIKE ?");
            $annee = substr($mois, 0, 4);
            $prefixe = 'F' . PREFIXES[$soc];
            $c->execute(["$prefixe-$annee-%"]);
            $f = ['numero' => sprintf('%s-%s-%06d', $prefixe, $annee, (int) $c->fetchColumn() + 1), 'periode' => $mois, 'montant' => $montant, 'emise_le' => date('Y-m-d')];
            $html = rendre_facture(parametres_societe($soc), $k, $k, $f, taux_tva($soc));
            file_put_contents(dossier_factures() . '/' . $f['numero'] . '.html', $html, LOCK_EX);
            $db->prepare("INSERT INTO factures (client_id, contrat_id, numero, periode, montant, emise_le, fichier, empreinte, statut, cree_par, societe) VALUES (?,?,?,?,?,?,?,?, 'emise', ?, ?)")
                ->execute([$k['client_id'], $k['id'], $f['numero'], $mois, $montant, $f['emise_le'], $f['numero'] . '.html', hash('sha256', $html), $par, $soc]);
            $db->commit();
            $ligne['facture'] = $f['numero'];
        }
        $lignes[] = $ligne;
    }
    return ['ok' => true, 'mois' => $mois, 'lignes' => $lignes, 'total' => array_sum(array_column($lignes, 'montant'))];
}

// Demande le prélèvement de chaque facture émise et pas encore prélevée, sur un mandat actif.
function prelever_factures(string $mois): array
{
    $ok = 0;
    $echecs = [];
    $configurees = 0;
    foreach (array_keys(SOCIETES) as $soc) {
        $g = paiement_config($soc);
        if (!$g) {
            continue;
        }
        $configurees++;
        // Chaque facture se prélève sur le mandat de sa propre société, jamais sur celui de l'autre.
        $q = base()->prepare("SELECT f.*, m.reference AS mandat FROM factures f JOIN mandats m ON m.client_id = f.client_id AND m.societe = f.societe AND m.statut = 'actif'
            WHERE f.periode = ? AND f.societe = ? AND f.statut = 'emise' AND f.paiement IS NULL");
        $q->execute([$mois, $soc]);
        foreach ($q->fetchAll() as $f) {
            try {
                $p = gocardless($g, 'POST', '/payments', ['payments' => ['amount' => (int) $f['montant'], 'currency' => 'EUR', 'description' => 'Maison Flamingo ' . $f['numero'],
                    'metadata' => ['facture' => $f['numero']], 'links' => ['mandate' => $f['mandat']]]], 'facture-' . $f['numero']);
                base()->prepare("UPDATE factures SET paiement = ?, statut = 'prelevement_demande', prelevement_le = ? WHERE id = ?")->execute([$p['payments']['id'], $p['payments']['charge_date'] ?? null, $f['id']]);
                $ok++;
            } catch (Throwable $e) {
                $echecs[] = $f['numero'] . ' : ' . $e->getMessage();
            }
        }
    }
    if (!$configurees) {
        return ['ok' => false, 'erreur' => 'non_configure'];
    }
    tracer('systeme', 'prélèvements demandés', "$mois : $ok demandé(s)" . ($echecs ? ', ' . count($echecs) . ' échec(s)' : ''));
    return ['ok' => true, 'demandes' => $ok, 'echecs' => $echecs];
}

/* ---------- Les notifications de GoCardless ---------- */

// GoCardless signe le corps brut avec le secret de l'adresse de notification : on recalcule, on compare, sinon 498.
// Les notifications ne disent que « quelque chose a changé » : l'état se relit chez GoCardless, l'ordre des événements n'est pas garanti.
function a_gocardless_webhook(): void
{
    // Chaque société déclare sa propre adresse de notification, avec son propre secret.
    $g = paiement_config((string) ($_GET['societe'] ?? ''));
    $brut = (string) file_get_contents('php://input', false, null, 0, 1000000);
    $signature = (string) ($_SERVER['HTTP_WEBHOOK_SIGNATURE'] ?? '');
    if (!$g || $signature === '' || !hash_equals(hash_hmac('sha256', $brut, $g['secret_webhook']), $signature)) {
        tracer('gocardless', 'notification refusée', 'signature');
        http_response_code(498);
        exit;
    }
    $d = json_decode($brut, true);
    foreach ((array) ($d['events'] ?? []) as $ev) {
        try {
            traiter_evenement($g, $ev);
        } catch (Throwable $e) {
            tracer('gocardless', 'notification non traitée', ($ev['id'] ?? '?') . ' : ' . $e->getMessage());
            sortir(500, ['ok' => false]);   // GoCardless réessaiera : rien n'est perdu
        }
    }
    sortir(200, ['ok' => true]);
}

function traiter_evenement(array $g, array $ev): void
{
    $type = $ev['resource_type'] ?? '';
    $links = $ev['links'] ?? [];
    $db = base();
    if ($type === 'payments' && !empty($links['payment'])) {
        $p = gocardless($g, 'GET', '/payments/' . rawurlencode($links['payment']))['payments'];
        $statut = ['confirmed' => 'payee', 'paid_out' => 'payee', 'failed' => 'rejetee', 'charged_back' => 'rejetee', 'cancelled' => 'emise'][$p['status']] ?? null;
        if ($statut) {
            $db->prepare("UPDATE factures SET statut = ?, paye_le = CASE WHEN ? = 'payee' THEN COALESCE(paye_le, ?) ELSE paye_le END, rejet_motif = ?, paiement = CASE WHEN ? = 'emise' THEN NULL ELSE paiement END WHERE paiement = ?")
                ->execute([$statut, $statut, maintenant(), $statut === 'rejetee' ? mb_substr((string) ($ev['details']['description'] ?? $p['status']), 0, 200) : '', $statut, $p['id']]);
            tracer('gocardless', 'paiement ' . $p['status'], $p['id']);
        }
    } elseif ($type === 'billing_requests' && !empty($links['billing_request'])) {
        $br = gocardless($g, 'GET', '/billing_requests/' . rawurlencode($links['billing_request']))['billing_requests'];
        $mandat = $br['links']['mandate_request_mandate'] ?? null;
        if ($mandat) {
            $db->prepare("UPDATE mandats SET reference = ?, maj_le = ? WHERE demande = ?")->execute([$mandat, maintenant(), $br['id']]);
            $m = gocardless($g, 'GET', '/mandates/' . rawurlencode($mandat))['mandates'];
            maj_mandat($m);
        }
    } elseif ($type === 'mandates' && !empty($links['mandate'])) {
        maj_mandat(gocardless($g, 'GET', '/mandates/' . rawurlencode($links['mandate']))['mandates']);
    }
}

function maj_mandat(array $m): void
{
    $statut = in_array($m['status'], ['active', 'submitted', 'pending_submission'], true) ? 'actif' : (in_array($m['status'], ['failed', 'cancelled', 'expired', 'blocked'], true) ? 'echec' : 'en_attente');
    base()->prepare('UPDATE mandats SET statut = ?, maj_le = ? WHERE reference = ?')->execute([$statut, maintenant(), $m['id']]);
    tracer('gocardless', 'mandat ' . $m['status'], $m['id']);
}

/* ---------- Les écrans ---------- */

function a_facturation(): void
{
    exiger_equipe(['superadmin']);
    $mois = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['mois'] ?? '')) ? $_GET['mois'] : date('Y-m');
    $q = base()->prepare("SELECT f.numero, f.societe, f.periode, f.montant, f.statut, f.emise_le, f.paye_le, f.rejet_motif, f.id, c.prenom, c.nom FROM factures f JOIN clients c ON c.id = f.client_id WHERE f.periode = ? ORDER BY f.numero");
    $q->execute([$mois]);
    $societes = [];
    foreach (SOCIETES as $soc => $nom) {
        $g = paiement_config($soc);
        $societes[$soc] = ['nom' => $nom, 'configure' => (bool) $g, 'environnement' => $g['environnement'] ?? null, 'manques' => manques_societe($soc), 'taux_tva' => taux_tva($soc),
            'adresse_webhook' => url_base() . '/api.php?action=gocardless_webhook&societe=' . $soc];
    }
    sortir(200, ['ok' => true, 'mois' => $mois, 'factures' => $q->fetchAll(), 'apercu' => factures_du_mois($mois, false, ''), 'societes' => $societes,
        'automatique' => base()->query("SELECT valeur FROM parametres WHERE cle = 'facturation.automatique'")->fetchColumn() === '1']);
}

function a_facturation_configurer(): void
{
    $u = exiger_equipe(['superadmin']);
    exiger_post();
    $d = entree();
    $soc = (string) ($d['societe'] ?? '');
    if (!isset(SOCIETES[$soc])) {
        sortir(200, ['ok' => false, 'erreur' => 'societe']);
    }
    $env = (string) ($d['environnement'] ?? '');
    $jeton = texte($d['jeton'] ?? '', 200);
    $secret = texte($d['secret_webhook'] ?? '', 200);
    if (!isset(GOCARDLESS_POINTS[$env]) || $jeton === '' || $secret === '') {
        sortir(200, ['ok' => false, 'erreur' => 'champs']);
    }
    // Un jeton de bac à sable commence par « sandbox_ » : on ne pose pas un jeton réel dans le bac à sable, ni l'inverse.
    if (($env === 'sandbox') !== str_starts_with($jeton, 'sandbox_')) {
        sortir(200, ['ok' => false, 'erreur' => 'environnement']);
    }
    $v = ['environnement' => $env, 'jeton' => $jeton, 'secret_webhook' => $secret];
    $point = (string) ($d['point'] ?? '');
    if ($point !== '' && preg_match('#^http://(localhost|127\.0\.0\.1)(:\d+)?$#', $point)) {
        $v['point'] = $point;
    }
    $f = dossier_donnees() . '/' . sprintf(PAIEMENT_FICHIER, $soc);
    file_put_contents($f, json_encode($v), LOCK_EX);
    @chmod($f, 0600);
    $taux = trim((string) ($d['taux_tva'] ?? ''));
    poser_parametre("facturation.$soc.taux_tva", preg_match('/^\d{1,2}([.,]\d{1,2})?$/', $taux) ? $taux : '', $u['courriel']);
    tracer($u, 'prélèvement configuré', SOCIETES[$soc] . ', ' . $env);
    sortir(200, ['ok' => true]);
}

function a_facturation_emettre(): void
{
    $u = exiger_equipe(['superadmin']);
    exiger_post();
    $mois = (string) (entree()['mois'] ?? '');
    // On facture le mois en cours ou le précédent, jamais l'avenir : une facture émise ne se reprend pas.
    if (!in_array($mois, [date('Y-m'), date('Y-m', strtotime('first day of last month'))], true)) {
        sortir(200, ['ok' => false, 'erreur' => 'mois']);
    }
    $r = factures_du_mois($mois, true, $u['courriel']);
    if (!$r['ok']) {
        sortir(200, $r);
    }
    tracer($u, 'factures émises', "$mois : " . count($r['lignes']) . ' ligne(s), ' . $r['total'] / 100 . ' €');
    $r['prelevements'] = prelever_factures($mois);
    sortir(200, $r);
}

function a_facture_document(): void
{
    $q = base()->prepare('SELECT * FROM factures WHERE id = ?');
    $q->execute([entier($_GET['id'] ?? 0)]);
    $f = $q->fetch();
    $client = session_client();
    $u = $client ? null : session_equipe();
    $autorise = $f && (($client && (int) $client['id'] === (int) $f['client_id']) || ($u && $u['role'] !== 'technicien' && (!double_facteur_requis($u) || (int) $u['totp_ok'] === 1) && client_autorise($u, (int) $f['client_id'])));
    if (!$autorise) {
        sortir(404, ['ok' => false, 'erreur' => 'introuvable']);
    }
    $html = (string) @file_get_contents(dossier_factures() . '/' . basename($f['fichier']));
    if ($html === '' || !hash_equals($f['empreinte'], hash('sha256', $html))) {
        sortir(409, ['ok' => false, 'erreur' => 'empreinte']);
    }
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: private, no-store');
    echo $html;
    exit;
}

function a_espace_factures(): void
{
    $c = exiger_client_connecte();
    $q = base()->prepare("SELECT id, numero, societe, periode, montant, statut, emise_le, paye_le FROM factures WHERE client_id = ? ORDER BY id DESC");
    $q->execute([$c['id']]);
    // Un mandat par société dont le client a un contrat signé : le pack en demande deux.
    $k = base()->prepare("SELECT DISTINCT societe FROM contrats WHERE client_id = ? AND statut = 'signe'");
    $k->execute([$c['id']]);
    $m = base()->prepare('SELECT statut FROM mandats WHERE client_id = ? AND societe = ? ORDER BY id DESC LIMIT 1');
    $mandats = [];
    foreach ($k->fetchAll(PDO::FETCH_COLUMN) as $soc) {
        $m->execute([$c['id'], $soc]);
        $mandats[] = ['societe' => $soc, 'nom' => SOCIETES[$soc] ?? $soc, 'statut' => $m->fetchColumn() ?: null, 'possible' => (bool) paiement_config($soc)];
    }
    sortir(200, ['ok' => true, 'factures' => $q->fetchAll(), 'mandats' => $mandats]);
}

// Le 1er du mois, si la direction l'a décidé : les factures du mois partent seules, puis leurs prélèvements.
// Désactivé par défaut : on prélève des clients seulement après avoir vu un premier mois passer à la main.
function facturation_automatique_du_mois(): array
{
    $q = base()->prepare("SELECT valeur FROM parametres WHERE cle = 'facturation.automatique'");
    $q->execute();
    if ($q->fetchColumn() !== '1' || (int) date('j') !== 1) {
        return ['faite' => false];
    }
    $r = factures_du_mois(date('Y-m'), true, 'tâche planifiée');
    if (!$r['ok']) {
        tracer('systeme', 'facturation automatique refusée', $r['erreur']);
        return ['faite' => false, 'erreur' => $r['erreur']];
    }
    return ['faite' => true, 'lignes' => count($r['lignes']), 'prelevements' => prelever_factures(date('Y-m'))];
}

function a_facturation_automatique(): void
{
    $u = exiger_equipe(['superadmin']);
    exiger_post();
    $oui = !empty(entree()['actif']) ? '1' : '0';
    if ($oui === '1' && !paiement_config('piscine') && !paiement_config('maison')) {
        sortir(200, ['ok' => false, 'erreur' => 'non_configure']);
    }
    base()->prepare("INSERT INTO parametres (cle, valeur, maj_le, maj_par) VALUES ('facturation.automatique', ?, ?, ?) ON CONFLICT(cle) DO UPDATE SET valeur = excluded.valeur, maj_le = excluded.maj_le, maj_par = excluded.maj_par")
        ->execute([$oui, maintenant(), $u['courriel']]);
    tracer($u, $oui === '1' ? 'facturation automatique activée' : 'facturation automatique coupée');
    sortir(200, ['ok' => true, 'actif' => $oui === '1']);
}
