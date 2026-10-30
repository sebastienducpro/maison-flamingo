<?php
// Maison Flamingo · la fiche du technicien et ses demandes de congés.
//
// La fiche porte ce qui change sa journée : ses habilitations et leur échéance, sa tenue, qui prévenir s'il lui arrive
// quelque chose seul chez un client. Elle ne porte ni contrat, ni salaire, ni motif médical : ceux-là vivent dans la paie.
// Une demande de congés se fait depuis le téléphone, se décide par le responsable, et devient une absence une fois acceptée :
// la tournée se couvre alors depuis l'écran Absences, comme pour toute absence.

declare(strict_types=1);

const QUALIFICATIONS = [
    'permis' => 'Permis B', 'sst' => 'Sauveteur secouriste du travail', 'electrique' => 'Habilitation électrique',
    'produits' => 'Formation aux produits chimiques', 'formation' => 'Formation interne', 'autre' => 'Autre',
];
const ECHEANCE_PROCHE_JOURS = 30;   // un mois pour inscrire quelqu'un à un recyclage avant qu'il ne tombe
const MOTIFS_CONGE = ['Congés', 'Récupération', 'Formation', 'Autre'];
const CONGE_REPONSE_JOURS = 5;      // une demande sans réponse au-delà devient une alerte au pilotage

// La fiche de soi, ou celle d'un membre que l'on gère. Personne d'autre : un technicien ne lit pas la fiche d'un collègue.
function fiche_visible(array $u, int $id): ?array
{
    if ($id === 0 || $id === (int) $u['id']) {
        $q = base()->prepare('SELECT * FROM utilisateurs WHERE id = ?');
        $q->execute([$u['id']]);
        return $q->fetch() ?: null;
    }
    return membre_gerable($u, $id);
}

function etat_echeance(?string $expire): string
{
    if (!$expire) {
        return 'permanente';
    }
    if ($expire < date('Y-m-d')) {
        return 'expiree';
    }
    return $expire <= date('Y-m-d', strtotime('+' . ECHEANCE_PROCHE_JOURS . ' days')) ? 'bientot' : 'valide';
}

function qualifications_de(int $utilisateur): array
{
    $q = base()->prepare('SELECT * FROM qualifications WHERE utilisateur_id = ? ORDER BY expire_le IS NULL, expire_le, type');
    $q->execute([$utilisateur]);
    return array_map(fn ($x) => ['id' => (int) $x['id'], 'type' => $x['type'], 'libelle' => QUALIFICATIONS[$x['type']] ?? $x['type'], 'intitule' => $x['intitule'],
        'obtenue_le' => $x['obtenue_le'], 'expire_le' => $x['expire_le'], 'note' => $x['note'], 'etat' => etat_echeance($x['expire_le'])], $q->fetchAll());
}

function demande_publique(array $d): array
{
    return ['id' => (int) $d['id'], 'utilisateur_id' => (int) $d['utilisateur_id'], 'nom' => $d['nom'] ?? null, 'du' => $d['du'], 'au' => $d['au'],
        'motif' => $d['motif'], 'message' => $d['message'], 'statut' => $d['statut'], 'reponse' => $d['reponse'], 'decide_par' => $d['decide_par'],
        'decide_le' => $d['decide_le'], 'cree_le' => $d['cree_le']];
}

function a_fiche(): void
{
    $u = exiger_equipe();
    $m = fiche_visible($u, entier($_GET['id'] ?? 0));
    if (!$m) {
        tracer($u, 'accès refusé', 'fiche ' . entier($_GET['id'] ?? 0));
        sortir(404, ['ok' => false, 'erreur' => 'introuvable']);
    }
    $soi = (int) $m['id'] === (int) $u['id'];
    $d = base()->prepare('SELECT * FROM demandes_conges WHERE utilisateur_id = ? AND au >= ? ORDER BY du DESC');
    $d->execute([$m['id'], date('Y-m-d', strtotime('-365 days'))]);
    $a = base()->prepare('SELECT du, au, motif FROM absences WHERE utilisateur_id = ? AND au >= ? ORDER BY du');
    $a->execute([$m['id'], date('Y-m-d')]);
    $agence = base()->prepare('SELECT nom FROM agences WHERE id = ?');
    $agence->execute([(int) $m['agence_id']]);
    sortir(200, ['ok' => true, 'soi' => $soi,
        // On ne valide pas ses propres habilitations : c'est celui qui gère la personne qui les a vues.
        'peut_qualifier' => !$soi, 'peut_tout_modifier' => !$soi,
        'membre' => ['id' => (int) $m['id'], 'nom' => $m['nom'], 'role' => $m['role'], 'agence' => $agence->fetchColumn() ?: '', 'courriel' => $m['courriel'],
            'telephone' => $m['telephone'], 'entree_le' => $m['entree_le'], 'urgence_nom' => $m['urgence_nom'], 'urgence_tel' => $m['urgence_tel'],
            'taille_haut' => $m['taille_haut'], 'taille_bas' => $m['taille_bas'], 'pointure' => $m['pointure'], 'remplacant_jusquau' => $m['expire_le']],
        'qualifications' => qualifications_de((int) $m['id']), 'types' => QUALIFICATIONS, 'motifs' => MOTIFS_CONGE,
        'demandes' => array_map('demande_publique', $d->fetchAll()), 'absences' => $a->fetchAll(), 'echeance_proche' => ECHEANCE_PROCHE_JOURS]);
}

function a_fiche_enregistrer(): void
{
    $u = exiger_equipe();
    exiger_post();
    $d = entree();
    $m = fiche_visible($u, entier($d['id'] ?? 0));
    if (!$m) {
        tracer($u, 'accès refusé', 'fiche ' . entier($d['id'] ?? 0));
        sortir(404, ['ok' => false, 'erreur' => 'introuvable']);
    }
    $v = ['urgence_nom' => texte($d['urgence_nom'] ?? '', 80), 'urgence_tel' => texte($d['urgence_tel'] ?? '', 30),
        'taille_haut' => texte($d['taille_haut'] ?? '', 10), 'taille_bas' => texte($d['taille_bas'] ?? '', 10), 'pointure' => texte($d['pointure'] ?? '', 10)];
    if ($v['urgence_tel'] !== '' && !preg_match('/^[0-9+ .()\-]{6,30}$/', $v['urgence_tel'])) {
        sortir(200, ['ok' => false, 'erreur' => 'telephone']);
    }
    // La date d'entrée est un fait de l'entreprise : elle se pose par le responsable, pas par la personne.
    if ((int) $m['id'] !== (int) $u['id'] && array_key_exists('entree_le', $d)) {
        $entree = $d['entree_le'] === '' || $d['entree_le'] === null ? null : date_valide($d['entree_le']);
        if ($d['entree_le'] !== '' && $d['entree_le'] !== null && !$entree) {
            sortir(200, ['ok' => false, 'erreur' => 'date']);
        }
        $v['entree_le'] = $entree;
    }
    $cols = implode(', ', array_map(fn ($k) => "$k = ?", array_keys($v)));
    base()->prepare("UPDATE utilisateurs SET $cols WHERE id = ?")->execute([...array_values($v), $m['id']]);
    tracer($u, 'fiche modifiée', $m['courriel']);
    sortir(200, ['ok' => true]);
}

function a_qualification_enregistrer(): void
{
    $u = exiger_equipe(['chef', 'superadmin']);
    exiger_post();
    $d = entree();
    $m = membre_gerable($u, entier($d['utilisateur_id'] ?? 0));
    if (!$m) {
        tracer($u, 'accès refusé', 'qualification ' . entier($d['utilisateur_id'] ?? 0));
        sortir(404, ['ok' => false, 'erreur' => 'membre']);
    }
    $type = (string) ($d['type'] ?? '');
    $intitule = texte($d['intitule'] ?? '', 120);
    $obtenue = ($d['obtenue_le'] ?? '') === '' ? null : date_valide($d['obtenue_le']);
    $expire = ($d['expire_le'] ?? '') === '' ? null : date_valide($d['expire_le']);
    if (!isset(QUALIFICATIONS[$type]) || (in_array($type, ['formation', 'autre'], true) && $intitule === '')) {
        sortir(200, ['ok' => false, 'erreur' => 'type']);
    }
    if ((($d['obtenue_le'] ?? '') !== '' && !$obtenue) || (($d['expire_le'] ?? '') !== '' && !$expire)
        || ($obtenue && $obtenue > date('Y-m-d')) || ($obtenue && $expire && $expire < $obtenue)) {
        sortir(200, ['ok' => false, 'erreur' => 'dates']);
    }
    $db = base();
    $id = entier($d['id'] ?? 0);
    if ($id) {
        $q = $db->prepare('SELECT id FROM qualifications WHERE id = ? AND utilisateur_id = ?');
        $q->execute([$id, $m['id']]);
        if ($q->fetchColumn() === false) {
            sortir(404, ['ok' => false, 'erreur' => 'introuvable']);
        }
        $db->prepare('UPDATE qualifications SET type = ?, intitule = ?, obtenue_le = ?, expire_le = ?, note = ?, cree_par = ?, maj_le = ? WHERE id = ?')
            ->execute([$type, $intitule, $obtenue, $expire, texte($d['note'] ?? '', 300), $u['courriel'], maintenant(), $id]);
    } else {
        $db->prepare('INSERT INTO qualifications (utilisateur_id, type, intitule, obtenue_le, expire_le, note, cree_par, maj_le) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$m['id'], $type, $intitule, $obtenue, $expire, texte($d['note'] ?? '', 300), $u['courriel'], maintenant()]);
        $id = (int) $db->lastInsertId();
    }
    tracer($u, 'qualification enregistrée', "{$m['courriel']} : " . (QUALIFICATIONS[$type]) . ($expire ? " jusqu'au $expire" : ''));
    sortir(200, ['ok' => true, 'id' => $id]);
}

function a_qualification_supprimer(): void
{
    $u = exiger_equipe(['chef', 'superadmin']);
    exiger_post();
    $q = base()->prepare('SELECT * FROM qualifications WHERE id = ?');
    $q->execute([entier(entree()['id'] ?? 0)]);
    $x = $q->fetch();
    if (!$x || !membre_gerable($u, (int) $x['utilisateur_id'])) {
        sortir(404, ['ok' => false, 'erreur' => 'introuvable']);
    }
    base()->prepare('DELETE FROM qualifications WHERE id = ?')->execute([$x['id']]);
    tracer($u, 'qualification retirée', "{$x['utilisateur_id']} : {$x['type']}");
    sortir(200, ['ok' => true]);
}

/* ---------- Les congés ---------- */

function a_conge_demander(): void
{
    $u = exiger_equipe(['technicien', 'chef']);
    exiger_post();
    $d = entree();
    $du = date_valide($d['du'] ?? '');
    $au = date_valide($d['au'] ?? '');
    if (!$du || !$au || $au < $du || $du < date('Y-m-d') || $au > date('Y-m-d', strtotime('+365 days'))) {
        sortir(200, ['ok' => false, 'erreur' => 'dates']);
    }
    // Deux demandes sur les mêmes jours, ou une demande sur une absence déjà posée, ne disent rien de plus : on refuse.
    $c = base()->prepare("SELECT (SELECT COUNT(*) FROM demandes_conges WHERE utilisateur_id = ? AND statut IN ('attente', 'acceptee') AND du <= ? AND au >= ?)
        + (SELECT COUNT(*) FROM absences WHERE utilisateur_id = ? AND du <= ? AND au >= ?)");
    $c->execute([$u['id'], $au, $du, $u['id'], $au, $du]);
    if ((int) $c->fetchColumn() > 0) {
        sortir(200, ['ok' => false, 'erreur' => 'chevauche']);
    }
    $motif = in_array($d['motif'] ?? '', MOTIFS_CONGE, true) ? $d['motif'] : 'Congés';
    base()->prepare('INSERT INTO demandes_conges (utilisateur_id, du, au, motif, message, cree_le) VALUES (?,?,?,?,?,?)')
        ->execute([$u['id'], $du, $au, $motif, texte($d['message'] ?? '', 500), maintenant()]);
    $id = (int) base()->lastInsertId();
    tracer($u, 'congés demandés', "$du → $au ($motif)");
    sortir(200, ['ok' => true, 'id' => $id]);
}

function a_conge_annuler(): void
{
    $u = exiger_equipe(['technicien', 'chef']);
    exiger_post();
    $q = base()->prepare('SELECT * FROM demandes_conges WHERE id = ? AND utilisateur_id = ?');
    $q->execute([entier(entree()['id'] ?? 0), $u['id']]);
    $c = $q->fetch();
    // On annule ce qui n'a pas commencé. Un congé en cours se raccourcit avec son responsable, de vive voix.
    if (!$c || !in_array($c['statut'], ['attente', 'acceptee'], true) || ($c['statut'] === 'acceptee' && $c['du'] <= date('Y-m-d'))) {
        sortir(200, ['ok' => false, 'erreur' => 'annulation']);
    }
    $db = base();
    $db->beginTransaction();
    $db->prepare("UPDATE demandes_conges SET statut = 'annulee' WHERE id = ?")->execute([$c['id']]);
    if ($c['absence_id']) {
        // L'absence part, et les remplacements posés pour couvrir ses clients aussi : sinon le remplaçant passerait à sa place.
        $db->prepare('DELETE FROM absences WHERE id = ?')->execute([$c['absence_id']]);
        $db->prepare('DELETE FROM remplacements WHERE date BETWEEN ? AND ? AND client_id IN (SELECT id FROM clients WHERE technicien_id = ?)')->execute([$c['du'], $c['au'], $u['id']]);
    }
    $db->commit();
    tracer($u, 'congés annulés', "{$c['du']} → {$c['au']}");
    sortir(200, ['ok' => true]);
}

// Ce qu'une demande ferait à l'agence : les journées de tournée touchées, et qui d'autre est déjà absent ces jours-là.
function impact_conge(array $c, int $agence): array
{
    $jours = [];
    for ($d = $c['du']; $d <= $c['au'] && count($jours) < 31; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
        $q = base()->prepare("SELECT COUNT(*) FROM clients WHERE technicien_id = ? AND jour_passage = ? AND statut = 'client'");
        $q->execute([$c['utilisateur_id'], (int) date('N', strtotime($d))]);
        $n = (int) $q->fetchColumn();
        if ($n) {
            $jours[] = ['date' => $d, 'clients' => $n];
        }
    }
    $o = base()->prepare("SELECT u.nom, a.du, a.au FROM absences a JOIN utilisateurs u ON u.id = a.utilisateur_id
        WHERE u.agence_id = ? AND u.id <> ? AND a.du <= ? AND a.au >= ? ORDER BY a.du");
    $o->execute([$agence, $c['utilisateur_id'], $c['au'], $c['du']]);
    return ['jours' => $jours, 'clients' => array_sum(array_column($jours, 'clients')), 'deja_absents' => $o->fetchAll()];
}

function a_conges(): void
{
    $u = exiger_equipe(['chef', 'superadmin']);
    $agence = agence_visee($u);
    // Le chef décide pour ses techniciens ; la direction pour tout le monde, chefs compris.
    $w = $u['role'] === 'chef' ? "u.role = 'technicien' AND u.agence_id = ?" : ($agence ? 'u.agence_id = ?' : '1 = 1');
    $p = $u['role'] === 'chef' ? [(int) $u['agence_id']] : ($agence ? [$agence] : []);
    $q = base()->prepare("SELECT d.*, u.nom, u.agence_id FROM demandes_conges d JOIN utilisateurs u ON u.id = d.utilisateur_id
        WHERE $w AND (d.statut = 'attente' OR d.cree_le >= ? OR d.au >= ?) ORDER BY d.statut <> 'attente', d.du");
    $q->execute([...$p, date('c', strtotime('-90 days')), date('Y-m-d')]);
    $attente = [];
    $traitees = [];
    foreach ($q->fetchAll() as $c) {
        if ($c['statut'] === 'attente') {
            $attente[] = demande_publique($c) + ['impact' => impact_conge($c, (int) $c['agence_id']),
                'depuis_jours' => (int) floor((time() - strtotime($c['cree_le'])) / 86400)];
        } else {
            $traitees[] = demande_publique($c);
        }
    }
    sortir(200, ['ok' => true, 'agence' => $agence, 'attente' => $attente, 'traitees' => $traitees, 'delai' => CONGE_REPONSE_JOURS,
        'agences' => $u['role'] === 'superadmin' ? base()->query('SELECT id, nom FROM agences WHERE actif = 1 ORDER BY nom')->fetchAll() : []]);
}

function a_conge_decider(): void
{
    $u = exiger_equipe(['chef', 'superadmin']);
    exiger_post();
    $d = entree();
    $q = base()->prepare("SELECT * FROM demandes_conges WHERE id = ? AND statut = 'attente'");
    $q->execute([entier($d['id'] ?? 0)]);
    $c = $q->fetch();
    // Refus par défaut : on ne décide ni sa propre demande, ni celle de quelqu'un qu'on ne gère pas.
    $m = $c ? membre_gerable($u, (int) $c['utilisateur_id']) : null;
    if (!$c || !$m) {
        tracer($u, 'accès refusé', 'congés ' . entier($d['id'] ?? 0));
        sortir(404, ['ok' => false, 'erreur' => 'introuvable']);
    }
    $decision = (string) ($d['decision'] ?? '');
    $reponse = texte($d['reponse'] ?? '', 500);
    if (!in_array($decision, ['accepter', 'refuser'], true)) {
        sortir(200, ['ok' => false, 'erreur' => 'decision']);
    }
    // Un refus s'explique toujours : c'est ce qui le rend acceptable, et ce qui permet de proposer d'autres dates.
    if ($decision === 'refuser' && $reponse === '') {
        sortir(200, ['ok' => false, 'erreur' => 'motif']);
    }
    $db = base();
    $db->beginTransaction();
    $absence = null;
    if ($decision === 'accepter') {
        $db->prepare('INSERT INTO absences (utilisateur_id, du, au, motif, cree_par, cree_le) VALUES (?,?,?,?,?,?)')
            ->execute([$c['utilisateur_id'], $c['du'], $c['au'], $c['motif'], $u['courriel'], maintenant()]);
        $absence = (int) $db->lastInsertId();
    }
    $db->prepare('UPDATE demandes_conges SET statut = ?, reponse = ?, decide_par = ?, decide_le = ?, absence_id = ? WHERE id = ?')
        ->execute([$decision === 'accepter' ? 'acceptee' : 'refusee', $reponse, $u['nom'], maintenant(), $absence, $c['id']]);
    $db->commit();
    tracer($u, $decision === 'accepter' ? 'congés acceptés' : 'congés refusés', "{$m['courriel']} {$c['du']} → {$c['au']}");
    sortir(200, ['ok' => true, 'absence_id' => $absence]);
}
