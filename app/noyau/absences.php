<?php
// Maison Flamingo · les absences, la couverture des tournées et le vivier de remplaçants.
//
// Un client garde son technicien : le remplacement ne vaut que pour une date, et le client le sait.
// La marge des tournées (remplies à 85 % au plus) absorbe la plupart des absences ; le vivier prend le reste.
// Un remplaçant n'entre chez personne tant qu'une seule vérification manque, et son accès s'éteint seul.

declare(strict_types=1);

const MOTIFS_ABSENCE = ['Congés', 'Maladie', 'Formation', 'Autre'];
const COUVERTURE_JOURS = 14;

function date_valide($v): ?string
{
    $s = (string) $v;
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) && strtotime($s) !== false && date('Y-m-d', strtotime($s)) === $s ? $s : null;
}

// Les personnes qui peuvent prendre une tournée ce jour-là, avec la marge qu'il leur reste avant 7 h 30.
function disponibles_le(int $agence, string $date, int $jour): array
{
    $q = base()->prepare("SELECT id, nom, agence_id, expire_le FROM utilisateurs WHERE role = 'technicien' AND actif = 1 AND hash <> '' AND agence_id = ?
        AND (expire_le IS NULL OR expire_le >= ?) ORDER BY nom");
    $q->execute([$agence, $date]);
    $sortie = [];
    foreach ($q->fetchAll() as $t) {
        if (absent_le((int) $t['id'], $date)) {
            continue;
        }
        $charge = bilan_journee($t, $jour, $date)['charge'];
        $sortie[] = ['id' => (int) $t['id'], 'nom' => $t['nom'], 'temporaire' => $t['expire_le'] !== null,
            'charge_min' => $charge['min'], 'marge_min' => max(0, JOURNEE_MAX_MIN - $charge['min']), 'piscines' => $charge['piscines']];
    }
    usort($sortie, fn ($a, $b) => $b['marge_min'] <=> $a['marge_min']);
    return $sortie;
}

// Ce qui reste à couvrir dans les quinze prochains jours : chaque journée d'absence, ses clients, qui les prend.
function couverture_a_venir(?int $agence): array
{
    $debut = date('Y-m-d');
    $fin = date('Y-m-d', strtotime('+' . (COUVERTURE_JOURS - 1) . ' days'));
    $q = base()->prepare("SELECT a.*, u.nom, u.agence_id FROM absences a JOIN utilisateurs u ON u.id = a.utilisateur_id
        WHERE a.au >= ? AND a.du <= ? AND u.role = 'technicien'" . ($agence ? ' AND u.agence_id = ?' : '') . ' ORDER BY a.du');
    $q->execute($agence ? [$debut, $fin, $agence] : [$debut, $fin]);
    $jours = [];
    foreach ($q->fetchAll() as $a) {
        for ($d = max($a['du'], $debut); $d <= min($a['au'], $fin); $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            $n = (int) date('N', strtotime($d));
            $c = base()->prepare("SELECT c.id, c.prenom, c.nom, c.commune, c.formule, r.technicien_id AS remplacant_id, t.nom AS remplacant
                FROM clients c LEFT JOIN remplacements r ON r.client_id = c.id AND r.date = ? LEFT JOIN utilisateurs t ON t.id = r.technicien_id
                WHERE c.technicien_id = ? AND c.jour_passage = ? AND c.statut = 'client' ORDER BY c.ordre, c.id");
            $c->execute([$d, (int) $a['utilisateur_id'], $n]);
            $clients = array_map(fn ($x) => ['id' => (int) $x['id'], 'nom' => trim($x['prenom'] . ' ' . $x['nom']), 'commune' => $x['commune'], 'formule' => $x['formule'],
                'remplacant' => $x['remplacant_id'] ? ['id' => (int) $x['remplacant_id'], 'nom' => $x['remplacant']] : null], $c->fetchAll());
            if (!$clients) {
                continue;   // un jour sans tournée n'a rien à couvrir
            }
            $jours[] = ['date' => $d, 'jour_nom' => JOURS[$n], 'absence_id' => (int) $a['id'], 'motif' => $a['motif'],
                'technicien' => ['id' => (int) $a['utilisateur_id'], 'nom' => $a['nom']], 'clients' => $clients,
                'a_couvrir' => count(array_filter($clients, fn ($x) => !$x['remplacant'])),
                'disponibles' => disponibles_le((int) $a['agence_id'], $d, $n)];
        }
    }
    usort($jours, fn ($x, $y) => strcmp($x['date'], $y['date']));
    return $jours;
}

function a_absences(): void
{
    $u = exiger_equipe(['chef', 'superadmin']);
    $agence = agence_visee($u);
    $q = base()->prepare("SELECT a.id, a.utilisateur_id, a.du, a.au, a.motif, a.cree_par, u.nom FROM absences a JOIN utilisateurs u ON u.id = a.utilisateur_id
        WHERE a.au >= ?" . ($agence ? ' AND u.agence_id = ?' : '') . ' ORDER BY a.du');
    $q->execute($agence ? [date('Y-m-d', strtotime('-30 days')), $agence] : [date('Y-m-d', strtotime('-30 days'))]);
    $m = base()->prepare("SELECT id, nom, role, expire_le FROM utilisateurs WHERE actif = 1 AND role IN ('technicien', 'chef')" . ($u['role'] === 'chef' ? " AND role = 'technicien' AND agence_id = ?" : ($agence ? ' AND agence_id = ?' : '')) . ' ORDER BY nom');
    $m->execute($u['role'] === 'chef' ? [(int) $u['agence_id']] : ($agence ? [$agence] : []));
    sortir(200, ['ok' => true, 'agence' => $agence, 'absences' => $q->fetchAll(), 'membres' => $m->fetchAll(), 'motifs' => MOTIFS_ABSENCE,
        'couverture' => couverture_a_venir($agence), 'agences' => $u['role'] === 'superadmin' ? base()->query('SELECT id, nom FROM agences WHERE actif = 1 ORDER BY nom')->fetchAll() : []]);
}

function a_absence_enregistrer(): void
{
    $u = exiger_equipe(['chef', 'superadmin']);
    exiger_post();
    $d = entree();
    $m = membre_gerable($u, entier($d['utilisateur_id'] ?? 0));
    if (!$m) {
        tracer($u, 'accès refusé', 'absence ' . entier($d['utilisateur_id'] ?? 0));
        sortir(404, ['ok' => false, 'erreur' => 'membre']);
    }
    $du = date_valide($d['du'] ?? '');
    $au = date_valide($d['au'] ?? '');
    // Une absence se pose d'avance ou se régularise dans le mois, jamais au-delà d'un an.
    if (!$du || !$au || $au < $du || $du < date('Y-m-d', strtotime('-30 days')) || $au > date('Y-m-d', strtotime('+365 days'))) {
        sortir(200, ['ok' => false, 'erreur' => 'dates']);
    }
    $motif = in_array($d['motif'] ?? '', MOTIFS_ABSENCE, true) ? $d['motif'] : 'Autre';
    base()->prepare('INSERT INTO absences (utilisateur_id, du, au, motif, cree_par, cree_le) VALUES (?,?,?,?,?,?)')
        ->execute([$m['id'], $du, $au, $motif, $u['courriel'], maintenant()]);
    // Lu avant la trace : tracer() écrit à son tour, et lastInsertId rendrait la ligne du journal.
    $id = (int) base()->lastInsertId();
    tracer($u, 'absence posée', "{$m['courriel']} $du → $au ($motif)");
    sortir(200, ['ok' => true, 'id' => $id]);
}

function a_absence_supprimer(): void
{
    $u = exiger_equipe(['chef', 'superadmin']);
    exiger_post();
    $q = base()->prepare('SELECT * FROM absences WHERE id = ?');
    $q->execute([entier(entree()['id'] ?? 0)]);
    $a = $q->fetch();
    if (!$a || !membre_gerable($u, (int) $a['utilisateur_id'])) {
        sortir(404, ['ok' => false, 'erreur' => 'absence']);
    }
    base()->prepare('DELETE FROM absences WHERE id = ?')->execute([$a['id']]);
    // Une absence née d'une demande de congés acceptée : la demande le dit, pour que le technicien ne croie pas partir.
    base()->prepare("UPDATE demandes_conges SET statut = 'annulee', reponse = CASE WHEN reponse = '' THEN 'Absence retirée par le responsable.' ELSE reponse END WHERE absence_id = ?")->execute([$a['id']]);
    tracer($u, 'absence retirée', "{$a['utilisateur_id']} {$a['du']} → {$a['au']}");
    sortir(200, ['ok' => true]);
}

function a_remplacement_poser(): void
{
    $u = exiger_equipe(['chef', 'superadmin']);
    exiger_post();
    $d = entree();
    $date = date_valide($d['date'] ?? '');
    if (!$date || $date < date('Y-m-d') || $date > date('Y-m-d', strtotime('+60 days'))) {
        sortir(200, ['ok' => false, 'erreur' => 'date']);
    }
    $t = base()->prepare("SELECT * FROM utilisateurs WHERE id = ? AND role = 'technicien' AND actif = 1 AND hash <> '' AND (expire_le IS NULL OR expire_le >= ?)");
    $t->execute([entier($d['technicien_id'] ?? 0), $date]);
    $tech = $t->fetch();
    // Refus par défaut : un remplaçant inconnu, d'une autre agence, absent ce jour-là, ou dont l'accès finit avant.
    if (!$tech || ($u['role'] === 'chef' && (int) $tech['agence_id'] !== (int) $u['agence_id']) || absent_le((int) $tech['id'], $date)) {
        tracer($u, 'accès refusé', 'remplacement ' . entier($d['technicien_id'] ?? 0));
        sortir(200, ['ok' => false, 'erreur' => 'technicien']);
    }
    $ids = array_values(array_unique(array_map('entier', is_array($d['client_ids'] ?? null) ? $d['client_ids'] : [])));
    if (!$ids || count($ids) > 30) {
        sortir(200, ['ok' => false, 'erreur' => 'clients']);
    }
    $db = base();
    $db->beginTransaction();
    foreach ($ids as $id) {
        $c = client_autorise($u, $id);
        if (!$c || (int) $c['agence_id'] !== (int) $tech['agence_id']) {
            $db->rollBack();
            tracer($u, 'client refusé', "remplacement $id");
            sortir(404, ['ok' => false, 'erreur' => 'introuvable']);
        }
        $db->prepare('INSERT INTO remplacements (date, client_id, technicien_id, cree_par, cree_le) VALUES (?,?,?,?,?)
            ON CONFLICT(date, client_id) DO UPDATE SET technicien_id = excluded.technicien_id, cree_par = excluded.cree_par, cree_le = excluded.cree_le')
            ->execute([$date, $id, $tech['id'], $u['courriel'], maintenant()]);
    }
    $db->commit();
    tracer($u, 'remplacement posé', "$date : " . count($ids) . " client(s) → {$tech['courriel']}");
    sortir(200, ['ok' => true, 'poses' => count($ids)]);
}

function a_remplacement_retirer(): void
{
    $u = exiger_equipe(['chef', 'superadmin']);
    exiger_post();
    $d = entree();
    $c = exiger_client_autorise($u, entier($d['client_id'] ?? 0));
    $date = date_valide($d['date'] ?? '') ?? '';
    base()->prepare('DELETE FROM remplacements WHERE client_id = ? AND date = ?')->execute([$c['id'], $date]);
    tracer($u, 'remplacement retiré', "{$c['id']} $date");
    sortir(200, ['ok' => true]);
}

/* ---------- Le vivier ---------- */

function statut_vivier(array $v): string
{
    if ((int) $v['ecarte'] === 1) {
        return 'ecarte';
    }
    if (!empty($v['expire_le']) && $v['expire_le'] >= date('Y-m-d') && (int) ($v['u_actif'] ?? 0) === 1) {
        return 'en_mission';
    }
    $faits = count(array_filter(array_keys(VIVIER_CONTROLES), fn ($k) => (int) $v[$k] === 1));
    return $faits === count(VIVIER_CONTROLES) ? 'pret' : ($faits > 0 ? 'en_verification' : 'a_rencontrer');
}

function vivier_autorise(array $u, int $id): ?array
{
    $q = base()->prepare('SELECT v.*, u.expire_le, u.actif AS u_actif, u.hash <> \'\' AS a_mot_de_passe FROM vivier v LEFT JOIN utilisateurs u ON u.id = v.utilisateur_id WHERE v.id = ?');
    $q->execute([$id]);
    $v = $q->fetch();
    if (!$v || ($u['role'] === 'chef' && (int) $v['agence_id'] !== (int) $u['agence_id'])) {
        return null;
    }
    return $v;
}

function vivier_public(array $v): array
{
    $c = [];
    foreach (VIVIER_CONTROLES as $k => $libelle) {
        $c[$k] = (int) $v[$k] === 1;
    }
    return ['id' => (int) $v['id'], 'agence_id' => (int) $v['agence_id'], 'prenom' => $v['prenom'], 'nom' => $v['nom'], 'telephone' => $v['telephone'],
        'courriel' => $v['courriel'], 'source' => $v['source'], 'disponibilites' => $v['disponibilites'], 'notes' => $v['notes'],
        'controles' => $c, 'ecarte' => (int) $v['ecarte'] === 1, 'statut' => statut_vivier($v), 'mission_jusquau' => $v['expire_le'] ?? null,
        'utilisateur_id' => $v['utilisateur_id'] !== null ? (int) $v['utilisateur_id'] : null];
}

function a_vivier(): void
{
    $u = exiger_equipe(['chef', 'superadmin']);
    $agence = agence_visee($u);
    $q = base()->prepare('SELECT v.*, u.expire_le, u.actif AS u_actif FROM vivier v LEFT JOIN utilisateurs u ON u.id = v.utilisateur_id' . ($agence ? ' WHERE v.agence_id = ?' : '') . ' ORDER BY v.ecarte, v.nom, v.prenom');
    $q->execute($agence ? [$agence] : []);
    sortir(200, ['ok' => true, 'agence' => $agence, 'personnes' => array_map('vivier_public', $q->fetchAll()), 'controles' => VIVIER_CONTROLES,
        'mission_max' => MISSION_JOURS_MAX, 'agences' => $u['role'] === 'superadmin' ? base()->query('SELECT id, nom FROM agences WHERE actif = 1 ORDER BY nom')->fetchAll() : []]);
}

function a_vivier_enregistrer(): void
{
    $u = exiger_equipe(['chef', 'superadmin']);
    exiger_post();
    $d = entree();
    $id = entier($d['id'] ?? 0);
    $avant = $id ? vivier_autorise($u, $id) : null;
    if ($id && !$avant) {
        sortir(404, ['ok' => false, 'erreur' => 'introuvable']);
    }
    $agence = $u['role'] === 'superadmin' ? entier($d['agence_id'] ?? ($avant['agence_id'] ?? 0)) : (int) $u['agence_id'];
    $a = base()->prepare('SELECT id FROM agences WHERE id = ?');
    $a->execute([$agence]);
    $v = ['agence_id' => $agence];
    foreach (['prenom' => 60, 'nom' => 60, 'telephone' => 30, 'courriel' => 120, 'source' => 80, 'disponibilites' => 200, 'notes' => 2000] as $k => $max) {
        $v[$k] = texte($d[$k] ?? '', $max);
    }
    $v['courriel'] = mb_strtolower($v['courriel']);
    if ($a->fetchColumn() === false || ($v['prenom'] === '' && $v['nom'] === '') || ($v['courriel'] !== '' && !filter_var($v['courriel'], FILTER_VALIDATE_EMAIL))) {
        sortir(200, ['ok' => false, 'erreur' => 'champs']);
    }
    $controles = is_array($d['controles'] ?? null) ? $d['controles'] : [];
    foreach (VIVIER_CONTROLES as $k => $libelle) {
        $v[$k] = !empty($controles[$k]) ? 1 : 0;
    }
    $v['ecarte'] = !empty($d['ecarte']) ? 1 : 0;
    $v['maj_le'] = maintenant();
    $db = base();
    if ($avant) {
        $cols = implode(', ', array_map(fn ($k) => "$k = ?", array_keys($v)));
        $db->prepare("UPDATE vivier SET $cols WHERE id = ?")->execute([...array_values($v), $id]);
        // Écarté ou une vérification retirée : une mission en cours s'arrête aussitôt.
        if ($avant['utilisateur_id'] && ($v['ecarte'] || statut_vivier($v + ['expire_le' => null]) !== 'pret')) {
            terminer_mission((int) $avant['utilisateur_id']);
        }
    } else {
        $v['cree_le'] = maintenant();
        $db->prepare('INSERT INTO vivier (' . implode(', ', array_keys($v)) . ') VALUES (' . implode(',', array_fill(0, count($v), '?')) . ')')->execute(array_values($v));
        $id = (int) $db->lastInsertId();
    }
    tracer($u, 'vivier enregistré', (string) $id);
    sortir(200, ['ok' => true, 'id' => $id]);
}

function terminer_mission(int $utilisateur): void
{
    $db = base();
    $db->prepare('UPDATE utilisateurs SET expire_le = ? WHERE id = ? AND expire_le IS NOT NULL')->execute([date('Y-m-d', strtotime('-1 day')), $utilisateur]);
    $db->prepare('DELETE FROM sessions WHERE utilisateur_id = ?')->execute([$utilisateur]);
    $db->prepare('DELETE FROM remplacements WHERE technicien_id = ? AND date >= ?')->execute([$utilisateur, date('Y-m-d')]);
}

function a_vivier_activer(): void
{
    $u = exiger_equipe(['chef', 'superadmin']);
    exiger_post();
    $d = entree();
    $v = vivier_autorise($u, entier($d['id'] ?? 0));
    if (!$v) {
        sortir(404, ['ok' => false, 'erreur' => 'introuvable']);
    }
    // Pas une case de moins : sans toutes les vérifications, personne n'entre chez un client.
    if (statut_vivier(['expire_le' => null] + $v) !== 'pret' && statut_vivier($v) !== 'en_mission') {
        tracer($u, 'accès refusé', "vivier {$v['id']} : vérifications incomplètes");
        sortir(200, ['ok' => false, 'erreur' => 'controles']);
    }
    $fin = date_valide($d['jusqu_au'] ?? '');
    if (!$fin || $fin < date('Y-m-d') || $fin > date('Y-m-d', strtotime('+' . MISSION_JOURS_MAX . ' days'))) {
        sortir(200, ['ok' => false, 'erreur' => 'dates']);
    }
    if (!filter_var($v['courriel'], FILTER_VALIDATE_EMAIL)) {
        sortir(200, ['ok' => false, 'erreur' => 'courriel']);
    }
    $db = base();
    $nom = trim($v['prenom'] . ' ' . $v['nom']);
    $lien = null;
    if ($v['utilisateur_id']) {
        $db->prepare('UPDATE utilisateurs SET expire_le = ?, actif = 1, agence_id = ?, nom = ?, telephone = ? WHERE id = ?')->execute([$fin, $v['agence_id'], $nom, $v['telephone'], $v['utilisateur_id']]);
        $uid = (int) $v['utilisateur_id'];
        if (!(int) ($v['a_mot_de_passe'] ?? 0)) {
            $lien = url_base() . '/#/invitation/' . creer_invitation('equipe', $uid, (int) $u['id']);
        }
    } else {
        $e = $db->prepare('SELECT id FROM utilisateurs WHERE courriel = ?');
        $e->execute([$v['courriel']]);
        if ($e->fetchColumn() !== false) {
            sortir(200, ['ok' => false, 'erreur' => 'courriel_pris']);
        }
        $db->prepare("INSERT INTO utilisateurs (courriel, nom, hash, cree_le, role, agence_id, actif, telephone, expire_le, vivier_id) VALUES (?,?,'',?,'technicien',?,1,?,?,?)")
            ->execute([$v['courriel'], $nom, maintenant(), $v['agence_id'], $v['telephone'], $fin, $v['id']]);
        $uid = (int) $db->lastInsertId();
        $db->prepare('UPDATE vivier SET utilisateur_id = ? WHERE id = ?')->execute([$uid, $v['id']]);
        $lien = url_base() . '/#/invitation/' . creer_invitation('equipe', $uid, (int) $u['id']);
    }
    tracer($u, 'mission ouverte', "{$v['courriel']} jusqu'au $fin");
    sortir(200, ['ok' => true, 'utilisateur_id' => $uid, 'jusqu_au' => $fin, 'lien' => $lien]);
}

function a_vivier_terminer(): void
{
    $u = exiger_equipe(['chef', 'superadmin']);
    exiger_post();
    $v = vivier_autorise($u, entier(entree()['id'] ?? 0));
    if (!$v || !$v['utilisateur_id']) {
        sortir(404, ['ok' => false, 'erreur' => 'introuvable']);
    }
    terminer_mission((int) $v['utilisateur_id']);
    tracer($u, 'mission terminée', $v['courriel']);
    sortir(200, ['ok' => true]);
}
