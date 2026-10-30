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
  if (r.status === 401 && d.erreur === 'double_facteur') demarrer();
  else if (r.status === 401 && !String(action).startsWith('invitation')) { APP.utilisateur = null; oublierQui(); aller('#/connexion'); }
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
  if (location.hash.startsWith('#/invitation/')) return vueInvitation(location.hash.split('/')[2]);
  if (etat.etape) return vueDoubleFacteur(etat.etape);
  APP.utilisateur = etat.utilisateur;
  if (APP.utilisateur) garderQui(APP.utilisateur); else oublierQui();
  document.getElementById('qui').textContent = APP.utilisateur ? APP.utilisateur.nom : '';
  dessinerNav();
  if (!etat.installee) return vueInstaller();
  if (!APP.utilisateur) return vueConnexion();
  viderFile();
  majAFaire();
  route();
}

// Chaque rôle a son point de départ : la tournée pour qui est sur le terrain, le tableau pour qui pilote.
function accueilDuRole() {
  return APP.utilisateur && APP.utilisateur.role !== 'technicien' ? '#/tableau' : '#/tournee';
}

const MENUS = {
  technicien: [['#/tournee', 'Tournée'], ['#/leads', 'Leads'], ['#/clients', 'Clients'], ['#/demandes', 'Demandes'], ['#/compte', 'Compte']],
  chef: [['#/tableau', 'Tableau'], ['#/pilotage', 'Pilotage'], ['#/leads', 'Leads'], ['#/tournee', 'Tournées'], ['#/charge', 'Semaine'], ['#/absences', 'Absences'], ['#/clients', 'Clients'], ['#/secteurs', 'Secteurs'], ['#/equipe', 'Équipe'], ['#/demandes', 'Demandes'], ['#/compte', 'Compte']],
  superadmin: [['#/tableau', 'Tableau'], ['#/pilotage', 'Pilotage'], ['#/leads', 'Leads'], ['#/tournee', 'Tournées'], ['#/charge', 'Semaine'], ['#/absences', 'Absences'], ['#/clients', 'Clients'], ['#/secteurs', 'Secteurs'], ['#/equipe', 'Équipe'], ['#/agences', 'Agences'], ['#/demandes', 'Demandes'], ['#/sauvegardes', 'Sauvegardes'], ['#/journal', 'Journal'], ['#/compte', 'Compte']],
};

// Ce qui attend : un chiffre sur Leads et Demandes, relu toutes les minutes. L'écran rappelle, il ne décide pas.
const A_FAIRE = { leads: 0, leads_retard: 0, demandes: 0 };
async function majAFaire() {
  if (!APP.utilisateur || !navigator.onLine) return;
  try {
    const d = await api('a_faire');
    if (d.ok) { Object.assign(A_FAIRE, d); dessinerNav(); }
  } catch (e) { /* hors ligne : les badges gardent leur dernière valeur */ }
}
setInterval(majAFaire, 60000);

function dessinerNav() {
  const nav = document.getElementById('nav');
  if (!nav) return;
  const menu = APP.utilisateur ? MENUS[APP.utilisateur.role] || [] : [];
  nav.hidden = !menu.length;
  // Le vivier est un onglet des absences : le menu reste court, l'onglet reste allumé.
  const tete = location.hash.slice(2).split('/')[0] || '';
  const ici = '#/' + (tete === 'vivier' ? 'absences' : tete);
  const badge = (h) => (h === '#/leads' && A_FAIRE.leads ? `<span class="badge${A_FAIRE.leads_retard ? ' retard' : ''}">${A_FAIRE.leads}</span>` : h === '#/demandes' && A_FAIRE.demandes ? `<span class="badge">${A_FAIRE.demandes}</span>` : '');
  nav.innerHTML = menu.map(([h, l]) => `<a href="${h}"${h === ici ? ' aria-current="page"' : ''}>${l}${badge(h)}</a>`).join('');
}

async function route() {
  const h = location.hash && location.hash !== '#/' ? location.hash : accueilDuRole();
  const morceaux = h.slice(2).split('/');
  if (morceaux[0] === 'invitation') return vueInvitation(morceaux[1]);
  if (!APP.utilisateur && morceaux[0] !== 'connexion') return vueConnexion();
  majBadge();
  dessinerNav();
  window.scrollTo(0, 0);
  try {
    switch (morceaux[0]) {
      case 'connexion': return await vueConnexion();
      case 'client': return await vueClient(morceaux[1]);
      case 'carnet': return await vueCarnet(morceaux[1]);
      case 'leads': return await vueLeads(morceaux[1] === 'tous');
      case 'secteurs': return await vueSecteurs();
      case 'visite': return await vueVisite(morceaux[1], morceaux[2]);
      case 'envoi': return await vueEnvoi();
      case 'tournee': return await vueTournee(morceaux[1], morceaux[2]);
      case 'charge': return await vueCharge(morceaux[1]);
      case 'tableau': return await vueTableau(morceaux[1]);
      case 'pilotage': return await vuePilotage(morceaux[1]);
      case 'absences': return await vueAbsences(morceaux[1]);
      case 'vivier': return await vueVivier(morceaux[1]);
      case 'equipe': return await vueEquipe();
      case 'agences': return await vueAgences();
      case 'demandes': return await vueDemandes();
      case 'sauvegardes': return await vueSauvegardes();
      case 'journal': return await vueJournal();
      case 'compte': return await vueCompte();
      case 'clients': return await vueClients();
      default: return aller(accueilDuRole());
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
      <button class="discret" id="oubli">Mot de passe oublié</button>
    </section>`;
  document.getElementById('oubli').addEventListener('click', async () => {
    const f = document.getElementById('f'), m = document.getElementById('m');
    if (!f.courriel.value) { m.textContent = 'Saisissez d\'abord votre courriel.'; return; }
    await api('motdepasse_oublie', { methode: 'POST', corps: { courriel: f.courriel.value, genre: 'equipe' } });
    m.textContent = 'Si cette adresse est connue, un lien part dans la minute. Rien reçu ? Demandez un lien à votre responsable.';
  });
  document.getElementById('f').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const f = ev.target, m = document.getElementById('m');
    const d = await api('connexion', { methode: 'POST', corps: { courriel: f.courriel.value, motdepasse: f.motdepasse.value } });
    if (d.ok && d.etape) vueDoubleFacteur(d.etape);
    else if (d.ok) { history.replaceState(null, '', '#/'); demarrer(); }
    else {
      m.textContent = d.erreur === 'trop' ? 'Trop d\'essais. Réessayez dans un quart d\'heure.' : 'Courriel ou mot de passe incorrect.';
    }
  });
}

/* ---------- Les clients ---------- */

const STATUTS = { prospect: 'Prospect', client: 'Actif', veille: 'En veille', desinscrit: 'Désinscrit', perdu: 'Perdu' };
// Les mêmes clés que VENUS_PAR côté serveur : le pilotage compte la part des voisins et des parrains.
const VENUS_PAR = { site: 'Le site', voisin: 'Un voisin client', parrainage: 'Parrainage', porte: 'Porte-à-porte', apporteur: 'Un apporteur', reseau: 'Réseau personnel', autre: 'Autre' };
const AIDE_STATUT = {
  prospect: 'Contact en cours. Pas de passage prévu, mais le diagnostic est possible.',
  client: 'Dans les tournées, espace client ouvert.',
  veille: 'Formule en pause : hors tournées, mais le client garde son espace et son carnet.',
  desinscrit: 'A résilié : hors tournées, espace client fermé, carnet conservé.',
  perdu: 'Prospect qui n\'a pas signé. Sort des leads en attente.',
};

async function vueClients() {
  vue().innerHTML = '<p class="chargement">Chargement…</p>';
  const role = APP.utilisateur.role;
  let horsLigne = false;
  // La recherche se fait au serveur : à deux mille clients, on n'envoie pas tout au téléphone.
  let filtre = '';
  const charger = async (q = '', debut = 0) => {
    try {
      const d = await api('clients', { params: { q, debut, statut: filtre } });
      if (d.ok) garderFiches(d.clients.map(({ derniere_visite, technicien_nom, agence_nom, ...c }) => c));
      return d;
    } catch (e) {
      horsLigne = true;
      const f = (await fichesGardees()).filter((c) => !q || [c.prenom, c.nom, c.commune, c.telephone].join(' ').toLowerCase().includes(q.toLowerCase()));
      return { ok: true, clients: f, total: f.length };
    }
  };
  let d = await charger();
  if (!d.ok) return;
  vue().innerHTML = `
    ${horsLigne ? BANDEAU_HORS_LIGNE : ''}
    <div class="tete">
      <h1>Clients <span class="aide" id="total"></span></h1>
      <a class="bouton principal" href="#/client/nouveau">Nouveau</a>
    </div>
    <input id="cherche" class="recherche" type="search" placeholder="Nom, commune, téléphone">
    <div class="filtres" id="filtres">${[['', 'En vie'], ['client', 'Actifs'], ['prospect', 'Prospects'], ['veille', 'En veille'], ['desinscrit', 'Désinscrits'], ['perdu', 'Perdus']].map(([k, l]) => `<button type="button" class="puce${k === filtre ? ' choisie' : ''}" data-filtre="${k}">${l}</button>`).join('')}</div>
    ${role !== 'technicien' ? `<div class="actions-ligne">
      <button id="importer" class="secondaire">Importer la liste d'attente</button>
      <a class="bouton secondaire" href="api.php?action=export&genre=clients">Exporter</a>
    </div>` : ''}
    <p class="message" id="m" role="status"></p>
    <ul class="liste" id="liste"></ul>
    <button class="secondaire" id="plus" hidden>Voir les suivants</button>`;
  let tous = d.clients;
  const dessiner = () => {
    document.getElementById('total').textContent = d.total ? d.total + (d.total > 1 ? ' fiches' : ' fiche') : '';
    document.getElementById('liste').innerHTML = tous.length ? tous.map((c) => `
      <li><a href="#/client/${c.id}">
        <span class="nom">${echapper((c.prenom + ' ' + c.nom).trim())}</span>
        <span class="statut statut-${c.statut}">${STATUTS[c.statut] || ''}</span>
        <span class="sous">${echapper(c.commune || 'Commune ?')}${c.jour_passage ? ' · ' + JOURS[c.jour_passage] : ''}${role !== 'technicien' && c.technicien_nom ? ' · ' + echapper(c.technicien_nom) : ''}${c.derniere_visite ? ' · vu ' + echapper(dateFr(c.derniere_visite, false)) : ''}</span>
      </a></li>`).join('') : '<li class="vide">Aucun client.</li>';
    document.getElementById('plus').hidden = tous.length >= (d.total || 0);
  };
  dessiner();
  let minuteur = null;
  document.getElementById('cherche').addEventListener('input', (e) => {
    clearTimeout(minuteur);
    minuteur = setTimeout(async () => { d = await charger(e.target.value.trim()); tous = d.clients; dessiner(); }, 300);
  });
  document.getElementById('filtres').addEventListener('click', async (e) => {
    const b = e.target.closest('[data-filtre]');
    if (!b) return;
    filtre = b.dataset.filtre;
    document.querySelectorAll('#filtres .puce').forEach((p) => p.classList.toggle('choisie', p === b));
    d = await charger(document.getElementById('cherche').value.trim()); tous = d.clients; dessiner();
  });
  document.getElementById('plus').addEventListener('click', async () => {
    const suite = await charger(document.getElementById('cherche').value.trim(), tous.length);
    tous = tous.concat(suite.clients); dessiner();
  });
  const imp = document.getElementById('importer');
  if (imp) imp.addEventListener('click', async () => {
    const m = document.getElementById('m');
    m.textContent = 'Import en cours…';
    const r = await api('import_liste', { methode: 'POST', corps: {} });
    if (!r.ok) { m.textContent = 'L\'import n\'a pas abouti.'; return; }
    m.textContent = r.ajoutes + ' inscrit' + (r.ajoutes > 1 ? 's' : '') + ' ajouté' + (r.ajoutes > 1 ? 's' : '') + ', ' + r.deja + ' déjà présent' + (r.deja > 1 ? 's' : '') + '.';
    if (r.ajoutes) setTimeout(() => vueClients(), 900);
  });
}

const JOURS = { 1: 'Lundi', 2: 'Mardi', 3: 'Mercredi', 4: 'Jeudi', 5: 'Vendredi', 6: 'Samedi', 7: 'Dimanche' };
const CHARGES = { leger: 'Légère', normal: 'Normale', lourd: 'Lourde' };

function lienSms(tel, texte) {
  const t = (tel || '').replace(/[^\d+]/g, '');
  return t ? `sms:${t}${/iPhone|iPad|iPod/.test(navigator.userAgent) ? '&' : '?'}body=${encodeURIComponent(texte)}` : '';
}

async function vueClient(id) {
  const role = APP.utilisateur.role, pilote = role !== 'technicien';
  let c = { id: 0, prenom: '', nom: '', telephone: '', courriel: '', adresse: '', commune: '', statut: 'prospect', offre: '', notes: '', acces: '', formule: '', technicien_id: null, jour_passage: null, agence_id: APP.utilisateur.agence_id };
  let d = { visites: [], demandes: [], compte: null, techniciens: [], agences: [] }, horsLigne = false;
  if (id !== 'nouveau') {
    vue().innerHTML = '<p class="chargement">Chargement…</p>';
    let r;
    try { r = await api('client', { params: { id } }); } catch (e) { r = null; }
    if (!r) {
      const garde = await ficheGardee(id);
      if (!garde) throw new Error('hors ligne');
      r = { ok: true, client: garde, visites: [], demandes: [] };
      horsLigne = true;
    } else if (r.ok) garderFiches([r.client]);
    if (!r.ok) { vue().innerHTML = '<section class="carte"><p>Client introuvable.</p><a href="#/clients">Retour</a></section>'; return; }
    c = r.client; d = r;
  } else if (pilote) {
    // Une fiche neuve : la direction choisit l'agence, et la liste des techniciens suit.
    try { const e = await api('equipe'); d.techniciens = e.membres.filter((m) => m.role === 'technicien' && m.actif && !m.remplacant_jusquau && (!c.agence_id || m.agence_id === c.agence_id)); d.agences = e.agences; d.tousTechniciens = e.membres; } catch (err) { /* hors ligne : la fiche s'enregistrera sans planning */ }
  }
  APP.client = c;
  const nouveau = !c.id;
  const tel = (c.telephone || '').replace(/[^\d+]/g, '');
  const visites = d.visites || [];
  const ouvertes = (d.demandes || []).filter((x) => !x.traitee_le);
  vue().innerHTML = `
    ${horsLigne ? BANDEAU_HORS_LIGNE : ''}
    <a class="retour" href="#/clients">← Clients</a>
    ${nouveau ? '<h1>Nouveau client</h1>' : `
    <div class="tete">
      <div><h1>${echapper((c.prenom + ' ' + c.nom).trim())}</h1><p class="sous">${[echapper(c.commune), STATUTS[c.statut], c.jour_passage ? JOURS[c.jour_passage] : '', d.duree_estimee ? 'environ ' + d.duree_estimee + ' min sur place' : '', d.temps_reel ? d.temps_reel + ' min réelles, trajet compris' : ''].filter(Boolean).join(' · ')}</p></div>
    </div>
    ${c.lead_recu_le && !c.lead_contacte_le ? `<div class="bandeau alerte lead-ouvert"><strong>Lead reçu ${echapper(dateFr(c.lead_recu_le))}</strong>${c.origine ? ' · ' + echapper(c.origine) : ''}${c.technicien_id ? '' : ' · <strong>aucun technicien</strong>'}. Appeler ou envoyer un SMS compte comme premier contact. <button class="secondaire" id="contact-fait">Premier contact fait</button></div>`
      : c.lead_contacte_le ? `<p class="aide">Lead du ${echapper(dateFr(c.lead_recu_le, false))}, premier contact ${echapper(dateFr(c.lead_contacte_le))} par ${echapper(c.lead_contact_par)}.</p>` : ''}
    ${['veille', 'desinscrit', 'perdu'].includes(c.statut) ? `<p class="bandeau ${c.statut === 'veille' ? 'calme' : 'alerte'}"><strong>${STATUTS[c.statut]}</strong>${c.statut_depuis ? ' depuis le ' + echapper(dateFr(c.statut_depuis, false)) : ''}${c.statut_motif ? ' · ' + echapper(c.statut_motif) : ''}. ${c.statut === 'veille' ? 'Hors tournées, espace client ouvert.' : c.statut === 'desinscrit' ? 'Hors tournées, espace client fermé.' : 'Sorti des leads.'}</p>` : ''}
    ${c.acces ? `<p class="acces"><strong>Accès</strong> ${echapper(c.acces)}</p>` : ''}
    <div class="gros-boutons">
      <a class="bouton principal" href="#/visite/${c.id}/diagnostic">Faire un diagnostic</a>
      <a class="bouton principal clair" href="#/visite/${c.id}/passage">Saisir un passage</a>
      ${!horsLigne ? `<a class="bouton secondaire" href="#/carnet/${c.id}">Carnet de la maison</a>` : ''}
    </div>
    <div class="actions-ligne">
      ${tel ? `<a class="bouton secondaire" href="tel:${tel}" data-contact>Appeler</a><a class="bouton secondaire" href="sms:${tel}" data-contact>SMS</a>` : ''}
      ${c.adresse ? `<a class="bouton secondaire" href="https://maps.apple.com/?daddr=${encodeURIComponent(c.adresse + ' ' + c.commune)}" rel="noopener">Itinéraire</a>` : ''}
    </div>
    ${ouvertes.length ? `<h2>Demandes du client</h2><ul class="liste">${ouvertes.map((x) => `<li class="demande"><p>${echapper(x.texte)}</p><span class="sous">${echapper(dateFr(x.cree_le))}</span><a class="bouton secondaire" href="#/demandes">Répondre</a></li>`).join('')}</ul>` : ''}
    <h2>Visites</h2>
    <ul class="liste">${horsLigne ? '<li class="vide">L\'historique s\'affichera avec du réseau.</li>' : visites.length ? visites.map((v, i) => `
      <li><a href="#/envoi" data-visite="${i}">
        <span class="nom">${v.type === 'diagnostic' ? 'Diagnostic' : 'Passage'}${v.alertes ? ` <span class="pastille ${v.alertes > 2 ? 'rouge' : 'orange'}">${v.alertes} point${v.alertes > 1 ? 's' : ''}</span>` : ''}</span>
        <span class="statut ${v.envoye_le ? 'statut-client' : 'statut-perdu'}">${v.envoye_le ? 'Envoyé' : 'Pas envoyé'}</span>
        <span class="sous">${echapper(dateFr(v.quand))} · ${echapper(v.auteur)}${v.duree_min ? ' · ' + v.duree_min + ' min' : ''}${v.charge ? ' · charge ' + CHARGES[v.charge].toLowerCase() : ''}</span>
      </a></li>`).join('') : '<li class="vide">Aucune visite.</li>'}</ul>
    ${!horsLigne ? `<h2>Espace client</h2>
    <div class="carte">
      <p>${d.compte ? (d.compte.actif ? `Ouvert pour <strong>${echapper(d.compte.courriel)}</strong>${d.compte.derniere_connexion ? ', dernière visite le ' + echapper(dateFr(d.compte.derniere_connexion, false)) : ', jamais connecté'}.` : 'Fermé.') : 'Pas encore ouvert. Le client reçoit un lien, choisit son mot de passe, et retrouve ses rapports et son jour de passage.'}</p>
      <div class="actions-ligne"><button class="secondaire" id="inviter">${d.compte ? 'Nouveau lien d\'accès' : 'Ouvrir son espace'}</button>${d.compte && d.compte.actif && pilote ? '<button class="discret" id="couper">Fermer l\'accès</button>' : ''}</div>
      <p class="message" id="m-espace" role="status"></p>
    </div>` : ''}
    <h2>Fiche</h2>`}
    <form id="fiche" class="carte">
      <div class="deux"><label>Prénom<input name="prenom" value="${echapper(c.prenom)}"></label><label>Nom<input name="nom" value="${echapper(c.nom)}"></label></div>
      <div class="deux"><label>Téléphone<input name="telephone" type="tel" value="${echapper(c.telephone)}"></label><label>Courriel<input name="courriel" type="email" value="${echapper(c.courriel)}"></label></div>
      <label>Adresse<input name="adresse" value="${echapper(c.adresse)}" autocomplete="street-address"></label>
      <label>Commune<input name="commune" value="${echapper(c.commune)}"></label>
      ${!nouveau && c.adresse && c.lat == null ? '<p class="aide">Cette adresse n\'a pas été trouvée sur la carte : vérifiez le numéro et la commune.</p>' : ''}
      <label>Accès <span class="aide">code du portail, chien, où se garer : visible par l'équipe seulement</span><textarea name="acces" rows="2">${echapper(c.acces)}</textarea></label>
      ${pilote ? `
      <fieldset class="planning"><legend>Planning</legend>
        ${role === 'superadmin' && d.agences && d.agences.length ? `<label>Agence<select name="agence_id">${d.agences.map((a) => `<option value="${a.id}"${a.id === c.agence_id ? ' selected' : ''}>${echapper(a.nom)}</option>`).join('')}</select></label>` : ''}
        <div class="deux">
          <label>Statut<select name="statut">${Object.entries(STATUTS).map(([k, l]) => `<option value="${k}"${k === c.statut ? ' selected' : ''}>${l}</option>`).join('')}</select>
            <span class="aide" id="aide-statut">${AIDE_STATUT[c.statut] || ''}${c.statut_depuis ? ' Depuis le ' + echapper(dateFr(c.statut_depuis, false)) + '.' : ''}</span></label>
          <label>Formule<select name="formule">${['', 'Piscine', 'Piscine + Maison fermée', 'Maison fermée'].map((f) => `<option${f === c.formule ? ' selected' : ''} value="${f}">${f || '–'}</option>`).join('')}</select></label>
        </div>
        <label id="motif-statut"${['veille', 'desinscrit', 'perdu'].includes(c.statut) ? '' : ' hidden'}>Motif <span class="aide">absence, travaux, impayé, déménagement, concurrent, prix…</span><input name="statut_motif" value="${echapper(c.statut_motif || '')}"></label>
        <div class="deux">
          <label>Technicien<select name="technicien_id"><option value="">À attribuer</option>${(d.techniciens || []).map((t) => `<option value="${t.id}"${t.id === c.technicien_id ? ' selected' : ''}>${echapper(t.nom)}</option>`).join('')}</select></label>
          <label>Jour de passage<select name="jour_passage"><option value="">–</option>${Object.entries(JOURS).map(([k, l]) => `<option value="${k}"${+k === c.jour_passage ? ' selected' : ''}>${l}</option>`).join('')}</select></label>
        </div>
      </fieldset>` : ''}
      <div class="deux"><label>Ce qui l'intéresse<input name="offre" value="${echapper(c.offre)}"></label>
        <label>Venu par<select name="venu_par"><option value="">–</option>${Object.entries(VENUS_PAR).map(([k, l]) => `<option value="${k}"${k === (c.venu_par || (c.origine === 'site' ? 'site' : '')) ? ' selected' : ''}>${l}</option>`).join('')}</select></label></div>
      <label>Notes<textarea name="notes" rows="4">${echapper(c.notes)}</textarea></label>
      <button class="principal">${nouveau ? 'Créer la fiche' : 'Enregistrer la fiche'}</button>
      <p class="message" id="m" role="status"></p>
    </form>
    ${!nouveau && pilote && !horsLigne ? `<p class="zone-danger"><button class="discret danger" id="supprimer">Supprimer la fiche, ses visites et ses photos</button></p>` : ''}`;

  const contactFait = async () => {
    if (!c.lead_recu_le || c.lead_contacte_le) return;
    const r = await api('lead_contacte', { methode: 'POST', corps: { id: c.id } }).catch(() => ({ ok: false }));
    if (r.ok) { c.lead_contacte_le = new Date().toISOString(); const b = vue().querySelector('.lead-ouvert'); if (b) b.outerHTML = '<p class="aide">Premier contact fait à l\'instant.</p>'; majAFaire(); }
  };
  vue().querySelectorAll('[data-contact]').forEach((a) => a.addEventListener('click', contactFait));
  const bc = document.getElementById('contact-fait');
  if (bc) bc.addEventListener('click', contactFait);
  vue().querySelectorAll('[data-visite]').forEach((a) => a.addEventListener('click', (ev) => {
    ev.preventDefault();
    const v = visites[+a.dataset.visite];
    sessionStorage.setItem('dernier_envoi', JSON.stringify({ id: v.id, lien: v.lien, type: v.type, deja: v.envoye_le, client: { prenom: c.prenom, telephone: c.telephone, courriel: c.courriel, id: c.id } }));
    aller('#/envoi');
  }));
  const selStatut = vue().querySelector('select[name="statut"]');
  if (selStatut) selStatut.addEventListener('change', () => {
    document.getElementById('aide-statut').textContent = AIDE_STATUT[selStatut.value] || '';
    document.getElementById('motif-statut').hidden = !['veille', 'desinscrit', 'perdu'].includes(selStatut.value);
  });
  const selAgence = vue().querySelector('select[name="agence_id"]');
  if (selAgence && d.tousTechniciens) selAgence.addEventListener('change', () => {
    const a = +selAgence.value, sel = vue().querySelector('select[name="technicien_id"]');
    sel.innerHTML = '<option value="">À attribuer</option>' + d.tousTechniciens.filter((t) => t.role === 'technicien' && t.actif && t.agence_id === a).map((t) => `<option value="${t.id}">${echapper(t.nom)}</option>`).join('');
  });
  const inviter = document.getElementById('inviter');
  if (inviter) inviter.addEventListener('click', async () => {
    const m = document.getElementById('m-espace');
    const r = await api('client_inviter', { methode: 'POST', corps: { id: c.id } });
    if (!r.ok) { m.textContent = r.erreur === 'desinscrit' ? 'Client désinscrit : repassez-le en Actif ou En veille avant de rouvrir son espace.' : 'Le lien n\'a pas pu être créé.'; return; }
    const texte = `Bonjour ${c.prenom}, voici votre accès à votre espace Maison Flamingo : vos rapports et votre jour de passage. Le lien sert une fois, pendant sept jours : ${r.lien}`;
    const sms = lienSms(c.telephone, texte);
    m.innerHTML = `Lien prêt, valable sept jours et une seule fois : <code class="lien">${echapper(r.lien)}</code> ${sms ? `<a class="bouton principal" href="${sms}">L'envoyer par SMS</a>` : ''} <button class="secondaire" id="copier-espace">Copier le lien</button>`;
    document.getElementById('copier-espace').addEventListener('click', async () => { try { await navigator.clipboard.writeText(r.lien); } catch (e) { /* le lien reste affiché */ } });
  });
  const couper = document.getElementById('couper');
  if (couper) couper.addEventListener('click', async () => {
    if (!confirm('Fermer l\'accès de ce client à son espace ?')) return;
    await api('client_compte_couper', { methode: 'POST', corps: { id: c.id } });
    vueClient(c.id);
  });
  const supprimer = document.getElementById('supprimer');
  if (supprimer) supprimer.addEventListener('click', async () => {
    const nomComplet = (c.prenom + ' ' + c.nom).trim();
    if (prompt(`Tout sera effacé pour de bon : la fiche, les visites, les photos, l'espace client.\nTapez le nom « ${nomComplet} » pour confirmer.`) !== nomComplet) return;
    const r = await api('client_supprimer', { methode: 'POST', corps: { id: c.id } });
    if (r.ok) { try { await MEMOIRE.effacer('fiches', c.id); } catch (e) { /* rien à effacer */ } aller('#/clients'); }
  });
  document.getElementById('fiche').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const f = ev.target, m = document.getElementById('m');
    const corps = Object.fromEntries(new FormData(f).entries());
    corps.id = c.id;
    let r;
    try { r = await api('client_enregistrer', { methode: 'POST', corps }); } catch (e) { m.textContent = 'Pas de réseau : la fiche n\'est pas enregistrée. Réessayez tout à l\'heure.'; return; }
    if (!r.ok) { m.textContent = r.erreur === 'nom' ? 'Il faut au moins un prénom ou un nom.' : 'L\'enregistrement n\'a pas abouti.'; return; }
    m.textContent = corps.adresse && !r.carte ? 'Fiche enregistrée. L\'adresse n\'a pas été trouvée sur la carte.' : 'Fiche enregistrée.';
    if (nouveau) aller('#/client/' + r.id);
  });
}

/* ---------- Le formulaire de visite ---------- */

async function compresser(fichier, max = 1600) {
  let source = null;
  try { source = await createImageBitmap(fichier, { imageOrientation: 'from-image' }); } catch (e) { source = null; }
  if (!source) {
    source = await new Promise((ok, ko) => { const i = new Image(); i.onload = () => ok(i); i.onerror = ko; i.src = URL.createObjectURL(fichier); });
  }
  const k = Math.min(1, max / Math.max(source.width, source.height));
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
  const vide = { valeurs: {}, etats: {}, interne: {}, constats: [{ texte: '', niveau: '' }, { texte: '', niveau: '' }, { texte: '', niveau: '' }], proposition: { formule: '', note: '' }, ouvertes: [], debut: Date.now(), duree: '', charge: '', acces: null };
  let brouillon = null;
  try { brouillon = await MEMOIRE.lire('brouillons', cle); } catch (e) { brouillon = null; }
  const etat = brouillon ? { debut: Date.now(), duree: '', charge: '', acces: null, ...brouillon.etat } : vide;
  if (etat.acces === null) etat.acces = client.acces || '';
  // Le temps passé se mesure seul, de l'ouverture du formulaire à l'enregistrement : c'est ce qui
  // permet ensuite d'équilibrer les journées. Le technicien corrige s'il a ouvert trop tôt.
  const minutesMesurees = () => Math.max(1, Math.min(600, Math.round((Date.now() - etat.debut) / 60000)));
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
      <section class="bloc interne"><h2>Fin de visite <span class="aide">jamais montré au client</span></h2>
        <div class="champ"><label for="fin-duree">Temps passé <span class="unite">min</span></label>
          <span class="aide" id="duree-mesuree">Mesuré depuis l'ouverture : ${minutesMesurees()} min. Laissez vide pour garder cette mesure.</span>
          <input id="fin-duree" data-fin="duree" type="number" inputmode="numeric" min="1" max="600" value="${echapper(etat.duree)}"></div>
        <div class="champ"><span class="libelle">Charge de cette visite</span>
          <div class="puces" data-charge>${Object.entries(CHARGES).map(([k, l]) => `<button type="button" class="puce${etat.charge === k ? ' choisie' : ''}" data-valeur="${k}">${l}</button>`).join('')}</div></div>
        <div class="champ"><label for="fin-acces">Accès <span class="aide">code, chien, stationnement : repris à la prochaine visite</span></label>
          <textarea id="fin-acces" data-fin="acces" rows="2">${echapper(etat.acces)}</textarea></div>
      </section>
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
  const horloge = setInterval(() => {
    const z = document.getElementById('duree-mesuree');
    if (!z) { clearInterval(horloge); return; }
    z.textContent = `Mesuré depuis l'ouverture : ${minutesMesurees()} min. Laissez vide pour garder cette mesure.`;
  }, 30000);

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
    else if (t.dataset.fin) etat[t.dataset.fin] = t.value;
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
    } else if (groupe.hasAttribute('data-charge')) {
      etat.charge = etat.charge === b.dataset.valeur ? '' : b.dataset.valeur;
      groupe.querySelectorAll('.puce').forEach((p) => p.classList.toggle('choisie', p.dataset.valeur === etat.charge));
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
      visite: { client_id: client.id, type, identifiant_local: identifiant, quand: new Date().toISOString(), contenu, legendes: photos.map((p) => p.legende),
        duree_min: +etat.duree || minutesMesurees(), charge: etat.charge, acces: etat.acces },
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

// Les écrans de pilotage et de tournée sont dans d'autres fichiers : on démarre quand tous sont chargés.
document.addEventListener('DOMContentLoaded', demarrer);
