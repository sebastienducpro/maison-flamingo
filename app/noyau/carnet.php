<?php
// Maison Flamingo · le carnet de la maison.
//
// Sans carnet, l'entretien est invisible : le client paie pour que rien ne se
// passe. Le carnet transforme « rien ne s'est passé » en choses faites, datées,
// photographiées. L'équipe l'écrit (dans sa portée habituelle), le client le lit
// (sa maison seulement), et peut toujours l'exporter : un client qui se sent
// retenu en otage ne recommande personne.

declare(strict_types=1);

const POSTES = [
    'chauffage' => 'Chauffage', 'toiture' => 'Toiture et gouttières', 'piscine' => 'Piscine',
    'joints' => 'Joints et étanchéité', 'plomberie' => 'Eau et plomberie', 'aeration' => 'Aération et VMC',
    'detecteurs' => 'Détecteurs et sécurité', 'clim' => 'Climatisation', 'jardin' => 'Jardin et extérieurs',
];
const ETATS_POSTE = ['bon', 'surveiller', 'prevoir', 'absent'];
const DOCUMENT_MAX = 8000000;

function dossier_documents(int $client): string
{
    return dossier_donnees() . '/documents/' . $client;
}

// À chaque visite, le poste piscine se met à jour tout seul : le carnet ne dépend pas de la mémoire du technicien.
function carnet_piscine_depuis_visite(int $client, array $contenu, int $alertes, string $qui): void
{
    $v = is_array($contenu['valeurs'] ?? null) ? $contenu['valeurs'] : [];
    $morceaux = [];
    foreach (['ph' => 'pH', 'chlore_libre' => 'chlore'] as $k => $l) {
        if (isset($v[$k]) && is_numeric($v[$k])) {
            $morceaux[] = $l . ' ' . str_replace('.', ',', (string) (float) $v[$k]);
        }
    }
    if (!$morceaux && $alertes === 0) {
        return;   // une visite sans relevé d'eau ne dit rien de la piscine
    }
    $etat = $alertes === 0 ? 'bon' : ($alertes > 2 ? 'prevoir' : 'surveiller');
    $detail = implode(' · ', $morceaux) . ($alertes ? ($morceaux ? ' · ' : '') . $alertes . ' point' . ($alertes > 1 ? 's' : '') . ' à reprendre' : '');
    base()->prepare("INSERT INTO carnet_postes (client_id, cle, etat, detail, maj_le, maj_par) VALUES (?, 'piscine', ?, ?, ?, ?)
        ON CONFLICT(client_id, cle) DO UPDATE SET etat = excluded.etat, detail = excluded.detail, maj_le = excluded.maj_le, maj_par = excluded.maj_par")
        ->execute([$client, $etat, $detail, maintenant(), $qui]);
}

function carnet_lire(int $client): array
{
    $db = base();
    $p = $db->prepare('SELECT cle, etat, detail, maj_le FROM carnet_postes WHERE client_id = ?');
    $p->execute([$client]);
    $par = [];
    foreach ($p->fetchAll() as $l) {
        $par[$l['cle']] = $l;
    }
    $postes = [];
    foreach (POSTES as $cle => $libelle) {
        $l = $par[$cle] ?? null;
        $postes[] = ['cle' => $cle, 'libelle' => $libelle, 'etat' => $l['etat'] ?? '', 'detail' => $l['detail'] ?? '', 'maj_le' => $l['maj_le'] ?? null];
    }
    $e = $db->prepare("SELECT id, quand, echeance, titre, detail, organise, statut FROM carnet_evenements WHERE client_id = ? ORDER BY COALESCE(quand, '9999'), id");
    $e->execute([$client]);
    $q = $db->prepare('SELECT id, nom, detail FROM carnet_equipements WHERE client_id = ? ORDER BY id');
    $q->execute([$client]);
    $d = $db->prepare('SELECT id, titre, type, octets, cree_le FROM carnet_documents WHERE client_id = ? ORDER BY id DESC');
    $d->execute([$client]);
    return ['postes' => $postes, 'evenements' => $e->fetchAll(), 'equipements' => $q->fetchAll(), 'documents' => $d->fetchAll()];
}

/* ---------- L'équipe écrit le carnet ---------- */

function a_carnet(): void
{
    $u = exiger_equipe();
    $c = exiger_client_autorise($u, entier($_GET['id'] ?? 0));
    sortir(200, ['ok' => true, 'client' => ['id' => (int) $c['id'], 'prenom' => $c['prenom'], 'nom' => $c['nom'], 'commune' => $c['commune']]] + carnet_lire((int) $c['id']));
}

function a_carnet_poste(): void
{
    $u = exiger_equipe();
    exiger_post();
    $d = entree();
    $c = exiger_client_autorise($u, entier($d['id'] ?? 0));
    $cle = (string) ($d['cle'] ?? '');
    $etat = (string) ($d['etat'] ?? '');
    if (!isset(POSTES[$cle]) || !in_array($etat, array_merge(ETATS_POSTE, ['']), true)) {
        sortir(400, ['ok' => false, 'erreur' => 'donnees']);
    }
    $av = base()->prepare('SELECT * FROM carnet_postes WHERE client_id = ? AND cle = ?');
    $av->execute([$c['id'], $cle]);
    $avant = $av->fetch();
    $detail = texte($d['detail'] ?? '', 200);
    base()->prepare('INSERT INTO carnet_postes (client_id, cle, etat, detail, maj_le, maj_par) VALUES (?,?,?,?,?,?)
        ON CONFLICT(client_id, cle) DO UPDATE SET etat = excluded.etat, detail = excluded.detail, maj_le = excluded.maj_le, maj_par = excluded.maj_par')
        ->execute([$c['id'], $cle, $etat, $detail, maintenant(), $u['nom']]);
    $e = $avant ? garder_version($u, 'carnet_postes', $avant, ['etat' => $etat, 'detail' => $detail], ['etat', 'detail'], POSTES[$cle]) : '';
    tracer($u, 'carnet : poste', "{$c['id']} $cle $etat" . ($e ? " · $e" : ''));
    sortir(200, ['ok' => true]);
}

function a_carnet_evenement(): void
{
    $u = exiger_equipe();
    exiger_post();
    $d = entree();
    $c = exiger_client_autorise($u, entier($d['id'] ?? 0));
    $titre = texte($d['titre'] ?? '', 120);
    if ($titre === '') {
        sortir(200, ['ok' => false, 'erreur' => 'titre']);
    }
    $quand = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($d['quand'] ?? '')) ? (string) $d['quand'] : null;
    $organise = in_array($d['organise'] ?? '', ['nous', 'client', 'choisir'], true) ? $d['organise'] : 'nous';
    $statut = in_array($d['statut'] ?? '', ['prevu', 'fait', 'annule'], true) ? $d['statut'] : 'prevu';
    $valeurs = [$quand, texte($d['echeance'] ?? '', 60), $titre, texte($d['detail'] ?? '', 500), $organise, $statut];
    $db = base();
    $eid = entier($d['evenement_id'] ?? 0);
    $e = '';
    if ($eid > 0) {
        $av = $db->prepare('SELECT * FROM carnet_evenements WHERE id = ? AND client_id = ?');
        $av->execute([$eid, $c['id']]);
        $avant = $av->fetch();
        $q = $db->prepare('UPDATE carnet_evenements SET quand = ?, echeance = ?, titre = ?, detail = ?, organise = ?, statut = ? WHERE id = ? AND client_id = ?');
        $q->execute([...$valeurs, $eid, $c['id']]);
        $e = $avant ? garder_version($u, 'carnet_evenements', $avant, array_combine(['quand', 'echeance', 'titre', 'detail', 'organise', 'statut'], $valeurs), ['quand', 'echeance', 'titre', 'detail', 'organise', 'statut'], $avant['titre']) : '';
    } else {
        $db->prepare('INSERT INTO carnet_evenements (quand, echeance, titre, detail, organise, statut, client_id, cree_le) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([...$valeurs, $c['id'], maintenant()]);
    }
    tracer($u, 'carnet : calendrier', "{$c['id']} $titre" . ($e ? " · $e" : ''));
    sortir(200, ['ok' => true]);
}

function a_carnet_evenement_supprimer(): void
{
    $u = exiger_equipe();
    exiger_post();
    $d = entree();
    $c = exiger_client_autorise($u, entier($d['id'] ?? 0));
    $q = base()->prepare('SELECT * FROM carnet_evenements WHERE id = ? AND client_id = ?');
    $q->execute([entier($d['evenement_id'] ?? 0), $c['id']]);
    if ($ev = $q->fetch()) {
        mettre_a_la_corbeille($u, 'carnet_evenements', $ev, 'efface', $ev['titre']);
        base()->prepare('DELETE FROM carnet_evenements WHERE id = ?')->execute([$ev['id']]);
        tracer($u, 'carnet : calendrier effacé', "{$c['id']} « {$ev['titre']} » " . ($ev['quand'] ?? '') . ', gardé dans la corbeille');
    }
    sortir(200, ['ok' => true]);
}

function a_carnet_equipement(): void
{
    $u = exiger_equipe();
    exiger_post();
    $d = entree();
    $c = exiger_client_autorise($u, entier($d['id'] ?? 0));
    $nom = texte($d['nom'] ?? '', 80);
    if ($nom === '') {
        sortir(200, ['ok' => false, 'erreur' => 'nom']);
    }
    $db = base();
    $qid = entier($d['equipement_id'] ?? 0);
    $e = '';
    if ($qid > 0) {
        $av = $db->prepare('SELECT * FROM carnet_equipements WHERE id = ? AND client_id = ?');
        $av->execute([$qid, $c['id']]);
        $avant = $av->fetch();
        $db->prepare('UPDATE carnet_equipements SET nom = ?, detail = ?, maj_le = ? WHERE id = ? AND client_id = ?')->execute([$nom, texte($d['detail'] ?? '', 200), maintenant(), $qid, $c['id']]);
        $e = $avant ? garder_version($u, 'carnet_equipements', $avant, ['nom' => $nom, 'detail' => texte($d['detail'] ?? '', 200)], ['nom', 'detail'], $avant['nom']) : '';
    } else {
        $db->prepare('INSERT INTO carnet_equipements (client_id, nom, detail, maj_le) VALUES (?,?,?,?)')->execute([$c['id'], $nom, texte($d['detail'] ?? '', 200), maintenant()]);
    }
    tracer($u, 'carnet : équipement', "{$c['id']} $nom" . ($e ? " · $e" : ''));
    sortir(200, ['ok' => true]);
}

function a_carnet_equipement_supprimer(): void
{
    $u = exiger_equipe();
    exiger_post();
    $d = entree();
    $c = exiger_client_autorise($u, entier($d['id'] ?? 0));
    $q = base()->prepare('SELECT * FROM carnet_equipements WHERE id = ? AND client_id = ?');
    $q->execute([entier($d['equipement_id'] ?? 0), $c['id']]);
    if ($eq = $q->fetch()) {
        mettre_a_la_corbeille($u, 'carnet_equipements', $eq, 'efface', $eq['nom']);
        base()->prepare('DELETE FROM carnet_equipements WHERE id = ?')->execute([$eq['id']]);
        tracer($u, 'carnet : équipement effacé', "{$c['id']} « {$eq['nom']} », gardé dans la corbeille");
    }
    sortir(200, ['ok' => true]);
}

function a_carnet_document(): void
{
    $u = exiger_equipe();
    exiger_post();
    $c = exiger_client_autorise($u, entier($_POST['id'] ?? 0));
    $titre = texte($_POST['titre'] ?? '', 120);
    $f = $_FILES['fichier'] ?? null;
    if ($titre === '' || !$f || !is_uploaded_file((string) $f['tmp_name']) || (int) $f['size'] > DOCUMENT_MAX) {
        sortir(200, ['ok' => false, 'erreur' => 'fichier']);
    }
    // Le type se lit dans le fichier lui-même, jamais dans son nom : un « .pdf » peut être n'importe quoi.
    $debut = (string) file_get_contents((string) $f['tmp_name'], false, null, 0, 5);
    $info = @getimagesize((string) $f['tmp_name']);
    if ($debut === '%PDF-') {
        [$type, $ext] = ['application/pdf', 'pdf'];
    } elseif ($info && in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
        [$type, $ext] = [$info['mime'], $info[2] === IMAGETYPE_PNG ? 'png' : 'jpg'];
    } else {
        tracer($u, 'carnet : document refusé', 'type non reconnu');
        sortir(200, ['ok' => false, 'erreur' => 'type']);
    }
    $dossier = dossier_documents((int) $c['id']);
    if (!is_dir($dossier)) {
        @mkdir($dossier, 0700, true);
    }
    $nom = jeton(12) . '.' . $ext;
    if (!move_uploaded_file((string) $f['tmp_name'], $dossier . '/' . $nom)) {
        sortir(500, ['ok' => false, 'erreur' => 'ecriture']);
    }
    base()->prepare('INSERT INTO carnet_documents (client_id, titre, fichier, type, octets, cree_le, cree_par) VALUES (?,?,?,?,?,?,?)')
        ->execute([$c['id'], $titre, $nom, $type, (int) $f['size'], maintenant(), $u['nom']]);
    tracer($u, 'carnet : document ajouté', "{$c['id']} $titre");
    sortir(200, ['ok' => true]);
}

function a_carnet_document_supprimer(): void
{
    $u = exiger_equipe(['chef', 'superadmin']);
    exiger_post();
    $d = entree();
    $c = exiger_client_autorise($u, entier($d['id'] ?? 0));
    $q = base()->prepare('SELECT * FROM carnet_documents WHERE id = ? AND client_id = ?');
    $q->execute([entier($d['document_id'] ?? 0), $c['id']]);
    if ($doc = $q->fetch()) {
        mettre_a_la_corbeille($u, 'carnet_documents', $doc, 'efface', $doc['titre'], [], dossier_documents((int) $c['id']) . '/' . basename((string) $doc['fichier']));
        base()->prepare('DELETE FROM carnet_documents WHERE id = ?')->execute([$doc['id']]);
        tracer($u, 'carnet : document supprimé', "{$c['id']} « {$doc['titre']} », gardé dans la corbeille");
    }
    sortir(200, ['ok' => true]);
}

// Un document se lit par l'équipe qui suit ce client, ou par le client lui-même. Personne d'autre.
function a_document(): void
{
    $q = base()->prepare('SELECT * FROM carnet_documents WHERE id = ?');
    $q->execute([entier($_GET['id'] ?? 0)]);
    $doc = $q->fetch();
    $permis = false;
    if ($doc) {
        $cl = session_client();
        $permis = $cl && (int) $cl['id'] === (int) $doc['client_id'];
        if (!$permis) {
            $u = session_equipe();
            $permis = $u && (!double_facteur_requis($u) || (int) $u['totp_ok'] === 1) && client_autorise($u, (int) $doc['client_id']);
        }
    }
    $chemin = $doc ? dossier_documents((int) $doc['client_id']) . '/' . basename($doc['fichier']) : '';
    if (!$permis || !is_file($chemin)) {
        sortir(404, ['ok' => false]);
    }
    header('Content-Type: ' . $doc['type']);
    header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9 ._-]/', '', $doc['titre']) . '.' . pathinfo($chemin, PATHINFO_EXTENSION) . '"');
    header('Cache-Control: private, no-store');
    header('Content-Length: ' . filesize($chemin));
    readfile($chemin);
    exit;
}

/* ---------- Le client lit son carnet ---------- */

function a_espace_carnet(): void
{
    $c = exiger_client_connecte();
    $db = base();
    $carnet = carnet_lire((int) $c['id']);
    $carnet['postes'] = array_values(array_filter($carnet['postes'], fn ($p) => in_array($p['etat'], ['bon', 'surveiller', 'prevoir'], true)));
    $carnet['evenements'] = array_values(array_filter($carnet['evenements'], fn ($e) => $e['statut'] === 'prevu' && ($e['quand'] === null || $e['quand'] >= date('Y-m-d'))));
    // L'historique : les visites, leur durée, et les photos par le lien du rapport (rien de plus que ce que le rapport montre).
    $v = $db->prepare('SELECT id, type, quand, auteur, jeton, duree_min, alertes, donnees FROM visites WHERE client_id = ? ORDER BY quand DESC LIMIT 30');
    $v->execute([$c['id']]);
    $p = $db->prepare('SELECT id, legende FROM photos WHERE visite_id = ? ORDER BY id LIMIT 6');
    $historique = [];
    foreach ($v->fetchAll() as $x) {
        $contenu = json_decode($x['donnees'], true) ?: [];
        $constats = array_values(array_filter(array_map(fn ($k) => is_array($k) ? trim((string) ($k['texte'] ?? '')) : '', (array) ($contenu['constats'] ?? []))));
        $p->execute([$x['id']]);
        $historique[] = [
            'type' => $x['type'], 'quand' => $x['quand'], 'technicien' => explode(' ', (string) $x['auteur'])[0], 'duree_min' => $x['duree_min'] !== null ? (int) $x['duree_min'] : null,
            'resume' => implode(' ', array_slice($constats, 0, 3)), 'points' => (int) $x['alertes'], 'lien' => url_rapport($x['jeton']),
            'photos' => array_map(fn ($ph) => ['url' => 'api.php?action=photo&id=' . $ph['id'] . '&t=' . $x['jeton'], 'legende' => $ph['legende']], $p->fetchAll()),
        ];
    }
    sortir(200, ['ok' => true, 'client' => ['prenom' => $c['prenom'], 'commune' => $c['commune'], 'formule' => $c['formule'], 'statut' => $c['statut'], 'jour' => $c['statut'] === 'client' ? (JOURS[(int) $c['jour_passage']] ?? null) : null],
        'historique' => $historique, 'equipe' => equipe_du_client($c), 'telephone' => TELEPHONE, 'demandes' => demandes_du_client((int) $c['id'])] + $carnet);
}

// Les messages du client et les réponses de l'équipe : écrire sans jamais lire la réponse ne sert à rien.
function demandes_du_client(int $client): array
{
    $q = base()->prepare('SELECT texte, cree_le, traitee_le, reponse FROM demandes WHERE client_id = ? ORDER BY id DESC LIMIT 10');
    $q->execute([$client]);
    return $q->fetchAll();
}

function a_espace_creneau(): void
{
    // « Choisir un créneau » devient une demande à l'équipe : le client n'écrit rien, il clique.
    $c = exiger_client_connecte();
    exiger_post();
    $q = base()->prepare("SELECT titre FROM carnet_evenements WHERE id = ? AND client_id = ? AND statut = 'prevu'");
    $q->execute([entier(entree()['evenement_id'] ?? 0), $c['id']]);
    $titre = $q->fetchColumn();
    if ($titre === false) {
        sortir(404, ['ok' => false]);
    }
    base()->prepare('INSERT INTO demandes (client_id, texte, cree_le) VALUES (?,?,?)')
        ->execute([$c['id'], 'Créneau à proposer : ' . $titre, maintenant()]);
    tracer('client ' . $c['id'], 'demande de créneau', (string) $titre);
    sortir(200, ['ok' => true]);
}

// La saison type : les rendez-vous qu'une formule implique, posés d'un geste. Jamais en double.
function a_carnet_saison(): void
{
    $u = exiger_equipe();
    exiger_post();
    $c = exiger_client_autorise($u, entier(entree()['id'] ?? 0));
    $annee = (int) date('Y');
    $mois = (int) date('n');
    $prochaine = fn (int $m, int $j) => sprintf('%04d-%02d-%02d', $annee + ($mois > $m ? 1 : 0), $m, $j);
    $piscine = str_contains(mb_strtolower($c['formule']), 'piscine');
    $maison = str_contains(mb_strtolower($c['formule']), 'maison') || str_contains(mb_strtolower($c['formule']), 'résidence');
    $modeles = [];
    if ($piscine) {
        $modeles[] = ['titre' => 'Hivernage de la piscine', 'quand' => $prochaine(11, 25), 'detail' => 'Le flamant rentre, la bâche se pose. L\'eau est équilibrée pour l\'hiver.'];
        $modeles[] = ['titre' => 'Contrôle d\'hiver', 'quand' => $prochaine(1, 15), 'detail' => 'Bâche et niveau d\'eau après le gel, tour du local technique.'];
        $modeles[] = ['titre' => 'Remise en route de la piscine', 'quand' => $prochaine(3, 25), 'detail' => 'Le flamant revient sur l\'eau. Filtre, produits, premiers réglages.'];
    }
    if ($maison) {
        $modeles[] = ['titre' => 'Entretien de la chaudière', 'echeance' => 'avant le 15 novembre', 'detail' => 'Chauffagiste partenaire. Nous ouvrons la maison et restons sur place ; l\'attestation arrive dans vos documents.'];
        $modeles[] = ['titre' => 'Visite de printemps', 'quand' => $prochaine(4, 10), 'detail' => 'Climatisation, volets, détecteurs, aération.'];
    }
    if (!$modeles) {
        sortir(200, ['ok' => false, 'erreur' => 'formule']);
    }
    $db = base();
    $existe = $db->prepare("SELECT COUNT(*) FROM carnet_evenements WHERE client_id = ? AND titre = ? AND statut = 'prevu'");
    $ins = $db->prepare("INSERT INTO carnet_evenements (client_id, quand, echeance, titre, detail, organise, statut, cree_le) VALUES (?,?,?,?,?, 'nous', 'prevu', ?)");
    $poses = 0;
    foreach ($modeles as $m) {
        $existe->execute([$c['id'], $m['titre']]);
        if ((int) $existe->fetchColumn() > 0) {
            continue;
        }
        $ins->execute([$c['id'], $m['quand'] ?? null, $m['echeance'] ?? '', $m['titre'], $m['detail'], maintenant()]);
        $poses++;
    }
    tracer($u, 'carnet : saison type', "{$c['id']} $poses rendez-vous");
    sortir(200, ['ok' => true, 'poses' => $poses]);
}
