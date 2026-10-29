<?php
// Maison Flamingo · l'espace des clients.
//
// Un client ne voit que sa propre piscine : son identité vient de sa session,
// jamais d'un identifiant envoyé par la page. Aucune note interne, aucun nom
// d'autre client, aucune donnée de l'équipe ne passe par ici.

declare(strict_types=1);

function a_espace_etat(): void
{
    $c = session_client();
    sortir(200, ['ok' => true, 'client' => $c ? ['prenom' => $c['prenom'], 'courriel' => $c['compte_courriel']] : null]);
}

function a_espace_connexion(): void
{
    exiger_post();
    $d = entree();
    $courriel = mb_strtolower(texte($d['courriel'] ?? '', 120));
    $ip = 'ipc:' . empreinte_ip();
    $compte = 'client:' . hash('sha256', $courriel);
    if (freine($ip, ESSAIS_IP) || freine($compte, ESSAIS_COMPTE)) {
        tracer('client ' . $courriel, 'connexion client freinée');
        sortir(429, ['ok' => false, 'erreur' => 'trop']);
    }
    $q = base()->prepare('SELECT * FROM comptes_clients WHERE courriel = ?');
    $q->execute([$courriel]);
    $cc = $q->fetch();
    if (!$cc || (int) $cc['actif'] !== 1 || !password_verify((string) ($d['motdepasse'] ?? ''), $cc['hash'])) {
        noter_echec($ip);
        noter_echec($compte);
        tracer('client ' . ($courriel ?: 'anonyme'), 'connexion client refusée');
        sortir(200, ['ok' => false, 'erreur' => 'identifiants']);
    }
    $brut = jeton(32);
    $expire = time() + DUREE_SESSION_CLIENT;
    $db = base();
    $db->prepare('INSERT INTO sessions_clients (jeton, compte_id, expire) VALUES (?,?,?)')->execute([hash('sha256', $brut), $cc['id'], $expire]);
    $db->prepare('DELETE FROM sessions_clients WHERE expire < ?')->execute([time()]);
    $db->prepare('UPDATE comptes_clients SET derniere_connexion = ? WHERE id = ?')->execute([maintenant(), $cc['id']]);
    poser_cookie('flamingo_c', $brut, $expire);
    tracer('client ' . $cc['client_id'], 'connexion client');
    sortir(200, ['ok' => true]);
}

function a_espace_deconnexion(): void
{
    $c = $_COOKIE['flamingo_c'] ?? '';
    if (is_string($c) && $c !== '') {
        base()->prepare('DELETE FROM sessions_clients WHERE jeton = ?')->execute([hash('sha256', $c)]);
    }
    poser_cookie('flamingo_c', '', time() - 3600);
    sortir(200, ['ok' => true]);
}

function a_espace_accueil(): void
{
    $c = exiger_client_connecte();
    $db = base();
    $v = $db->prepare('SELECT type, quand, jeton, alertes FROM visites WHERE client_id = ? ORDER BY quand DESC LIMIT 60');
    $v->execute([$c['id']]);
    $t = $db->prepare('SELECT nom FROM utilisateurs WHERE id = ? AND actif = 1');
    $t->execute([(int) $c['technicien_id']]);
    $technicien = (string) ($t->fetchColumn() ?: '');
    $dm = $db->prepare('SELECT texte, cree_le, traitee_le, reponse FROM demandes WHERE client_id = ? ORDER BY id DESC LIMIT 20');
    $dm->execute([$c['id']]);
    sortir(200, ['ok' => true,
        'client' => ['prenom' => $c['prenom'], 'commune' => $c['commune'], 'formule' => $c['formule'], 'statut' => $c['statut'],
            'jour' => JOURS[(int) $c['jour_passage']] ?? null, 'technicien' => $technicien !== '' ? explode(' ', $technicien)[0] : null],
        'visites' => array_map(fn ($x) => ['type' => $x['type'], 'quand' => $x['quand'], 'lien' => url_rapport($x['jeton']),
            'points' => $x['alertes'] !== null ? (int) $x['alertes'] : null], $v->fetchAll()),
        'demandes' => $dm->fetchAll(), 'telephone' => TELEPHONE]);
}

function a_espace_demande(): void
{
    $c = exiger_client_connecte();
    exiger_post();
    $texte = texte(entree()['texte'] ?? '', 1000);
    if (mb_strlen($texte) < 3) {
        sortir(200, ['ok' => false, 'erreur' => 'vide']);
    }
    $db = base();
    // Dix demandes ouvertes au plus : au-delà, le téléphone est le bon outil.
    $n = $db->prepare('SELECT COUNT(*) FROM demandes WHERE client_id = ? AND traitee_le IS NULL');
    $n->execute([$c['id']]);
    if ((int) $n->fetchColumn() >= 10) {
        tracer('client ' . $c['id'], 'demande refusée', 'trop de demandes ouvertes');
        sortir(200, ['ok' => false, 'erreur' => 'trop']);
    }
    $db->prepare('INSERT INTO demandes (client_id, texte, cree_le) VALUES (?,?,?)')->execute([$c['id'], $texte, maintenant()]);
    tracer('client ' . $c['id'], 'demande client');
    notifier_chefs((int) $c['agence_id'], 'Demande de ' . $c['prenom'] . ' (' . $c['commune'] . ')', $texte . "\n\nÀ traiter depuis l'outil : " . url_base() . '/#/demandes');
    sortir(200, ['ok' => true]);
}

function a_espace_motdepasse(): void
{
    $c = exiger_client_connecte();
    exiger_post();
    $d = entree();
    $q = base()->prepare('SELECT hash FROM comptes_clients WHERE id = ?');
    $q->execute([$c['compte_id']]);
    if (!password_verify((string) ($d['ancien'] ?? ''), (string) $q->fetchColumn())) {
        tracer('client ' . $c['id'], 'changement de mot de passe client refusé');
        sortir(200, ['ok' => false, 'erreur' => 'ancien']);
    }
    if (!motdepasse_valable((string) ($d['nouveau'] ?? ''))) {
        sortir(200, ['ok' => false, 'erreur' => 'court']);
    }
    $db = base();
    $db->prepare('UPDATE comptes_clients SET hash = ? WHERE id = ?')->execute([hacher((string) $d['nouveau']), $c['compte_id']]);
    $db->prepare('DELETE FROM sessions_clients WHERE compte_id = ? AND jeton <> ?')->execute([$c['compte_id'], hash('sha256', (string) $_COOKIE['flamingo_c'])]);
    tracer('client ' . $c['id'], 'mot de passe client changé');
    sortir(200, ['ok' => true]);
}
