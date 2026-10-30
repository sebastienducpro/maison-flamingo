// Maison Flamingo · les absences, la couverture des tournées et le vivier de remplaçants.
// L'écran ne décide rien : le serveur dit qui a de la marge et qui a passé toutes ses vérifications ;
// le responsable choisit, et le client est prévenu par un message qu'il peut relire.
'use strict';

const STATUTS_VIVIER = {
  a_rencontrer: 'À rencontrer', en_verification: 'Vérifications en cours', pret: 'Prêt', en_mission: 'En mission', ecarte: 'Écarté',
};
const COULEUR_VIVIER = { a_rencontrer: 'orange', en_verification: 'orange', pret: 'vert', en_mission: 'vert', ecarte: 'rouge' };

const ongletsAbsences = (ici) => `<nav class="jours onglets">${[['absences', 'Absences'], ['conges', 'Congés' + (A_FAIRE.conges ? ' (' + A_FAIRE.conges + ')' : '')], ['vivier', 'Vivier']].map(([h, l]) => `<a href="#/${h}"${ici === h ? ' aria-current="page"' : ''}>${l}</a>`).join('')}</nav>`;
const choixAgence = (d) => (d.agences && d.agences.length ? `<select id="agence" aria-label="Agence"><option value="">Toutes les agences</option>${d.agences.map((a) => `<option value="${a.id}"${a.id === d.agence ? ' selected' : ''}>${echapper(a.nom)}</option>`).join('')}</select>` : '');
const dateCourte = (iso) => dateFr(iso + 'T12:00:00', false);
// La date locale, pas celle de Greenwich : à 0 h 30 à Nîmes, toISOString dirait encore hier.
const dansJours = (n) => { const x = new Date(); x.setDate(x.getDate() + n); return x.toLocaleDateString('sv-SE'); };

async function vueAbsences(agence) {
  vue().innerHTML = '<p class="chargement">Chargement…</p>';
  const d = await api('absences', { params: agence ? { agence } : {} });
  if (!d.ok) return;
  const aCouvrir = d.couverture.reduce((s, j) => s + j.a_couvrir, 0);
  vue().innerHTML = `
    <div class="tete"><h1>Absences</h1>${choixAgence(d)}</div>
    ${ongletsAbsences('absences')}
    <p class="aide">Les tournées sont remplies à 85 % au plus : cette marge absorbe la plupart des absences. Un client garde son technicien ; le remplacement ne vaut que pour la date choisie, et le client en est prévenu.</p>
    <h2>À couvrir dans les quinze jours <span class="aide">${aCouvrir ? aCouvrir + ' client' + (aCouvrir > 1 ? 's' : '') + ' sans remplaçant' : 'tout est couvert'}</span></h2>
    ${d.couverture.length ? d.couverture.map((j, n) => `
      <form class="carte couverture" data-n="${n}">
        <div class="reco-tete"><span class="pastille ${j.a_couvrir ? 'rouge' : 'vert'}">${j.a_couvrir ? j.a_couvrir + ' à couvrir' : 'Couvert'}</span>
          <strong>${echapper(dateCourte(j.date))}</strong> <span>${echapper(j.technicien.nom)}, ${echapper(j.motif.toLowerCase())}</span></div>
        <ul class="clients-couverts">${j.clients.map((c) => `<li><label class="case"><input type="checkbox" name="client" value="${c.id}"${c.remplacant ? '' : ' checked'}>
          ${echapper(c.nom)} <span class="sous">${echapper(c.commune || '')}</span></label>
          ${c.remplacant ? `<span class="sous">confié à <strong>${echapper(c.remplacant.nom)}</strong></span> <button type="button" class="discret" data-retirer="${c.id}">Retirer</button>` : ''}</li>`).join('')}</ul>
        ${j.disponibles.length ? `<div class="actions-ligne"><select name="technicien" aria-label="Remplaçant">${j.disponibles.map((t) => `<option value="${t.id}">${echapper(t.nom)}${t.temporaire ? ' (vivier)' : ''} · encore ${heures(t.marge_min)} de marge, ${t.piscines} pisc.</option>`).join('')}</select>
          <button class="principal">Confier les clients cochés</button></div>`
        : '<p class="bandeau alerte">Personne de l\'agence n\'a de marge ce jour-là. Ouvrez une mission à quelqu\'un du <a href="#/vivier">vivier</a>, ou décalez les passages avec les clients.</p>'}
        <p class="message" role="status"></p>
      </form>`).join('') : '<p class="aide">Aucune absence ne touche une tournée dans les quinze jours.</p>'}
    <h2>Absences posées</h2>
    ${d.absences.length ? `<ul class="liste">${d.absences.map((a) => `<li class="membre"><strong>${echapper(a.nom)}</strong> · ${a.du === a.au ? 'le ' + echapper(dateCourte(a.du)) : 'du ' + echapper(dateCourte(a.du)) + ' au ' + echapper(dateCourte(a.au))} · ${echapper(a.motif)}
      <button class="discret" data-absence="${a.id}">Retirer</button></li>`).join('')}</ul>` : '<p class="aide">Aucune absence en cours ou à venir.</p>'}
    <h2>Poser une absence</h2>
    <form id="absence" class="carte">
      <label>Qui<select name="utilisateur_id" required><option value="">–</option>${d.membres.map((m) => `<option value="${m.id}">${echapper(m.nom)}${m.expire_le ? ' (vivier)' : ''}</option>`).join('')}</select></label>
      <div class="deux"><label>Du<input type="date" name="du" required min="${dansJours(-30)}"></label><label>Au<input type="date" name="au" required max="${dansJours(365)}"></label></div>
      <label>Motif<select name="motif">${d.motifs.map((m) => `<option>${echapper(m)}</option>`).join('')}</select></label>
      <p class="aide">Le motif reste entre vous : le client lit seulement qui passe à la place.</p>
      <button class="principal">Enregistrer l'absence</button>
      <p class="message" id="absence-m" role="status"></p>
    </form>`;

  const sel = document.getElementById('agence');
  if (sel) sel.addEventListener('change', () => aller(sel.value ? '#/absences/' + sel.value : '#/absences'));
  const recharger = () => vueAbsences(agence);

  document.querySelectorAll('form.couverture').forEach((f) => {
    const j = d.couverture[+f.dataset.n];
    const m = f.querySelector('.message');
    f.addEventListener('submit', async (e) => {
      e.preventDefault();
      const ids = [...f.querySelectorAll('input[name=client]:checked')].map((i) => +i.value);
      if (!ids.length) { m.textContent = 'Cochez au moins un client.'; return; }
      const t = j.disponibles.find((x) => x.id === +f.technicien.value);
      const r = await api('remplacement_poser', { methode: 'POST', corps: { date: j.date, technicien_id: t.id, client_ids: ids } });
      if (!r.ok) { m.textContent = { date: 'Cette date ne peut plus être couverte.', technicien: 'Cette personne n\'est pas disponible ce jour-là.', clients: 'Cochez au moins un client.' }[r.erreur] || 'Le remplacement n\'a pas été enregistré.'; return; }
      // Le message part du téléphone du responsable : c'est lui que le client connaît.
      const phrase = `Bonjour, ${j.technicien.nom.split(' ')[0]} est absent ${dateCourte(j.date)} : c'est ${t.nom.split(' ')[0]}, de Maison Flamingo, qui passera chez vous à sa place. Rien ne change pour vous.`;
      f.innerHTML = `<p class="bandeau calme">${r.poses} client${r.poses > 1 ? 's' : ''} confié${r.poses > 1 ? 's' : ''} à <strong>${echapper(t.nom)}</strong>. Prévenez-les :</p>
        <p class="note">${echapper(phrase)}</p>
        <div class="actions-ligne"><button type="button" class="secondaire" data-copier>Copier le message</button><button type="button" class="discret" data-fini>Fermer</button></div>`;
      f.querySelector('[data-copier]').addEventListener('click', async (ev) => { try { await navigator.clipboard.writeText(phrase); ev.target.textContent = 'Message copié'; } catch (err) { /* le message reste affiché */ } });
      f.querySelector('[data-fini]').addEventListener('click', recharger);
    });
    f.querySelectorAll('[data-retirer]').forEach((b) => b.addEventListener('click', async () => {
      b.disabled = true;
      const r = await api('remplacement_retirer', { methode: 'POST', corps: { client_id: +b.dataset.retirer, date: j.date } });
      if (r.ok) recharger(); else b.disabled = false;
    }));
  });

  document.querySelectorAll('[data-absence]').forEach((b) => b.addEventListener('click', async () => {
    if (!confirm('Retirer cette absence ? Les remplacements déjà posés restent, retirez-les si besoin.')) return;
    const r = await api('absence_supprimer', { methode: 'POST', corps: { id: +b.dataset.absence } });
    if (r.ok) recharger();
  }));

  const f = document.getElementById('absence');
  f.du.addEventListener('change', () => { if (!f.au.value || f.au.value < f.du.value) f.au.value = f.du.value; f.au.min = f.du.value; });
  f.addEventListener('submit', async (e) => {
    e.preventDefault();
    const m = document.getElementById('absence-m');
    const r = await api('absence_enregistrer', { methode: 'POST', corps: { utilisateur_id: +f.utilisateur_id.value, du: f.du.value, au: f.au.value, motif: f.motif.value } });
    if (r.ok) { m.textContent = 'Absence enregistrée.'; setTimeout(recharger, 500); return; }
    m.textContent = { dates: 'Vérifiez les dates : au plus un mois en arrière, un an en avant, et la fin après le début.', membre: 'Cette personne n\'est pas dans votre équipe.' }[r.erreur] || 'L\'absence n\'a pas été enregistrée.';
  });
}

/* ---------- Le vivier : des remplaçants de confiance, vérifiés avant d'entrer chez quiconque ---------- */

async function vueVivier(agence) {
  vue().innerHTML = '<p class="chargement">Chargement…</p>';
  const d = await api('vivier', { params: agence ? { agence } : {} });
  if (!d.ok) return;
  const prets = d.personnes.filter((p) => p.statut === 'pret' || p.statut === 'en_mission').length;
  const fiche = (p) => `
    <form class="fiche-vivier" data-id="${p ? p.id : ''}">
      <div class="deux"><label>Prénom<input name="prenom" value="${echapper(p ? p.prenom : '')}" autocomplete="off"></label><label>Nom<input name="nom" value="${echapper(p ? p.nom : '')}" autocomplete="off"></label></div>
      <div class="deux"><label>Téléphone<input name="telephone" type="tel" value="${echapper(p ? p.telephone : '')}"></label><label>Courriel<input name="courriel" type="email" value="${echapper(p ? p.courriel : '')}"></label></div>
      ${!p && d.agences.length ? `<label>Agence<select name="agence_id" required><option value="">–</option>${d.agences.map((a) => `<option value="${a.id}"${a.id === d.agence ? ' selected' : ''}>${echapper(a.nom)}</option>`).join('')}</select></label>` : ''}
      <label>Venu par <span class="aide">école, France Travail, un technicien, un client…</span><input name="source" value="${echapper(p ? p.source : '')}"></label>
      <label>Disponibilités <span class="aide">les jours, les périodes, le préavis qu'il lui faut</span><input name="disponibilites" value="${echapper(p ? p.disponibilites : '')}"></label>
      <fieldset class="saisie-groupe"><legend>Vérifications, toutes avant la première mission</legend>
        ${Object.entries(d.controles).map(([k, libelle]) => `<label class="case"><input type="checkbox" name="c_${k}"${p && p.controles[k] ? ' checked' : ''}> ${echapper(libelle)}</label>`).join('')}
      </fieldset>
      <label>Notes<textarea name="notes" rows="3">${echapper(p ? p.notes : '')}</textarea></label>
      ${p ? `<label class="case"><input type="checkbox" name="ecarte"${p.ecarte ? ' checked' : ''}> Écarté : on ne le rappelle plus</label>` : ''}
      <div class="actions-ligne"><button class="principal">${p ? 'Enregistrer' : 'Ajouter au vivier'}</button></div>
      <p class="message" role="status"></p>
      ${p && (p.statut === 'pret' || p.statut === 'en_mission') ? `
      <div class="mission">
        <label>${p.statut === 'en_mission' ? 'Prolonger la mission' : 'Ouvrir une mission'} jusqu'au <span class="aide">${d.mission_max} jours au plus, l'accès s'éteint seul ce soir-là</span>
          <input type="date" name="jusqu_au" min="${dansJours(0)}" max="${dansJours(d.mission_max)}" value="${p.mission_jusquau || ''}"></label>
        <div class="actions-ligne"><button type="button" class="principal clair" data-activer>${p.statut === 'en_mission' ? 'Prolonger' : 'Ouvrir l\'accès'}</button>
          ${p.statut === 'en_mission' ? '<button type="button" class="danger" data-terminer>Terminer la mission maintenant</button>' : ''}</div>
        <p class="message" data-mission role="status"></p>
      </div>` : ''}
    </form>`;
  vue().innerHTML = `
    <div class="tete"><h1>Vivier</h1>${choixAgence(d)}</div>
    ${ongletsAbsences('vivier')}
    <p class="aide">Des personnes de confiance pour absorber une absence ou un pic : étudiants, demandeurs d'emploi, anciens du métier. Personne n'entre chez un client tant qu'une seule vérification manque, et l'accès s'éteint seul à la date prévue. Un remplaçant ne voit que les clients qu'on lui confie, le jour où on les lui confie.</p>
    <p class="bandeau ${prets >= 2 ? 'calme' : 'alerte'}"><strong>${prets} personne${prets > 1 ? 's' : ''} prête${prets > 1 ? 's' : ''}</strong> à remplacer. ${prets >= 2 ? 'Le vivier tient.' : 'Visez deux par agence : une absence ne prévient pas.'}</p>
    <ul class="liste">${d.personnes.map((p) => `<li class="membre${p.ecarte ? ' inactif' : ''}"><details>
      <summary><strong>${echapper((p.prenom + ' ' + p.nom).trim())}</strong> <span class="pastille ${COULEUR_VIVIER[p.statut]}">${STATUTS_VIVIER[p.statut]}${p.statut === 'en_mission' ? ' jusqu\'au ' + echapper(dateCourte(p.mission_jusquau)) : ''}</span>
        <span class="sous">${[p.disponibilites, p.statut === 'en_verification' ? Object.values(p.controles).filter(Boolean).length + ' vérifications sur ' + Object.keys(d.controles).length : ''].filter(Boolean).map(echapper).join(' · ')}</span></summary>
      ${fiche(p)}</details></li>`).join('') || '<li class="aide">Le vivier est vide. Ajoutez une première personne ci-dessous.</li>'}</ul>
    <h2>Ajouter quelqu'un</h2>
    <div class="carte">${fiche(null)}</div>`;

  const sel = document.getElementById('agence');
  if (sel) sel.addEventListener('change', () => aller(sel.value ? '#/vivier/' + sel.value : '#/vivier'));
  const recharger = () => vueVivier(agence);

  document.querySelectorAll('form.fiche-vivier').forEach((f) => {
    const id = +f.dataset.id || 0;
    const m = f.querySelector('.message');
    f.addEventListener('submit', async (e) => {
      e.preventDefault();
      const controles = Object.fromEntries(Object.keys(d.controles).map((k) => [k, f['c_' + k].checked]));
      const corps = { id, prenom: f.prenom.value, nom: f.nom.value, telephone: f.telephone.value, courriel: f.courriel.value, source: f.source.value,
        disponibilites: f.disponibilites.value, notes: f.notes.value, controles, ecarte: f.ecarte ? f.ecarte.checked : false };
      if (f.agence_id) corps.agence_id = +f.agence_id.value;
      const p = d.personnes.find((x) => x.id === id);
      if (p && p.statut === 'en_mission' && (corps.ecarte || Object.values(controles).some((v) => !v)) && !confirm('Une vérification retirée arrête la mission tout de suite : son accès est coupé. Continuer ?')) return;
      const r = await api('vivier_enregistrer', { methode: 'POST', corps });
      if (r.ok) { m.textContent = 'Enregistré.'; setTimeout(recharger, 500); return; }
      m.textContent = { champs: 'Il faut au moins un prénom ou un nom, un courriel valable s\'il est rempli, et l\'agence.' }[r.erreur] || 'La fiche n\'a pas été enregistrée.';
    });
    const activer = f.querySelector('[data-activer]');
    if (activer) activer.addEventListener('click', async () => {
      const mm = f.querySelector('[data-mission]');
      const r = await api('vivier_activer', { methode: 'POST', corps: { id, jusqu_au: f.jusqu_au.value } });
      if (!r.ok) {
        mm.textContent = { controles: 'Toutes les vérifications doivent être cochées et enregistrées avant d\'ouvrir l\'accès.', dates: 'Choisissez une date de fin dans les ' + d.mission_max + ' jours.', courriel: 'Il faut un courriel valable pour envoyer l\'invitation.', courriel_pris: 'Ce courriel a déjà un compte dans l\'outil.' }[r.erreur] || 'L\'accès n\'a pas été ouvert.';
        return;
      }
      if (!r.lien) { mm.textContent = 'Mission prolongée jusqu\'au ' + dateCourte(r.jusqu_au) + '.'; setTimeout(recharger, 800); return; }
      const p = d.personnes.find((x) => x.id === id);
      const tel = (p.telephone || '').replace(/[^\d+]/g, '');
      const sms = tel ? `sms:${tel}${/iPhone|iPad|iPod/.test(navigator.userAgent) ? '&' : '?'}body=${encodeURIComponent('Bonjour ' + p.prenom + ', voici ton accès à l\'outil Maison Flamingo : ' + r.lien)}` : '';
      mm.innerHTML = `Accès ouvert jusqu'au ${echapper(dateCourte(r.jusqu_au))}. Lien de première connexion, valable sept jours et une seule fois : <code class="lien">${echapper(r.lien)}</code>
        ${sms ? `<a class="bouton principal" href="${sms}">L'envoyer par SMS</a>` : ''} <button type="button" class="secondaire" data-copier>Copier le lien</button>`;
      mm.querySelector('[data-copier]').addEventListener('click', async () => { try { await navigator.clipboard.writeText(r.lien); } catch (err) { /* le lien reste affiché */ } });
    });
    const terminer = f.querySelector('[data-terminer]');
    if (terminer) terminer.addEventListener('click', async () => {
      if (!confirm('Terminer la mission ? L\'accès est coupé tout de suite, et les remplacements à venir lui sont retirés.')) return;
      const r = await api('vivier_terminer', { methode: 'POST', corps: { id } });
      if (r.ok) recharger();
    });
  });
}
