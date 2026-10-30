<?php
// Maison Flamingo · le pilotage du dirigeant : les chiffres qui tiennent ensemble les clients,
// l'équipe et l'argent, et ce qu'il faut faire quand l'un d'eux décroche.
//
// Les seuils et les recommandations vivent ici, côté serveur, et nulle part ailleurs :
// l'écran les affiche, il ne les invente pas. La règle qui tient l'équilibre est celle du
// concept : la marge se fait par le prix et la densité, jamais par la cadence ni par les salaires.
// Une recommandation propose ; c'est toujours une personne qui décide.

declare(strict_types=1);

// Une semaine tenable : cinq journées de sept heures, sur place et trajets compris. Le remplissage se mesure en heures
// réellement passées chez les clients, pas en nombre de bassins : une maison avec piscine pèse ce qu'elle pèse.
const SEMAINE_MIN = 5 * JOURNEE_CIBLE_MIN;
const REMPLISSAGE_EMBAUCHE = 0.85;  // au-delà, on embauche avant de signer
const REMPLISSAGE_BAS = 0.60;       // en dessous, on coupe la publicité, jamais les gens
const PASSAGE_COURT_MIN = 20;       // un passage plus court déclenche un contrôle

// Les chiffres que l'outil ne peut pas connaître. « agence » : un par agence, saisi par son chef ou la direction ;
// « entreprise » : la direction seule. Les bornes refusent la faute de frappe, pas la mauvaise nouvelle.
const SAISIES = [
    'note_google' => ['portee' => 'agence', 'min' => 1, 'max' => 5, 'libelle' => 'Note Google de la fiche', 'unite' => '/ 5', 'frequence' => 'chaque mois'],
    'avis_google' => ['portee' => 'agence', 'min' => 0, 'max' => 100000, 'libelle' => 'Nombre d\'avis Google', 'unite' => 'avis', 'frequence' => 'chaque mois'],
    'score_equipe' => ['portee' => 'agence', 'min' => 1, 'max' => 10, 'libelle' => 'Recommanderais-tu Maison Flamingo à un ami qui cherche du travail ? (moyenne)', 'unite' => '/ 10', 'frequence' => 'chaque trimestre'],
    'prime_moyenne' => ['portee' => 'agence', 'min' => 0, 'max' => 10000, 'libelle' => 'Prime moyenne versée par technicien ce trimestre', 'unite' => '€', 'frequence' => 'chaque trimestre'],
    'tresorerie_point_bas' => ['portee' => 'entreprise', 'min' => -10000000, 'max' => 100000000, 'libelle' => 'Point bas de trésorerie prévu sur 90 jours', 'unite' => '€', 'frequence' => 'chaque mois'],
    'salaires_mensuels' => ['portee' => 'entreprise', 'min' => 0, 'max' => 10000000, 'libelle' => 'Masse salariale d\'un mois, charges comprises', 'unite' => '€', 'frequence' => 'à chaque changement'],
    'ebitda_pct' => ['portee' => 'entreprise', 'min' => -100, 'max' => 100, 'libelle' => 'EBITDA du dernier mois clos', 'unite' => '% du CA', 'frequence' => 'chaque mois'],
    'contribution_tech' => ['portee' => 'entreprise', 'min' => -100000, 'max' => 100000, 'libelle' => 'Contribution moyenne par technicien et par mois', 'unite' => '€', 'frequence' => 'chaque mois'],
    'produits_client' => ['portee' => 'entreprise', 'min' => 0, 'max' => 500, 'libelle' => 'Produits consommés par client et par mois', 'unite' => '€', 'frequence' => 'chaque mois'],
    'prelevements_rejetes' => ['portee' => 'entreprise', 'min' => 0, 'max' => 100, 'libelle' => 'Prélèvements rejetés du mois', 'unite' => '%', 'frequence' => 'chaque mois'],
];

// Qui suit chaque chiffre avant le dirigeant : c'est ce qui permet à l'entreprise de tourner sans lui.
const PROPRIETAIRES = ['technicien' => 'Le technicien', 'chef' => 'Le chef d\'équipe', 'agence' => 'Le responsable d\'agence', 'direction' => 'La direction'];

// Les recommandations, par indicateur et par état. Des gestes concrets, dans l'ordre où les faire.
const RECOMMANDATIONS = [
    'resiliations' => [
        'alerte' => ['Les résiliations dépassent 1,5 % par mois', 'agence', [
            'Appeler cette semaine chaque client parti depuis 90 jours : le motif noté sur la fiche ne dit pas tout.',
            'Regarder si les départs viennent d\'une même tournée ou d\'un même technicien : c\'est le plus souvent là que ça se joue.',
            'Freiner les nouvelles signatures tant que la cause n\'est pas réparée : signer pendant que le seau fuit ne sert à rien.',
            'Appeler les voisins des partants, avant qu\'ils ne partent à leur tour.']],
        'attention' => ['Les résiliations approchent le seuil de 1 % par mois', 'agence', [
            'Appeler les clients partis récemment et noter la vraie raison sur leur fiche.',
            'Vérifier que le message du vendredi et le rapport après chaque passage partent bien chez tous.']],
    ],
    'rapports' => [
        'alerte' => ['Trop de rapports partent tard, ou pas du tout', 'chef', [
            'Repérer dans le tableau par technicien qui envoie en retard, et en parler avec lui, pas devant les autres.',
            'Rappeler la règle : le rapport part avant que le van quitte la rue. C\'est la promesse que voit le client.',
            'Vérifier que chaque téléphone a l\'outil à jour : sans réseau, l\'outil garde le rapport et l\'envoie seul.']],
        'attention' => ['Les rapports ne partent pas tous dans l\'heure', 'chef', [
            'Regarder les tournées où le retard se répète : souvent une journée trop pleine.']],
    ],
    'jour_prevu' => [
        'alerte' => ['Les passages glissent hors du jour promis', 'chef', [
            'Revoir les tournées trop pleines : un jour qui déborde décale toute la semaine.',
            'Prévenir le client par SMS avant tout changement de jour, jamais après.',
            'Garder une demi-journée tampon dans la semaine pour la pluie et les imprévus.']],
        'attention' => ['Quelques passages ont changé de jour', 'chef', [
            'Vérifier que chaque client concerné a été prévenu avant.']],
    ],
    'demandes' => [
        'alerte' => ['Des clients attendent une réponse depuis plus de 48 heures', 'chef', [
            'Leur répondre aujourd\'hui depuis l\'écran Demandes.',
            'Désigner qui répond quand le chef d\'équipe est en tournée.']],
        'attention' => ['Une demande client attend depuis plus de 48 heures', 'chef', [
            'Y répondre aujourd\'hui depuis l\'écran Demandes.']],
    ],
    'leads' => [
        'alerte' => ['Des contacts attendent un premier appel depuis plus de 24 heures', 'chef', [
            'Les rappeler aujourd\'hui depuis l\'écran Leads : un contact qui attend appelle un concurrent.',
            'Vérifier les secteurs sans technicien attitré.']],
        'attention' => ['Le premier contact des leads ralentit', 'chef', [
            'Rappeler aux techniciens que l\'appel ou le SMS compte dès qu\'il part depuis la fiche.']],
    ],
    'note_google' => [
        'alerte' => ['La note Google passe sous 4,6', 'agence', [
            'Lire les derniers avis et répondre à chacun, signé d\'un prénom, sans se justifier.',
            'Appeler le client mécontent avant de lui répondre en ligne.',
            'Demander un avis après le 4e passage aux clients contents, jamais à un client qu\'on n\'a pas appelé.']],
        'attention' => ['La note Google est sous 4,8', 'agence', [
            'Répondre à chaque avis récent, et appeler l\'auteur d\'un avis moyen.']],
    ],
    'remplissage' => [
        'alerte' => ['Une tournée dépasse 85 % de remplissage', 'direction', [
            'Embaucher avant de signer : ne plus ajouter de client à cette tournée.',
            'Mettre les nouveaux clients sur liste d\'attente, avec une date annoncée.',
            'Déplacer quelques clients vers une tournée moins pleine du même secteur, en prévenant chacun.']],
        'attention' => ['Les tournées sont peu remplies', 'direction', [
            'Relancer la prospection dans les rues déjà servies : le voisin d\'un client coûte le moins cher et raccourcit la tournée.',
            'Couper la publicité payante avant de toucher à l\'équipe : on coupe la pub, jamais les gens.',
            'Employer le temps libre à la formation et à la saison hérisson.']],
    ],
    'journees' => [
        'alerte' => ['Des journées dépassent 7 h 30 ou 10 piscines', 'chef', [
            'Déplacer une ou deux piscines vers un jour plus léger de la même semaine, en prévenant les clients concernés.',
            'Placer les maisons fermées l\'après-midi, les piscines le matin.',
            'Si toute la semaine est pleine, ce n\'est plus une question de planning : embaucher avant de signer.']],
        'attention' => ['Des journées sont creuses', 'chef', [
            'Regrouper ces clients sur moins de jours, si le jour promis aux clients le permet.',
            'Y placer ce qui n\'a pas de jour fixe : interventions, diagnostics, saison hérisson, formation.',
            'Remplir avec des voisins des rues déjà servies plutôt qu\'avec des clients isolés.']],
    ],
    'couverture' => [
        'alerte' => ['Une absence des trois prochains jours n\'est pas couverte', 'chef', [
            'Ouvrir l\'écran Absences : l\'outil propose les collègues qui ont encore de la marge ce jour-là.',
            'Si personne n\'a de marge, confier la journée à un remplaçant prêt du vivier.',
            'En dernier recours, prévenir les clients par SMS et décaler leur passage, avant le jour, jamais après.']],
        'attention' => ['Des absences à venir ne sont pas encore couvertes', 'chef', [
            'Les répartir cette semaine depuis l\'écran Absences, pendant qu\'il y a le choix.']],
    ],
    'vivier' => [
        'alerte' => ['Aucun remplaçant prêt dans une agence', 'agence', [
            'Rencontrer deux personnes ce mois-ci : étudiants des écoles voisines, demandeurs d\'emploi via France Travail, personnes recommandées par l\'équipe.',
            'Faire avec chacune les six vérifications avant d\'en avoir besoin, journée d\'essai en binôme comprise.',
            'Un remplaçant se recrute en contrat à durée déterminée, jamais en auto-entrepreneur.']],
        'attention' => ['Un seul remplaçant prêt dans une agence', 'agence', [
            'Compléter le vivier avant l\'été et les congés : il en faut deux pour qu\'une absence n\'en devienne pas deux.']],
    ],
    'echeances' => [
        'alerte' => ['Une habilitation a expiré', 'agence', [
            'Ne plus confier à la personne ce que l\'habilitation couvre, le temps du recyclage.',
            'L\'inscrire au prochain recyclage cette semaine, et poser la nouvelle date sur sa fiche dès qu\'elle est connue.',
            'Pour le permis : pas de conduite du véhicule de l\'entreprise tant qu\'il n\'est pas régularisé.']],
        'attention' => ['Des habilitations arrivent à échéance dans le mois', 'agence', [
            'Inscrire maintenant chaque personne concernée au recyclage : les sessions se remplissent vite.',
            'Poser la journée de formation comme une absence, pour couvrir sa tournée à l\'avance.']],
        'a_saisir' => ['Aucune habilitation n\'est enregistrée', 'agence', [
            'Poser au moins le permis de chaque technicien sur sa fiche, avec sa date de fin s\'il en a une.',
            'Ajouter ensuite les formations suivies : c\'est ce qui permettra de prévenir avant qu\'elles ne tombent.']],
    ],
    'conges_attente' => [
        'alerte' => ['Une demande de congés attend depuis plus de cinq jours', 'chef', [
            'Répondre aujourd\'hui depuis Absences, onglet Congés : oui, ou non avec la raison et d\'autres dates possibles.',
            'Une demande sans réponse coûte plus de confiance qu\'un refus expliqué.']],
        'attention' => ['Des demandes de congés attendent une réponse', 'chef', [
            'Les décider dans la semaine, en regardant les journées de tournée touchées et qui d\'autre est déjà absent.']],
    ],
    'passages_courts' => [
        'alerte' => ['Trop de passages durent moins de 20 minutes', 'chef', [
            'Accompagner cette semaine les tournées concernées, sans prévenir et sans juger.',
            'Chercher la cause : tournée trop chargée, matériel qui manque, ou geste mal appris.',
            'Ne pas en faire d\'abord une faute : c\'est le plus souvent une tournée trop pleine.']],
        'attention' => ['Quelques passages sont très courts', 'chef', [
            'Regarder ces passages un par un : certains sont justes (bâche en place, eau parfaite), d\'autres non.']],
    ],
    'departs' => [
        'alerte' => ['Plusieurs membres de l\'équipe sont partis cette année', 'direction', [
            'Un entretien individuel avec chacun ce mois-ci, avec une seule question : qu\'est-ce qui te ferait partir ?',
            'Comparer la grille des salaires et des primes au marché local.',
            'Vérifier que chacun a une date écrite pour sa prochaine marche (référent, chef d\'équipe).']],
        'attention' => ['Un membre de l\'équipe est parti cette année', 'direction', [
            'Comprendre pourquoi, avec lui si possible, et l\'écrire.',
            'Faire un point avec ceux qui restent dans le mois.']],
    ],
    'score_equipe' => [
        'alerte' => ['L\'équipe recommande peu l\'entreprise', 'direction', [
            'Des entretiens individuels ce mois-ci : qu\'est-ce qui pèse le plus, la charge, le salaire, la reconnaissance ?',
            'Annoncer à tous une action visible dans le mois, et la tenir.']],
        'attention' => ['Le ressenti de l\'équipe baisse', 'agence', [
            'Demander à chacun ce qui a changé depuis le dernier trimestre.']],
    ],
    'prime' => [
        'alerte' => ['Aucune prime versée ce trimestre', 'direction', [
            'Une prime que personne ne touche ne motive personne : revoir ses seuils (note Google, clients conservés).',
            'Annoncer la prime nominativement, en réunion ou à l\'apéro de fin de saison.']],
    ],
    'tresorerie' => [
        'alerte' => ['La trésorerie prévue passe sous un mois de salaires', 'direction', [
            'Geler les embauches, les achats non urgents et la publicité. Jamais les salaires.',
            'Relancer les prélèvements rejetés et les factures en attente.',
            'Proposer l\'année payée d\'avance, avec 5 % de remise, aux clients fidèles.',
            'Prévenir la banque avant le creux, pas pendant.']],
        'attention' => ['La trésorerie prévue passe sous deux mois de salaires', 'direction', [
            'Reporter les dépenses qui peuvent attendre et vérifier les encaissements du mois.']],
    ],
    'ebitda' => [
        'alerte' => ['La marge d\'exploitation passe sous 8 %', 'direction', [
            'Regarder d\'abord la densité : clients par tournée, temps de trajet.',
            'Puis les achats de produits : l\'objectif est 11 € par client et par mois.',
            'Puis le prix des nouveaux clients et l\'indexation annuelle.',
            'Jamais la cadence chez le client ni les salaires.']],
        'attention' => ['La marge d\'exploitation est sous 10 %', 'direction', [
            'Suivre la densité des tournées et le coût des produits ce mois-ci.']],
        'trop_belle' => ['Une marge au-dessus de 20 % se vérifie', 'direction', [
            'Regarder les passages courts et le remplissage : on rogne peut-être chez le client.',
            'Regarder la charge et l\'humeur de l\'équipe.',
            'Si tout va bien, mettre de côté : intéressement, formation, prochain van.']],
    ],
    'contribution' => [
        'alerte' => ['Une tournée ne se paie pas', 'direction', [
            'Densifier : des clients dans les rues déjà servies plutôt qu\'au loin.',
            'Augmenter la part de maisons fermées : environ trois fois plus rentables par heure de technicien.',
            'Vérifier que les nouveaux clients sont au prix public.']],
        'attention' => ['La contribution par technicien est sous 3 500 €', 'direction', [
            'Regarder la densité et le mix piscines et maisons fermées de chaque tournée.']],
    ],
    'produits' => [
        'alerte' => ['Les produits coûtent plus de 15 € par client et par mois', 'direction', [
            'Acheter en bidons de 20 litres chez un grossiste, et constituer le stock en novembre.',
            'Comparer la consommation par technicien : un fort écart signale un dosage à reprendre.']],
        'attention' => ['Les produits dépassent 12 € par client et par mois', 'direction', [
            'Comparer les prix de deux grossistes avant la prochaine commande.']],
    ],
    'prelevements' => [
        'alerte' => ['Plus de 3 % des prélèvements sont rejetés', 'direction', [
            'Relancer à 3 jours et à 10 jours, puis appeler : un rejet est souvent un changement de banque.',
            'Vérifier les mandats des clients signés récemment.']],
        'attention' => ['Des prélèvements sont rejetés', 'direction', [
            'Appeler les clients concernés cette semaine.']],
    ],
    'conversion' => [
        'alerte' => ['Moins d\'un diagnostic sur trois aboutit', 'agence', [
            'Relire les raisons de refus notées au diagnostic (export des visites).',
            'Si c\'est le prix, travailler l\'argument avant de baisser : le prix finance la qualité.',
            'Rappeler les « à relancer » dans les sept jours.']],
        'attention' => ['Moins d\'un diagnostic sur deux aboutit', 'agence', [
            'Rappeler les « à relancer » dans les sept jours.']],
    ],
    'voisins' => [
        'alerte' => ['Peu de clients arrivent par un voisin ou un parrain', 'agence', [
            'Laisser deux cartes pour les voisins à chaque passage.',
            'Rappeler le parrainage (un mois offert) dans le message du vendredi.',
            'Poser le flamant du portail chez les clients d\'accord : c\'est notre seul affichage dans la rue.']],
        'attention' => ['Le bouche-à-oreille pourrait faire mieux', 'agence', [
            'Laisser deux cartes pour les voisins à chaque passage.']],
    ],
];

// Un indicateur : sa valeur, son état et ce qu'on attend de lui. L'état décide de tout le reste.
function indicateur(string $cle, string $libelle, ?string $valeur, string $cible, string $etat, string $proprietaire, string $detail = '', ?string $reco = null): array
{
    return ['cle' => $cle, 'libelle' => $libelle, 'valeur' => $valeur, 'cible' => $cible, 'etat' => $etat,
        'proprietaire' => PROPRIETAIRES[$proprietaire] ?? $proprietaire, 'detail' => $detail, 'reco' => $reco ?? $cle];
}

function heures_lisibles(int $min): string
{
    return intdiv($min, 60) . ' h' . ($min % 60 ? ' ' . str_pad((string) ($min % 60), 2, '0', STR_PAD_LEFT) : '');
}

function pct(float $x): string
{
    return str_replace('.', ',', (string) round($x * 100, 1)) . ' %';
}

function euros(float $x): string
{
    return number_format($x, 0, ',', ' ') . ' €';
}

// La dernière valeur saisie d'un chiffre, si elle n'est pas trop vieille. Pour une vue « toutes les agences »,
// un chiffre d'agence prend la plus mauvaise des agences : c'est elle qui demande de l'attention.
function derniere_saisie(string $cle, ?int $agence, int $jours, bool $plus_bas_est_pire = true): ?array
{
    $depuis = date('Y-m', strtotime("-$jours days"));
    $db = base();
    if (SAISIES[$cle]['portee'] === 'entreprise') {
        $q = $db->prepare('SELECT valeur, mois, agence_id FROM pilotage_saisies WHERE cle = ? AND agence_id = 0 AND mois >= ? ORDER BY mois DESC LIMIT 1');
        $q->execute([$cle, $depuis]);
        $r = $q->fetch();
        return $r ? ['valeur' => (float) $r['valeur'], 'mois' => $r['mois'], 'agence' => null] : null;
    }
    $q = $db->prepare('SELECT s.valeur, s.mois, s.agence_id, a.nom FROM pilotage_saisies s JOIN agences a ON a.id = s.agence_id
        WHERE s.cle = ? AND s.mois >= ?' . ($agence ? ' AND s.agence_id = ?' : '') . ' ORDER BY s.mois DESC');
    $q->execute($agence ? [$cle, $depuis, $agence] : [$cle, $depuis]);
    $par = [];
    foreach ($q->fetchAll() as $r) {
        $par[(int) $r['agence_id']] ??= $r;   // la plus récente de chaque agence
    }
    if (!$par) {
        return null;
    }
    usort($par, fn ($a, $b) => $plus_bas_est_pire ? $a['valeur'] <=> $b['valeur'] : $b['valeur'] <=> $a['valeur']);
    return ['valeur' => (float) $par[0]['valeur'], 'mois' => $par[0]['mois'], 'agence' => count($par) > 1 || !$agence ? $par[0]['nom'] : null];
}

// Les calculs du mois sont gardés, pour qu'une règle puisse dire « deux mois de suite ».
function garder_releve(string $cle, ?int $agence, float $valeur): ?float
{
    $db = base();
    $mois = date('Y-m');
    $db->prepare('INSERT INTO pilotage_saisies (mois, agence_id, cle, valeur, saisi_par, saisi_le) VALUES (?,?,?,?,?,?)
        ON CONFLICT(mois, agence_id, cle) DO UPDATE SET valeur = excluded.valeur, saisi_le = excluded.saisi_le')
        ->execute([$mois, $agence ?? 0, $cle, $valeur, 'calcul', maintenant()]);
    $q = $db->prepare('SELECT valeur FROM pilotage_saisies WHERE cle = ? AND agence_id = ? AND mois = ?');
    $q->execute([$cle, $agence ?? 0, date('Y-m', strtotime('first day of last month'))]);
    $v = $q->fetchColumn();
    return $v === false ? null : (float) $v;
}

function a_pilotage(): void
{
    $u = exiger_equipe(['chef', 'superadmin']);
    $agence = agence_visee($u);
    $direction = $u['role'] === 'superadmin';
    $db = base();
    $w = $agence ? 'c.agence_id = ?' : '1=1';
    $p = $agence ? [$agence] : [];
    $il_y_a = fn (int $j) => date('c', strtotime("-$j days"));
    $un = function (string $sql, array $params) use ($db) {
        $q = $db->prepare($sql);
        $q->execute($params);
        return $q->fetch(PDO::FETCH_NUM);
    };
    $ind = [];

    // ---- Les clients ----
    // Sur 90 jours ramenés au mois : avec 25 clients, un seul départ sur 30 jours ferait 4 % et ne dirait rien.
    [$actifs, $partis90] = $un("SELECT SUM(c.statut = 'client'), SUM(c.statut = 'desinscrit' AND c.statut_depuis >= ?) FROM clients c WHERE $w", [$il_y_a(90), ...$p]);
    $base_res = (int) $actifs + (int) $partis90;
    $q = $db->prepare("SELECT c.statut_motif, COUNT(*) n FROM clients c WHERE $w AND c.statut = 'desinscrit' AND c.statut_depuis >= ? GROUP BY c.statut_motif ORDER BY n DESC LIMIT 3");
    $q->execute([...$p, $il_y_a(90)]);
    $motifs = array_map(fn ($r) => ($r['statut_motif'] !== '' ? $r['statut_motif'] : 'sans motif') . ' (' . $r['n'] . ')', $q->fetchAll());
    if ($base_res < 5) {
        $ind[] = indicateur('resiliations', 'Résiliations par mois', null, 'moins de 1 %', 'pas_assez', 'agence', 'Moins de cinq clients : le taux ne veut encore rien dire.');
        $taux_res = null;
    } else {
        $taux_res = ((int) $partis90 / 3) / $base_res;
        $ind[] = indicateur('resiliations', 'Résiliations par mois', pct($taux_res), 'moins de 1 %',
            $taux_res > 0.015 ? 'alerte' : ($taux_res > 0.01 ? 'attention' : 'ok'), 'agence',
            (int) $partis90 . ' départ' . ((int) $partis90 > 1 ? 's' : '') . ' sur 90 jours' . ($motifs ? ' : ' . implode(', ', $motifs) : ''));
        garder_releve('calc_resiliations', $agence, $taux_res);
    }

    [$v30, $vite] = $un("SELECT COUNT(*), SUM(v.envoye_le IS NOT NULL AND (julianday(v.envoye_le) - julianday(v.quand)) * 24 <= 1)
        FROM visites v JOIN clients c ON c.id = v.client_id WHERE $w AND v.quand >= ?", [...$p, $il_y_a(30)]);
    $ind[] = (int) $v30 < 10
        ? indicateur('rapports', 'Rapports envoyés moins d\'une heure après le passage', null, '95 % ou plus', 'pas_assez', 'chef', 'Moins de dix visites sur 30 jours.')
        : indicateur('rapports', 'Rapports envoyés moins d\'une heure après le passage', pct((int) $vite / (int) $v30), '95 % ou plus',
            (int) $vite / (int) $v30 < 0.85 ? 'alerte' : ((int) $vite / (int) $v30 < 0.95 ? 'attention' : 'ok'), 'chef', (int) $vite . ' sur ' . (int) $v30 . ' visites, 30 jours');

    // Le jour de la semaine se lit sur la date locale écrite (les dix premiers caractères), pas convertie en UTC.
    [$pass, $bon_jour] = $un("SELECT COUNT(*), SUM(((CAST(strftime('%w', substr(v.quand, 1, 10)) AS INTEGER) + 6) % 7) + 1 = c.jour_passage)
        FROM visites v JOIN clients c ON c.id = v.client_id WHERE $w AND v.type = 'passage' AND c.jour_passage IS NOT NULL AND v.quand >= ?", [...$p, $il_y_a(30)]);
    $ind[] = (int) $pass < 10
        ? indicateur('jour_prevu', 'Passages faits le jour prévu', null, '95 % ou plus', 'pas_assez', 'chef', 'Moins de dix passages planifiés sur 30 jours.')
        : indicateur('jour_prevu', 'Passages faits le jour prévu', pct((int) $bon_jour / (int) $pass), '95 % ou plus',
            (int) $bon_jour / (int) $pass < 0.90 ? 'alerte' : ((int) $bon_jour / (int) $pass < 0.95 ? 'attention' : 'ok'), 'chef', (int) $bon_jour . ' sur ' . (int) $pass . ' passages, 30 jours');

    [$attente, $vieilles] = $un("SELECT COUNT(*), SUM(d.cree_le < ?) FROM demandes d JOIN clients c ON c.id = d.client_id WHERE $w AND d.traitee_le IS NULL", [$il_y_a(2), ...$p]);
    $ind[] = indicateur('demandes', 'Demandes de clients sans réponse depuis plus de 48 h', (string) (int) $vieilles, 'aucune',
        (int) $vieilles >= 3 ? 'alerte' : ((int) $vieilles > 0 ? 'attention' : 'ok'), 'chef', (int) $attente . ' en attente au total');

    [$leads30, $contactes30, $delai30, $retard] = $un("SELECT COUNT(*), SUM(c.lead_contacte_le IS NOT NULL),
        AVG(CASE WHEN c.lead_contacte_le IS NOT NULL THEN (julianday(c.lead_contacte_le) - julianday(c.lead_attribue_le)) * 24 END),
        SUM(c.lead_contacte_le IS NULL AND c.statut NOT IN ('perdu', 'desinscrit') AND julianday('now') - julianday(COALESCE(c.lead_attribue_le, c.lead_recu_le)) > ?)
        FROM clients c WHERE $w AND c.lead_recu_le >= ?", [LEAD_RETARD_H / 24, ...$p, $il_y_a(30)]);
    if ((int) $leads30 === 0) {
        $ind[] = indicateur('leads', 'Premier contact des leads', null, 'dans les 24 h', 'pas_assez', 'chef', 'Aucun lead sur 30 jours.');
    } else {
        $taux = (int) $contactes30 / (int) $leads30;
        $ind[] = indicateur('leads', 'Premier contact des leads', $delai30 !== null ? str_replace('.', ',', (string) round((float) $delai30, 1)) . ' h en moyenne' : 'aucun rappel', 'dans les 24 h',
            (int) $retard > 0 ? 'alerte' : ($taux < 0.9 || ($delai30 !== null && (float) $delai30 > LEAD_RETARD_H) ? 'attention' : 'ok'), 'chef',
            (int) $contactes30 . ((int) $contactes30 > 1 ? ' rappelés' : ' rappelé') . ' sur ' . (int) $leads30 . ((int) $retard ? ', ' . (int) $retard . ' en attente depuis plus de 24 h' : ''));
    }

    $note = derniere_saisie('note_google', $agence, 62);
    $ind[] = !$note
        ? indicateur('note_google', 'Note Google', null, '4,8 ou plus', 'a_saisir', 'agence', 'À saisir chaque mois.')
        : indicateur('note_google', 'Note Google', str_replace('.', ',', (string) $note['valeur']) . ' / 5', '4,8 ou plus',
            $note['valeur'] < 4.6 ? 'alerte' : ($note['valeur'] < 4.8 ? 'attention' : 'ok'), 'agence', 'Saisie de ' . $note['mois'] . ($note['agence'] ? ', ' . $note['agence'] : ''));

    // ---- L'équipe ----
    // Ni les casser, ni les payer à rien faire, ni y perdre : chaque journée se mesure en heures, chaque semaine aussi.
    $q = $db->prepare("SELECT id, nom, agence_id FROM utilisateurs WHERE role = 'technicien' AND actif = 1 AND expire_le IS NULL AND hash <> ''" . ($agence ? ' AND agence_id = ?' : '') . ' ORDER BY nom');
    $q->execute($p);
    $tournees = [];
    $trop = [];
    $creuses = [];
    foreach ($q->fetchAll() as $t) {
        $semaine = 0;
        for ($j = 1; $j <= 6; $j++) {
            $c = bilan_journee($t, $j)['charge'];
            $semaine += $c['min'];
            if ($c['niveau'] === 'trop') {
                $trop[] = $t['nom'] . ' le ' . mb_strtolower(JOURS[$j]) . ' (' . heures_lisibles($c['min']) . ', ' . $c['piscines'] . ' piscine' . ($c['piscines'] > 1 ? 's' : '') . ')';
            } elseif ($c['niveau'] === 'creuse' && $j <= 5) {
                $creuses[] = $t['nom'] . ' le ' . mb_strtolower(JOURS[$j]) . ' (' . heures_lisibles($c['min']) . ')';
            }
        }
        $tournees[] = ['nom' => $t['nom'], 'minutes' => $semaine, 'remplissage' => round($semaine / SEMAINE_MIN, 2)];
    }
    if (!$tournees) {
        $ind[] = indicateur('remplissage', 'Remplissage moyen des tournées', null, '75 à 85 %', 'pas_assez', 'direction', 'Aucun technicien actif.');
    } else {
        $max = max(array_column($tournees, 'remplissage'));
        $moy = array_sum(array_column($tournees, 'remplissage')) / count($tournees);
        $plein = array_values(array_filter($tournees, fn ($t) => $t['remplissage'] > REMPLISSAGE_EMBAUCHE));
        $avant = garder_releve('calc_remplissage_max', $agence, $max);
        $deux_mois = $avant !== null && $avant > REMPLISSAGE_EMBAUCHE && $max > REMPLISSAGE_EMBAUCHE;
        $ind[] = indicateur('remplissage', 'Remplissage moyen des tournées', pct($moy), '75 à 85 %',
            $max > REMPLISSAGE_EMBAUCHE ? 'alerte' : ($moy < REMPLISSAGE_BAS ? 'attention' : 'ok'), 'direction',
            ($plein ? 'Au-delà de 85 % : ' . implode(', ', array_map(fn ($t) => $t['nom'] . ' (' . pct($t['remplissage']) . ')', $plein)) . ($deux_mois ? ', deux mois de suite' : '') . '. '
                : 'Tournée la plus pleine : ' . pct($max) . '. ') . 'Une semaine pleine : ' . heures_lisibles(SEMAINE_MIN) . ', trajets compris ; les 15 % restants absorbent les absences et les imprévus.');
    }
    $ind[] = !$tournees
        ? indicateur('journees', 'Journées trop chargées ou creuses', null, 'entre 5 h et 7 h 30, 10 piscines au plus', 'pas_assez', 'chef', 'Aucun technicien actif.')
        : indicateur('journees', 'Journées trop chargées ou creuses', $trop ? count($trop) . ' trop chargée' . (count($trop) > 1 ? 's' : '') : ($creuses ? count($creuses) . ' creuse' . (count($creuses) > 1 ? 's' : '') : 'aucune'),
            'entre 5 h et 7 h 30, 10 piscines au plus', $trop ? 'alerte' : ($creuses ? 'attention' : 'ok'), 'chef',
            $trop ? 'Trop chargées : ' . implode(', ', array_slice($trop, 0, 6)) . '.' : ($creuses ? 'Creuses : ' . implode(', ', array_slice($creuses, 0, 6)) . '.' : 'Toutes les journées tiennent entre 5 h et 7 h 30.'));

    // La couverture : chaque journée d'absence des quinze prochains jours a-t-elle quelqu'un pour chaque client ?
    $couverture = couverture_a_venir($agence);
    $trous = array_values(array_filter($couverture, fn ($j) => $j['a_couvrir'] > 0));
    $proches = array_values(array_filter($trous, fn ($j) => $j['date'] <= date('Y-m-d', strtotime('+3 days'))));
    $ind[] = indicateur('couverture', 'Journées d\'absence à couvrir, quinze jours', (string) count($trous), 'aucune', $proches ? 'alerte' : ($trous ? 'attention' : 'ok'), 'chef',
        $trous ? implode(', ', array_map(fn ($j) => $j['technicien']['nom'] . ' le ' . date('d/m', strtotime($j['date'])) . ' (' . $j['a_couvrir'] . ' client' . ($j['a_couvrir'] > 1 ? 's' : '') . ')', array_slice($trous, 0, 5))) . '.' : 'Toutes les absences à venir sont couvertes.');

    // Le vivier : des remplaçants déjà vérifiés, avant d'en avoir besoin. Deux par agence au moins, dès qu'une équipe existe.
    $v = $db->prepare('SELECT a.nom, (SELECT COUNT(*) FROM vivier v WHERE v.agence_id = a.id AND v.ecarte = 0 AND v.entretien = 1 AND v.casier = 1 AND v.permis = 1 AND v.essai = 1 AND v.securite = 1 AND v.cles = 1) AS prets,
        (SELECT COUNT(*) FROM utilisateurs u WHERE u.agence_id = a.id AND u.role = \'technicien\' AND u.actif = 1 AND u.hash <> \'\' AND u.expire_le IS NULL) AS equipe
        FROM agences a WHERE a.actif = 1' . ($agence ? ' AND a.id = ?' : ''));
    $v->execute($agence ? [$agence] : []);
    $agences_vivier = array_values(array_filter($v->fetchAll(), fn ($r) => (int) $r['equipe'] > 0));
    if (!$agences_vivier) {
        $ind[] = indicateur('vivier', 'Remplaçants prêts dans le vivier', null, 'deux par agence', 'pas_assez', 'agence', 'Pas encore d\'équipe à remplacer.');
    } else {
        usort($agences_vivier, fn ($a, $b) => (int) $a['prets'] <=> (int) $b['prets']);
        $min = (int) $agences_vivier[0]['prets'];
        $ind[] = indicateur('vivier', 'Remplaçants prêts dans le vivier', $min . ($agence ? '' : ' au moins'), 'deux par agence', $min === 0 ? 'alerte' : ($min < 2 ? 'attention' : 'ok'), 'agence',
            implode(', ', array_map(fn ($r) => $r['nom'] . ' : ' . (int) $r['prets'], $agences_vivier)));
    }

    // Les échéances : une habilitation qui tombe sans qu'on l'ait vue venir, c'est une tournée qu'on ne peut plus confier.
    $ech_membres = "u.actif = 1 AND u.hash <> '' AND u.role IN ('technicien', 'chef') AND (u.expire_le IS NULL OR u.expire_le >= '" . date('Y-m-d') . "')" . ($agence ? ' AND u.agence_id = ?' : '');
    $pag = $agence ? [$agence] : [];
    $ech = $db->prepare("SELECT u.nom, q.type, q.intitule, q.expire_le FROM qualifications q JOIN utilisateurs u ON u.id = q.utilisateur_id WHERE $ech_membres AND q.expire_le IS NOT NULL
        AND q.expire_le <= ? ORDER BY q.expire_le");
    $ech->execute([...$pag, date('Y-m-d', strtotime('+' . ECHEANCE_PROCHE_JOURS . ' days'))]);
    $ech_proches = $ech->fetchAll();
    $ech_expirees = array_values(array_filter($ech_proches, fn ($x) => $x['expire_le'] < date('Y-m-d')));
    $nq = $db->prepare("SELECT COUNT(DISTINCT u.id), (SELECT COUNT(*) FROM qualifications q JOIN utilisateurs u ON u.id = q.utilisateur_id WHERE $ech_membres) FROM utilisateurs u WHERE $ech_membres");
    $nq->execute([...$pag, ...$pag]);
    [$nb_membres, $nb_qualifs] = array_map('intval', $nq->fetch(PDO::FETCH_NUM));
    $ech_nommer = fn ($l) => implode(', ', array_map(fn ($x) => $x['nom'] . ' : ' . (QUALIFICATIONS[$x['type']] ?? $x['type']) . ($x['intitule'] ? ' (' . $x['intitule'] . ')' : '') . ' au ' . date('d/m', strtotime($x['expire_le'])), array_slice($l, 0, 5))) . '.';
    $ind[] = !$nb_membres
        ? indicateur('echeances', 'Habilitations expirées ou à échéance dans le mois', null, 'aucune', 'pas_assez', 'agence', 'Pas encore d\'équipe.')
        : (!$nb_qualifs
            ? indicateur('echeances', 'Habilitations expirées ou à échéance dans le mois', null, 'aucune', 'a_saisir', 'agence', 'Aucune habilitation enregistrée sur les fiches.')
            : indicateur('echeances', 'Habilitations expirées ou à échéance dans le mois', (string) count($ech_proches), 'aucune', $ech_expirees ? 'alerte' : ($ech_proches ? 'attention' : 'ok'), 'agence',
                $ech_proches ? $ech_nommer($ech_expirees ?: $ech_proches) : 'Toutes les habilitations enregistrées sont valables au-delà d\'un mois.'));

    // Les congés demandés : une réponse dans les cinq jours, oui ou non avec la raison.
    $cgq = $db->prepare("SELECT u.nom, d.du, d.cree_le FROM demandes_conges d JOIN utilisateurs u ON u.id = d.utilisateur_id WHERE d.statut = 'attente'" . ($agence ? ' AND u.agence_id = ?' : '') . ' ORDER BY d.cree_le');
    $cgq->execute($pag);
    $cg_attente = $cgq->fetchAll();
    $cg_vieilles = array_values(array_filter($cg_attente, fn ($x) => strtotime($x['cree_le']) < time() - CONGE_REPONSE_JOURS * 86400));
    $ind[] = indicateur('conges_attente', 'Demandes de congés sans réponse', (string) count($cg_attente), 'réponse sous ' . CONGE_REPONSE_JOURS . ' jours', $cg_vieilles ? 'alerte' : ($cg_attente ? 'attention' : 'ok'), 'chef',
        $cg_attente ? implode(', ', array_map(fn ($x) => $x['nom'] . ' (pour le ' . date('d/m', strtotime($x['du'])) . ', demandé le ' . date('d/m', strtotime($x['cree_le'])) . ')', array_slice($cg_attente, 0, 5))) . '.' : 'Aucune demande en attente.');

    [$mesures, $courts, $duree] = $un("SELECT COUNT(*), SUM(v.duree_min < ?), AVG(v.duree_min) FROM visites v JOIN clients c ON c.id = v.client_id
        WHERE $w AND v.type = 'passage' AND v.duree_min IS NOT NULL AND v.quand >= ?", [PASSAGE_COURT_MIN, ...$p, $il_y_a(30)]);
    $ind[] = (int) $mesures < 10
        ? indicateur('passages_courts', 'Passages de moins de 20 minutes', null, '5 % au plus', 'pas_assez', 'chef', 'Moins de dix passages mesurés sur 30 jours.')
        : indicateur('passages_courts', 'Passages de moins de 20 minutes', pct((int) $courts / (int) $mesures), '5 % au plus',
            (int) $courts / (int) $mesures > 0.15 ? 'alerte' : ((int) $courts / (int) $mesures > 0.05 ? 'attention' : 'ok'), 'chef',
            (int) $courts . ' sur ' . (int) $mesures . ' passages mesurés ; durée moyenne ' . (int) round((float) $duree) . ' min (cible 25 à 30)');

    [$departs] = $un("SELECT COUNT(*) FROM utilisateurs WHERE role IN ('technicien', 'chef') AND parti_le >= ?" . ($agence ? ' AND agence_id = ?' : ''), [$il_y_a(365), ...$p]);
    $ind[] = indicateur('departs', 'Départs de l\'équipe sur 12 mois', (string) (int) $departs, 'aucun ou un',
        (int) $departs >= 2 ? 'alerte' : ((int) $departs === 1 ? 'attention' : 'ok'), 'direction');

    $score = derniere_saisie('score_equipe', $agence, 100);
    $ind[] = !$score
        ? indicateur('score_equipe', 'L\'équipe recommanderait l\'entreprise', null, '8 / 10 ou plus', 'a_saisir', 'agence', 'Une question à chacun, chaque trimestre.')
        : indicateur('score_equipe', 'L\'équipe recommanderait l\'entreprise', str_replace('.', ',', (string) $score['valeur']) . ' / 10', '8 / 10 ou plus',
            $score['valeur'] < 7 ? 'alerte' : ($score['valeur'] < 8 ? 'attention' : 'ok'), 'agence', 'Saisie de ' . $score['mois'] . ($score['agence'] ? ', ' . $score['agence'] : ''));

    $prime = derniere_saisie('prime_moyenne', $agence, 100);
    $ind[] = !$prime
        ? indicateur('prime', 'Prime moyenne du trimestre', null, 'versée chaque trimestre', 'a_saisir', 'direction', 'Sur la note Google et les clients conservés, jamais sur le nombre de passages.')
        : indicateur('prime', 'Prime moyenne du trimestre', euros($prime['valeur']), 'versée chaque trimestre', $prime['valeur'] <= 0 ? 'alerte' : 'ok', 'direction',
            'Saisie de ' . $prime['mois'] . ($prime['agence'] ? ', ' . $prime['agence'] : ''));

    // ---- L'argent : la direction seule. Un chef d'agence pilote sa qualité, pas la trésorerie de l'entreprise. ----
    if ($direction) {
        $treso = derniere_saisie('tresorerie_point_bas', null, 45);
        $sal = derniere_saisie('salaires_mensuels', null, 400);
        if (!$treso) {
            $ind[] = indicateur('tresorerie', 'Point bas de trésorerie prévu sur 90 jours', null, 'plus de deux mois de salaires', 'a_saisir', 'direction', 'À saisir chaque mois.');
        } else {
            $s = $sal ? $sal['valeur'] : 0.0;
            $ind[] = indicateur('tresorerie', 'Point bas de trésorerie prévu sur 90 jours', euros($treso['valeur']), 'plus de deux mois de salaires',
                $treso['valeur'] < 0 || ($s > 0 && $treso['valeur'] < $s) ? 'alerte' : ($s > 0 && $treso['valeur'] < 2 * $s ? 'attention' : 'ok'), 'direction',
                'Saisie de ' . $treso['mois'] . ($s > 0 ? ' ; un mois de salaires : ' . euros($s) : ' ; masse salariale non saisie, seul le passage sous zéro alerte'));
        }
        $e = derniere_saisie('ebitda_pct', null, 62);
        $ind[] = !$e
            ? indicateur('ebitda', 'EBITDA', null, '10 à 20 %', 'a_saisir', 'direction', 'Depuis la comptabilité, chaque mois clos.')
            : indicateur('ebitda', 'EBITDA', str_replace('.', ',', (string) $e['valeur']) . ' %', '10 à 20 %',
                $e['valeur'] < 8 ? 'alerte' : ($e['valeur'] < 10 ? 'attention' : ($e['valeur'] > 20 ? 'trop_belle' : 'ok')), 'direction', 'Saisie de ' . $e['mois']);
        $k = derniere_saisie('contribution_tech', null, 62);
        $ind[] = !$k
            ? indicateur('contribution', 'Contribution par technicien et par mois', null, '3 500 € ou plus', 'a_saisir', 'direction', 'Ce qu\'une tournée rapporte une fois payés son technicien, son van et ses produits.')
            : indicateur('contribution', 'Contribution par technicien et par mois', euros($k['valeur']), '3 500 € ou plus',
                $k['valeur'] < 3000 ? 'alerte' : ($k['valeur'] < 3500 ? 'attention' : 'ok'), 'direction', 'Saisie de ' . $k['mois']);
        $pr = derniere_saisie('produits_client', null, 62);
        $ind[] = !$pr
            ? indicateur('produits', 'Produits par client et par mois', null, '12 € au plus, objectif 11 €', 'a_saisir', 'direction', 'Achats de produits du mois divisés par les clients piscine.')
            : indicateur('produits', 'Produits par client et par mois', euros($pr['valeur']), '12 € au plus, objectif 11 €',
                $pr['valeur'] > 15 ? 'alerte' : ($pr['valeur'] > 12 ? 'attention' : 'ok'), 'direction', 'Saisie de ' . $pr['mois']);
        $rj = derniere_saisie('prelevements_rejetes', null, 62);
        $ind[] = !$rj
            ? indicateur('prelevements', 'Prélèvements rejetés', null, '1 % au plus', 'a_saisir', 'direction', 'Depuis le relevé du prestataire de prélèvement.')
            : indicateur('prelevements', 'Prélèvements rejetés', str_replace('.', ',', (string) $rj['valeur']) . ' %', '1 % au plus',
                $rj['valeur'] > 3 ? 'alerte' : ($rj['valeur'] > 1 ? 'attention' : 'ok'), 'direction', 'Saisie de ' . $rj['mois']);
    }

    // ---- La croissance ----
    $signes = 0;
    $tranches = 0;
    $q = $db->prepare("SELECT v.donnees FROM visites v JOIN clients c ON c.id = v.client_id WHERE $w AND v.type = 'diagnostic' AND v.quand >= ?");
    $q->execute([...$p, $il_y_a(90)]);
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $json) {
        $s = (json_decode($json, true) ?: [])['interne']['signe'] ?? '';
        if (in_array($s, ['Oui', 'Non'], true)) {   // « à relancer » n'est pas encore une réponse
            $tranches++;
            $signes += $s === 'Oui' ? 1 : 0;
        }
    }
    $ind[] = $tranches < 6
        ? indicateur('conversion', 'Diagnostics qui aboutissent', null, 'un sur deux', 'pas_assez', 'agence', 'Moins de six diagnostics tranchés sur 90 jours.')
        : indicateur('conversion', 'Diagnostics qui aboutissent', pct($signes / $tranches), 'un sur deux',
            $signes / $tranches < 0.35 ? 'alerte' : ($signes / $tranches < 0.5 ? 'attention' : 'ok'), 'agence', "$signes signés sur $tranches tranchés, 90 jours");

    // Un lead du site sans « venu par » est compté comme venu par le site.
    [$connus, $voisins] = $un("SELECT SUM(COALESCE(NULLIF(c.venu_par, ''), CASE WHEN c.origine = 'site' THEN 'site' END) IS NOT NULL),
        SUM(c.venu_par IN ('voisin', 'parrainage')) FROM clients c WHERE $w AND c.statut IN ('client', 'veille', 'desinscrit') AND c.cree_le >= ?", [...$p, $il_y_a(180)]);
    $ind[] = (int) $connus < 6
        ? indicateur('voisins', 'Nouveaux clients venus par un voisin ou un parrain', null, 'un sur deux', 'pas_assez', 'agence', 'Moins de six nouveaux clients dont on sait d\'où ils viennent, sur six mois.')
        : indicateur('voisins', 'Nouveaux clients venus par un voisin ou un parrain', pct((int) $voisins / (int) $connus), 'un sur deux',
            (int) $voisins / (int) $connus < 0.3 ? 'alerte' : ((int) $voisins / (int) $connus < 0.5 ? 'attention' : 'ok'), 'agence', (int) $voisins . ' sur ' . (int) $connus . ', six mois');

    // ---- Les recommandations : une par indicateur qui décroche, puis les arbitrages qui en croisent plusieurs. ----
    $par_cle = array_column($ind, null, 'cle');
    $etat = fn (string $c) => $par_cle[$c]['etat'] ?? 'absent';
    $recos = [];
    foreach ($ind as $i) {
        $r = RECOMMANDATIONS[$i['reco']][$i['etat']] ?? null;
        if ($r) {
            $actions = $r[2];
            if ($i['cle'] === 'remplissage' && $i['etat'] === 'alerte' && str_contains($i['detail'], 'deux mois de suite')) {
                array_unshift($actions, 'Deux mois de suite au-delà de 85 % : lancer le recrutement cette semaine.');
            }
            $recos[] = ['niveau' => $i['etat'] === 'alerte' ? 'alerte' : 'attention', 'titre' => $r[0], 'qui' => PROPRIETAIRES[$r[1]], 'actions' => $actions, 'indicateur' => $i['cle'], 'detail' => $i['detail']];
        }
    }
    $arbitrages = [];
    if ($etat('tresorerie') === 'alerte') {
        $arbitrages[] = ['titre' => 'La trésorerie passe avant tout le reste', 'texte' => 'Tant que le point bas reste sous un mois de salaires, aucune embauche ni dépense nouvelle. Les salaires, eux, ne se discutent pas.'];
    }
    if ($etat('resiliations') === 'alerte' && (int) $leads30 > 0) {
        $arbitrages[] = ['titre' => 'Réparer le service avant de vendre', 'texte' => 'Des clients partent pendant que d\'autres arrivent. Ralentir les signatures le temps de comprendre et de corriger : un nouveau client servi par une tournée qui décroche partira aussi.'];
    }
    if ($etat('remplissage') === 'alerte' && $etat('tresorerie') !== 'alerte') {
        $arbitrages[] = ['titre' => 'Embaucher avant de signer', 'texte' => 'Une tournée trop pleine finit en passages courts, puis en clients perdus et en technicien épuisé. La liste d\'attente coûte moins cher.'];
    }
    if ($etat('ebitda') === 'trop_belle' && (in_array($etat('passages_courts'), ['attention', 'alerte'], true) || in_array($etat('score_equipe'), ['attention', 'alerte'], true))) {
        $arbitrages[] = ['titre' => 'Une marge trop belle se paie plus tard', 'texte' => 'La marge monte pendant que les passages raccourcissent ou que l\'équipe décroche : c\'est la qualité qui finance le résultat. Redonner du temps chez le client.'];
    }
    if ($etat('departs') !== 'ok' && in_array($etat('score_equipe'), ['attention', 'alerte'], true)) {
        $arbitrages[] = ['titre' => 'Le risque est humain', 'texte' => 'Des départs et un ressenti en baisse : les prochains départs se préparent maintenant. Les entretiens passent avant les nouveaux clients ce mois-ci.'];
    }
    $ordre = ['alerte' => 0, 'attention' => 1];
    usort($recos, fn ($a, $b) => $ordre[$a['niveau']] <=> $ordre[$b['niveau']]);

    // Les cinq chiffres du lundi : ce que le dirigeant regarde, et lui seul, quand l'entreprise tourne sans lui.
    $lundi = array_values(array_filter(array_map(fn ($c) => $par_cle[$c] ?? null, ['resiliations', 'tresorerie', 'remplissage', 'note_google', 'departs'])));

    $mois = date('Y-m');
    $q = $db->prepare("SELECT agence_id, cle, valeur FROM pilotage_saisies WHERE mois = ? AND saisi_par <> 'calcul'" . ($direction ? '' : ' AND agence_id = ?'));
    $q->execute($direction ? [$mois] : [$mois, (int) $u['agence_id']]);
    $saisies = [];
    foreach ($q->fetchAll() as $r) {
        $saisies[(int) $r['agence_id']][$r['cle']] = (float) $r['valeur'];
    }
    $champs = [];
    foreach (SAISIES as $cle => $s) {
        if ($s['portee'] === 'entreprise' && !$direction) {
            continue;
        }
        $champs[] = ['cle' => $cle, 'portee' => $s['portee'], 'libelle' => $s['libelle'], 'unite' => $s['unite'], 'frequence' => $s['frequence'], 'min' => $s['min'], 'max' => $s['max']];
    }
    sortir(200, [
        'ok' => true, 'agence' => $agence, 'direction' => $direction, 'mois' => $mois,
        'lundi' => $lundi, 'indicateurs' => $ind, 'recommandations' => $recos, 'arbitrages' => $arbitrages, 'tournees' => $tournees,
        'champs' => $champs, 'saisies' => (object) $saisies,
        'agences' => $direction ? $db->query('SELECT id, nom FROM agences WHERE actif = 1 ORDER BY nom')->fetchAll() : [],
    ]);
}

function a_pilotage_saisie(): void
{
    $u = exiger_equipe(['chef', 'superadmin']);
    exiger_post();
    $d = entree();
    $mois = (string) ($d['mois'] ?? '');
    // Le mois en cours ou un mois passé : on ne saisit pas l'avenir, ni un mois vieux de plus d'un an.
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mois) || $mois > date('Y-m') || $mois < date('Y-m', strtotime('-12 months'))) {
        sortir(200, ['ok' => false, 'erreur' => 'mois']);
    }
    $agence = $u['role'] === 'superadmin' ? entier($d['agence_id'] ?? 0) : (int) $u['agence_id'];
    if ($agence > 0) {
        $a = base()->prepare('SELECT id FROM agences WHERE id = ?');
        $a->execute([$agence]);
        if ($a->fetchColumn() === false) {
            sortir(200, ['ok' => false, 'erreur' => 'agence']);
        }
    }
    $valeurs = is_array($d['valeurs'] ?? null) ? $d['valeurs'] : [];
    $a_ecrire = [];
    foreach ($valeurs as $cle => $v) {
        $s = SAISIES[$cle] ?? null;
        if (!$s) {
            sortir(200, ['ok' => false, 'erreur' => 'champ', 'champ' => (string) $cle]);
        }
        // Refus par défaut : un chiffre d'entreprise n'appartient qu'à la direction, un chiffre d'agence à une agence précise.
        if ($s['portee'] === 'entreprise' && $u['role'] !== 'superadmin') {
            tracer($u, 'accès refusé', "pilotage $cle");
            sortir(403, ['ok' => false, 'erreur' => 'droits']);
        }
        if ($s['portee'] === 'agence' && $agence <= 0) {
            sortir(200, ['ok' => false, 'erreur' => 'agence', 'champ' => $cle]);
        }
        if ($v === null || $v === '') {
            $a_ecrire[$cle] = null;   // un champ vidé efface la saisie du mois
            continue;
        }
        if (!is_numeric($v) || (float) $v < $s['min'] || (float) $v > $s['max']) {
            sortir(200, ['ok' => false, 'erreur' => 'valeur', 'champ' => $cle]);
        }
        $a_ecrire[$cle] = (float) $v;
    }
    $db = base();
    $db->beginTransaction();
    foreach ($a_ecrire as $cle => $v) {
        $ag = SAISIES[$cle]['portee'] === 'entreprise' ? 0 : $agence;
        if ($v === null) {
            $db->prepare("DELETE FROM pilotage_saisies WHERE mois = ? AND agence_id = ? AND cle = ? AND saisi_par <> 'calcul'")->execute([$mois, $ag, $cle]);
        } else {
            $db->prepare('INSERT INTO pilotage_saisies (mois, agence_id, cle, valeur, saisi_par, saisi_le) VALUES (?,?,?,?,?,?)
                ON CONFLICT(mois, agence_id, cle) DO UPDATE SET valeur = excluded.valeur, saisi_par = excluded.saisi_par, saisi_le = excluded.saisi_le')
                ->execute([$mois, $ag, $cle, $v, $u['courriel'], maintenant()]);
        }
    }
    $db->commit();
    tracer($u, 'pilotage saisi', "$mois agence=$agence " . implode(', ', array_keys($a_ecrire)));
    sortir(200, ['ok' => true, 'enregistres' => count($a_ecrire)]);
}
