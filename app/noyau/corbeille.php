<?php
// Maison Flamingo · la corbeille : ce qui est effacé ou remplacé dans la fiche et le carnet d'un client.
//
// Un technicien peut effacer une note ou un équipement du carnet, et réécrire la fiche de ses clients. Ces gestes
// restent utiles, donc permis ; mais rien de ce qu'ils retirent ne disparaît : l'ancienne version part ici, entière,
// avec qui l'a retirée et quand. Un chef ou la direction la remet d'un clic, sans restaurer toute la base et sans
// perdre ce que les autres ont saisi depuis. Remettre est lui-même réversible : la version écartée repart ici.
// Au bout de 90 jours, la corbeille se vide : on ne garde pas indéfiniment des données qu'on a voulu retirer.

declare(strict_types=1);

const CORBEILLE_JOURS = 90;
const CORBEILLE_QUOI = [
    'clients' => 'Fiche client', 'carnet_evenements' => 'Calendrier du carnet', 'carnet_equipements' => 'Équipement du carnet',
    'carnet_postes' => 'Poste du carnet', 'carnet_documents' => 'Document du carnet',
];
// Les champs d'une fiche client que l'écran montre et qu'une personne écrit ; le reste (position, dates) se recalcule.
const CHAMPS_CLIENT = ['prenom', 'nom', 'telephone', 'courriel', 'adresse', 'commune', 'offre', 'notes', 'acces', 'venu_par', 'statut', 'statut_motif', 'formule', 'technicien_id', 'jour_passage', 'agence_id'];

function dossier_corbeille(): string
{
    $d = dossier_donnees() . '/corbeille';
    if (!is_dir($d)) {
        @mkdir($d, 0700, true);
    }
    return $d;
}

// Ce qui a changé entre deux versions, champ par champ.
function ecarts(array $avant, array $apres, array $champs): array
{
    $e = [];
    foreach ($champs as $k) {
        if (array_key_exists($k, $apres) && (string) ($avant[$k] ?? '') !== (string) ($apres[$k] ?? '')) {
            $e[$k] = [(string) ($avant[$k] ?? ''), (string) ($apres[$k] ?? '')];
        }
    }
    return $e;
}

// L'avant et l'après, lisibles dans le journal ; un texte long y est coupé, la version entière reste dans la corbeille.
function texte_ecarts(array $e): string
{
    $court = fn ($v) => $v === '' ? 'vide' : '« ' . (mb_strlen($v) > 120 ? mb_substr($v, 0, 120) . '…' : $v) . ' »';
    return implode(' ; ', array_map(fn ($k, $v) => "$k : " . $court($v[0]) . ' → ' . $court($v[1]), array_keys($e), $e));
}

// Garde la ligne retirée (nature « efface ») ou la version remplacée (nature « remplace », avec les champs changés).
function mettre_a_la_corbeille($u, string $table, array $ligne, string $nature, string $libelle, array $champs = [], ?string $fichier = null): int
{
    $db = base();
    $db->prepare('INSERT INTO corbeille (quand, qui, role, agence_id, client_id, quoi, ligne_id, nature, libelle, contenu, champs, fichier) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([maintenant(), is_array($u) ? (string) $u['courriel'] : (string) $u, is_array($u) ? (string) $u['role'] : '', is_array($u) && isset($u['agence_id']) ? (int) $u['agence_id'] : null,
            $table === 'clients' ? (int) $ligne['id'] : (int) ($ligne['client_id'] ?? 0), $table, (int) ($ligne['id'] ?? 0), $nature, mb_substr($libelle, 0, 200),
            json_encode($ligne, JSON_UNESCAPED_UNICODE), json_encode(array_values($champs)), null]);
    $id = (int) $db->lastInsertId();
    if ($fichier !== null && is_file($fichier)) {
        // Le fichier change de dossier, il n'est pas effacé : le client ne le voit plus, la corbeille le garde.
        $dest = dossier_corbeille() . '/' . $id . '-' . basename($fichier);
        if (@rename($fichier, $dest)) {
            $db->prepare('UPDATE corbeille SET fichier = ? WHERE id = ?')->execute([basename($dest), $id]);
        }
    }
    return $id;
}

// Une version remplacée n'entre dans la corbeille que si quelque chose a vraiment changé. Rend le texte pour le journal.
function garder_version($u, string $table, array $avant, array $apres, array $champs, string $libelle): string
{
    $e = ecarts($avant, $apres, $champs);
    if ($e) {
        mettre_a_la_corbeille($u, $table, $avant, 'remplace', $libelle, array_keys($e));
    }
    return texte_ecarts($e);
}

function ligne_corbeille(int $id): ?array
{
    $q = base()->prepare('SELECT * FROM corbeille WHERE id = ?');
    $q->execute([$id]);
    return $q->fetch() ?: null;
}

function a_corbeille(): void
{
    $u = exiger_equipe(['chef', 'superadmin']);
    [$w, $p] = portee_clients($u);
    // Un chef voit ce qui touche les clients de son agence ; la direction voit tout, y compris un client effacé depuis.
    $q = base()->prepare("SELECT k.id, k.quand, k.qui, k.role, k.client_id, k.quoi, k.nature, k.libelle, k.contenu, k.champs, k.remis_le, k.remis_par, c.prenom, c.nom
        FROM corbeille k LEFT JOIN clients c ON c.id = k.client_id
        WHERE k.quand >= ? AND " . ($u['role'] === 'superadmin' ? '1=1' : "k.client_id IN (SELECT c.id FROM clients c WHERE $w)") . ' ORDER BY k.id DESC LIMIT 300');
    $q->execute([date('c', strtotime('-' . CORBEILLE_JOURS . ' days')), ...($u['role'] === 'superadmin' ? [] : $p)]);
    $l = [];
    foreach ($q->fetchAll() as $k) {
        $contenu = json_decode($k['contenu'], true) ?: [];
        $champs = json_decode($k['champs'], true) ?: [];
        $actuel = $k['nature'] === 'remplace' ? ligne_actuelle($k['quoi'], (int) ($contenu['id'] ?? 0)) : null;
        $l[] = ['id' => (int) $k['id'], 'quand' => $k['quand'], 'qui' => $k['qui'], 'quoi' => CORBEILLE_QUOI[$k['quoi']] ?? $k['quoi'], 'nature' => $k['nature'],
            'libelle' => $k['libelle'], 'client' => $k['prenom'] !== null ? trim($k['prenom'] . ' ' . $k['nom']) : 'client effacé depuis', 'client_id' => (int) $k['client_id'],
            'remis_le' => $k['remis_le'], 'remis_par' => $k['remis_par'],
            // Pour une version remplacée : ce qu'elle disait, et ce que dit la fiche aujourd'hui.
            'ecarts' => array_map(fn ($c) => ['champ' => $c, 'avant' => (string) ($contenu[$c] ?? ''), 'maintenant' => $actuel ? (string) ($actuel[$c] ?? '') : null], $champs)];
    }
    sortir(200, ['ok' => true, 'elements' => $l, 'jours' => CORBEILLE_JOURS]);
}

function ligne_actuelle(string $table, int $id): ?array
{
    if (!isset(CORBEILLE_QUOI[$table])) {
        return null;
    }
    $q = base()->prepare("SELECT * FROM $table WHERE id = ?");
    $q->execute([$id]);
    return $q->fetch() ?: null;
}

function a_corbeille_remettre(): void
{
    $u = exiger_equipe(['chef', 'superadmin']);
    exiger_post();
    $k = ligne_corbeille(entier(entree()['id'] ?? 0));
    // Un chef ne remet que ce qui touche un client de son agence ; un élément hors de sa portée n'existe pas pour lui.
    if (!$k || !isset(CORBEILLE_QUOI[$k['quoi']]) || ($u['role'] !== 'superadmin' && !client_autorise($u, (int) $k['client_id']))) {
        sortir(404, ['ok' => false, 'erreur' => 'introuvable']);
    }
    if ($k['remis_le']) {
        sortir(200, ['ok' => false, 'erreur' => 'deja']);
    }
    $table = $k['quoi'];
    $ligne = json_decode($k['contenu'], true) ?: [];
    $db = base();
    $colonnes = array_column($db->query("PRAGMA table_info($table)")->fetchAll(), 'name');
    $db->beginTransaction();
    if ($k['nature'] === 'efface') {
        if ($table !== 'clients' && !ligne_actuelle('clients', (int) $k['client_id'])) {
            $db->rollBack();
            sortir(200, ['ok' => false, 'erreur' => 'client']);
        }
        if (ligne_actuelle($table, (int) $ligne['id'])) {
            $db->rollBack();
            sortir(200, ['ok' => false, 'erreur' => 'present']);
        }
        $cols = array_values(array_intersect(array_keys($ligne), $colonnes));
        $db->prepare("INSERT INTO $table (" . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')
            ->execute(array_map(fn ($c) => $ligne[$c], $cols));
        if ($table === 'carnet_documents' && $k['fichier']) {
            $dossier = dossier_documents((int) $k['client_id']);
            if (!is_dir($dossier)) {
                @mkdir($dossier, 0700, true);
            }
            if (!@rename(dossier_corbeille() . '/' . basename($k['fichier']), $dossier . '/' . basename((string) $ligne['fichier']))) {
                $db->rollBack();
                sortir(200, ['ok' => false, 'erreur' => 'fichier']);
            }
        }
    } else {
        $actuel = ligne_actuelle($table, (int) $ligne['id']);
        if (!$actuel) {
            $db->rollBack();
            sortir(200, ['ok' => false, 'erreur' => 'introuvable']);
        }
        $champs = array_values(array_intersect(json_decode($k['champs'], true) ?: [], $colonnes));
        // La version qu'on écarte en remettant l'ancienne part à son tour dans la corbeille : remettre se défait aussi.
        mettre_a_la_corbeille($u, $table, $actuel, 'remplace', $k['libelle'] . ' (version écartée en remettant la précédente)', array_keys(ecarts($actuel, $ligne, $champs)));
        $db->prepare("UPDATE $table SET " . implode(', ', array_map(fn ($c) => "$c = ?", $champs)) . ' WHERE id = ?')
            ->execute([...array_map(fn ($c) => $ligne[$c], $champs), $ligne['id']]);
    }
    $db->prepare('UPDATE corbeille SET remis_le = ?, remis_par = ? WHERE id = ?')->execute([maintenant(), $u['courriel'], $k['id']]);
    $db->commit();
    tracer($u, 'corbeille : remis', (CORBEILLE_QUOI[$table]) . ', client ' . $k['client_id'] . ' : ' . $k['libelle'] . ' (retiré par ' . $k['qui'] . ' le ' . substr($k['quand'], 0, 10) . ')');
    sortir(200, ['ok' => true]);
}

// Lancé par la tâche de nuit.
function purger_corbeille(): int
{
    $limite = date('c', strtotime('-' . CORBEILLE_JOURS . ' days'));
    $q = base()->prepare('SELECT id, fichier FROM corbeille WHERE quand < ?');
    $q->execute([$limite]);
    $n = 0;
    foreach ($q->fetchAll() as $k) {
        if ($k['fichier']) {
            @unlink(dossier_corbeille() . '/' . basename($k['fichier']));
        }
        $n++;
    }
    base()->prepare('DELETE FROM corbeille WHERE quand < ?')->execute([$limite]);
    if ($n) {
        tracer('systeme', 'corbeille vidée', "$n élément(s) de plus de " . CORBEILLE_JOURS . ' jours');
    }
    return $n;
}
