// Maison Flamingo · ce que contiennent un diagnostic et un passage.
// Le formulaire et le rapport lisent tous deux cette description : ajouter une
// ligne ici l'ajoute aux deux, et rien ne peut diverger entre ce qu'on saisit et
// ce que le client lit. Les sections « interne » ne sortent jamais vers le client.

const TARIFS = {
  piscine: { libelle: 'Piscine', prix: 149, detail: 'Chaque semaine d\'avril à octobre, produits, remise en route et hivernage compris' },
  pack:    { libelle: 'Piscine + Maison fermée', prix: 179, detail: 'La piscine chaque semaine et la maison veillée, dans la même visite' },
  maison:  { libelle: 'Maison fermée', prix: 49, detail: 'Une visite complète chaque mois, et après chaque coup de vent' },
};
// Hypothèse à valider sur les premiers relevés : au-delà de 10 × 5 m, les produits coûtent plus.
const SURFACE_STANDARD = 50;
const SUPPLEMENT_GRAND_BASSIN = 20;

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

function prixPropose(formule, valeurs) {
  const t = TARIFS[formule];
  if (!t) return null;
  const surface = parseFloat(valeurs.longueur) * parseFloat(valeurs.largeur);
  const grand = formule !== 'maison' && Number.isFinite(surface) && surface > SURFACE_STANDARD;
  return { prix: t.prix + (grand ? SUPPLEMENT_GRAND_BASSIN : 0), supplement: grand };
}
