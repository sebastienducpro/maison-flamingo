// Maison Flamingo · la tournée du jour sur une carte, et la semaine de chaque technicien.
//
// Les distances sont à vol d'oiseau majoré de 30 % : assez juste pour comparer deux
// ordres et équilibrer des journées, pas pour guider la voiture. Le guidage reste
// à Plans ou Google Maps, que chaque arrêt sait ouvrir.
'use strict';

const FOND_IGN = 'https://data.geopf.fr/wmts?SERVICE=WMTS&REQUEST=GetTile&VERSION=1.0.0&LAYER=GEOGRAPHICALGRIDSYSTEMS.PLANIGNV2&STYLE=normal&TILEMATRIXSET=PM&FORMAT=image/png&TILEMATRIX={z}&TILEROW={y}&TILECOL={x}';
let CARTE = null;

function itineraire(a) {
  const q = encodeURIComponent(`${a.adresse} ${a.commune}`.trim());
  const coords = a.lat != null ? `${a.lat},${a.lon}` : q;
  return /iPhone|iPad|iPod|Macintosh/.test(navigator.userAgent) ? `https://maps.apple.com/?daddr=${coords}` : `https://www.google.com/maps/dir/?api=1&destination=${coords}`;
}

async function vueTournee(idTech, jourDemande) {
  vue().innerHTML = '<p class="chargement">Chargement…</p>';
  const role = APP.utilisateur.role, pilote = role !== 'technicien';
  let techniciens = [];
  if (pilote) {
    const e = await api('equipe');
    techniciens = (e.membres || []).filter((m) => m.role === 'technicien' && m.actif);
    if (!idTech && techniciens.length) idTech = techniciens[0].id;
    if (!techniciens.length) { vue().innerHTML = '<h1>Tournées</h1><p>Aucun technicien actif pour l\'instant. Invitez-en un depuis <a href="#/equipe">Équipe</a>.</p>'; return; }
  }
  const jour = +jourDemande || (new Date().getDay() || 7);
  const cle = 'tournee:' + (idTech || 'moi') + ':' + jour;
  let d, horsLigne = false;
  try {
    d = await api('tournee', { params: { jour, ...(pilote ? { technicien: idTech } : {}) } });
    if (d.ok) try { localStorage.setItem(cle, JSON.stringify(d)); } catch (e) { /* mémoire pleine : on s'en passe */ }
  } catch (e) {
    // Sans réseau, la dernière tournée vue reste consultable : adresses, accès, ordre.
    try { d = JSON.parse(localStorage.getItem(cle) || 'null'); } catch (err) { d = null; }
    if (!d) throw e;
    horsLigne = true;
  }
  if (!d.ok) return;
  const base = '#/tournee/' + (pilote ? idTech : 'moi');
  const faits = d.arrets.filter((a) => a.fait).length;
  vue().innerHTML = `
    ${horsLigne ? BANDEAU_HORS_LIGNE : ''}
    <div class="tete"><h1>${pilote ? 'Tournée de ' + echapper(d.technicien.nom) : 'Ma tournée'}</h1>
      ${pilote ? `<select id="tech" aria-label="Technicien">${techniciens.map((t) => `<option value="${t.id}"${t.id === +idTech ? ' selected' : ''}>${echapper(t.nom)}</option>`).join('')}</select>` : ''}</div>
    <nav class="jours">${[1, 2, 3, 4, 5, 6].map((j) => `<a href="${base}/${j}"${j === jour ? ' aria-current="page"' : ''}>${JOURS[j].slice(0, 3)}</a>`).join('')}</nav>
    ${d.arrets.length ? `
    <div class="resume">
      <div><strong>${d.arrets.length}</strong> arrêt${d.arrets.length > 1 ? 's' : ''}${faits ? ` · ${faits} fait${faits > 1 ? 's' : ''}` : ''}</div>
      <div>Sur place <strong>${heures(d.actuel.sur_place_min)}</strong> · trajets <strong>${String(d.actuel.km).replace('.', ',')} km</strong>, environ ${heures(d.actuel.trajet_min)}</div>
      <div>Journée estimée <strong>${heures(d.actuel.total_min)}</strong></div>
    </div>
    ${d.propose.gain_min >= 5 ? `<div class="bandeau calme proposition-ordre">Un autre ordre fait gagner environ <strong>${d.propose.gain_min} min</strong> (${String(d.propose.km).replace('.', ',')} km au lieu de ${String(d.actuel.km).replace('.', ',')}).
      <button class="principal" id="adopter">Adopter cet ordre</button> <button class="discret" id="apercu">Le voir sur la carte</button></div>` : ''}
    <div id="carte" class="carte-tournee" role="img" aria-label="Carte de la tournée"></div>
    ${d.sans_position ? `<p class="aide">${d.sans_position} adresse${d.sans_position > 1 ? 's' : ''} non placée${d.sans_position > 1 ? 's' : ''} sur la carte : elles sont en fin de liste.</p>` : ''}
    ${d.depart ? '' : '<p class="aide">Le point de départ de l\'agence n\'est pas renseigné : les trajets partent du premier arrêt.</p>'}
    <ol class="arrets">${d.arrets.map((a, i) => `<li class="${a.fait ? 'fait' : ''}">
      <span class="numero">${i + 1}</span>
      <div class="arret">
        <a class="nom" href="#/client/${a.id}">${echapper((a.prenom + ' ' + a.nom).trim())}</a>
        <span class="sous">${echapper([a.adresse, a.commune].filter(Boolean).join(', '))}</span>
        <span class="sous">environ ${a.duree} min${a.charge ? ' · dernière charge ' + CHARGES[a.charge].toLowerCase() : ''}${a.fait ? ' · fait aujourd\'hui' : ''}</span>
        ${a.acces ? `<p class="acces"><strong>Accès</strong> ${echapper(a.acces)}</p>` : ''}
        <div class="actions-ligne">
          <a class="bouton principal" href="#/visite/${a.id}/passage">${a.fait ? 'Refaire' : 'Passage'}</a>
          ${a.adresse || a.lat != null ? `<a class="bouton secondaire" href="${itineraire(a)}" rel="noopener">Y aller</a>` : ''}
          ${a.telephone ? `<a class="bouton secondaire" href="tel:${a.telephone.replace(/[^\d+]/g, '')}">Appeler</a>` : ''}
        </div>
      </div></li>`).join('')}</ol>` : `<p class="vide">Aucun client prévu le ${JOURS[jour].toLowerCase()}.${pilote ? ' Attribuez un technicien et un jour depuis la fiche d\'un client.' : ''}</p>`}`;

  const sel = document.getElementById('tech');
  if (sel) sel.addEventListener('change', () => aller('#/tournee/' + sel.value + '/' + jour));
  if (d.arrets.length && typeof L !== 'undefined' && !horsLigne) dessinerCarte(d);
  const adopter = document.getElementById('adopter');
  if (adopter) adopter.addEventListener('click', async () => {
    adopter.disabled = true;
    const r = await api('tournee_ordonner', { methode: 'POST', corps: { jour, ids: d.propose.ordre, ...(pilote ? { technicien_id: idTech } : {}) } });
    if (r.ok) vueTournee(idTech, jour); else adopter.disabled = false;
  });
  const apercu = document.getElementById('apercu');
  if (apercu) apercu.addEventListener('click', () => { dessinerCarte(d, true); document.getElementById('carte').scrollIntoView({ behavior: 'smooth', block: 'center' }); });
}

function dessinerCarte(d, avecPropose = false) {
  if (CARTE) { CARTE.remove(); CARTE = null; }
  const zone = document.getElementById('carte');
  if (!zone) return;
  CARTE = L.map(zone, { scrollWheelZoom: false });
  L.tileLayer(FOND_IGN, { maxZoom: 19, attribution: '© IGN Géoplateforme' }).addTo(CARTE);
  const places = d.arrets.map((a, i) => ({ ...a, rang: i + 1 })).filter((a) => a.lat != null);
  const points = [];
  if (d.depart) {
    L.marker([d.depart.lat, d.depart.lon], { icon: L.divIcon({ className: 'repere depart', html: 'A', iconSize: [28, 28] }), title: 'Départ : ' + d.depart.nom }).addTo(CARTE);
    points.push([d.depart.lat, d.depart.lon]);
  }
  places.forEach((a) => {
    L.marker([a.lat, a.lon], { icon: L.divIcon({ className: 'repere' + (a.fait ? ' fait' : ''), html: String(a.rang), iconSize: [28, 28] }), title: `${a.rang}. ${a.prenom} ${a.nom}` })
      .addTo(CARTE).bindPopup(`<strong>${echapper((a.prenom + ' ' + a.nom).trim())}</strong><br>${echapper(a.commune)} · environ ${a.duree} min`);
  });
  const trace = (liste) => (d.depart ? [[d.depart.lat, d.depart.lon], ...liste, [d.depart.lat, d.depart.lon]] : liste);
  const actuel = trace(places.map((a) => [a.lat, a.lon]));
  L.polyline(actuel, { color: '#3FA69C', weight: 3, opacity: 0.8 }).addTo(CARTE);
  if (avecPropose) {
    const parId = Object.fromEntries(places.map((a) => [a.id, a]));
    const propose = trace(d.propose.ordre.filter((id) => parId[id]).map((id) => [parId[id].lat, parId[id].lon]));
    L.polyline(propose, { color: '#F28B9B', weight: 4, dashArray: '8 8' }).addTo(CARTE);
  }
  points.push(...places.map((a) => [a.lat, a.lon]));
  if (points.length) CARTE.fitBounds(points, { padding: [30, 30], maxZoom: 15 });
}

/* ---------- La semaine : équilibrer les journées en heures ---------- */

async function vueCharge(agence) {
  vue().innerHTML = '<p class="chargement">Chargement…</p>';
  const [d, l] = await Promise.all([api('charge', { params: agence ? { agence } : {} }), api('longues')]);
  if (!d.ok) return;
  // Une journée pleine fait environ sept heures, trajets compris ; au-delà, on la soulage.
  const niveauJour = (min) => (min === 0 ? 'vide' : min > 450 ? 'lourde' : min > 390 ? 'pleine' : min < 180 ? 'legere' : 'bonne');
  vue().innerHTML = `
    <div class="tete"><h1>La semaine</h1>${APP.utilisateur.role === 'superadmin' ? '<select id="agence" aria-label="Agence"></select>' : ''}</div>
    <p class="aide">Chaque case : nombre d'arrêts et durée estimée de la journée, temps sur place mesuré et trajets compris. Une journée de dix gros chantiers pèse plus qu'une de vingt contrôles : c'est la durée qui compte.</p>
    <div class="table-defile"><table class="charge">
      <thead><tr><th>Technicien</th>${Object.values(d.jours).map((j) => `<th>${j.slice(0, 3)}</th>`).join('')}<th>Semaine</th></tr></thead>
      <tbody>${d.techniciens.map((t) => {
        const total = Object.values(t.jours).reduce((s, j) => s + j.total_min, 0);
        return `<tr><td>${echapper(t.nom)}</td>${Object.entries(t.jours).map(([j, x]) => `<td class="jour-${niveauJour(x.total_min)}"><a href="#/tournee/${t.id}/${j}">${x.visites ? `${x.visites} arrêt${x.visites > 1 ? 's' : ''}<br><strong>${heures(x.total_min)}</strong>` : '–'}</a></td>`).join('')}<td><strong>${heures(total)}</strong></td></tr>`;
      }).join('') || '<tr><td colspan="8">Aucun technicien actif.</td></tr>'}</tbody>
    </table></div>
    <p class="legende-charge"><span class="jour-legere">moins de 3 h</span> <span class="jour-bonne">3 à 6 h 30</span> <span class="jour-pleine">6 h 30 à 7 h 30</span> <span class="jour-lourde">plus de 7 h 30</span></p>
    <h2>Les piscines les plus longues <span class="aide">médiane des passages mesurés sur quatre mois</span></h2>
    ${l.ok && l.clients.length ? `<div class="table-defile"><table><thead><tr><th>Client</th><th>Jour</th><th>Technicien</th><th>Durée</th><th>Visites lourdes</th></tr></thead>
      <tbody>${l.clients.map((c) => `<tr><td><a href="#/client/${c.id}">${echapper(c.nom)}</a> <span class="sous">${echapper(c.commune)}</span></td><td>${echapper(c.jour)}</td><td>${echapper(c.technicien || '–')}</td><td><strong>${c.mediane} min</strong></td><td>${c.lourdes} sur ${c.visites}</td></tr>`).join('')}</tbody></table></div>`
      : '<p class="aide">Il faut au moins deux passages mesurés par client pour les classer.</p>'}`;
  const sel = document.getElementById('agence');
  if (sel) {
    const e = await api('equipe');
    sel.innerHTML = (e.agences || []).map((a) => `<option value="${a.id}"${a.id === d.agence ? ' selected' : ''}>${echapper(a.nom)}</option>`).join('');
    sel.addEventListener('change', () => aller('#/charge/' + sel.value));
  }
}
