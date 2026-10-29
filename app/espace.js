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
  return vueAccueilClient();
}

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
      <p class="aide">Mot de passe oublié ? Appelez-nous, nous vous envoyons un nouveau lien.</p>
    </section>`;
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
    if (c.ok) { history.replaceState(null, '', 'espace.html'); vueAccueilClient(true); } else vueConnexionClient('Votre espace est prêt. Connectez-vous.');
  });
}

async function vueAccueilClient(bienvenue = false) {
  zone().innerHTML = '<p class="attente">Chargement…</p>';
  const d = await appel('espace_accueil');
  if (!d.ok) return vueConnexionClient();
  const c = d.client;
  const ios = /iPhone|iPad|iPod/.test(navigator.userAgent);
  zone().innerHTML = `
    <section class="titre"><p class="surtitre">Mon espace</p><h1>Bonjour ${echapper(c.prenom)}</h1></section>
    ${bienvenue ? `<p class="verdict ok">Votre espace est ouvert.</p><p class="aide">${ios ? 'Pour le retrouver d\'un geste : Partager, puis « Sur l\'écran d\'accueil ».' : 'Pour le retrouver d\'un geste : menu du navigateur, puis « Ajouter à l\'écran d\'accueil ».'}</p>` : ''}
    ${c.jour ? `<div class="prochain"><span class="surtitre">Votre Flamingo passe chaque</span><div class="grand-jour">${echapper(c.jour.toLowerCase())}</div>${c.technicien ? `<p>${echapper(c.technicien)} s'occupe de votre piscine.</p>` : ''}</div>`
      : `<div class="carte-espace"><p>Votre jour de passage sera fixé au démarrage de votre formule.</p></div>`}
    <section class="carte-espace">
      <h2>Mes rapports</h2>
      ${d.visites.length ? `<ul class="historique">${d.visites.map((v) => `<li><a href="${echapper(v.lien)}">
        <span><strong>${v.type === 'diagnostic' ? 'Diagnostic' : 'Passage'}</strong><p class="quand">${echapper(dateFr(v.quand))}</p></span>
        <span class="etiquette ${v.points ? 'voir' : 'ok'}">${v.points ? v.points + ' point' + (v.points > 1 ? 's' : '') + ' à voir' : 'En ordre'}</span>
      </a></li>`).join('')}</ul>` : '<p>Vos rapports apparaîtront ici après chaque passage.</p>'}
    </section>
    <section class="carte-espace">
      <h2>Écrire à mon Flamingo</h2>
      <form id="f"><label>Votre message<textarea name="texte" rows="3" required placeholder="Une question, une absence, un souci sur la piscine…"></textarea></label>
        <button>Envoyer</button><p class="message" id="m" role="status"></p></form>
      ${d.demandes.length ? `<ul class="demandes">${d.demandes.map((x) => `<li>${echapper(x.texte)}<br><span class="quand">${echapper(dateFr(x.cree_le, false))} · ${x.traitee_le ? 'traitée' : 'en attente'}</span>${x.reponse ? `<p class="reponse">« ${echapper(x.reponse)} »</p>` : ''}</li>`).join('')}</ul>` : ''}
    </section>
    <section class="carte-espace">
      <a class="bouton clair" href="tel:+33674859078">Nous appeler</a>
      <p><a href="#/motdepasse" id="lien-mdp">Changer mon mot de passe</a> · <button class="discret" id="sortir">Se déconnecter</button></p>
    </section>`;
  document.getElementById('f').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const m = document.getElementById('m');
    const r = await appel('espace_demande', { methode: 'POST', corps: { texte: ev.target.texte.value } });
    if (r.ok) { m.textContent = 'Message envoyé. Nous revenons vers vous rapidement.'; setTimeout(() => vueAccueilClient(), 1500); }
    else m.textContent = r.erreur === 'trop' ? 'Plusieurs messages attendent déjà une réponse : appelez-nous si c\'est urgent.' : 'Écrivez quelques mots.';
  });
  document.getElementById('lien-mdp').addEventListener('click', (ev) => { ev.preventDefault(); vueMotdepasseClient(); });
  document.getElementById('sortir').addEventListener('click', async () => { await appel('espace_deconnexion', { methode: 'POST', corps: {} }); vueConnexionClient(); });
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
  document.getElementById('retour').addEventListener('click', () => vueAccueilClient());
}

demarrerEspace();
