// Maison Flamingo · l'espace du client.
// Le client voit sa piscine, son jour de passage, ses rapports, et peut nous écrire.
// Son identité vient de sa session côté serveur : cette page ne choisit jamais quel client afficher.
'use strict';

const zone = () => document.getElementById('vue');

async function appel(action, { methode = 'GET', corps = null, params = {} } = {}) {
  const options = { method: methode, credentials: 'same-origin', headers: {} };
  if (methode !== 'GET') options.headers['X-Flamingo'] = '1';
  if (corps) { options.headers['Content-Type'] = 'application/json'; options.body = JSON.stringify(corps); }
  const r = await fetch('api.php?' + new URLSearchParams({ action, ...params }), options);
  let d = {};
  try { d = await r.json(); } catch (e) { d = {}; }
  d.statut = r.status;
  return d;
}

async function demarrerEspace() {
  if ('serviceWorker' in navigator) navigator.serviceWorker.register('sw.js').catch(() => {});
  const h = location.hash;
  if (h.startsWith('#/invitation/')) return vueInvitationClient(h.split('/')[2]);
  let e;
  try { e = await appel('espace_etat'); } catch (err) { zone().innerHTML = '<section class="carte-espace etroite"><h1>Pas de réseau</h1><p>Réessayez dans un instant.</p></section>'; return; }
  if (!e.client) return vueConnexionClient();
  if (h === '#/motdepasse') return vueMotdepasseClient();
  if (h === '#/export') return vueExportClient();
  return vueAccueilClient(false, ['#/avenir', '#/historique'].includes(h) ? h.slice(2) : 'sante');
}
window.addEventListener('hashchange', () => { if (!location.hash.startsWith('#/invitation/')) demarrerEspace(); });

function vueConnexionClient(message = '') {
  history.replaceState(null, '', 'espace.html');
  zone().innerHTML = `
    <section class="carte-espace etroite">
      <h1>Mon espace</h1>
      <p>Vos rapports de passage, votre jour, et un mot à votre Flamingo.</p>
      <form id="f">
        <label>Courriel<input name="courriel" type="email" required autocomplete="username"></label>
        <label>Mot de passe<input name="motdepasse" type="password" required autocomplete="current-password"></label>
        <button>Entrer</button>
        <p class="message" id="m" role="status">${echapper(message)}</p>
      </form>
      <button class="discret" id="oubli">Mot de passe oublié</button>
    </section>`;
  document.getElementById('oubli').addEventListener('click', async () => {
    const f = document.getElementById('f'), m = document.getElementById('m');
    if (!f.courriel.value) { m.textContent = 'Saisissez d\'abord votre courriel.'; return; }
    await appel('motdepasse_oublie', { methode: 'POST', corps: { courriel: f.courriel.value, genre: 'client' } });
    m.textContent = 'Si cette adresse est connue, un lien part dans la minute. Rien reçu ? Appelez-nous.';
  });
  document.getElementById('f').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const f = ev.target, m = document.getElementById('m');
    const r = await appel('espace_connexion', { methode: 'POST', corps: { courriel: f.courriel.value, motdepasse: f.motdepasse.value } });
    if (r.ok) vueAccueilClient();
    else m.textContent = r.erreur === 'trop' ? 'Trop d\'essais. Réessayez dans un quart d\'heure.' : 'Courriel ou mot de passe incorrect.';
  });
}

async function vueInvitationClient(t) {
  const d = await appel('invitation_lire', { params: { t } });
  if (!d.ok || d.genre !== 'client') {
    zone().innerHTML = '<section class="carte-espace etroite"><h1>Lien expiré</h1><p>Ce lien a déjà servi ou date de plus de sept jours. Appelez-nous : nous vous en envoyons un nouveau.</p><a class="bouton" href="espace.html">Se connecter</a></section>';
    return;
  }
  zone().innerHTML = `
    <section class="carte-espace etroite">
      <h1>Bonjour ${echapper(d.prenom)}</h1>
      <p>Choisissez votre mot de passe : douze caractères au moins. Une petite phrase se retient mieux.</p>
      <form id="f">
        <label>Votre courriel<input name="courriel" type="email" value="${echapper(d.courriel)}" required autocomplete="username"></label>
        <label>Mot de passe<input name="motdepasse" type="password" minlength="12" required autocomplete="new-password"></label>
        <label>Le même, une seconde fois<input name="confirmation" type="password" required autocomplete="new-password"></label>
        <button>Ouvrir mon espace</button>
        <p class="message" id="m" role="status"></p>
      </form>
    </section>`;
  document.getElementById('f').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const f = ev.target, m = document.getElementById('m');
    if (f.motdepasse.value !== f.confirmation.value) { m.textContent = 'Les deux mots de passe sont différents.'; return; }
    const r = await appel('invitation_accepter', { methode: 'POST', corps: { t, courriel: f.courriel.value, motdepasse: f.motdepasse.value } });
    if (!r.ok) { m.textContent = { court: 'Douze caractères au moins.', courriel: 'Ce courriel n\'est pas valable.', courriel_pris: 'Ce courriel est déjà utilisé pour un autre espace.' }[r.erreur] || 'Ce lien n\'est plus valable.'; return; }
    const c = await appel('espace_connexion', { methode: 'POST', corps: { courriel: f.courriel.value, motdepasse: f.motdepasse.value } });
    if (c.ok) { history.replaceState(null, '', 'espace.html#/sante'); vueAccueilClient(true); } else vueConnexionClient('Votre espace est prêt. Connectez-vous.');
  });
}

// La saison donne le ton du calendrier : en hiver la piscine se repose et la maison se prépare.
function saison() {
  const m = new Date().getMonth() + 1;
  return m >= 11 || m <= 3
    ? { nom: 'Saison hérisson', texte: 'La piscine se repose, la maison se prépare. Votre Flamingo passe chaque mois jusqu\'au printemps.' }
    : { nom: 'Saison piscine', texte: 'Votre Flamingo passe chaque semaine. Vous vous baignez, on s\'occupe du reste.' };
}
const MOTS_ETAT = { bon: 'En ordre', surveiller: 'À surveiller', prevoir: 'À prévoir' };
const jourLong = (iso) => new Date(iso + 'T12:00:00');

async function vueAccueilClient(bienvenue = false, onglet = 'sante') {
  zone().innerHTML = '<p class="attente">Chargement…</p>';
  const d = await appel('espace_carnet');
  if (!d.ok) return vueConnexionClient();
  const ios = /iPhone|iPad|iPod/.test(navigator.userAgent);
  const compte = { bon: 0, surveiller: 0, prevoir: 0 };
  d.postes.forEach((p) => { compte[p.etat]++; });
  const dernier = d.historique[0];
  const sante = !d.postes.length ? 'Votre carnet se remplit à chaque passage.'
    : compte.prevoir >= 3 || compte.surveiller >= 4 ? 'Quelques points à reprendre ensemble.' : 'Votre maison est en bonne santé.';

  const ecranSante = `
    <section class="tete-carnet">
      <p class="surtitre">Le carnet de la maison${d.client.commune ? ' · ' + echapper(d.client.commune) : ''}</p>
      <h1>${sante}</h1>
      ${d.postes.length ? `<div class="compteurs"><div><strong>${compte.bon}</strong>poste${compte.bon > 1 ? 's' : ''} en ordre</div><div class="s"><strong>${compte.surveiller}</strong>à surveiller</div><div class="p"><strong>${compte.prevoir}</strong>à prévoir</div></div>` : ''}
    </section>
    ${d.client.statut === 'veille' ? '<p class="verdict a-voir">Votre formule est en pause. Votre carnet reste à vous, et nous reprenons quand vous voulez.</p>' : ''}
    ${bienvenue ? `<p class="verdict ok">Votre espace est ouvert.</p><p class="aide">${ios ? 'Pour le retrouver d\'un geste : Partager, puis « Sur l\'écran d\'accueil ».' : 'Pour le retrouver d\'un geste : menu du navigateur, puis « Ajouter à l\'écran d\'accueil ».'}</p>` : ''}
    ${dernier ? `<section class="carte-espace dernier">
      <p class="surtitre">Dernier passage · ${echapper(dateFr(dernier.quand))}</p>
      <p class="citation">« Votre Flamingo est passé.${dernier.resume ? ' ' + echapper(dernier.resume) : dernier.points ? ' ' + dernier.points + (dernier.points > 1 ? ' points à reprendre' : ' point à reprendre') + ', le détail est dans le rapport.' : ' Tout est en ordre.'} »</p>
      <p class="aide">${echapper(dernier.technicien)}${dernier.photos.length ? ' · ' + dernier.photos.length + ' photo' + (dernier.photos.length > 1 ? 's' : '') : ''} · <a href="${echapper(dernier.lien)}">voir le rapport</a></p>
    </section>` : ''}
    ${d.postes.length ? `<h2 class="intertitre">Poste par poste</h2>
    <ul class="postes-client">${d.postes.map((p) => `<li><div><strong>${echapper(p.libelle)}</strong>${p.detail ? `<span>${echapper(p.detail)}</span>` : ''}</div><span class="pastille-etat ${p.etat}">${MOTS_ETAT[p.etat]}</span></li>`).join('')}</ul>` : ''}
    ${d.client.jour ? `<div class="prochain"><span class="surtitre">Votre Flamingo passe chaque</span><div class="grand-jour">${echapper(d.client.jour.toLowerCase())}</div></div>` : ''}
    <section class="carte-espace">
      <h2>Écrire à mon Flamingo</h2>
      <form id="f"><label>Votre message<textarea name="texte" rows="3" required placeholder="Une question, une absence, un souci sur la maison…"></textarea></label>
        <button>Envoyer</button><p class="message" id="m" role="status"></p></form>
    </section>`;

  const parMois = {};
  d.evenements.forEach((e) => {
    const cle = e.quand ? jourLong(e.quand).toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' }) : 'À caler';
    (parMois[cle] = parMois[cle] || []).push(e);
  });
  const s = saison();
  const ecranAvenir = `
    <section class="tete-carnet courte"><p class="surtitre">Le carnet de la maison</p><h1>Ce qui arrive. Vous n'avez rien à retenir.</h1></section>
    <section class="bandeau-saison"><strong>${s.nom}</strong><p>${s.texte}</p></section>
    ${Object.keys(parMois).length ? Object.entries(parMois).map(([mois, liste]) => `<h2 class="intertitre">${echapper(mois.charAt(0).toUpperCase() + mois.slice(1))}</h2>
      <ul class="calendrier">${liste.map((e) => `<li>
        <div class="date">${e.quand ? `<strong>${jourLong(e.quand).getDate()}</strong><span>${jourLong(e.quand).toLocaleDateString('fr-FR', { weekday: 'short' })}</span>` : `<span>${echapper(e.echeance)}</span>`}</div>
        <div><strong>${echapper(e.titre)}</strong>${e.detail ? `<p>${echapper(e.detail)}</p>` : ''}
          ${e.organise === 'nous' ? '<span class="pastille-etat bon">Organisé par nous</span>' : e.organise === 'choisir' ? `<button class="petit-bouton" data-creneau="${e.id}">Choisir un créneau</button>` : '<span class="pastille-etat surveiller">À votre charge</span>'}
        </div></li>`).join('')}</ul>`).join('') : '<section class="carte-espace"><p>Rien de prévu pour l\'instant. Votre Flamingo vous préviendra ici.</p></section>'}
    <p class="message" id="m-creneau" role="status"></p>`;

  const ecranHistorique = `
    <section class="tete-carnet courte"><p class="surtitre">Le carnet de la maison</p><h1>Tout ce qui a été fait, et la preuve.</h1></section>
    <h2 class="intertitre">Derniers passages</h2>
    ${d.historique.length ? `<ul class="passages">${d.historique.map((v) => `<li>
      <a href="${echapper(v.lien)}"><strong>${echapper(dateFr(v.quand, false).replace(/^./, (x) => x.toUpperCase()))}</strong>
      <span class="aide">${v.type === 'diagnostic' ? 'Diagnostic · ' : ''}${echapper(v.technicien)}${v.duree_min ? ' · ' + v.duree_min + ' min' : ''}${v.photos.length ? ' · ' + v.photos.length + ' photo' + (v.photos.length > 1 ? 's' : '') : ''}</span></a>
      ${v.photos.length ? `<div class="vignettes">${v.photos.slice(0, 3).map((p) => `<figure><img src="${p.url}" alt="${echapper(p.legende || 'Photo du passage')}" loading="lazy">${p.legende ? `<figcaption>${echapper(p.legende)}</figcaption>` : ''}</figure>`).join('')}</div>` : ''}
      ${v.resume ? `<p>${echapper(v.resume)}</p>` : ''}
    </li>`).join('')}</ul>` : '<section class="carte-espace"><p>Vos passages apparaîtront ici.</p></section>'}
    <h2 class="intertitre">Les équipements</h2>
    ${d.equipements.length ? `<ul class="postes-client">${d.equipements.map((q) => `<li><div><strong>${echapper(q.nom)}</strong><span>${echapper(q.detail)}</span></div></li>`).join('')}</ul>` : '<p class="aide">L\'inventaire se fait lors du diagnostic.</p>'}
    <h2 class="intertitre">Les documents</h2>
    ${d.documents.length ? `<ul class="postes-client">${d.documents.map((x) => `<li><div><a href="api.php?action=document&id=${x.id}" target="_blank" rel="noopener"><strong>${echapper(x.titre)}</strong></a></div><span class="pastille-etat neutre">${x.type === 'application/pdf' ? 'PDF' : 'Photo'}</span></li>`).join('')}</ul>` : '<p class="aide">Attestations, certificats et factures arriveront ici.</p>'}
    <section class="carte-espace">
      <a class="bouton" href="#/export">Exporter le carnet complet</a>
      <p class="aide">À joindre au dossier de vente : l'acheteur voit une maison entretenue, poste par poste, depuis le premier jour.</p>
    </section>`;

  const ecrans = { sante: ecranSante, avenir: ecranAvenir, historique: ecranHistorique };
  zone().innerHTML = `${ecrans[onglet] || ecranSante}
    <p class="compte-liens"><a href="#/motdepasse">Changer mon mot de passe</a> · <button class="discret" id="sortir">Se déconnecter</button></p>
    <nav class="onglets">${[['sante', 'Santé'], ['avenir', 'À venir'], ['historique', 'Historique']].map(([k, l]) => `<a href="#/${k}"${k === onglet ? ' aria-current="page"' : ''}>${l}</a>`).join('')}</nav>`;
  window.scrollTo(0, 0);

  const f = document.getElementById('f');
  if (f) f.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const m = document.getElementById('m');
    const r = await appel('espace_demande', { methode: 'POST', corps: { texte: ev.target.texte.value } });
    if (r.ok) { m.textContent = 'Message envoyé. Nous revenons vers vous rapidement.'; ev.target.reset(); }
    else m.textContent = r.erreur === 'trop' ? 'Plusieurs messages attendent déjà une réponse : appelez-nous si c\'est urgent.' : 'Écrivez quelques mots.';
  });
  zone().querySelectorAll('[data-creneau]').forEach((b) => b.addEventListener('click', async () => {
    b.disabled = true;
    const r = await appel('espace_creneau', { methode: 'POST', corps: { evenement_id: +b.dataset.creneau } });
    document.getElementById('m-creneau').textContent = r.ok ? 'C\'est noté : votre Flamingo vous propose des créneaux très vite.' : 'La demande n\'est pas partie. Appelez-nous.';
  }));
  document.getElementById('sortir').addEventListener('click', async () => { await appel('espace_deconnexion', { methode: 'POST', corps: {} }); vueConnexionClient(); });
}

// L'export : tout le carnet sur une page, que le navigateur imprime ou enregistre en PDF.
async function vueExportClient() {
  const d = await appel('espace_carnet');
  if (!d.ok) return vueConnexionClient();
  zone().innerHTML = `
    <section class="export">
      <p class="surtitre">Maison Flamingo · carnet de la maison</p>
      <h1>${echapper(d.client.commune || 'Ma maison')}</h1>
      <p class="aide">Édité le ${echapper(dateFr(new Date().toISOString(), false))}</p>
      <button class="ne-pas-imprimer" id="imprimer">Imprimer ou enregistrer en PDF</button>
      <h2>État poste par poste</h2>
      <table>${d.postes.map((p) => `<tr><th>${echapper(p.libelle)}</th><td>${MOTS_ETAT[p.etat]}</td><td>${echapper(p.detail)}</td></tr>`).join('') || '<tr><td>Aucun poste renseigné.</td></tr>'}</table>
      <h2>Équipements</h2>
      <table>${d.equipements.map((q) => `<tr><th>${echapper(q.nom)}</th><td colspan="2">${echapper(q.detail)}</td></tr>`).join('') || '<tr><td>Aucun équipement noté.</td></tr>'}</table>
      <h2>Passages</h2>
      <table>${d.historique.map((v) => `<tr><th>${echapper(dateFr(v.quand, false))}</th><td>${v.type === 'diagnostic' ? 'Diagnostic' : 'Passage'}${v.duree_min ? ', ' + v.duree_min + ' min' : ''}</td><td>${echapper(v.resume)}</td></tr>`).join('') || '<tr><td>Aucun passage.</td></tr>'}</table>
      <h2>Documents</h2>
      <ul>${d.documents.map((x) => `<li>${echapper(x.titre)} (${echapper(dateFr(x.cree_le, false))})</li>`).join('') || '<li>Aucun document.</li>'}</ul>
      <p class="ne-pas-imprimer"><a href="#/historique">← Retour au carnet</a></p>
    </section>`;
  document.getElementById('imprimer').addEventListener('click', () => window.print());
}

function vueMotdepasseClient() {
  zone().innerHTML = `
    <section class="carte-espace etroite">
      <h1>Mot de passe</h1>
      <form id="f">
        <label>Mot de passe actuel<input name="ancien" type="password" required autocomplete="current-password"></label>
        <label>Nouveau, douze caractères au moins<input name="nouveau" type="password" minlength="12" required autocomplete="new-password"></label>
        <button>Changer</button>
        <p class="message" id="m" role="status"></p>
      </form>
      <button class="discret" id="retour">Retour</button>
    </section>`;
  document.getElementById('f').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const f = ev.target, m = document.getElementById('m');
    const r = await appel('espace_motdepasse', { methode: 'POST', corps: { ancien: f.ancien.value, nouveau: f.nouveau.value } });
    m.textContent = r.ok ? 'Mot de passe changé.' : r.erreur === 'court' ? 'Douze caractères au moins.' : 'Le mot de passe actuel est incorrect.';
  });
  document.getElementById('retour').addEventListener('click', () => { location.hash = '#/sante'; });
}

demarrerEspace();
