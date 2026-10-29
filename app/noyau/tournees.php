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

// Durée estimée par client : la médiane de ses dernières visites mesurées, sinon la valeur par défaut.
function estimer_durees(array $ids, string $type = 'passage'): array
{
    $sortie = array_fill_keys($ids, DUREE_DEFAUT[$type] ?? 30);
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

// Le bilan d'une journée : arrêts dans l'ordre enregistré, ordre proposé, et ce que chacun coûte en minutes.
function bilan_journee(array $technicien, int $jour): array
{
    $db = base();
    $q = $db->prepare("SELECT id, prenom, nom, telephone, adresse, commune, lat, lon, acces, formule, statut, ordre FROM clients
        WHERE technicien_id = ? AND jour_passage = ? AND statut = 'client' ORDER BY ordre, id");
    $q->execute([(int) $technicien['id'], $jour]);
    $clients = $q->fetchAll();
    $ids = array_map(fn ($c) => (int) $c['id'], $clients);
    $durees = estimer_durees($ids);
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
        'depart' => $depart, 'arrets' => $arrets,
        'actuel' => ['km' => round($km_actuel, 1), 'trajet_min' => minutes_route($km_actuel), 'sur_place_min' => $sur_place, 'total_min' => $sur_place + minutes_route($km_actuel)],
        'propose' => [
            'ordre' => array_merge(array_map(fn ($a) => $a['id'], $propose), array_map(fn ($a) => $a['id'], $sans_position)),
            'km' => round($km_propose, 1), 'trajet_min' => minutes_route($km_propose), 'total_min' => $sur_place + minutes_route($km_propose),
            'gain_min' => max(0, minutes_route($km_actuel) - minutes_route($km_propose)),
        ],
        'sans_position' => count($sans_position),
    ];
}
