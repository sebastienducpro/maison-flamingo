// Maison Flamingo · le pilotage du dirigeant.
// L'écran affiche ce que le serveur calcule : les seuils et les recommandations ne vivent que là-bas,
// pour qu'un chef d'agence et la direction lisent exactement les mêmes règles.
'use strict';

const NIVEAUX = {
  ok: 'Dans la cible', attention: 'À surveiller', alerte: 'Alerte', trop_belle: 'À vérifier',
  a_saisir: 'À saisir', pas_assez: 'Pas encore assez de données',
};
const FAMILLES = [
  ['Les clients', ['resiliations', 'rapports', 'jour_prevu', 'demandes', 'leads', 'note_google']],
  ['L\'équipe', ['remplissage', 'journees', 'couverture', 'vivier', 'conges_attente', 'echeances', 'passages_courts', 'departs', 'score_equipe', 'prime']],
  ['L\'argent', ['tresorerie', 'ebitda', 'contribution', 'produits', 'prelevements']],
  ['La croissance', ['conversion', 'voisins']],
];

const pastilleNiveau = (n) => `<span class="niveau ${n}">${NIVEAUX[n] || n}</span>`;

async function vuePilotage(agence) {
  vue().innerHTML = '<p class="chargement">Chargement…</p>';
  const d = await api('pilotage', { params: agence ? { agence } : {} });
  if (!d.ok) return;
  const parCle = Object.fromEntries(d.indicateurs.map((i) => [i.cle, i]));
  const alertes = d.recommandations.filter((r) => r.niveau === 'alerte').length;
  const ligne = (i) => `<tr class="ligne-${i.etat}"><td><strong>${echapper(i.libelle)}</strong>${i.detail ? `<span class="detail">${echapper(i.detail)}</span>` : ''}</td>
    <td class="valeur">${i.valeur !== null ? echapper(i.valeur) : '–'}</td><td>${echapper(i.cible)}</td><td>${pastilleNiveau(i.etat)}</td><td>${echapper(i.proprietaire)}</td></tr>`;
  vue().innerHTML = `
    <div class="tete"><h1>Pilotage</h1>
      ${d.direction ? `<select id="agence" aria-label="Agence"><option value="">Toute l'entreprise</option>${d.agences.map((a) => `<option value="${a.id}"${a.id === d.agence ? ' selected' : ''}>${echapper(a.nom)}</option>`).join('')}</select>` : ''}
    </div>
    <p class="aide">La marge se fait par le prix et la densité des tournées, jamais par la cadence chez le client ni par les salaires. Les recommandations proposent ; c'est toujours vous qui décidez.</p>
    <h2>Les chiffres du lundi</h2>
    <div class="kpis lundi">${d.lundi.map((i) => `<div class="kpi niveau-${i.etat}"><span class="kpi-val">${i.valeur !== null ? echapper(i.valeur) : '–'}</span><span class="kpi-lib">${echapper(i.libelle)}</span><span class="kpi-sous">${pastilleNiveau(i.etat)} · cible : ${echapper(i.cible)}</span></div>`).join('')}</div>
    ${d.arbitrages.length ? `<h2>Les arbitrages du moment</h2>${d.arbitrages.map((a) => `<div class="arbitrage"><strong>${echapper(a.titre)}</strong><p>${echapper(a.texte)}</p></div>`).join('')}` : ''}
    <h2>À faire <span class="aide">${d.recommandations.length ? d.recommandations.length + ' point' + (d.recommandations.length > 1 ? 's' : '') + (alertes ? ', dont ' + alertes + ' en alerte' : '') : 'rien ne décroche'}</span></h2>
    ${d.recommandations.length ? `<ul class="liste recos">${d.recommandations.map((r) => `<li class="reco ${r.niveau}">
      <div class="reco-tete">${pastilleNiveau(r.niveau)}<strong>${echapper(r.titre)}</strong></div>
      ${r.detail ? `<p class="detail">${echapper(r.detail)}</p>` : ''}
      <ol>${r.actions.map((x) => `<li>${echapper(x)}</li>`).join('')}</ol>
      <p class="qui">Qui agit : ${echapper(r.qui)}</p></li>`).join('')}</ul>`
      : '<p class="bandeau calme">Tous les indicateurs mesurés sont dans leur cible. Gardez le rythme : rapports, jour fixe, message du vendredi.</p>'}
    ${FAMILLES.map(([titre, cles]) => {
      const lignes = cles.map((c) => parCle[c]).filter(Boolean);
      return lignes.length ? `<h2>${titre}</h2><div class="table-defile"><table class="pilotage">
        <thead><tr><th>Indicateur</th><th>Valeur</th><th>Cible</th><th>État</th><th>Qui le suit</th></tr></thead>
        <tbody>${lignes.map(ligne).join('')}</tbody></table></div>` : '';
    }).join('')}
    ${d.tournees.length ? `<h2>Remplissage par tournée <span class="aide">une semaine pleine fait 35 h, trajets compris ; une maison fermée seule compte pour un quart, puisqu'on n'y passe qu'une semaine sur quatre</span></h2>
    <ul class="barres">${d.tournees.map((t) => `<li><span>${echapper(t.nom)}</span><meter min="0" max="1" low="0.6" high="0.85" optimum="0.8" value="${Math.min(t.remplissage, 1)}"></meter><strong>${heures(t.minutes)} · ${Math.round(t.remplissage * 100)} %</strong></li>`).join('')}</ul>` : ''}
    <h2>Saisie du mois <span class="aide">les chiffres que l'outil ne peut pas connaître</span></h2>
    <form id="saisie" class="carte">
      <div class="deux">
        <label>Mois<input type="month" name="mois" id="saisie-mois" value="${d.mois}" max="${d.mois}"></label>
        ${d.direction && !d.agence ? `<label>Agence des chiffres d'agence<select name="agence_id" id="saisie-agence"><option value="">–</option>${d.agences.map((a) => `<option value="${a.id}">${echapper(a.nom)}</option>`).join('')}</select></label>` : ''}
      </div>
      <div id="saisie-champs"></div>
      <p class="aide">Un champ vidé efface la saisie de ce mois. Les bornes refusent une faute de frappe, jamais une mauvaise nouvelle.</p>
      <button class="principal">Enregistrer la saisie</button>
      <p class="message" id="saisie-m" role="status"></p>
    </form>`;
  const sel = document.getElementById('agence');
  if (sel) sel.addEventListener('change', () => aller(sel.value ? '#/pilotage/' + sel.value : '#/pilotage'));

  const form = document.getElementById('saisie');
  const choixAgence = document.getElementById('saisie-agence');
  const agenceSaisie = () => (d.direction ? (d.agence || +(choixAgence && choixAgence.value) || 0) : null);
  // Les valeurs déjà saisies ce mois-ci, pour l'agence choisie et pour l'entreprise.
  const dessinerChamps = () => {
    const ag = agenceSaisie();
    const deja = (cle, portee) => {
      const bloc = d.saisies[portee === 'entreprise' ? 0 : (ag || 0)] || {};
      return bloc[cle] !== undefined ? bloc[cle] : '';
    };
    const groupe = (portee, titre) => {
      const champs = d.champs.filter((c) => c.portee === portee);
      if (!champs.length) return '';
      const ferme = portee === 'agence' && d.direction && !ag;
      return `<fieldset class="saisie-groupe"><legend>${titre}</legend>${ferme ? '<p class="aide">Choisissez d\'abord l\'agence concernée.</p>' : ''}
        ${champs.map((c) => `<label>${echapper(c.libelle)} <span class="aide">${echapper(c.unite)}, ${echapper(c.frequence)}</span>
          <input type="number" step="any" inputmode="decimal" name="${c.cle}" min="${c.min}" max="${c.max}" value="${deja(c.cle, portee)}"${ferme ? ' disabled' : ''}></label>`).join('')}</fieldset>`;
    };
    document.getElementById('saisie-champs').innerHTML = groupe('agence', d.direction ? 'Chiffres d\'agence' : 'Chiffres de votre agence') + groupe('entreprise', 'Chiffres de l\'entreprise');
  };
  dessinerChamps();
  if (choixAgence) choixAgence.addEventListener('change', dessinerChamps);
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const m = document.getElementById('saisie-m');
    const valeurs = {};
    form.querySelectorAll('#saisie-champs input:not([disabled])').forEach((i) => { valeurs[i.name] = i.value === '' ? null : i.value; });
    const corps = { mois: document.getElementById('saisie-mois').value, valeurs };
    if (d.direction) corps.agence_id = agenceSaisie() || 0;
    m.textContent = 'Enregistrement…';
    const r = await api('pilotage_saisie', { methode: 'POST', corps });
    if (r.ok) { m.textContent = 'Enregistré.'; setTimeout(() => vuePilotage(agence), 600); return; }
    const pourquoi = { mois: 'Ce mois ne peut pas être saisi : le mois en cours ou un mois des douze derniers.', valeur: 'Cette valeur sort des bornes possibles.', agence: 'Choisissez l\'agence concernée.', droits: 'Ces chiffres sont réservés à la direction.' };
    const champ = r.champ ? (d.champs.find((c) => c.cle === r.champ) || {}).libelle : '';
    m.textContent = (pourquoi[r.erreur] || 'La saisie n\'a pas été enregistrée.') + (champ ? ' (' + champ + ')' : '');
  });
}
