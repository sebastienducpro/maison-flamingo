// Maison Flamingo · ce que contiennent un diagnostic et un passage.
// Le formulaire et le rapport lisent tous deux cette description : ajouter une
// ligne ici l'ajoute aux deux, et rien ne peut diverger entre ce qu'on saisit et
// ce que le client lit. Les sections « interne » ne sortent jamais vers le client.

// Les formules et leurs prix par défaut, les mêmes que FORMULES côté serveur. Le diagnostic se fait souvent sans
// réseau : l'écran part de ces valeurs, puis prend la grille de la direction dès qu'il l'a lue une fois (chargerGrille).
// Le contrat, lui, est toujours chiffré par le serveur.
const TARIFS = {
  piscine:          { libelle: 'Piscine', prix: 179, fondateur: 149, piscine: true, detail: 'Chaque semaine d\'avril à octobre, produits, remise en route et hivernage compris' },
  quinzaine:        { libelle: 'Piscine équipée, quinzaine hors été', prix: 149, fondateur: 125, piscine: true, detail: 'Chaque semaine l\'été, tous les quinze jours le reste de la saison, pour un bassin équipé d\'un traitement automatique et d\'une régulation du pH' },
  pack:             { libelle: 'Piscine + Maison', pack: ['piscine', 'maison'], piscine: true, detail: 'La piscine chaque semaine, et le jardin, le petit bricolage et la vigilance de la maison' },
  maison:           { libelle: 'Maison', prix: 45, fondateur: 45, piscine: false, detail: 'Petits travaux de jardinage, petit bricolage et vigilance de la maison, surtout d\'automne au printemps' },
  maison_renforcee: { libelle: 'Maison renforcée', prix: 100, fondateur: 100, piscine: false, detail: 'Environ deux heures d\'intervention chaque mois : jardin, petit bricolage, vigilance' },
  veille:           { libelle: 'Veille de résidence', prix: 49, fondateur: 49, piscine: false, detail: 'Une visite complète chaque mois de la maison inoccupée, et après chaque coup de vent' },
};
let REMISE_PACK = 12;
// La taille du bassin, comme au contrat : jusqu'à 50 m² le prix de la formule, de 50 à 80 m² +20 %, au-delà sur devis.
const SURFACE_STANDARD = 50;
const SURFACE_MAJOREE = 80;
const MAJORATION_PCT = 20;

function appliquerGrille(g) {
  if (!g) return;
  Object.values(g).forEach((f) => {
    const t = TARIFS[f.cle];
    if (!t) return;
    if (f.pack) { REMISE_PACK = f.remise; return; }
    t.prix = f.prix; t.fondateur = f.fondateur;
  });
}
try { appliquerGrille(JSON.parse(localStorage.getItem('flamingo_grille') || 'null')); } catch (e) {}

const ETAT_CHOIX = ['bon', 'surveiller', 'prevoir', 'absent'];

const FORMULAIRES = {
  diagnostic: {
    titre: 'Diagnostic',
    sections: [
      { titre: 'L\'eau', champs: [
        { type: 'mesure', cle: 'ph' },
        { type: 'mesure', cle: 'chlore_libre' },
        { type: 'mesure', cle: 'chlore_total' },
        { type: 'mesure', cle: 'tac' },
        { type: 'mesure', cle: 'th' },
        { type: 'mesure', cle: 'stabilisant' },
        { type: 'mesure', cle: 'sel', aide: 'Seulement si le bassin a un électrolyseur' },
        { type: 'mesure', cle: 'temperature' },
        { type: 'choix', cle: 'aspect', libelle: 'Aspect de l\'eau', options: ['Claire', 'Voilée', 'Verdâtre', 'Verte'] },
      ] },
      { titre: 'Le local technique', champs: [
        { type: 'etat', cle: 'pompe', libelle: 'Pompe', aide: 'Bruit, fuite au joint, chaleur' },
        { type: 'etat', cle: 'prefiltre', libelle: 'Préfiltre de la pompe' },
        { type: 'etat', cle: 'filtre', libelle: 'Filtre', aide: 'Type, âge du sable ou de la cartouche' },
        { type: 'mesure', cle: 'pression' },
        { type: 'etat', cle: 'vanne', libelle: 'Vanne et tuyauterie', aide: 'Suintements, raccords' },
        { type: 'etat', cle: 'coffret', libelle: 'Coffret électrique', aide: 'À l\'œil seulement : horloge, différentiel' },
        { type: 'nombre', cle: 'filtration_heures', libelle: 'Durée de filtration réglée', unite: 'h par jour' },
        { type: 'etat', cle: 'traitement_auto', libelle: 'Électrolyseur ou régulateur' },
        { type: 'etat', cle: 'chauffage', libelle: 'Chauffage ou pompe à chaleur' },
      ] },
      { titre: 'Le bassin', champs: [
        { type: 'nombre', cle: 'longueur', libelle: 'Longueur', unite: 'm' },
        { type: 'nombre', cle: 'largeur', libelle: 'Largeur', unite: 'm' },
        { type: 'nombre', cle: 'profondeur', libelle: 'Profondeur moyenne', unite: 'm' },
        { type: 'volume' },
        { type: 'choix', cle: 'revetement', libelle: 'Revêtement', options: ['Liner', 'PVC armé', 'Béton', 'Coque', 'Autre'] },
        { type: 'etat', cle: 'ligne_eau', libelle: 'Ligne d\'eau' },
        { type: 'etat', cle: 'skimmers', libelle: 'Skimmers, bonde, buses' },
        { type: 'etat', cle: 'parois', libelle: 'Parois et fond', aide: 'Fissures, décollements, taches' },
      ] },
      { titre: 'Les abords', champs: [
        { type: 'choix', cle: 'arbres', libelle: 'Arbres autour', options: ['Peu', 'Quelques-uns', 'Beaucoup'] },
        { type: 'choix', cle: 'couverture', libelle: 'Couverture', options: ['Volet roulant', 'Bâche', 'Abri', 'Aucune'] },
        { type: 'ouinon', cle: 'robot', libelle: 'Robot de nettoyage' },
        { type: 'choix', cle: 'securite', libelle: 'Dispositif de sécurité', options: ['Alarme', 'Barrière', 'Volet', 'Abri', 'Aucun vu'] },
      ] },
      { titre: 'Aujourd\'hui', interne: true, champs: [
        { type: 'choix', cle: 'qui', libelle: 'Qui s\'en occupe', options: ['Le propriétaire', 'Un pisciniste', 'Un proche', 'Personne'] },
        { type: 'choix', cle: 'frequence', libelle: 'À quelle fréquence', options: ['Chaque semaine', 'Tous les 15 jours', 'Quand il faut', 'Rarement'] },
        { type: 'ouinon', cle: 'eau_tournee', libelle: 'L\'eau a tourné cet été' },
        { type: 'ouinon', cle: 'niveau_baisse', libelle: 'Le niveau baisse plus vite que prévu' },
      ] },
      { titre: 'La maison', optionnel: true, interne: true, champs: [
        { type: 'texte', cle: 'compteurs', libelle: 'Où sont les compteurs' },
        { type: 'texte', cle: 'cles', libelle: 'Clés et accès' },
      ] },
      { titre: 'Ce qu\'on a vu', champs: [ { type: 'constats' } ] },
      { titre: 'Notre proposition', champs: [ { type: 'proposition' } ] },
      { titre: 'Pour toi seul', interne: true, champs: [
        { type: 'choix', cle: 'signe', libelle: 'Il a signé', options: ['Oui', 'Non', 'À relancer'] },
        { type: 'choix', cle: 'raison_refus', libelle: 'Si non, pourquoi', options: ['Le prix', 'Pas le moment', 'Le fait lui-même', 'A déjà quelqu\'un', 'Autre'] },
        { type: 'choix', cle: 'a_179', libelle: '« À 179 €, vous auriez signé ? »', options: ['Oui', 'Non', 'Hésite'] },
        { type: 'texte', cle: 'quartier', libelle: 'Quartier', aide: 'Le Mail, centre, route de Redessan… Un test de prix se mène un quartier à la fois' },
        { type: 'ouinon', cle: 'dans_zone', libelle: 'Dans la zone' },
        { type: 'nombre', cle: 'voisins_piscine', libelle: 'Piscines dans la rue', unite: '' },
        { type: 'ouinon', cle: 'decideur', libelle: 'J\'ai vu la personne qui décide' },
        { type: 'texte', cle: 'budget_actuel', libelle: 'Ce qu\'il paie aujourd\'hui' },
        { type: 'texte', cle: 'note_interne', libelle: 'Note', long: true },
      ] },
    ],
  },
  passage: {
    titre: 'Passage',
    sections: [
      { titre: 'L\'eau', champs: [
        { type: 'mesure', cle: 'ph' },
        { type: 'mesure', cle: 'chlore_libre' },
        { type: 'mesure', cle: 'temperature' },
        { type: 'mesure', cle: 'tac', aide: 'Une fois par mois suffit' },
        { type: 'mesure', cle: 'stabilisant', aide: 'Une fois par mois suffit' },
        { type: 'choix', cle: 'aspect', libelle: 'Aspect de l\'eau', options: ['Claire', 'Voilée', 'Verdâtre', 'Verte'] },
      ] },
      { titre: 'Ce qui a été fait', champs: [
        { type: 'multi', cle: 'gestes', libelle: 'Gestes', options: ['Paniers vidés', 'Ligne d\'eau brossée', 'Parois brossées', 'Fond aspiré', 'Contre-lavage', 'Préfiltre nettoyé', 'Niveau d\'eau ajusté'] },
        { type: 'texte', cle: 'produits', libelle: 'Produits ajoutés', aide: 'Exemple : 1 galet de chlore, 200 g de pH moins' },
      ] },
      { titre: 'Contrôles', champs: [
        { type: 'etat', cle: 'pompe', libelle: 'Pompe' },
        { type: 'etat', cle: 'filtre', libelle: 'Filtre' },
        { type: 'mesure', cle: 'pression' },
      ] },
      { titre: 'La maison', optionnel: true, champs: [
        { type: 'multi', cle: 'maison', libelle: 'Tour de la maison', options: ['Volets', 'Portail', 'Courrier rentré', 'Compteur d\'eau relevé', 'Aération', 'Rien à signaler'] },
        { type: 'nombre', cle: 'compteur_eau', libelle: 'Index du compteur d\'eau', unite: 'm³' },
      ] },
      { titre: 'Ce qu\'on a vu', champs: [ { type: 'constats' } ] },
      { titre: 'Pour toi seul', interne: true, champs: [
        { type: 'texte', cle: 'note_interne', libelle: 'Note', long: true },
      ] },
    ],
  },
};

function volumeBassin(v) {
  const L = parseFloat(v.longueur), l = parseFloat(v.largeur), p = parseFloat(v.profondeur);
  return [L, l, p].every(Number.isFinite) ? Math.round(L * l * p) : null;
}

// Le prix proposé au diagnostic, calculé comme au contrat : majoration sur la piscine, puis remise de pack sur la piscine.
// Rend null sans formule ; prix null avec une raison (« sur devis », « à fixer ») quand le chiffre ne peut pas se dire.
function prixPropose(formule, valeurs, fondateur = true) {
  const t = TARIFS[formule];
  if (!t) return null;
  const parts = (t.pack || [formule]).map((k) => TARIFS[k]);
  const base = parts.map((x) => (fondateur ? x.fondateur : x.prix));
  if (base.some((b) => b === null || b === undefined)) return { prix: null, raison: 'a_fixer', fondateur };
  const surface = parseFloat(valeurs.longueur) * parseFloat(valeurs.largeur);
  if (t.piscine && Number.isFinite(surface) && surface > SURFACE_MAJOREE) return { prix: null, raison: 'devis', fondateur };
  const grand = t.piscine && Number.isFinite(surface) && surface > SURFACE_STANDARD;
  const prix = parts.reduce((s, x, i) => s + (x.piscine ? Math.round(base[i] * (grand ? 1 + MAJORATION_PCT / 100 : 1)) - (t.pack ? REMISE_PACK : 0) : base[i]), 0);
  return { prix, supplement: grand, fondateur };
}

function textePrixPropose(c) {
  if (!c) return '';
  if (c.prix === null) return c.raison === 'devis' ? '<strong>Sur devis</strong> : bassin de plus de ' + SURFACE_MAJOREE + ' m², le prix se fixe après le diagnostic.' : '<strong>Prix à fixer</strong> dans la grille de la direction.';
  return `<strong>${c.prix} € TTC par mois</strong>, ${c.fondateur ? 'tarif fondateurs' : 'tarif standard'}${c.supplement ? ', dont +' + MAJORATION_PCT + ' % pour un bassin de ' + SURFACE_STANDARD + ' à ' + SURFACE_MAJOREE + ' m²' : ''}`;
}
