<?php
// Maison Flamingo · la carte et l'équilibre des journées.
//
// Une journée ne se compte pas en visites mais en heures : dix chantiers lourds
// pèsent plus que vingt contrôles. On estime donc chaque arrêt à sa durée
// réellement mesurée, on ajoute les trajets, et on propose l'ordre le plus court.
// Les distances sont à vol d'oiseau majoré de 30 % : une estimation honnête,
// pas un guidage routier, et l'écran le dit.

declare(strict_types=1);

function geocoder(string $adresse, string $commune): ?array
{
    $q = trim($adresse . ' ' . $commune);
    if (mb_strlen($q) < 5) {
        return null;
    }
    // Service public de la Géoplateforme (IGN), sans compte ni clé. Seule l'adresse part, jamais le nom.
    $url = 'https://data.geopf.fr/geocodage/search?limit=1&q=' . rawurlencode($q);
    $ctx = stream_context_create(['http' => ['timeout' => 4, 'header' => "User-Agent: MaisonFlamingo/1.0\r\n"]]);
    $brut = @file_get_contents($url, false, $ctx);
    if ($brut === false) {
        return null;
    }
    $d = json_decode($brut, true);
    $f = $d['features'][0] ?? null;
    if (!is_array($f) || !isset($f['geometry']['coordinates'][1])) {
        return null;
    }
    return ['lat' => (float) $f['geometry']['coordinates'][1], 'lon' => (float) $f['geometry']['coordinates'][0], 'score' => (float) ($f['properties']['score'] ?? 0)];
}

function distance_km(float $a1, float $o1, float $a2, float $o2): float
{
    $r = 6371.0;
    $da = deg2rad($a2 - $a1);
    $do = deg2rad($o2 - $o1);
    $h = sin($da / 2) ** 2 + cos(deg2rad($a1)) * cos(deg2rad($a2)) * sin($do / 2) ** 2;
    return 2 * $r * asin(min(1.0, sqrt($h))) * DETOUR_ROUTE;
}

function minutes_route(float $km): int
{
    return (int) round($km / VITESSE_KMH * 60);
}

// Durée estimée par client : la médiane de ses dernières visites mesurées, sinon la valeur par défaut de sa formule.
function estimer_durees(array $ids, string $type = 'passage', array $formules = []): array
{
    $sortie = [];
    foreach ($ids as $id) {
        $sortie[$id] = ($type === 'passage' ? DUREE_FORMULE[$formules[$id] ?? ''] ?? null : null) ?? DUREE_DEFAUT[$type] ?? 30;
    }
    if (!$ids) {
        return $sortie;
    }
    $marques = implode(',', array_fill(0, count($ids), '?'));
    $q = base()->prepare("SELECT client_id, duree_min FROM visites WHERE client_id IN ($marques) AND type = ? AND duree_min IS NOT NULL ORDER BY quand DESC");
    $q->execute([...$ids, $type]);
    $par = [];
    foreach ($q->fetchAll() as $l) {
        if (count($par[$l['client_id']] ?? []) < 8) {
            $par[$l['client_id']][] = (int) $l['duree_min'];
        }
    }
    foreach ($par as $id => $durees) {
        if (count($durees) >= 3) {
            sort($durees);
            $sortie[$id] = $durees[intdiv(count($durees), 2)];
        }
    }
    return $sortie;
}

function longueur_parcours(?array $depart, array $arrets): float
{
    $km = 0.0;
    $prec = $depart;
    foreach ($arrets as $a) {
        if ($prec) {
            $km += distance_km($prec['lat'], $prec['lon'], $a['lat'], $a['lon']);
        }
        $prec = $a;
    }
    if ($depart && $prec && $arrets) {
        $km += distance_km($prec['lat'], $prec['lon'], $depart['lat'], $depart['lon']);
    }
    return $km;
}

// Plus proche voisin, puis échanges de segments (2-opt) tant que le trajet raccourcit.
// Pour vingt-cinq arrêts au plus, c'est instantané et à quelques pour cent de l'optimum.
function optimiser_ordre(?array $depart, array $arrets): array
{
    $restants = array_values($arrets);
    if (count($restants) < 3) {
        return $restants;
    }
    $ordre = [];
    $courant = $depart ?: array_shift($restants);
    if (!$depart) {
        $ordre[] = $courant;
    }
    while ($restants) {
        $meilleur = 0;
        $dmin = INF;
        foreach ($restants as $i => $a) {
            $d = distance_km($courant['lat'], $courant['lon'], $a['lat'], $a['lon']);
            if ($d < $dmin) {
                $dmin = $d;
                $meilleur = $i;
            }
        }
        $courant = $restants[$meilleur];
        $ordre[] = $courant;
        array_splice($restants, $meilleur, 1);
    }
    $n = count($ordre);
    $ameliore = true;
    $tours = 0;
    while ($ameliore && $tours++ < 50) {
        $ameliore = false;
        for ($i = 0; $i < $n - 1; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $essai = array_merge(array_slice($ordre, 0, $i), array_reverse(array_slice($ordre, $i, $j - $i + 1)), array_slice($ordre, $j + 1));
                if (longueur_parcours($depart, $essai) + 0.001 < longueur_parcours($depart, $ordre)) {
                    $ordre = $essai;
                    $ameliore = true;
                }
            }
        }
    }
    return $ordre;
}

function depart_agence(?int $agence_id): ?array
{
    if (!$agence_id) {
        return null;
    }
    $q = base()->prepare('SELECT nom, adresse, lat, lon FROM agences WHERE id = ?');
    $q->execute([$agence_id]);
    $a = $q->fetch();
    return ($a && $a['lat'] !== null) ? ['nom' => $a['nom'], 'adresse' => $a['adresse'], 'lat' => (float) $a['lat'], 'lon' => (float) $a['lon']] : null;
}

// La date d'un jour de la semaine en cours (lundi = 1), pour appliquer les absences et les remplacements.
function date_du_jour_semaine(int $jour): string
{
    return date('Y-m-d', strtotime('today -' . ((int) date('N') - 1) . ' days +' . ($jour - 1) . ' days'));
}

function absent_le(int $utilisateur, string $date): ?array
{
    $q = base()->prepare('SELECT * FROM absences WHERE utilisateur_id = ? AND du <= ? AND au >= ? LIMIT 1');
    $q->execute([$utilisateur, $date, $date]);
    return $q->fetch() ?: null;
}

// Le temps réel d'une journée, lu sur l'heure des rapports : l'écart entre deux rapports, c'est l'intervention
// et le trajet qui l'a précédée. Ni position ni suivi en direct : seulement l'heure que le rapport porte déjà.
// Sert à équilibrer les journées et à estimer les durées, jamais à comparer ou sanctionner les personnes.
function mesure_journee(int $utilisateur, string $date): array
{
    $q = base()->prepare("SELECT v.client_id, v.quand, v.duree_min, c.prenom, c.nom FROM visites v JOIN clients c ON c.id = v.client_id
        WHERE v.technicien_id = ? AND substr(v.quand, 1, 10) = ? ORDER BY v.quand");
    $q->execute([$utilisateur, $date]);
    $v = $q->fetchAll();
    $ecarts = [];
    for ($i = 1; $i < count($v); $i++) {
        $min = (int) round((strtotime($v[$i]['quand']) - strtotime($v[$i - 1]['quand'])) / 60);
        $ecarts[] = ['client_id' => (int) $v[$i]['client_id'], 'client' => trim($v[$i]['prenom'] . ' ' . $v[$i]['nom']), 'ecart_min' => $min,
            'sur_place_min' => $v[$i]['duree_min'] !== null ? (int) $v[$i]['duree_min'] : null,
            'trajet_min' => $v[$i]['duree_min'] !== null && $min <= ECART_PAUSE_MIN ? max(0, $min - (int) $v[$i]['duree_min']) : null,
            'pause' => $min > ECART_PAUSE_MIN];
    }
    $utiles = array_values(array_filter($ecarts, fn ($e) => !$e['pause']));
    $debut = $v ? strtotime($v[0]['quand']) - 60 * (int) ($v[0]['duree_min'] ?? 0) : null;
    return ['rapports' => count($v),
        'amplitude_min' => $v ? (int) round((strtotime($v[count($v) - 1]['quand']) - $debut) / 60) : 0,
        'ecart_moyen_min' => $utiles ? (int) round(array_sum(array_column($utiles, 'ecart_min')) / count($utiles)) : null,
        'ecarts' => $ecarts];
}

// Le temps réel d'un client, trajet compris : la médiane des écarts entre le rapport précédent du même technicien,
// le même jour, et le sien. Il dit ce que ce client coûte vraiment dans une journée, mieux que la durée sur place seule.
function temps_reel_client(int $client): ?int
{
    $q = base()->prepare("SELECT v.quand, v.technicien_id,
            (SELECT p.quand FROM visites p WHERE p.technicien_id = v.technicien_id AND substr(p.quand, 1, 10) = substr(v.quand, 1, 10) AND p.quand < v.quand ORDER BY p.quand DESC LIMIT 1) AS avant
        FROM visites v WHERE v.client_id = ? AND v.type = 'passage' AND v.technicien_id IS NOT NULL ORDER BY v.quand DESC LIMIT 8");
    $q->execute([$client]);
    $ecarts = [];
    foreach ($q->fetchAll() as $l) {
        if ($l['avant']) {
            $min = (int) round((strtotime($l['quand']) - strtotime($l['avant'])) / 60);
            if ($min > 0 && $min <= ECART_PAUSE_MIN) {
                $ecarts[] = $min;
            }
        }
    }
    if (count($ecarts) < 2) {
        return null;
    }
    sort($ecarts);
    return $ecarts[intdiv(count($ecarts), 2)];
}

// Le bilan d'une journée : arrêts dans l'ordre enregistré, ordre proposé, et ce que chacun coûte en minutes.
// Avec une date : un technicien absent n'a pas de tournée, et les clients confiés pour ce jour s'ajoutent.
function bilan_journee(array $technicien, int $jour, ?string $date = null): array
{
    $db = base();
    $absence = $date ? absent_le((int) $technicien['id'], $date) : null;
    $clients = [];
    if (!$absence) {
        $q = $db->prepare("SELECT id, prenom, nom, telephone, adresse, commune, lat, lon, acces, formule, statut, ordre, NULL AS remplace FROM clients c
            WHERE technicien_id = ? AND jour_passage = ? AND statut = 'client'" . ($date ? ' AND NOT EXISTS (SELECT 1 FROM remplacements r WHERE r.client_id = c.id AND r.date = ? AND r.technicien_id <> c.technicien_id)' : '') . ' ORDER BY ordre, id');
        $q->execute($date ? [(int) $technicien['id'], $jour, $date] : [(int) $technicien['id'], $jour]);
        $clients = $q->fetchAll();
    }
    if ($date) {
        $q = $db->prepare("SELECT c.id, c.prenom, c.nom, c.telephone, c.adresse, c.commune, c.lat, c.lon, c.acces, c.formule, c.statut, c.ordre, t.nom AS remplace
            FROM remplacements r JOIN clients c ON c.id = r.client_id LEFT JOIN utilisateurs t ON t.id = c.technicien_id
            WHERE r.technicien_id = ? AND r.date = ? AND (c.technicien_id IS NULL OR c.technicien_id <> r.technicien_id) ORDER BY c.ordre, c.id");
        $q->execute([(int) $technicien['id'], $date]);
        $clients = array_merge($clients, $q->fetchAll());
    }
    $ids = array_map(fn ($c) => (int) $c['id'], $clients);
    $durees = estimer_durees($ids, 'passage', array_combine($ids, array_map(fn ($c) => $c['formule'], $clients)) ?: []);
    $aujourdhui = date('Y-m-d');
    $faits = [];
    $charges = [];
    if ($ids) {
        $marques = implode(',', array_fill(0, count($ids), '?'));
        $v = $db->prepare("SELECT client_id, quand, charge FROM visites WHERE client_id IN ($marques) ORDER BY quand DESC");
        $v->execute($ids);
        foreach ($v->fetchAll() as $l) {
            if (substr($l['quand'], 0, 10) === $aujourdhui) {
                $faits[$l['client_id']] = true;
            }
            if (!isset($charges[$l['client_id']]) && $l['charge'] !== '') {
                $charges[$l['client_id']] = $l['charge'];
            }
        }
    }
    $depart = depart_agence($technicien['agence_id'] !== null ? (int) $technicien['agence_id'] : null);
    $arrets = [];
    foreach ($clients as $c) {
        $arrets[] = [
            'id' => (int) $c['id'], 'prenom' => $c['prenom'], 'nom' => $c['nom'], 'telephone' => $c['telephone'],
            'adresse' => $c['adresse'], 'commune' => $c['commune'], 'acces' => $c['acces'], 'formule' => $c['formule'],
            'lat' => $c['lat'] !== null ? (float) $c['lat'] : null, 'lon' => $c['lon'] !== null ? (float) $c['lon'] : null,
            'duree' => $durees[(int) $c['id']], 'fait' => isset($faits[$c['id']]), 'charge' => $charges[$c['id']] ?? '',
            'remplace' => $c['remplace'] ?? null,
        ];
    }
    $places = array_values(array_filter($arrets, fn ($a) => $a['lat'] !== null));
    $sur_place = array_sum(array_column($arrets, 'duree'));
    $km_actuel = longueur_parcours($depart, $places);
    $propose = optimiser_ordre($depart, $places);
    $km_propose = longueur_parcours($depart, $propose);
    $sans_position = array_values(array_filter($arrets, fn ($a) => $a['lat'] === null));
    return [
        'jour' => $jour, 'jour_nom' => JOURS[$jour] ?? '', 'technicien' => ['id' => (int) $technicien['id'], 'nom' => $technicien['nom']],
        'date' => $date, 'absent' => $absence ? ['du' => $absence['du'], 'au' => $absence['au'], 'motif' => $absence['motif']] : null,
        'mesure' => $date && $date <= date('Y-m-d') ? mesure_journee((int) $technicien['id'], $date) : null,
        'depart' => $depart, 'arrets' => $arrets,
        'actuel' => ['km' => round($km_actuel, 1), 'trajet_min' => minutes_route($km_actuel), 'sur_place_min' => $sur_place, 'total_min' => $sur_place + minutes_route($km_actuel)],
        'propose' => [
            'ordre' => array_merge(array_map(fn ($a) => $a['id'], $propose), array_map(fn ($a) => $a['id'], $sans_position)),
            'km' => round($km_propose, 1), 'trajet_min' => minutes_route($km_propose), 'total_min' => $sur_place + minutes_route($km_propose),
            'gain_min' => max(0, minutes_route($km_actuel) - minutes_route($km_propose)),
        ],
        'sans_position' => count($sans_position),
        'charge' => charge_journee($arrets, minutes_route($km_propose)),
    ];
}

// Ce qu'une journée pèse vraiment une semaine donnée : les maisons fermées seules au quart (une visite par mois),
// les trajets en entier, et le nombre de piscines, qui use même quand l'horloge dit que ça tient.
function charge_journee(array $arrets, int $trajet_min): array
{
    $min = $trajet_min;
    $piscines = 0;
    foreach ($arrets as $a) {
        $min += $a['duree'] * (PART_HEBDO_FORMULE[$a['formule']] ?? 1);
        $piscines += str_contains($a['formule'], 'Piscine') || $a['formule'] === '' ? 1 : 0;
    }
    $min = (int) round($min);
    return ['min' => $min, 'piscines' => $piscines,
        'niveau' => $min === 0 ? 'vide' : ($min > JOURNEE_MAX_MIN || $piscines > PISCINES_JOUR_MAX ? 'trop' : ($min < JOURNEE_CREUSE_MIN ? 'creuse' : 'bonne'))];
}
