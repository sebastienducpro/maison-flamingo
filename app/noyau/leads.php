<?php
// Maison Flamingo · les leads : qui arrive, à qui on le confie, et en combien de temps on rappelle.
//
// Un lead est un client au statut prospect, avec trois dates : reçu, attribué,
// contacté. L'attribution suit le secteur (la commune), puis le technicien le
// moins chargé de l'agence. Le temps de réponse se mesure entre l'attribution
// et le premier geste vers le client : appel, SMS, visite, ou « contact fait ».

declare(strict_types=1);

const LEAD_RETARD_H = 24;   // au-delà, le lead passe en rouge sur le tableau

function normaliser_commune(string $c): string
{
    $c = mb_strtolower(trim($c));
    $c = strtr($c, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'à' => 'a', 'â' => 'a', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ù' => 'u', 'û' => 'u', 'ç' => 'c', 'œ' => 'oe']);
    $c = preg_replace('/\bsaint\b/', 'st', $c) ?? $c;
    return trim(preg_replace('/[^a-z0-9]+/', ' ', $c) ?? '');
}

// Le technicien actif de l'agence qui a le moins de leads encore sans réponse.
function technicien_le_moins_charge(int $agence): ?int
{
    $q = base()->prepare("SELECT u.id, (SELECT COUNT(*) FROM clients c WHERE c.technicien_id = u.id AND c.lead_contacte_le IS NULL AND c.lead_recu_le IS NOT NULL) AS ouverts
        FROM utilisateurs u WHERE u.role = 'technicien' AND u.actif = 1 AND u.hash <> '' AND u.agence_id = ? ORDER BY ouverts, u.id LIMIT 1");
    $q->execute([$agence]);
    $t = $q->fetch();
    return $t ? (int) $t['id'] : null;
}

// Rend [agence_id, technicien_id|null, règle appliquée].
function choisir_attribution(string $commune): array
{
    $db = base();
    $q = $db->prepare('SELECT s.agence_id, s.technicien_id, u.actif, u.role FROM secteurs s LEFT JOIN utilisateurs u ON u.id = s.technicien_id WHERE s.commune = ?');
    $q->execute([normaliser_commune($commune)]);
    $s = $q->fetch();
    if ($s) {
        $agence = (int) $s['agence_id'];
        if ($s['technicien_id'] && (int) $s['actif'] === 1 && $s['role'] === 'technicien') {
            return [$agence, (int) $s['technicien_id'], 'secteur'];
        }
        return [$agence, technicien_le_moins_charge($agence), 'agence du secteur, technicien le moins chargé'];
    }
    $agence = (int) $db->query('SELECT MIN(id) FROM agences WHERE actif = 1')->fetchColumn();
    return [$agence, technicien_le_moins_charge($agence), 'hors secteur, première agence'];
}

// Entrée d'un lead : depuis le site, depuis l'import, ou à la main. Rend l'identifiant, ou null si doublon.
function enregistrer_lead(array $d, string $origine): ?int
{
    $db = base();
    $tel = preg_replace('/\D/', '', (string) ($d['telephone'] ?? '')) ?? '';
    if ($tel !== '') {
        $q = $db->prepare("SELECT id FROM clients WHERE REPLACE(REPLACE(REPLACE(telephone,' ',''),'.',''),'-','') = ?");
        $q->execute([$tel]);
        if ($q->fetchColumn() !== false) {
            return null;
        }
    }
    [$agence, $technicien, $regle] = choisir_attribution((string) ($d['commune'] ?? ''));
    $maintenant = maintenant();
    $db->prepare("INSERT INTO clients (prenom, nom, telephone, courriel, commune, offre, notes, statut, origine, cree_le, maj_le, agence_id, technicien_id, lead_recu_le, lead_attribue_le)
        VALUES (?,?,?,?,?,?,?, 'prospect', ?, ?, ?, ?, ?, ?, ?)")
        ->execute([texte($d['prenom'] ?? '', 60), texte($d['nom'] ?? '', 60), texte($d['telephone'] ?? '', 30), mb_strtolower(texte($d['courriel'] ?? '', 120)),
            texte($d['commune'] ?? '', 60), texte($d['interets'] ?? '', 60), texte($d['notes'] ?? '', 500), $origine, $maintenant, $maintenant,
            $agence, $technicien, $d['recu_le'] ?? $maintenant, $technicien ? $maintenant : null]);
    $id = (int) $db->lastInsertId();
    tracer(['courriel' => 'systeme', 'role' => '', 'agence_id' => $agence], 'lead attribué', "$id $origine : $regle" . ($technicien ? '' : ', aucun technicien disponible'));
    if ($technicien) {
        notifier_technicien_lead($technicien, $id, $d);
    } else {
        notifier_chefs($agence, 'Un lead attend un technicien', "Nouvelle demande de " . texte($d['prenom'] ?? '', 60) . " à " . texte($d['commune'] ?? '', 60) . ", aucun technicien disponible dans l'agence. À attribuer depuis l'outil : " . url_base() . "/#/client/$id");
    }
    return $id;
}

// Le premier geste vers le client ferme le chrono. Une fois seulement : on ne réécrit pas l'histoire.
function marquer_lead_contacte(int $client, string $par): void
{
    base()->prepare('UPDATE clients SET lead_contacte_le = ?, lead_contact_par = ? WHERE id = ? AND lead_recu_le IS NOT NULL AND lead_contacte_le IS NULL')
        ->execute([maintenant(), $par, $client]);
}

/* ---------- Courriels, au mieux : l'outil reste utilisable sans eux ---------- */

function envoyer_courriel(string $a, string $objet, string $corps): bool
{
    if (!filter_var($a, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $entetes = implode("\r\n", ['From: Maison Flamingo <' . EXPEDITEUR . '>', 'Reply-To: ' . EXPEDITEUR, 'MIME-Version: 1.0', 'Content-Type: text/plain; charset=UTF-8']);
    $ok = @mail($a, '=?UTF-8?B?' . base64_encode($objet) . '?=', $corps, $entetes, '-f' . EXPEDITEUR);
    if (!$ok) {
        tracer('systeme', 'courriel non parti', $objet);
    }
    return $ok;
}

function notifier_technicien_lead(int $technicien, int $client, array $d): void
{
    $q = base()->prepare('SELECT courriel, nom FROM utilisateurs WHERE id = ?');
    $q->execute([$technicien]);
    $t = $q->fetch();
    if (!$t) {
        return;
    }
    envoyer_courriel($t['courriel'], 'Nouveau lead : ' . texte($d['prenom'] ?? '', 60) . ' à ' . texte($d['commune'] ?? '', 60),
        "Bonjour " . explode(' ', $t['nom'])[0] . ",\n\nUne nouvelle demande vient d'arriver et t'est confiée :\n"
        . texte($d['prenom'] ?? '', 60) . ' ' . texte($d['nom'] ?? '', 60) . "\n" . texte($d['commune'] ?? '', 60) . "\n" . texte($d['telephone'] ?? '', 30)
        . "\nIntérêt : " . (texte($d['interets'] ?? '', 60) ?: '-') . "\n\nL'objectif de la maison : un premier contact dans la journée.\n"
        . url_base() . "/#/client/$client\n\nMaison Flamingo");
}

function notifier_chefs(int $agence, string $objet, string $corps): void
{
    $q = base()->prepare("SELECT courriel FROM utilisateurs WHERE actif = 1 AND hash <> '' AND (role = 'superadmin' OR (role = 'chef' AND agence_id = ?))");
    $q->execute([$agence]);
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $c) {
        envoyer_courriel((string) $c, $objet, $corps . "\n\nMaison Flamingo");
    }
}

/* ---------- Actions ---------- */

function a_leads(): void
{
    $u = exiger_equipe();
    [$w, $p] = portee_clients($u);
    $tous = ($_GET['tous'] ?? '') === '1';
    $q = base()->prepare("SELECT c.id, c.prenom, c.nom, c.telephone, c.commune, c.offre, c.origine, c.statut, c.lead_recu_le, c.lead_attribue_le, c.lead_contacte_le, c.lead_contact_par,
            t.nom AS technicien, a.nom AS agence
        FROM clients c LEFT JOIN utilisateurs t ON t.id = c.technicien_id LEFT JOIN agences a ON a.id = c.agence_id
        WHERE ($w) AND c.lead_recu_le IS NOT NULL" . ($tous ? '' : " AND c.lead_contacte_le IS NULL AND c.statut NOT IN ('perdu', 'desinscrit')") . ' ORDER BY c.lead_recu_le ' . ($tous ? 'DESC' : 'ASC') . ' LIMIT 200');
    $q->execute($p);
    sortir(200, ['ok' => true, 'leads' => $q->fetchAll(), 'retard_h' => LEAD_RETARD_H]);
}

function a_lead_contacte(): void
{
    $u = exiger_equipe();
    exiger_post();
    $c = exiger_client_autorise($u, entier(entree()['id'] ?? 0));
    marquer_lead_contacte((int) $c['id'], $u['nom']);
    tracer($u, 'lead contacté', (string) $c['id']);
    sortir(200, ['ok' => true]);
}

function a_lead_creer(): void
{
    // Un lead reçu par téléphone ou au comptoir : même chrono que ceux du site.
    $u = exiger_equipe(['chef', 'superadmin']);
    exiger_post();
    $d = entree();
    if (texte($d['prenom'] ?? '', 60) === '' && texte($d['nom'] ?? '', 60) === '') {
        sortir(200, ['ok' => false, 'erreur' => 'nom']);
    }
    $id = enregistrer_lead($d, 'saisie ' . $u['nom']);
    if ($id === null) {
        sortir(200, ['ok' => false, 'erreur' => 'doublon']);
    }
    if ($u['role'] === 'chef') {
        // Un chef ne crée que chez lui, quel que soit le secteur de la commune.
        base()->prepare('UPDATE clients SET agence_id = ?, technicien_id = COALESCE((SELECT id FROM utilisateurs WHERE id = clients.technicien_id AND agence_id = ?), ?) WHERE id = ?')
            ->execute([$u['agence_id'], $u['agence_id'], technicien_le_moins_charge((int) $u['agence_id']), $id]);
    }
    sortir(200, ['ok' => true, 'id' => $id]);
}

function a_secteurs(): void
{
    $u = exiger_equipe(['chef', 'superadmin']);
    $db = base();
    $sql = 'SELECT s.*, a.nom AS agence, t.nom AS technicien FROM secteurs s JOIN agences a ON a.id = s.agence_id LEFT JOIN utilisateurs t ON t.id = s.technicien_id';
    if ($u['role'] === 'chef') {
        $q = $db->prepare("$sql WHERE s.agence_id = ? ORDER BY s.libelle");
        $q->execute([$u['agence_id']]);
    } else {
        $q = $db->query("$sql ORDER BY a.nom, s.libelle");
    }
    // Les communes vues chez les clients mais sans secteur : c'est là que l'attribution tombe en « hors secteur ».
    $vues = $db->query("SELECT DISTINCT commune FROM clients WHERE commune <> ''")->fetchAll(PDO::FETCH_COLUMN);
    $connues = array_column($q->fetchAll(), null, 'commune');
    $sans = [];
    foreach ($vues as $c) {
        $n = normaliser_commune((string) $c);
        if ($n !== '' && !isset($connues[$n]) && !in_array($n, ['autre commune'], true)) {
            $sans[$n] = $c;
        }
    }
    $t = $db->prepare("SELECT id, nom, agence_id FROM utilisateurs WHERE role = 'technicien' AND actif = 1" . ($u['role'] === 'chef' ? ' AND agence_id = ?' : '') . ' ORDER BY nom');
    $t->execute($u['role'] === 'chef' ? [$u['agence_id']] : []);
    sortir(200, ['ok' => true, 'secteurs' => array_values($connues), 'sans_secteur' => array_values($sans),
        'techniciens' => $t->fetchAll(), 'agences' => $u['role'] === 'superadmin' ? $db->query('SELECT id, nom FROM agences WHERE actif = 1 ORDER BY nom')->fetchAll() : []]);
}

function a_secteur_enregistrer(): void
{
    $u = exiger_equipe(['chef', 'superadmin']);
    exiger_post();
    $d = entree();
    $libelle = texte($d['commune'] ?? '', 60);
    $cle = normaliser_commune($libelle);
    if ($cle === '') {
        sortir(200, ['ok' => false, 'erreur' => 'commune']);
    }
    $db = base();
    $existant = $db->prepare('SELECT * FROM secteurs WHERE commune = ?');
    $existant->execute([$cle]);
    $s = $existant->fetch();
    // La direction place une commune dans une agence ; un chef ne touche qu'aux communes de la sienne.
    $agence = $u['role'] === 'superadmin' ? entier($d['agence_id'] ?? ($s['agence_id'] ?? 0)) : (int) $u['agence_id'];
    if ($u['role'] === 'chef' && $s && (int) $s['agence_id'] !== (int) $u['agence_id']) {
        tracer($u, 'secteur refusé', $libelle);
        sortir(404, ['ok' => false, 'erreur' => 'introuvable']);
    }
    $ok = $db->prepare('SELECT id FROM agences WHERE id = ? AND actif = 1');
    $ok->execute([$agence]);
    if ($ok->fetchColumn() === false) {
        sortir(200, ['ok' => false, 'erreur' => 'agence']);
    }
    $technicien = technicien_valable(entier($d['technicien_id'] ?? 0), $agence);
    if (!empty($d['supprimer'])) {
        $db->prepare('DELETE FROM secteurs WHERE commune = ?')->execute([$cle]);
    } else {
        $db->prepare('INSERT INTO secteurs (commune, libelle, agence_id, technicien_id, maj_le) VALUES (?,?,?,?,?)
            ON CONFLICT(commune) DO UPDATE SET libelle = excluded.libelle, agence_id = excluded.agence_id, technicien_id = excluded.technicien_id, maj_le = excluded.maj_le')
            ->execute([$cle, $libelle, $agence, $technicien, maintenant()]);
    }
    tracer($u, 'secteur enregistré', "$libelle → agence $agence" . ($technicien ? ", technicien $technicien" : ''));
    sortir(200, ['ok' => true]);
}

// Ce qui attend la personne connectée : les badges du menu, rafraîchis toutes les minutes.
function a_a_faire(): void
{
    $u = exiger_equipe();
    [$w, $p] = portee_clients($u);
    $db = base();
    $l = $db->prepare("SELECT COUNT(*), SUM(julianday('now') - julianday(c.lead_attribue_le) > ?) FROM clients c WHERE ($w) AND c.lead_recu_le IS NOT NULL AND c.lead_contacte_le IS NULL AND c.statut NOT IN ('perdu', 'desinscrit')");
    $l->execute([LEAD_RETARD_H / 24, ...$p]);
    [$leads, $retard] = $l->fetch(PDO::FETCH_NUM);
    $d = $db->prepare("SELECT COUNT(*) FROM demandes d JOIN clients c ON c.id = d.client_id WHERE ($w) AND d.traitee_le IS NULL");
    $d->execute($p);
    $sans = 0;
    if ($u['role'] !== 'technicien') {
        $s = $db->prepare("SELECT COUNT(*) FROM clients c WHERE ($w) AND c.lead_recu_le IS NOT NULL AND c.lead_contacte_le IS NULL AND c.technicien_id IS NULL");
        $s->execute($p);
        $sans = (int) $s->fetchColumn();
    }
    sortir(200, ['ok' => true, 'leads' => (int) $leads, 'leads_retard' => (int) $retard, 'leads_sans_technicien' => $sans, 'demandes' => (int) $d->fetchColumn()]);
}

// Les indicateurs de réponse : par technicien dans une agence, par agence pour la direction. Trente jours glissants.
function kpi_leads(string $w, array $p, string $groupe): array
{
    $depuis = date('c', strtotime('-30 days'));
    $champ = $groupe === 'agence' ? 'c.agence_id' : 'c.technicien_id';
    $q = base()->prepare("SELECT $champ AS cle, COUNT(*) AS recus, SUM(c.lead_contacte_le IS NOT NULL) AS contactes,
            AVG(CASE WHEN c.lead_contacte_le IS NOT NULL THEN (julianday(c.lead_contacte_le) - julianday(c.lead_attribue_le)) * 24 END) AS delai_h,
            SUM(c.lead_contacte_le IS NULL AND julianday('now') - julianday(COALESCE(c.lead_attribue_le, c.lead_recu_le)) > ?) AS en_retard,
            SUM(c.statut = 'client') AS devenus_clients
        FROM clients c WHERE ($w) AND c.lead_recu_le >= ? GROUP BY $champ");
    $q->execute([LEAD_RETARD_H / 24, ...$p, $depuis]);
    $par = [];
    foreach ($q->fetchAll() as $l) {
        $par[(string) $l['cle']] = ['recus' => (int) $l['recus'], 'contactes' => (int) $l['contactes'], 'taux_reponse' => $l['recus'] ? round($l['contactes'] / $l['recus'], 2) : null,
            'delai_moyen_h' => $l['delai_h'] !== null ? round((float) $l['delai_h'], 1) : null, 'en_retard' => (int) $l['en_retard'], 'devenus_clients' => (int) $l['devenus_clients']];
    }
    return $par;
}

function a_motdepasse_oublie(): void
{
    // Toujours la même réponse, que l'adresse existe ou non : rien à deviner. Le lien part par courriel, s'il part.
    exiger_post();
    $ip = 'oubli:' . empreinte_ip();
    if (freine($ip, 5)) {
        sortir(429, ['ok' => false, 'erreur' => 'trop']);
    }
    noter_echec($ip);
    $d = entree();
    $courriel = mb_strtolower(texte($d['courriel'] ?? '', 120));
    $genre = ($d['genre'] ?? '') === 'client' ? 'client' : 'equipe';
    $db = base();
    if ($genre === 'equipe') {
        $q = $db->prepare("SELECT id, nom FROM utilisateurs WHERE courriel = ? AND actif = 1 AND hash <> ''");
        $q->execute([$courriel]);
        if ($m = $q->fetch()) {
            $lien = url_base() . '/#/invitation/' . creer_invitation('equipe', (int) $m['id'], null);
            envoyer_courriel($courriel, 'Votre nouveau lien de connexion', "Bonjour " . explode(' ', $m['nom'])[0] . ",\n\nVoici votre lien pour choisir un nouveau mot de passe. Il sert une fois, pendant sept jours :\n$lien\n\nSi vous n'avez rien demandé, ignorez ce message : votre mot de passe actuel reste valable.\n\nMaison Flamingo");
            tracer($courriel, 'mot de passe oublié', 'lien envoyé');
        }
    } else {
        $q = $db->prepare('SELECT cc.client_id, c.prenom FROM comptes_clients cc JOIN clients c ON c.id = cc.client_id WHERE cc.courriel = ? AND cc.actif = 1');
        $q->execute([$courriel]);
        if ($m = $q->fetch()) {
            $lien = url_base() . '/espace.html#/invitation/' . creer_invitation('client', (int) $m['client_id'], null);
            envoyer_courriel($courriel, 'Votre accès à votre espace Maison Flamingo', "Bonjour " . $m['prenom'] . ",\n\nVoici votre lien pour choisir un nouveau mot de passe. Il sert une fois, pendant sept jours :\n$lien\n\nSi vous n'avez rien demandé, ignorez ce message.\n\nMaison Flamingo · " . TELEPHONE);
            tracer('client ' . $m['client_id'], 'mot de passe oublié', 'lien envoyé');
        }
    }
    sortir(200, ['ok' => true]);
}
