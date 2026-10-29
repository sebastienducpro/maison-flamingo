// Maison Flamingo · ce que l'outil terrain et le rapport client partagent.
// Les repères de l'eau sont ici une seule fois : le technicien et le client
// voient la même couleur pour la même mesure.

const MESURES = {
  ph:            { libelle: 'pH', unite: '', pas: 0.1, vert: [7.0, 7.4], orange: [6.8, 7.6] },
  chlore_libre:  { libelle: 'Chlore libre', unite: 'mg/L', pas: 0.1, vert: [1, 3], orange: [0.5, 5] },
  chlore_total:  { libelle: 'Chlore total', unite: 'mg/L', pas: 0.1 },
  tac:           { libelle: 'TAC', unite: 'mg/L', pas: 5, vert: [80, 150], orange: [60, 200] },
  th:            { libelle: 'Dureté (TH)', unite: '°f', pas: 1, vert: [15, 30], orange: [10, 40] },
  stabilisant:   { libelle: 'Stabilisant', unite: 'mg/L', pas: 5, vert: [20, 50], orange: [10, 75] },
  sel:           { libelle: 'Sel', unite: 'g/L', pas: 0.1, vert: [3, 5], orange: [2.5, 6] },
  temperature:   { libelle: 'Eau', unite: '°C', pas: 0.5 },
  pression:      { libelle: 'Pression du filtre', unite: 'bar', pas: 0.1 },
};

// Le chlore combiné (total moins libre) dit si l'eau « sent le chlore » : au-delà de 0,6 elle fatigue.
function chloreCombine(m) {
  const l = parseFloat(m.chlore_libre), t = parseFloat(m.chlore_total);
  return Number.isFinite(l) && Number.isFinite(t) ? Math.max(0, Math.round((t - l) * 10) / 10) : null;
}

function niveau(cle, valeur) {
  const r = MESURES[cle];
  const v = parseFloat(valeur);
  if (!r || !r.vert || !Number.isFinite(v)) return '';
  if (v >= r.vert[0] && v <= r.vert[1]) return 'vert';
  if (v >= r.orange[0] && v <= r.orange[1]) return 'orange';
  return 'rouge';
}

function niveauCombine(c) {
  if (c === null) return '';
  return c <= 0.6 ? 'vert' : c <= 1 ? 'orange' : 'rouge';
}

const MOTS_NIVEAU = { vert: 'dans la norme', orange: 'à surveiller', rouge: 'à corriger' };
const ETATS = { bon: 'En bon état', surveiller: 'À surveiller', prevoir: 'À prévoir', absent: 'Absent' };

function nombreFr(v) {
  const n = parseFloat(v);
  return Number.isFinite(n) ? String(n).replace('.', ',') : '';
}

function dateFr(iso, avecHeure = true) {
  const d = new Date(iso);
  if (isNaN(d)) return '';
  const jour = d.toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' });
  return avecHeure ? jour + ', ' + d.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }).replace(':', ' h ') : jour;
}

function echapper(s) {
  return String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}
