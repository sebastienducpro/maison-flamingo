// Maison Flamingo · les écrans d'accès renforcé et de pilotage.
// Ce que montre un écran n'est jamais ce qui protège : chaque donnée affichée ici
// a été filtrée par le serveur selon le rôle. Masquer un bouton n'est qu'un confort.
'use strict';

/* ---------- Second facteur ---------- */

async function vueDoubleFacteur(etape) {
  history.replaceState(null, '', '#/second-facteur');
  const installer = etape === 'totp_installer';
  let prep = null;
  if (installer) {
    prep = await api('totp_preparer', { methode: 'POST', corps: {} });
    if (!prep.ok && prep.erreur === 'deja') return vueDoubleFacteur('totp');
    if (!prep.ok) return vueConnexion();
  }
  let qr = '';
  if (prep && typeof qrcode === 'function') {
    const q = qrcode(0, 'M'); q.addData(prep.uri); q.make();
    qr = q.createSvgTag({ cellSize: 5, margin: 2, scalable: true });
  }
  vue().innerHTML = `
    <section class="carte etroite">
      <h1>${installer ? 'Protéger votre compte' : 'Code de vérification'}</h1>
      ${installer ? `<p>Votre compte voit plusieurs clients : il se protège par un second facteur. Scannez ce code avec l'app Mots de passe de l'iPhone, Google Authenticator ou Microsoft Authenticator.</p>
        <div class="qr">${qr}</div>
        <p class="aide">Sur ce téléphone : <a href="${echapper(prep.uri)}">ajouter directement</a>. Ou saisissez la clé à la main : <code>${echapper(prep.secret)}</code></p>` :
        '<p>Ouvrez votre app d\'authentification et saisissez le code à six chiffres de Maison Flamingo.</p>'}
      <form id="f" autocomplete="off">
        <label>Code à six chiffres<input name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]*" maxlength="7" required></label>
        <button class="principal">${installer ? 'Activer' : 'Valider'}</button>
        <p class="message" id="m" role="status"></p>
      </form>
      <button class="discret" id="sortir">Se déconnecter</button>
    </section>`;
  document.getElementById('f').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const m = document.getElementById('m');
    const d = await api('totp_valider', { methode: 'POST', corps: { code: ev.target.code.value } });
    if (d.ok) { history.replaceState(null, '', '#/'); demarrer(); }
    else m.textContent = d.erreur === 'trop' ? 'Trop d\'essais. Patientez un quart d\'heure.' : 'Code refusé. Vérifiez l\'heure du téléphone et réessayez avec le code suivant.';
  });
  document.getElementById('sortir').addEventListener('click', seDeconnecter);
}

async function seDeconnecter() {
  try { await api('deconnexion', { methode: 'POST', corps: {} }); } catch (e) { /* la session locale s'efface quand même */ }
  APP.utilisateur = null;
  oublierQui();
  document.getElementById('qui').textContent = '';
  dessinerNav();
  aller('#/connexion');
}

/* ---------- Invitation d'un membre de l'équipe ---------- */

async function vueInvitation(t) {
  const d = await api('invitation_lire', { params: { t } }).catch(() => ({ ok: false }));
  if (d.ok && d.genre === 'client') { location.href = 'espace.html#/invitation/' + t; return; }
  if (!d.ok) {
    vue().innerHTML = '<section class="carte etroite"><h1>Lien expiré</h1><p>Ce lien a déjà servi ou date de plus de sept jours. Demandez-en un nouveau à votre responsable.</p><a href="#/connexion">Se connecter</a></section>';
    return;
  }
  const roles = { technicien: 'technicien', chef: 'chef d\'agence', superadmin: 'direction' };
  vue().innerHTML = `
    <section class="carte etroite">
      <h1>Bienvenue ${echapper(d.nom)}</h1>
      <p>Votre accès ${roles[d.role] ? 'de ' + roles[d.role] : ''}${d.agence ? ', agence ' + echapper(d.agence) : ''}. Choisissez votre mot de passe : douze caractères au moins. Une phrase est plus facile à retenir et plus solide.</p>
      <form id="f">
        <input type="email" name="courriel" value="${echapper(d.courriel)}" autocomplete="username" readonly>
        <label>Mot de passe<input name="motdepasse" type="password" minlength="12" required autocomplete="new-password"></label>
        <label>Le même, une seconde fois<input name="confirmation" type="password" required autocomplete="new-password"></label>
        <button class="principal">Valider</button>
        <p class="message" id="m" role="status"></p>
      </form>
    </section>`;
  document.getElementById('f').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const f = ev.target, m = document.getElementById('m');
    if (f.motdepasse.value !== f.confirmation.value) { m.textContent = 'Les deux mots de passe sont différents.'; return; }
    const r = await api('invitation_accepter', { methode: 'POST', corps: { t, motdepasse: f.motdepasse.value } });
    if (r.ok) { m.textContent = 'C\'est prêt. Connectez-vous.'; setTimeout(() => aller('#/connexion'), 1200); }
    else m.textContent = r.erreur === 'court' ? 'Douze caractères au moins.' : 'Ce lien n\'est plus valable.';
  });
}

/* ---------- Mon compte ---------- */

async function vueCompte() {
  const u = APP.utilisateur, roles = { technicien: 'Technicien', chef: 'Chef d\'agence', superadmin: 'Direction' };
  vue().innerHTML = `
    <h1>Mon compte</h1>
    <section class="carte">
      <p><strong>${echapper(u.nom)}</strong> · ${roles[u.role] || ''}${u.agence ? ' · agence ' + echapper(u.agence) : ''}</p>
      <p class="aide">${echapper(u.courriel)} · second facteur ${u.double_facteur ? 'activé' : 'non activé'}</p>
    </section>
    <form id="f" class="carte">
      <h2>Changer de mot de passe</h2>
      <p class="aide">Toutes vos autres sessions seront fermées : c'est le bon geste si vous pensez qu'on a pu utiliser votre compte.</p>
      <label>Mot de passe actuel<input name="ancien" type="password" required autocomplete="current-password"></label>
      <label>Nouveau, douze caractères au moins<input name="nouveau" type="password" minlength="12" required autocomplete="new-password"></label>
      <button class="principal">Changer</button>
      <p class="message" id="m" role="status"></p>
    </form>
    <button class="secondaire" id="sortir">Se déconnecter</button>`;
  document.getElementById('f').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const f = ev.target, m = document.getElementById('m');
    const r = await api('motdepasse', { methode: 'POST', corps: { ancien: f.ancien.value, nouveau: f.nouveau.value } });
    m.textContent = r.ok ? 'Mot de passe changé. Vos autres sessions sont fermées.' : r.erreur === 'court' ? 'Douze caractères au moins.' : 'Le mot de passe actuel est incorrect.';
    if (r.ok) f.reset();
  });
  document.getElementById('sortir').addEventListener('click', seDeconnecter);
}

/* ---------- Tableau de bord ---------- */

function libellePoint(k) {
  if (MESURES[k]) return MESURES[k].libelle;
  for (const f of Object.values(FORMULAIRES)) for (const s of f.sections) for (const c of s.champs) if (c.cle === k && c.libelle) return c.libelle;
  return k;
}

const pourcent = (a, b) => (b ? Math.round((a / b) * 100) + ' %' : '–');
const heures = (min) => (min >= 60 ? Math.floor(min / 60) + ' h ' + String(min % 60).padStart(2, '0') : min + ' min');
const tuileHtml = (valeur, libelle, sous = '', classe = '') => `<div class="kpi ${classe}"><span class="kpi-val">${valeur}</span><span class="kpi-lib">${libelle}</span>${sous ? `<span class="kpi-sous">${sous}</span>` : ''}</div>`;

async function vueTableau(agence) {
  vue().innerHTML = '<p class="chargement">Chargement…</p>';
  const d = await api('tableau', { params: agence ? { agence } : {} });
  if (!d.ok) return;
  const sa = APP.utilisateur.role === 'superadmin';
  const maxPoint = Math.max(1, ...d.points_frequents.map((p) => p.nombre));
  const s = d.sauvegarde;
  vue().innerHTML = `
    <div class="tete"><h1>Tableau de bord</h1>
      ${sa ? `<select id="agence" aria-label="Agence"><option value="">Toutes les agences</option>${d.agences.map((a) => `<option value="${a.id}"${a.id === d.agence ? ' selected' : ''}>${echapper(a.nom)}</option>`).join('')}</select>` : ''}
    </div>
    ${sa ? `<p class="bandeau ${!s || s.en_retard ? 'alerte' : 'calme'}">${!s ? 'Aucune sauvegarde encore faite.' : s.en_retard ? 'La dernière sauvegarde date du ' + echapper(dateFr(s.quand)) + ' : elle est en retard.' : 'Dernière sauvegarde le ' + echapper(dateFr(s.quand)) + ', relue et complète.'} <a href="#/sauvegardes">Voir</a></p>` : ''}
    ${d.clients_sans_tournee ? `<p class="bandeau alerte">${d.clients_sans_tournee} client${d.clients_sans_tournee > 1 ? 's' : ''} sans technicien ou sans jour de passage. <a href="#/clients">Les attribuer</a></p>` : ''}
    <div class="kpis">
      ${tuileHtml(d.statuts.client, 'clients', d.statuts.prospect + ' prospects')}
      ${tuileHtml(d.semaine.faits + ' / ' + d.semaine.prevus, 'passages cette semaine', pourcent(d.semaine.faits, d.semaine.prevus) + ' faits')}
      ${tuileHtml(d.visites_30j, 'visites sur 30 jours', d.diagnostics_30j + ' diagnostics')}
      ${tuileHtml(pourcent(d.envoyes_30j, d.visites_30j), 'rapports envoyés', 'sur 30 jours', d.visites_30j && d.envoyes_30j / d.visites_30j < 0.8 ? 'a-voir' : '')}
      ${tuileHtml(pourcent(d.conversion.devenus_clients, d.conversion.diagnostiques), 'diagnostics devenus clients', d.conversion.devenus_clients + ' sur ' + d.conversion.diagnostiques)}
      ${tuileHtml(d.duree_passage_moyenne ? d.duree_passage_moyenne + ' min' : '–', 'durée moyenne d\'un passage', d.mesurees_30j + ' visites mesurées')}
      ${tuileHtml(pourcent(d.avec_points_30j, d.visites_30j), 'visites avec un point à reprendre')}
      ${tuileHtml(d.demandes_en_attente, 'demandes clients en attente', d.comptes_clients + (d.comptes_clients > 1 ? ' espaces clients ouverts' : ' espace client ouvert'), d.demandes_en_attente ? 'a-voir' : '')}
    </div>
    <h2>Ce qui revient le plus <span class="aide">90 derniers jours : là où sont les travaux à proposer</span></h2>
    ${d.points_frequents.length ? `<ul class="barres">${d.points_frequents.map((p) => `<li><span>${echapper(libellePoint(p.point))}</span><meter min="0" max="${maxPoint}" value="${p.nombre}"></meter><strong>${p.nombre}</strong></li>`).join('')}</ul>` : '<p class="aide">Rien à signaler pour l\'instant.</p>'}
    <h2>${d.detail_par === 'agence' ? 'Par agence' : 'Par technicien'}</h2>
    <div class="table-defile"><table>
      <thead><tr><th>${d.detail_par === 'agence' ? 'Agence' : 'Technicien'}</th><th>Clients</th><th>Visites 30 j</th><th>Semaine</th>${d.detail_par === 'agence' ? '<th>Demandes</th>' : '<th>Durée moy.</th>'}</tr></thead>
      <tbody>${d.detail.map((x) => `<tr>
        <td>${d.detail_par === 'agence' ? `<a href="#/tableau/${x.id}">${echapper(x.nom)}</a>` : `<a href="#/tournee/${x.id}">${echapper(x.nom)}</a>`}</td>
        <td>${x.clients}</td><td>${x.visites_30j}</td><td>${x.faits} / ${x.prevus}</td>
        <td>${d.detail_par === 'agence' ? x.demandes : (x.duree_moyenne ? x.duree_moyenne + ' min' : '–')}</td></tr>`).join('') || '<tr><td colspan="5">Personne pour l\'instant.</td></tr>'}</tbody>
    </table></div>
    <div class="actions-ligne">
      <a class="bouton secondaire" href="api.php?action=export&genre=clients">Exporter les clients</a>
      <a class="bouton secondaire" href="api.php?action=export&genre=visites">Exporter les visites</a>
    </div>`;
  const sel = document.getElementById('agence');
  if (sel) sel.addEventListener('change', () => aller(sel.value ? '#/tableau/' + sel.value : '#/tableau'));
}

/* ---------- Équipe ---------- */

async function vueEquipe() {
  vue().innerHTML = '<p class="chargement">Chargement…</p>';
  const d = await api('equipe');
  if (!d.ok) return;
  const sa = APP.utilisateur.role === 'superadmin';
  const roles = { technicien: 'Technicien', chef: 'Chef d\'agence', superadmin: 'Direction' };
  vue().innerHTML = `
    <h1>Équipe</h1>
    <ul class="liste">${d.membres.map((m) => `<li class="membre${m.actif ? '' : ' inactif'}">
      <div><span class="nom">${echapper(m.nom)}</span> <span class="sous">${roles[m.role]}${m.agence ? ' · ' + echapper(m.agence) : ''}</span></div>
      <span class="sous">${echapper(m.courriel)} · ${!m.actif ? 'désactivé' : m.en_attente ? 'invitation en attente' : m.derniere_connexion ? 'vu le ' + echapper(dateFr(m.derniere_connexion, false)) : 'jamais connecté'}${m.double_facteur ? ' · second facteur' : ''}</span>
      ${m.id !== APP.utilisateur.id && m.role !== 'superadmin' && (sa || m.role === 'technicien') ? `<div class="actions-ligne">
        <button class="secondaire" data-lien="${m.id}">Nouveau lien d'accès</button>
        ${m.double_facteur ? `<button class="discret" data-facteur="${m.id}">Téléphone perdu</button>` : ''}
        <button class="discret" data-actif="${m.id}" data-valeur="${m.actif ? 0 : 1}">${m.actif ? 'Désactiver' : 'Réactiver'}</button>
      </div>` : ''}
    </li>`).join('')}</ul>
    <p class="message" id="m" role="status"></p>
    <form id="f" class="carte">
      <h2>Inviter quelqu'un</h2>
      <p class="aide">Vous recevez un lien à usage unique, valable sept jours, à lui transmettre. Il choisit lui-même son mot de passe : personne d'autre ne le connaît.</p>
      <div class="deux"><label>Prénom et nom<input name="nom" required></label><label>Courriel<input name="courriel" type="email" required></label></div>
      ${sa ? `<div class="deux"><label>Rôle<select name="role"><option value="technicien">Technicien</option><option value="chef">Chef d'agence</option></select></label>
        <label>Agence<select name="agence_id">${d.agences.map((a) => `<option value="${a.id}">${echapper(a.nom)}</option>`).join('')}</select></label></div>` : ''}
      <button class="principal">Créer le lien</button>
    </form>`;
  const m = document.getElementById('m');
  const montrerLien = (lien, nom) => {
    m.innerHTML = `Lien pour ${echapper(nom)}, valable sept jours et une seule fois : <code class="lien">${echapper(lien)}</code> <button class="secondaire" id="copier">Copier</button>`;
    document.getElementById('copier').addEventListener('click', async () => { try { await navigator.clipboard.writeText(lien); } catch (e) { /* le lien reste affiché */ } });
    m.scrollIntoView({ behavior: 'smooth', block: 'center' });
  };
  document.getElementById('f').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const corps = Object.fromEntries(new FormData(ev.target).entries());
    const r = await api('equipe_inviter', { methode: 'POST', corps });
    if (!r.ok) { m.textContent = r.erreur === 'courriel_pris' ? 'Ce courriel a déjà un compte.' : 'Vérifiez le nom et le courriel.'; return; }
    await vueEquipe();
    montrerLien(r.lien, corps.nom);
  });
  vue().querySelectorAll('[data-lien], [data-facteur]').forEach((b) => b.addEventListener('click', async () => {
    const id = +(b.dataset.lien || b.dataset.facteur), facteur = !!b.dataset.facteur;
    const membre = d.membres.find((x) => x.id === id);
    if (!confirm(facteur ? `Remettre à zéro le second facteur de ${membre.nom} ? Son mot de passe actuel cessera de fonctionner jusqu'à ce qu'il utilise le nouveau lien.` : `Créer un nouveau lien pour ${membre.nom} ? Son mot de passe actuel cessera de fonctionner tout de suite.`)) return;
    const r = await api('equipe_lien', { methode: 'POST', corps: { id, double_facteur: facteur } });
    if (r.ok) { await vueEquipe(); montrerLien(r.lien, membre.nom); }
  }));
  vue().querySelectorAll('[data-actif]').forEach((b) => b.addEventListener('click', async () => {
    const actif = b.dataset.valeur === '1';
    if (!actif && !confirm('Désactiver ce compte ? Il est coupé immédiatement, sur tous ses appareils.')) return;
    const r = await api('equipe_modifier', { methode: 'POST', corps: { id: +b.dataset.actif, actif } });
    await vueEquipe();
    if (r.clients_a_reattribuer) m.textContent = r.clients_a_reattribuer + ' client' + (r.clients_a_reattribuer > 1 ? 's sont' : ' est') + ' encore attribué' + (r.clients_a_reattribuer > 1 ? 's' : '') + ' à ce technicien : pensez à les confier à quelqu\'un.';
  }));
}

/* ---------- Agences ---------- */

async function vueAgences() {
  vue().innerHTML = '<p class="chargement">Chargement…</p>';
  const d = await api('agences');
  if (!d.ok) return;
  const formulaire = (a = { id: 0, nom: '', ville: '', adresse: '' }) => `
    <form class="carte agence" data-id="${a.id}">
      <h2>${a.id ? echapper(a.nom) : 'Nouvelle agence'}</h2>
      ${a.id ? `<p class="aide">${a.clients} client${a.clients > 1 ? 's' : ''} · ${a.membres} personne${a.membres > 1 ? 's' : ''}${a.carte ? '' : ' · départ des tournées non placé sur la carte'}</p>` : ''}
      <div class="deux"><label>Nom<input name="nom" value="${echapper(a.nom)}" required></label><label>Ville<input name="ville" value="${echapper(a.ville)}"></label></div>
      <label>Adresse de départ des tournées<input name="adresse" value="${echapper(a.adresse)}"></label>
      <button class="${a.id ? 'secondaire' : 'principal'}">${a.id ? 'Enregistrer' : 'Créer l\'agence'}</button>
      <p class="message" role="status"></p>
    </form>`;
  vue().innerHTML = `<h1>Agences</h1>${d.agences.map(formulaire).join('')}${formulaire()}`;
  vue().querySelectorAll('form.agence').forEach((f) => f.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const corps = Object.fromEntries(new FormData(f).entries());
    corps.id = +f.dataset.id;
    const r = await api('agence_enregistrer', { methode: 'POST', corps });
    const m = f.querySelector('.message');
    if (!r.ok) { m.textContent = r.erreur === 'nom_pris' ? 'Une agence porte déjà ce nom.' : 'Il faut un nom.'; return; }
    m.textContent = corps.adresse && !r.carte ? 'Enregistrée. L\'adresse n\'a pas été trouvée sur la carte.' : 'Enregistrée.';
    if (!corps.id) setTimeout(vueAgences, 800);
  }));
}

/* ---------- Demandes des clients ---------- */

async function vueDemandes(toutes = false) {
  vue().innerHTML = '<p class="chargement">Chargement…</p>';
  const d = await api('demandes', { params: toutes ? { toutes: '1' } : {} });
  if (!d.ok) return;
  vue().innerHTML = `
    <div class="tete"><h1>Demandes des clients</h1><button class="secondaire" id="bascule">${toutes ? 'En attente seulement' : 'Voir aussi les traitées'}</button></div>
    <ul class="liste">${d.demandes.map((x) => `<li class="demande${x.traitee_le ? ' traitee' : ''}">
      <a href="#/client/${x.client_id}" class="nom">${echapper((x.prenom + ' ' + x.nom).trim())}</a> <span class="sous">${echapper(x.commune)} · ${echapper(dateFr(x.cree_le))}</span>
      <p>${echapper(x.texte)}</p>
      ${x.traitee_le ? `<p class="aide">Traitée par ${echapper(x.traitee_par)} le ${echapper(dateFr(x.traitee_le, false))}${x.reponse ? ' : « ' + echapper(x.reponse) + ' »' : ''}</p>` : `
      <form data-id="${x.id}" class="reponse"><label>Réponse visible par le client<textarea name="reponse" rows="2"></textarea></label><button class="secondaire">Marquer comme traitée</button></form>`}
    </li>`).join('') || '<li class="vide">Aucune demande en attente.</li>'}</ul>`;
  document.getElementById('bascule').addEventListener('click', () => vueDemandes(!toutes));
  vue().querySelectorAll('form.reponse').forEach((f) => f.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    await api('demande_traiter', { methode: 'POST', corps: { id: +f.dataset.id, reponse: f.reponse.value } });
    vueDemandes(toutes);
  }));
}

/* ---------- Journal ---------- */

async function vueJournal() {
  vue().innerHTML = '<p class="chargement">Chargement…</p>';
  const d = await api('journal');
  if (!d.ok) return;
  vue().innerHTML = `<h1>Journal <span class="aide">les 300 derniers événements, refus compris</span></h1>
    <div class="table-defile"><table class="journal"><thead><tr><th>Quand</th><th>Qui</th><th>Quoi</th><th>Détail</th></tr></thead>
    <tbody>${d.lignes.map((l) => `<tr class="${/refus|frein/.test(l.action) ? 'refus' : ''}"><td class="date">${echapper(new Date(l.quand).toLocaleString('fr-FR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }))}</td><td>${echapper(l.qui)}</td><td>${echapper(l.action)}</td><td>${echapper(l.detail)}</td></tr>`).join('')}</tbody></table></div>`;
}

/* ---------- Sauvegardes ---------- */

async function vueSauvegardes() {
  vue().innerHTML = '<p class="chargement">Chargement…</p>';
  const d = await api('sauvegardes');
  if (!d.ok) return;
  const octets = (n) => (n > 1048576 ? (n / 1048576).toFixed(1).replace('.', ',') + ' Mo' : Math.max(1, Math.round(n / 1024)) + ' Ko');
  const derniere = d.sauvegardes.find((s) => s.statut === 'ok');
  const retard = !derniere || Date.now() - new Date(derniere.quand) > d.age_max_h * 3600000;
  vue().innerHTML = `
    <h1>Sauvegardes</h1>
    <p class="bandeau ${retard ? 'alerte' : 'calme'}">${derniere ? 'Dernière copie le ' + echapper(dateFr(derniere.quand)) + ' : ' + echapper(derniere.detail) + '.' : 'Aucune copie encore.'}</p>
    <section class="carte">
      <p>Chaque nuit, une copie complète de la base est faite, rouverte et vérifiée. Trente jours sont gardés, puis une par mois pendant un an. Si la tâche de nuit manque, la première connexion du lendemain fait la copie.</p>
      <p><strong>Une fois par semaine, téléchargez la dernière</strong> sur votre ordinateur : une copie qui ne vit que sur l'hébergement disparaît avec lui. Les photos sont dans les sauvegardes de l'hébergement.</p>
      <button class="principal" id="lancer">Faire une copie maintenant</button>
      <p class="message" id="m" role="status"></p>
    </section>
    <div class="table-defile"><table><thead><tr><th>Quand</th><th>Origine</th><th>Taille</th><th>État</th><th></th></tr></thead>
    <tbody>${d.sauvegardes.map((s) => `<tr class="${s.statut === 'ok' ? '' : 'refus'}"><td>${echapper(dateFr(s.quand))}</td><td>${echapper(s.origine)}</td><td>${s.octets ? octets(s.octets) : '–'}</td><td>${s.statut === 'ok' ? 'relue' : 'échec : ' + echapper(s.detail)}</td>
      <td>${s.present ? `<a href="api.php?action=sauvegarde_telecharger&id=${s.id}">Télécharger</a>` : ''}</td></tr>`).join('')}</tbody></table></div>`;
  document.getElementById('lancer').addEventListener('click', async () => {
    const m = document.getElementById('m');
    m.textContent = 'Copie en cours…';
    const r = await api('sauvegarde_lancer', { methode: 'POST', corps: {} });
    m.textContent = r.ok ? 'Copie faite et relue.' : 'La copie a échoué : regardez le journal.';
    setTimeout(vueSauvegardes, 900);
  });
}
