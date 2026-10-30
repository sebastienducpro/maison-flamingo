<?php
// Maison Flamingo · le relevé du lundi.
//
// Chaque lundi, la tâche de nuit envoie à la direction les chiffres de toute l'entreprise, et à chaque chef ceux de
// son agence : les chiffres du lundi, ce qu'il y a à faire et qui agit. Le pilotage vient au dirigeant au lieu
// qu'il aille le chercher : c'est ce qui permet à l'entreprise de tourner sans lui.
// Le calcul est celui de l'écran Pilotage, refait avec les droits de chaque destinataire : un chef ne reçoit jamais
// les chiffres d'argent. Un courriel peut se perdre ; l'écran, lui, reste la référence.

declare(strict_types=1);

const NIVEAUX_RELEVE = ['ok' => 'dans la cible', 'attention' => 'à surveiller', 'alerte' => 'ALERTE', 'trop_belle' => 'à vérifier', 'a_saisir' => 'à saisir', 'pas_assez' => 'pas encore assez de données'];

function texte_releve(array $u, array $d): array
{
    $alertes = count(array_filter($d['recommandations'], fn ($r) => $r['niveau'] === 'alerte'));
    $jour = jour_en_lettres(time());
    $objet = 'Maison Flamingo · ' . $jour . ' : ' . ($alertes ? $alertes . ' alerte' . ($alertes > 1 ? 's' : '') : (count($d['recommandations']) ? count($d['recommandations']) . ' point' . (count($d['recommandations']) > 1 ? 's' : '') . ' à suivre' : 'rien ne décroche'));
    $l = ['Bonjour ' . explode(' ', $u['nom'])[0] . ',', '', $d['direction'] ? 'Le relevé de toute l\'entreprise.' : 'Le relevé de votre agence.', '', 'LES CHIFFRES DU LUNDI'];
    foreach ($d['lundi'] as $i) {
        $l[] = '- ' . $i['libelle'] . ' : ' . ($i['valeur'] ?? '–') . ' (cible ' . $i['cible'] . ') · ' . (NIVEAUX_RELEVE[$i['etat']] ?? $i['etat']);
    }
    if ($d['arbitrages']) {
        $l[] = '';
        $l[] = 'LES ARBITRAGES DU MOMENT';
        foreach ($d['arbitrages'] as $a) {
            $l[] = '- ' . $a['titre'] . ' : ' . $a['texte'];
        }
    }
    $l[] = '';
    $l[] = $d['recommandations'] ? 'À FAIRE' : 'À FAIRE : rien ne décroche. Gardez le rythme : rapports, jour fixe, message du vendredi.';
    foreach ($d['recommandations'] as $n => $r) {
        $l[] = ($n + 1) . '. ' . ($r['niveau'] === 'alerte' ? '[ALERTE] ' : '') . $r['titre'] . ' · ' . $r['qui'];
        if ($r['detail']) {
            $l[] = '   ' . $r['detail'];
        }
        foreach ($r['actions'] as $x) {
            $l[] = '   - ' . $x;
        }
    }
    if ($d['direction']) {
        $s = derniere_sauvegarde();
        $x = derniere_copie_externe();
        $l[] = '';
        $l[] = 'SAUVEGARDES';
        $l[] = '- Dernière copie : ' . ($s ? date('d/m à H:i', strtotime($s['quand'])) . (strtotime($s['quand']) < time() - SAUVEGARDE_AGE_MAX ? ', EN RETARD' : '') : 'aucune');
        $l[] = '- Hors de l\'hébergeur : ' . (!externe_config() ? 'pas configuré' : ($x ? date('d/m à H:i', strtotime($x['externe_le'])) . (strtotime($x['externe_le']) < time() - SAUVEGARDE_AGE_MAX ? ', EN RETARD' : '') : 'aucune copie arrivée'));
    }
    $l[] = '';
    $l[] = 'Le détail, et la saisie du mois : https://app.maisonflamingo.fr/#/pilotage';
    $l[] = 'Les recommandations proposent ; c\'est toujours vous qui décidez.';
    $l[] = '';
    $l[] = 'Ne plus recevoir ce relevé : décochez « Recevoir le relevé chaque lundi » en bas de l\'écran Pilotage.';
    return [$objet, implode("\n", $l)];
}

// La date en toutes lettres sans dépendre de l'extension intl, absente de certains hébergements.
function jour_en_lettres(int $t): string
{
    $jours = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
    $mois = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    return $jours[(int) date('w', $t)] . ' ' . (int) date('j', $t) . ' ' . $mois[(int) date('n', $t) - 1];
}

function envoyer_releve(array $u): bool
{
    [$objet, $corps] = texte_releve($u, calculer_pilotage($u, $u['role'] === 'chef' ? (int) $u['agence_id'] : null));
    $ok = envoyer_courriel($u['courriel'], $objet, $corps);
    tracer('systeme', $ok ? 'relevé envoyé' : 'relevé non parti', $u['courriel']);
    return $ok;
}

// Lancé par la tâche de nuit. Le lundi seulement, et une seule fois même si la tâche tourne deux fois.
function releves_du_lundi(): array
{
    if ((int) date('N') !== 1) {
        return ['envoyes' => 0, 'raison' => 'pas lundi'];
    }
    $deja = base()->prepare("SELECT COUNT(*) FROM journal WHERE action = 'relevés du lundi' AND quand >= ?");
    $deja->execute([date('Y-m-d')]);
    if ((int) $deja->fetchColumn() > 0) {
        return ['envoyes' => 0, 'raison' => 'déjà envoyés'];
    }
    $q = base()->query("SELECT * FROM utilisateurs WHERE role IN ('superadmin', 'chef') AND actif = 1 AND hash <> '' AND releve_lundi = 1 AND expire_le IS NULL");
    $n = 0;
    foreach ($q->fetchAll() as $u) {
        $n += envoyer_releve($u) ? 1 : 0;
    }
    tracer('systeme', 'relevés du lundi', "$n envoyé(s)");
    return ['envoyes' => $n];
}

function a_releve_essai(): void
{
    $u = exiger_equipe(['chef', 'superadmin']);
    exiger_post();
    $q = base()->prepare('SELECT * FROM utilisateurs WHERE id = ?');
    $q->execute([$u['id']]);
    $ok = envoyer_releve($q->fetch());
    sortir(200, ['ok' => $ok, 'courriel' => $u['courriel']]);
}

function a_releve_preference(): void
{
    $u = exiger_equipe(['chef', 'superadmin']);
    exiger_post();
    $oui = !empty(entree()['recevoir']) ? 1 : 0;
    base()->prepare('UPDATE utilisateurs SET releve_lundi = ? WHERE id = ?')->execute([$oui, $u['id']]);
    tracer($u, $oui ? 'relevé du lundi activé' : 'relevé du lundi coupé');
    sortir(200, ['ok' => true, 'recevoir' => (bool) $oui]);
}
