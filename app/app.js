// Maison Flamingo · l'outil terrain.
// Une page, des vues qui se redessinent. Chaque vue remplace entièrement son
// contenu : les écouteurs sont posés sur des éléments neufs, jamais empilés sur
// les anciens (un clic qui part deux fois, c'est une visite enregistrée deux fois).
'use strict';

const APP = { utilisateur: null, client: null };
const vue = () => document.getElementById('vue');

/* ---------- Le serveur ---------- */

async function api(action, { methode = 'GET', corps = null, params = {}, formulaire = null } = {}) {
  const q = new URLSearchParams({ action, ...params });
  const options = { method: methode, credentials: 'same-origin', headers: {} };
  if (methode !== 'GET') options.headers['X-Flamingo'] = '1';
  if (formulaire) options.body = formulaire;
  else if (corps) { options.headers['Content-Type'] = 'application/json'; options.body = JSON.stringify(corps); }
  const r = await fetch('api.php?' + q, options);
  let d = {};
  try { d = await r.json(); } catch (e) { d = {}; }
  d.statut = r.status;
  if (r.status === 401) { APP.utilisateur = null; oublierQui(); aller('#/connexion'); }
  return d;
}

/* ---------- La mémoire du téléphone ---------- */
// Un envoi sans réseau attend ici, et un formulaire en cours y est gardé avec ses
// photos : un téléphone qui se verrouille au bord d'un bassin ne perd rien.

const MEMOIRE = {
  base: null,
  async ouvrir() {
    if (this.base) return this.base;
    this.base = await new Promise((ok, ko) => {
      const r = indexedDB.open('flamingo', 2);
      r.onupgradeneeded = () => {
        const b = r.result;
        if (!b.objectStoreNames.contains('envois')) b.createObjectStore('envois', { keyPath: 'identifiant_local' });
        if (!b.objectStoreNames.contains('brouillons')) b.createObjectStore('brouillons', { keyPath: 'cle' });
        if (!b.objectStoreNames.contains('fiches')) b.createObjectStore('fiches', { keyPath: 'id' });
      };
      r.onsuccess = () => ok(r.result);
      r.onerror = () => ko(r.error);
    });
    return this.base;
  },
  async faire(magasin, mode, geste) {
    const b = await this.ouvrir();
    return new Promise((ok, ko) => {
      const t = b.transaction(magasin, mode);
      const req = geste(t.objectStore(magasin));
      t.oncomplete = () => ok(req ? req.result : undefined);
      t.onerror = () => ko(t.error);
    });
  },
  lire(m, cle) { return this.faire(m, 'readonly', (s) => s.get(cle)); },
  tous(m) { return this.faire(m, 'readonly', (s) => s.getAll()); },
  ecrire(m, v) { return this.faire(m, 'readwrite', (s) => s.put(v)); },
  effacer(m, cle) { return this.faire(m, 'readwrite', (s) => s.delete(cle)); },
};

// Au fond d'un jardin, le réseau tombe. Le téléphone garde donc les fiches clients
// vues en dernier et la personne connectée : on peut ouvrir une fiche et remplir
// une visite sans réseau, l'envoi attend dans la file.
function garderQui(u) { try { localStorage.setItem('flamingo_qui', JSON.stringify(u)); } catch (e) {} }
function oublierQui() { try { localStorage.removeItem('flamingo_qui'); } catch (e) {} }
function quiGarde() { try { return JSON.parse(localStorage.getItem('flamingo_qui') || 'null'); } catch (e) { return null; } }
async function garderFiches(liste) { try { for (const c of liste) await MEMOIRE.ecrire('fiches', c); } catch (e) {} }
async function ficheGardee(id) { try { return (await MEMOIRE.lire('fiches', +id)) || null; } catch (e) { return null; } }
async function fichesGardees() { try { return await MEMOIRE.tous('fiches'); } catch (e) { return []; } }
const BANDEAU_HORS_LIGNE = '<p class="hors-ligne">Pas de réseau. Vous voyez ce que le téléphone a gardé ; les visites saisies partiront toutes seules.</p>';

async function enAttente() {
  try { return (await MEMOIRE.tous('envois')).length; } catch (e) { return 0; }
}

async function majBadge() {
  const n = await enAttente();
  const b = document.getElementById('attente');
  if (!b) return;
  b.hidden = n === 0;
  b.textContent = n + ' en attente';
  b.title = n === 1 ? 'Une visite attend le réseau pour partir' : n + ' visites attendent le réseau pour partir';
}

let envoiEnCours = false;
async function viderFile() {
  if (envoiEnCours || !navigator.onLine || !APP.utilisateur) return {};
  envoiEnCours = true;
  const resultats = {};
  try {
    for (const e of await MEMOIRE.tous('envois')) {
      const fd = new FormData();
      fd.append('visite', JSON.stringify(e.visite));
      e.photos.forEach((p, i) => fd.append('photos[]', p.blob, 'photo-' + (i + 1) + '.jpg'));
      let d;
      try { d = await api('visite_enregistrer', { methode: 'POST', formulaire: fd }); } catch (err) { break; }
      if (d.ok) {
        await MEMOIRE.effacer('envois', e.identifiant_local);
        resultats[e.identifiant_local] = d;
      } else if (d.statut === 401) {
        break;
      }
    }
  } finally {
    envoiEnCours = false;
    majBadge();
  }
  return resultats;
}

window.addEventListener('online', () => viderFile());
setInterval(() => viderFile(), 60000);

/* ---------- Navigation ---------- */

function aller(h) { if (location.hash === h) route(); else location.hash = h; }
window.addEventListener('hashchange', () => route());

async function demarrer() {
  if ('serviceWorker' in navigator) navigator.serviceWorker.register('sw.js').catch(() => {});
  let etat;
  try { etat = await api('etat'); } catch (e) { etat = { ok: false }; }
  if (!etat.ok) {
    const garde = quiGarde();
    if (garde) {
      APP.utilisateur = garde;
      document.getElementById('qui').textContent = garde.nom;
      return route();
    }
    vue().innerHTML = '<section class="carte"><h1>Pas de connexion au serveur</h1><p>Vérifiez le réseau, puis rechargez la page.</p></section>';
    return;
  }
  APP.utilisateur = etat.utilisateur;
  if (APP.utilisateur) garderQui(APP.utilisateur); else oublierQui();
  document.getElementById('qui').textContent = APP.utilisateur ? APP.utilisateur.nom : '';
  if (!etat.installee) return vueInstaller();
  if (!APP.utilisateur) return vueConnexion();
  viderFile();
  route();
}

async function route() {
  const h = location.hash || '#/clients';
  const morceaux = h.slice(2).split('/');
  if (!APP.utilisateur && morceaux[0] !== 'connexion') return vueConnexion();
  majBadge();
  try {
    switch (morceaux[0]) {
      case 'connexion': return await vueConnexion();
      case 'client': return await vueClient(morceaux[1]);
      case 'visite': return await vueVisite(morceaux[1], morceaux[2]);
      case 'envoi': return await vueEnvoi();
      default: return await vueClients();
    }
  } catch (e) {
    vue().innerHTML = `<section class="carte"><h1>Pas de réseau</h1><p>Cette page a besoin du réseau. Les visites déjà saisies sont gardées sur le téléphone et partiront toutes seules.</p><a class="retour" href="#/clients">← Clients</a></section>`;
  }
}

/* ---------- Installation et connexion ---------- */

function vueInstaller() {
  vue().innerHTML = `
    <section class="carte etroite">
      <h1>Première installation</h1>
      <p class="aide">Déposez d'abord, dans le dossier <code>donnees-flamingo-app</code> à côté de <code>public_html</code>, un fichier <code>code-installation.txt</code> contenant une phrase de votre choix, d'au moins huit caractères. Saisissez-la ici : elle prouve que c'est bien vous.</p>
      <form id="f" autocomplete="off">
        <label>Code d'installation<input name="code" required></label>
        <label>Votre prénom<input name="nom" required autocomplete="given-name"></label>
        <label>Votre courriel<input name="courriel" type="email" required autocomplete="username"></label>
        <label>Mot de passe, 12 caractères au moins<input name="motdepasse" type="password" minlength="12" required autocomplete="new-password"></label>
        <label>Le même, une seconde fois<input name="confirmation" type="password" required autocomplete="new-password"></label>
        <button class="principal">Créer le compte</button>
        <p class="message" id="m" role="status"></p>
      </form>
    </section>`;
  document.getElementById('f').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const f = ev.target, m = document.getElementById('m');
    if (f.motdepasse.value !== f.confirmation.value) { m.textContent = 'Les deux mots de passe sont différents.'; return; }
    const d = await api('installer', { methode: 'POST', corps: { code: f.code.value, nom: f.nom.value, courriel: f.courriel.value, motdepasse: f.motdepasse.value } });
    const erreurs = { code: 'Code d\'installation absent ou incorrect.', champs: 'Vérifiez le courriel, le prénom et la longueur du mot de passe.', deja: 'L\'outil est déjà installé.' };
    if (d.ok) { m.textContent = 'Compte créé. Connectez-vous.'; setTimeout(() => location.reload(), 1200); }
    else m.textContent = erreurs[d.erreur] || 'L\'installation n\'a pas abouti (' + (d.detail || d.erreur || d.statut) + ').';
  });
}

function vueConnexion() {
  if (location.hash !== '#/connexion') history.replaceState(null, '', '#/connexion');
  vue().innerHTML = `
    <section class="carte etroite">
      <h1>Connexion</h1>
      <form id="f">
        <label>Courriel<input name="courriel" type="email" required autocomplete="username"></label>
        <label>Mot de passe<input name="motdepasse" type="password" required autocomplete="current-password"></label>
        <button class="principal">Se connecter</button>
        <p class="message" id="m" role="status"></p>
      </form>
    </section>`;
  document.getElementById('f').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const f = ev.target, m = document.getElementById('m');
    const d = await api('connexion', { methode: 'POST', corps: { courriel: f.courriel.value, motdepasse: f.motdepasse.value } });
    if (d.ok) {
      APP.utilisateur = d.utilisateur;
      garderQui(d.utilisateur);
      document.getElementById('qui').textContent = d.utilisateur.nom;
      aller('#/clients');
      viderFile();
    } else {
      m.textContent = d.erreur === 'trop' ? 'Trop d\'essais. Réessayez dans un quart d\'heure.' : 'Courriel ou mot de passe incorrect.';
    }
  });
}

/* ---------- Les clients ---------- */

const STATUTS = { prospect: 'Prospect', client: 'Client', perdu: 'Perdu' };

async function vueClients() {
  vue().innerHTML = '<p class="chargement">Chargement…</p>';
  let d, horsLigne = false;
  try { d = await api('clients'); } catch (e) { d = null; }
  if (d && d.ok) garderFiches(d.clients);
  else if (!d) { horsLigne = true; d = { ok: true, clients: await fichesGardees() }; }
  if (!d.ok) return;
  const tous = d.clients;
  vue().innerHTML = `
    ${horsLigne ? BANDEAU_HORS_LIGNE : ''}
    <div class="tete">
      <h1>Clients</h1>
      <a class="bouton principal" href="#/client/nouveau">Nouveau</a>
    </div>
    <input id="cherche" class="recherche" type="search" placeholder="Rechercher un nom, une commune, un numéro">
    <div class="actions-ligne">
      <button id="importer" class="secondaire">Importer la liste d'attente</button>
      <button id="sortir" class="discret">Se déconnecter</button>
    </div>
    <p class="message" id="m" role="status"></p>
    <ul class="liste" id="liste"></ul>`;
  const dessiner = (filtre) => {
    const f = filtre.trim().toLowerCase();
    const choisis = tous.filter((c) => !f || [c.prenom, c.nom, c.commune, c.telephone].join(' ').toLowerCase().includes(f));
    document.getElementById('liste').innerHTML = choisis.length ? choisis.map((c) => `
      <li><a href="#/client/${c.id}">
        <span class="nom">${echapper((c.prenom + ' ' + c.nom).trim())}</span>
        <span class="statut statut-${c.statut}">${STATUTS[c.statut] || ''}</span>
        <span class="sous">${echapper(c.commune || 'Commune ?')}${c.derniere_visite ? ' · vu ' + echapper(dateFr(c.derniere_visite, false)) : ''}</span>
      </a></li>`).join('') : '<li class="vide">Aucun client pour l\'instant.</li>';
  };
  dessiner('');
  document.getElementById('cherche').addEventListener('input', (e) => dessiner(e.target.value));
  document.getElementById('importer').addEventListener('click', async () => {
    const m = document.getElementById('m');
    m.textContent = 'Import en cours…';
    const r = await api('import_liste', { methode: 'POST', corps: {} });
    if (!r.ok) { m.textContent = 'L\'import n\'a pas abouti.'; return; }
    m.textContent = r.ajoutes + ' inscrit' + (r.ajoutes > 1 ? 's' : '') + ' ajouté' + (r.ajoutes > 1 ? 's' : '') + ', ' + r.deja + ' déjà présent' + (r.deja > 1 ? 's' : '') + '.';
    if (r.ajoutes) setTimeout(() => vueClients(), 900);
  });
  document.getElementById('sortir').addEventListener('click', async () => {
    try { await api('deconnexion', { methode: 'POST', corps: {} }); } catch (e) {}
    APP.utilisateur = null;
    oublierQui();
    document.getElementById('qui').textContent = '';
    aller('#/connexion');
  });
}

async function vueClient(id) {
  let c = { id: 0, prenom: '', nom: '', telephone: '', courriel: '', adresse: '', commune: '', statut: 'prospect', offre: '', notes: '' };
  let visites = [], horsLigne = false;
  if (id !== 'nouveau') {
    vue().innerHTML = '<p class="chargement">Chargement…</p>';
    let d;
    try { d = await api('client', { params: { id } }); } catch (e) { d = null; }
    if (!d) {
      const garde = await ficheGardee(id);
      if (!garde) throw new Error('hors ligne');
      d = { ok: true, client: garde, visites: [] };
      horsLigne = true;
    } else if (d.ok) garderFiches([d.client]);
    if (!d.ok) { vue().innerHTML = '<section class="carte"><p>Client introuvable.</p><a href="#/clients">Retour</a></section>'; return; }
    c = d.client; visites = d.visites;
  }
  APP.client = c;
  const nouveau = !c.id;
  const tel = (c.telephone || '').replace(/[^\d+]/g, '');
  vue().innerHTML = `
    ${horsLigne ? BANDEAU_HORS_LIGNE : ''}
    <a class="retour" href="#/clients">← Clients</a>
    ${nouveau ? '<h1>Nouveau client</h1>' : `
    <div class="tete">
      <div><h1>${echapper((c.prenom + ' ' + c.nom).trim())}</h1><p class="sous">${echapper(c.commune)} · ${STATUTS[c.statut] || ''}</p></div>
    </div>
    <div class="gros-boutons">
      <a class="bouton principal" href="#/visite/${c.id}/diagnostic">Faire un diagnostic</a>
      <a class="bouton principal clair" href="#/visite/${c.id}/passage">Saisir un passage</a>
    </div>
    <div class="actions-ligne">
      ${tel ? `<a class="bouton secondaire" href="tel:${tel}">Appeler</a><a class="bouton secondaire" href="sms:${tel}">SMS</a>` : ''}
      ${c.adresse ? `<a class="bouton secondaire" href="https://maps.apple.com/?q=${encodeURIComponent(c.adresse + ' ' + c.commune)}" rel="noopener">Itinéraire</a>` : ''}
    </div>
    <h2>Visites</h2>
    <ul class="liste">${horsLigne ? '<li class="vide">L\'historique s\'affichera avec du réseau.</li>' : visites.length ? visites.map((v, i) => `
      <li><a href="#/envoi" data-visite="${i}">
        <span class="nom">${v.type === 'diagnostic' ? 'Diagnostic' : 'Passage'}</span>
        <span class="statut ${v.envoye_le ? 'statut-client' : 'statut-perdu'}">${v.envoye_le ? 'Envoyé' : 'Pas envoyé'}</span>
        <span class="sous">${echapper(dateFr(v.quand))} · ${echapper(v.auteur)}</span>
      </a></li>`).join('') : '<li class="vide">Aucune visite.</li>'}</ul>
    <h2>Fiche</h2>`}
    <form id="fiche" class="carte">
      <div class="deux"><label>Prénom<input name="prenom" value="${echapper(c.prenom)}"></label><label>Nom<input name="nom" value="${echapper(c.nom)}"></label></div>
      <div class="deux"><label>Téléphone<input name="telephone" type="tel" value="${echapper(c.telephone)}"></label><label>Courriel<input name="courriel" type="email" value="${echapper(c.courriel)}"></label></div>
      <label>Adresse<input name="adresse" value="${echapper(c.adresse)}"></label>
      <div class="deux"><label>Commune<input name="commune" value="${echapper(c.commune)}"></label>
        <label>Statut<select name="statut">${Object.entries(STATUTS).map(([k, l]) => `<option value="${k}"${k === c.statut ? ' selected' : ''}>${l}</option>`).join('')}</select></label></div>
      <label>Ce qui l'intéresse<input name="offre" value="${echapper(c.offre)}"></label>
      <label>Notes<textarea name="notes" rows="4">${echapper(c.notes)}</textarea></label>
      <button class="principal">${nouveau ? 'Créer la fiche' : 'Enregistrer la fiche'}</button>
      <p class="message" id="m" role="status"></p>
    </form>`;
  vue().querySelectorAll('[data-visite]').forEach((a) => a.addEventListener('click', (ev) => {
    ev.preventDefault();
    const v = visites[+a.dataset.visite];
    sessionStorage.setItem('dernier_envoi', JSON.stringify({ id: v.id, lien: v.lien, type: v.type, deja: v.envoye_le, client: { prenom: c.prenom, telephone: c.telephone, courriel: c.courriel, id: c.id } }));
    aller('#/envoi');
  }));
  document.getElementById('fiche').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const f = ev.target, m = document.getElementById('m');
    const corps = Object.fromEntries(new FormData(f).entries());
    corps.id = c.id;
    let d;
    try { d = await api('client_enregistrer', { methode: 'POST', corps }); } catch (e) { m.textContent = 'Pas de réseau : la fiche n\'est pas enregistrée. Réessayez tout à l\'heure.'; return; }
    if (!d.ok) { m.textContent = d.erreur === 'nom' ? 'Il faut au moins un prénom ou un nom.' : 'L\'enregistrement n\'a pas abouti.'; return; }
    m.textContent = 'Fiche enregistrée.';
    if (nouveau) aller('#/client/' + d.id);
  });
}

/* ---------- Le formulaire de visite ---------- */

async function compresser(fichier) {
  let source = null;
  try { source = await createImageBitmap(fichier, { imageOrientation: 'from-image' }); } catch (e) { source = null; }
  if (!source) {
    source = await new Promise((ok, ko) => { const i = new Image(); i.onload = () => ok(i); i.onerror = ko; i.src = URL.createObjectURL(fichier); });
  }
  const max = 1600, k = Math.min(1, max / Math.max(source.width, source.height));
  const c = document.createElement('canvas');
  c.width = Math.round(source.width * k); c.height = Math.round(source.height * k);
  c.getContext('2d').drawImage(source, 0, 0, c.width, c.height);
  return new Promise((ok) => c.toBlob(ok, 'image/jpeg', 0.8));
}

const LEGENDES = ['', 'L\'eau', 'Le local technique', 'La pompe', 'Le bassin', 'À surveiller', 'La maison', 'Autre'];

function champHtml(ch, etat) {
  const v = etat.valeurs, interne = ch.interne;
  const val = (cle) => (interne ? etat.interne[cle] : v[cle]) ?? '';
  const aide = ch.aide ? `<span class="aide">${echapper(ch.aide)}</span>` : '';
  switch (ch.type) {
    case 'mesure': {
      const r = MESURES[ch.cle];
      const n = niveau(ch.cle, v[ch.cle]);
      let html = `<div class="champ mesure"><label for="m-${ch.cle}">${r.libelle}${r.unite ? ' <span class="unite">' + r.unite + '</span>' : ''}</label>${aide}
        <div class="saisie"><input id="m-${ch.cle}" data-mesure="${ch.cle}" type="number" inputmode="decimal" step="${r.pas}" value="${echapper(v[ch.cle] ?? '')}">
        <span class="pastille ${n}" data-pastille="${ch.cle}">${n ? MOTS_NIVEAU[n] : ''}</span></div></div>`;
      if (ch.cle === 'chlore_total') {
        const cc = chloreCombine(v), nc = niveauCombine(cc);
        html += `<div class="champ calcule" data-combine>Chlore combiné : <strong>${cc === null ? '–' : nombreFr(cc) + ' mg/L'}</strong> <span class="pastille ${nc}">${nc ? MOTS_NIVEAU[nc] : ''}</span></div>`;
      }
      return html;
    }
    case 'etat': {
      const e = etat.etats[ch.cle] || {};
      return `<div class="champ"><span class="libelle">${echapper(ch.libelle)}</span>${aide}
        <div class="puces" data-etat="${ch.cle}">${ETAT_CHOIX.map((k) => `<button type="button" class="puce ${k}${e.etat === k ? ' choisie' : ''}" data-valeur="${k}">${ETATS[k]}</button>`).join('')}</div>
        <input class="note" data-etat-note="${ch.cle}" placeholder="Précision" value="${echapper(e.note || '')}"${e.etat && e.etat !== 'bon' ? '' : ' hidden'}></div>`;
    }
    case 'choix':
    case 'ouinon': {
      const options = ch.type === 'ouinon' ? ['Oui', 'Non'] : ch.options;
      return `<div class="champ"><span class="libelle">${echapper(ch.libelle)}</span>${aide}
        <div class="puces" data-choix="${ch.cle}"${interne ? ' data-interne' : ''}>${options.map((o) => `<button type="button" class="puce${val(ch.cle) === o ? ' choisie' : ''}" data-valeur="${echapper(o)}">${echapper(o)}</button>`).join('')}</div></div>`;
    }
    case 'multi': {
      const choisis = Array.isArray(v[ch.cle]) ? v[ch.cle] : [];
      return `<div class="champ"><span class="libelle">${echapper(ch.libelle)}</span>${aide}
        <div class="puces" data-multi="${ch.cle}">${ch.options.map((o) => `<button type="button" class="puce${choisis.includes(o) ? ' choisie' : ''}" data-valeur="${echapper(o)}">${echapper(o)}</button>`).join('')}</div></div>`;
    }
    case 'nombre':
      return `<div class="champ"><label for="c-${ch.cle}">${echapper(ch.libelle)}${ch.unite ? ' <span class="unite">' + echapper(ch.unite) + '</span>' : ''}</label>${aide}
        <input id="c-${ch.cle}" type="number" inputmode="decimal" step="any" data-champ="${ch.cle}"${interne ? ' data-interne' : ''} value="${echapper(val(ch.cle))}"></div>`;
    case 'texte':
      return `<div class="champ"><label for="c-${ch.cle}">${echapper(ch.libelle)}</label>${aide}
        ${ch.long ? `<textarea id="c-${ch.cle}" rows="3" data-champ="${ch.cle}"${interne ? ' data-interne' : ''}>${echapper(val(ch.cle))}</textarea>` : `<input id="c-${ch.cle}" data-champ="${ch.cle}"${interne ? ' data-interne' : ''} value="${echapper(val(ch.cle))}">`}</div>`;
    case 'volume': {
      const vol = volumeBassin(v);
      return `<div class="champ calcule" data-volume>Volume estimé : <strong>${vol === null ? '–' : vol + ' m³'}</strong></div>`;
    }
    case 'constats':
      return `<div class="champ"><span class="aide">Trois phrases au plus, que le client comprend sans vous.</span>
        ${etat.constats.map((c, i) => `<div class="constat">
          <textarea rows="2" data-constat="${i}" placeholder="Exemple : le joint de la pompe suinte, rien d'urgent">${echapper(c.texte)}</textarea>
          <div class="puces" data-constat-niveau="${i}">${['bon', 'surveiller', 'prevoir'].map((k) => `<button type="button" class="puce ${k}${c.niveau === k ? ' choisie' : ''}" data-valeur="${k}">${ETATS[k]}</button>`).join('')}</div>
        </div>`).join('')}
        <button type="button" class="secondaire" data-suggerer>Proposer à partir des relevés</button></div>`;
    case 'proposition': {
      const p = etat.proposition;
      const calcul = prixPropose(p.formule, v);
      return `<div class="champ"><div class="puces" data-formule>${Object.entries(TARIFS).map(([k, t]) => `<button type="button" class="puce${p.formule === k ? ' choisie' : ''}" data-valeur="${k}">${t.libelle}</button>`).join('')}<button type="button" class="puce${p.formule === 'aucune' ? ' choisie' : ''}" data-valeur="aucune">Hors formule</button></div>
        <p class="calcule" data-prix>${calcul ? `<strong>${calcul.prix} € TTC par mois</strong>, tarif fondateurs${calcul.supplement ? ', dont ' + SUPPLEMENT_GRAND_BASSIN + ' € pour un bassin de plus de ' + SURFACE_STANDARD + ' m²' : ''}` : ''}</p>
        <label>Un mot pour le client<textarea rows="2" data-proposition-note>${echapper(p.note || '')}</textarea></label></div>`;
    }
    default:
      return '';
  }
}

function suggerer(etat, formulaire) {
  const idees = [];
  for (const [cle, r] of Object.entries(MESURES)) {
    const n = niveau(cle, etat.valeurs[cle]);
    if (n === 'rouge' || n === 'orange') {
      const u = r.unite ? ' ' + r.unite : '';
      // Un pH s'écrit toujours avec sa décimale : « 7,0 », jamais « 7 ».
      const borne = (x) => (cle === 'ph' ? x.toFixed(1).replace('.', ',') : nombreFr(x));
      idees.push({ texte: `${r.libelle} à ${nombreFr(etat.valeurs[cle])}${u}, pour une plage conseillée de ${borne(r.vert[0])} à ${borne(r.vert[1])}${u} : ${n === 'rouge' ? 'à corriger' : 'à surveiller'}.`, niveau: n === 'rouge' ? 'prevoir' : 'surveiller' });
    }
  }
  const libelles = {};
  formulaire.sections.forEach((s) => s.champs.forEach((c) => { if (c.type === 'etat') libelles[c.cle] = c.libelle; }));
  for (const [cle, e] of Object.entries(etat.etats)) {
    if (e.etat === 'surveiller' || e.etat === 'prevoir') idees.push({ texte: `${libelles[cle] || cle} ${ETATS[e.etat].toLowerCase()}${e.note ? ' : ' + e.note.charAt(0).toLowerCase() + e.note.slice(1).replace(/\.$/, '') : ''}.`, niveau: e.etat });
  }
  if (!idees.length) idees.push({ texte: 'Tout ce que nous avons regardé est en bon état.', niveau: 'bon' });
  idees.sort((a, b) => (a.niveau === 'prevoir' ? -1 : 0) - (b.niveau === 'prevoir' ? -1 : 0));
  let j = 0;
  etat.constats.forEach((c) => { if (!c.texte.trim() && j < idees.length) { c.texte = idees[j].texte; c.niveau = idees[j].niveau; j++; } });
}

async function vueVisite(idClient, type) {
  const formulaire = FORMULAIRES[type];
  if (!formulaire) return aller('#/client/' + idClient);
  let d;
  try { d = await api('client', { params: { id: idClient } }); } catch (e) { d = null; }
  if (!d) {
    const garde = await ficheGardee(idClient);
    if (!garde) throw new Error('hors ligne');
    d = { ok: true, client: garde };
  }
  if (!d.ok) return;
  const client = d.client;
  const cle = 'brouillon:' + idClient + ':' + type;
  const vide = { valeurs: {}, etats: {}, interne: {}, constats: [{ texte: '', niveau: '' }, { texte: '', niveau: '' }, { texte: '', niveau: '' }], proposition: { formule: '', note: '' }, ouvertes: [] };
  let brouillon = null;
  try { brouillon = await MEMOIRE.lire('brouillons', cle); } catch (e) { brouillon = null; }
  const etat = brouillon ? brouillon.etat : vide;
  const photos = brouillon ? brouillon.photos.map((p) => ({ ...p, url: URL.createObjectURL(p.blob) })) : [];

  let minuteur = null;
  const garder = () => {
    clearTimeout(minuteur);
    minuteur = setTimeout(() => {
      MEMOIRE.ecrire('brouillons', { cle, etat, photos: photos.map((p) => ({ blob: p.blob, legende: p.legende })) }).catch(() => {});
    }, 500);
  };

  const sectionsHtml = formulaire.sections.map((s, i) => {
    const ouverte = !s.optionnel || etat.ouvertes.includes(i);
    const champs = s.champs.map((c) => champHtml({ ...c, interne: s.interne }, etat)).join('');
    return `<section class="bloc${s.interne ? ' interne' : ''}" data-section="${i}">
      <h2>${echapper(s.titre)}${s.interne ? ' <span class="aide">jamais montré au client</span>' : ''}</h2>
      ${s.optionnel ? `<button type="button" class="secondaire" data-ouvrir="${i}"${ouverte ? ' hidden' : ''}>Remplir cette partie</button>` : ''}
      <div class="contenu"${ouverte ? '' : ' hidden'}>${champs}</div>
    </section>`;
  });
  // Les photos se placent juste avant « Ce qu'on a vu » : on photographie ce qu'on va écrire.
  const indexConstats = formulaire.sections.findIndex((s) => s.champs.some((c) => c.type === 'constats'));
  sectionsHtml.splice(indexConstats, 0, `<section class="bloc"><h2>Photos <span class="aide">quatre suffisent</span></h2>
      <div class="photos" id="photos"></div>
      <label class="bouton secondaire fichier">Prendre ou choisir une photo<input id="ajout-photo" type="file" accept="image/*" capture="environment" multiple hidden></label>
    </section>`);

  vue().innerHTML = `
    <a class="retour" href="#/client/${client.id}">← ${echapper((client.prenom + ' ' + client.nom).trim())}</a>
    <h1>${formulaire.titre}${brouillon ? ' <span class="aide">brouillon repris</span>' : ''}</h1>
    <form id="visite" novalidate>${sectionsHtml.join('')}
      <div class="barre-envoi"><button class="principal" id="enregistrer">Enregistrer et préparer l'envoi</button></div>
      <p class="message" id="m" role="status"></p>
    </form>`;

  const form = document.getElementById('visite');
  const dessinerPhotos = () => {
    document.getElementById('photos').innerHTML = photos.map((p, i) => `<figure>
      <img src="${p.url}" alt="">
      <select data-legende="${i}">${LEGENDES.map((l) => `<option value="${echapper(l)}"${l === p.legende ? ' selected' : ''}>${l ? echapper(l) : 'Sans légende'}</option>`).join('')}</select>
      <button type="button" class="discret" data-retirer="${i}">Retirer</button></figure>`).join('');
  };
  dessinerPhotos();

  const rafraichirCalculs = () => {
    MESURES && Object.keys(MESURES).forEach((k) => {
      const p = form.querySelector(`[data-pastille="${k}"]`);
      if (p) { const n = niveau(k, etat.valeurs[k]); p.className = 'pastille ' + n; p.textContent = n ? MOTS_NIVEAU[n] : ''; }
    });
    const zc = form.querySelector('[data-combine]');
    if (zc) { const cc = chloreCombine(etat.valeurs), nc = niveauCombine(cc); zc.innerHTML = `Chlore combiné : <strong>${cc === null ? '–' : nombreFr(cc) + ' mg/L'}</strong> <span class="pastille ${nc}">${nc ? MOTS_NIVEAU[nc] : ''}</span>`; }
    const zv = form.querySelector('[data-volume]');
    if (zv) { const vol = volumeBassin(etat.valeurs); zv.innerHTML = `Volume estimé : <strong>${vol === null ? '–' : vol + ' m³'}</strong>`; }
    const zp = form.querySelector('[data-prix]');
    if (zp) { const c = prixPropose(etat.proposition.formule, etat.valeurs); zp.innerHTML = c ? `<strong>${c.prix} € TTC par mois</strong>, tarif fondateurs${c.supplement ? ', dont ' + SUPPLEMENT_GRAND_BASSIN + ' € pour un bassin de plus de ' + SURFACE_STANDARD + ' m²' : ''}` : ''; }
  };

  form.addEventListener('input', (ev) => {
    const t = ev.target;
    if (t.dataset.mesure) etat.valeurs[t.dataset.mesure] = t.value;
    else if (t.dataset.champ) (t.hasAttribute('data-interne') ? etat.interne : etat.valeurs)[t.dataset.champ] = t.value;
    else if (t.dataset.etatNote) { etat.etats[t.dataset.etatNote] = etat.etats[t.dataset.etatNote] || {}; etat.etats[t.dataset.etatNote].note = t.value; }
    else if (t.dataset.constat !== undefined) etat.constats[+t.dataset.constat].texte = t.value;
    else if (t.hasAttribute('data-proposition-note')) etat.proposition.note = t.value;
    else if (t.dataset.legende !== undefined) photos[+t.dataset.legende].legende = t.value;
    rafraichirCalculs();
    garder();
  });

  form.addEventListener('click', (ev) => {
    const b = ev.target.closest('button');
    if (!b || b.id === 'enregistrer') return;
    const groupe = b.parentElement;
    if (b.dataset.ouvrir !== undefined) {
      const i = +b.dataset.ouvrir;
      etat.ouvertes.push(i);
      b.hidden = true;
      form.querySelector(`[data-section="${i}"] .contenu`).hidden = false;
    } else if (b.dataset.retirer !== undefined) {
      photos.splice(+b.dataset.retirer, 1);
      dessinerPhotos();
    } else if (b.hasAttribute('data-suggerer')) {
      suggerer(etat, formulaire);
      etat.constats.forEach((c, i) => {
        form.querySelector(`[data-constat="${i}"]`).value = c.texte;
        form.querySelectorAll(`[data-constat-niveau="${i}"] .puce`).forEach((p) => p.classList.toggle('choisie', p.dataset.valeur === c.niveau));
      });
    } else if (groupe.dataset.etat) {
      const k = groupe.dataset.etat, e = (etat.etats[k] = etat.etats[k] || {});
      e.etat = e.etat === b.dataset.valeur ? '' : b.dataset.valeur;
      groupe.querySelectorAll('.puce').forEach((p) => p.classList.toggle('choisie', p.dataset.valeur === e.etat));
      form.querySelector(`[data-etat-note="${k}"]`).hidden = !e.etat || e.etat === 'bon';
    } else if (groupe.dataset.choix) {
      const cible = groupe.hasAttribute('data-interne') ? etat.interne : etat.valeurs;
      cible[groupe.dataset.choix] = cible[groupe.dataset.choix] === b.dataset.valeur ? '' : b.dataset.valeur;
      groupe.querySelectorAll('.puce').forEach((p) => p.classList.toggle('choisie', p.dataset.valeur === cible[groupe.dataset.choix]));
    } else if (groupe.dataset.multi) {
      const k = groupe.dataset.multi, liste = Array.isArray(etat.valeurs[k]) ? etat.valeurs[k] : [];
      etat.valeurs[k] = liste.includes(b.dataset.valeur) ? liste.filter((x) => x !== b.dataset.valeur) : [...liste, b.dataset.valeur];
      b.classList.toggle('choisie');
    } else if (groupe.dataset.constatNiveau !== undefined) {
      const c = etat.constats[+groupe.dataset.constatNiveau];
      c.niveau = c.niveau === b.dataset.valeur ? '' : b.dataset.valeur;
      groupe.querySelectorAll('.puce').forEach((p) => p.classList.toggle('choisie', p.dataset.valeur === c.niveau));
    } else if (groupe.hasAttribute('data-formule')) {
      etat.proposition.formule = etat.proposition.formule === b.dataset.valeur ? '' : b.dataset.valeur;
      groupe.querySelectorAll('.puce').forEach((p) => p.classList.toggle('choisie', p.dataset.valeur === etat.proposition.formule));
    }
    rafraichirCalculs();
    garder();
  });

  document.getElementById('ajout-photo').addEventListener('change', async (ev) => {
    const m = document.getElementById('m');
    for (const f of ev.target.files) {
      if (photos.length >= 6) { m.textContent = 'Six photos au plus.'; break; }
      try {
        const blob = await compresser(f);
        photos.push({ blob, url: URL.createObjectURL(blob), legende: '' });
      } catch (e) { m.textContent = 'Cette photo n\'a pas pu être lue.'; }
    }
    ev.target.value = '';
    dessinerPhotos();
    garder();
  });

  form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const bouton = document.getElementById('enregistrer'), m = document.getElementById('m');
    const rien = !Object.values(etat.valeurs).some((x) => x !== '' && x !== undefined) && !photos.length;
    if (rien && !confirm('Rien n\'a été saisi. Enregistrer quand même ?')) return;
    bouton.disabled = true;
    m.textContent = 'Enregistrement…';
    clearTimeout(minuteur);
    const identifiant = (crypto.randomUUID ? crypto.randomUUID() : String(Date.now()) + Math.random().toString(16).slice(2));
    const contenu = { valeurs: etat.valeurs, etats: etat.etats, constats: etat.constats.filter((c) => c.texte.trim()), proposition: etat.proposition, interne: etat.interne };
    if (type === 'diagnostic') {
      const calcul = prixPropose(etat.proposition.formule, etat.valeurs);
      contenu.proposition = { ...etat.proposition, prix: calcul ? calcul.prix : null, supplement: calcul ? calcul.supplement : false };
      contenu.volume = volumeBassin(etat.valeurs);
    }
    const envoi = {
      identifiant_local: identifiant,
      visite: { client_id: client.id, type, identifiant_local: identifiant, quand: new Date().toISOString(), contenu, legendes: photos.map((p) => p.legende) },
      photos: photos.map((p) => ({ blob: p.blob })),
      client: { prenom: client.prenom, telephone: client.telephone, courriel: client.courriel, id: client.id },
    };
    let gardeLocale = true;
    try { await MEMOIRE.ecrire('envois', envoi); } catch (e) { gardeLocale = false; }
    try { await MEMOIRE.effacer('brouillons', cle); } catch (e) {}
    let resultat = null;
    if (gardeLocale) {
      const r = await viderFile();
      resultat = r[identifiant] || null;
    } else {
      // Sans mémoire locale (navigation privée), on tente l'envoi direct.
      const fd = new FormData();
      fd.append('visite', JSON.stringify(envoi.visite));
      envoi.photos.forEach((p, i) => fd.append('photos[]', p.blob, 'photo-' + (i + 1) + '.jpg'));
      const d2 = await api('visite_enregistrer', { methode: 'POST', formulaire: fd }).catch(() => ({ ok: false }));
      if (d2.ok) resultat = d2;
    }
    if (resultat) {
      sessionStorage.setItem('dernier_envoi', JSON.stringify({ id: resultat.id, lien: resultat.lien, type, client: envoi.client }));
      aller('#/envoi');
    } else if (gardeLocale) {
      m.textContent = 'Enregistré sur le téléphone. La visite partira dès que le réseau revient.';
      setTimeout(() => aller('#/client/' + client.id), 1800);
    } else {
      bouton.disabled = false;
      m.textContent = 'L\'envoi n\'a pas abouti et le téléphone ne peut pas le garder. Réessayez avec du réseau.';
    }
  });
}

/* ---------- L'envoi au client ---------- */

function vueEnvoi() {
  let e = null;
  try { e = JSON.parse(sessionStorage.getItem('dernier_envoi') || 'null'); } catch (err) { e = null; }
  if (!e) return aller('#/clients');
  const tel = (e.client.telephone || '').replace(/[^\d+]/g, '');
  const ios = /iPhone|iPad|iPod/.test(navigator.userAgent);
  const phrase = e.type === 'diagnostic'
    ? `Bonjour ${e.client.prenom}, voici le diagnostic de votre piscine : ${e.lien}`
    : `Bonjour ${e.client.prenom}, votre Flamingo est passé. Le rapport est ici : ${e.lien}`;
  vue().innerHTML = `
    <section class="carte">
      <h1>Le rapport est prêt</h1>
      <p>${e.deja ? 'Déjà envoyé le ' + echapper(dateFr(e.deja, false)) + '. Vous pouvez le renvoyer.' : 'Il reste à le faire partir.'} Le client n'a besoin d'aucun compte pour l'ouvrir.</p>
      <div class="gros-boutons">
        ${tel ? `<a class="bouton principal" id="sms" href="sms:${tel}${ios ? '&' : '?'}body=${encodeURIComponent(phrase)}">Envoyer par SMS</a>` : ''}
        ${e.client.courriel ? '<button class="principal clair" id="courriel">Envoyer par courriel</button>' : ''}
        <a class="bouton secondaire" href="${echapper(e.lien)}" target="_blank" rel="noopener">Voir le rapport</a>
        <button class="secondaire" id="copier">Copier le lien</button>
      </div>
      <p class="message" id="m" role="status"></p>
      <a class="retour" href="#/client/${e.client.id}">← Retour à la fiche</a>
    </section>`;
  const m = document.getElementById('m');
  const sms = document.getElementById('sms');
  if (sms) sms.addEventListener('click', () => { api('visite_marquer_envoyee', { methode: 'POST', corps: { id: e.id } }); });
  const courriel = document.getElementById('courriel');
  if (courriel) courriel.addEventListener('click', async () => {
    courriel.disabled = true;
    const d = await api('visite_courriel', { methode: 'POST', corps: { id: e.id } });
    m.textContent = d.ok ? 'Courriel envoyé à ' + e.client.courriel + '.' : 'Le courriel n\'est pas parti. Envoyez plutôt le SMS.';
    courriel.disabled = false;
  });
  document.getElementById('copier').addEventListener('click', async () => {
    try { await navigator.clipboard.writeText(e.lien); m.textContent = 'Lien copié.'; } catch (err) { m.textContent = e.lien; }
  });
}

demarrer();
