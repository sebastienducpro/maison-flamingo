// Maison Flamingo · la fiche du technicien et les demandes de congés.
// Le serveur décide qui voit quelle fiche et qui décide quelle demande ; l'écran ne fait que montrer ce qu'il reçoit.
'use strict';

const ETATS_QUALIF = { expiree: ['rouge', 'Expirée'], bientot: ['orange', 'Expire bientôt'], valide: ['vert', 'Valable'], permanente: ['', 'Sans échéance'] };
const STATUTS_CONGE = { attente: ['orange', 'En attente'], acceptee: ['vert', 'Acceptée'], refusee: ['rouge', 'Refusée'], annulee: ['', 'Annulée'] };
const pastilleDe = ([couleur, mot]) => `<span class="pastille ${couleur}">${mot}</span>`;
const periode = (du, au) => (du === au ? 'le ' + dateCourte(du) : 'du ' + dateCourte(du) + ' au ' + dateCourte(au));
const nbJours = (du, au) => Math.round((new Date(au + 'T12:00:00') - new Date(du + 'T12:00:00')) / 86400000) + 1;

async function vueFiche(id) {
  vue().innerHTML = '<p class="chargement">Chargement…</p>';
  const d = await api('fiche', { params: id ? { id } : {} });
  if (!d.ok) { vue().innerHTML = '<h1>Fiche</h1><p>Cette fiche n\'est pas accessible.</p>'; return; }
  const m = d.membre;
  const roles = { technicien: 'Technicien', chef: 'Chef d\'agence', superadmin: 'Direction' };
  const recharger = () => vueFiche(id);
  const qualif = (q) => `<li class="membre"><div class="reco-tete"><strong>${echapper(q.libelle)}${q.intitule ? ' · ' + echapper(q.intitule) : ''}</strong>${pastilleDe(ETATS_QUALIF[q.etat])}</div>
    <span class="sous">${[q.obtenue_le ? 'obtenue le ' + dateCourte(q.obtenue_le) : '', q.expire_le ? 'valable jusqu\'au ' + dateCourte(q.expire_le) : 'sans date de fin', q.note].filter(Boolean).map(echapper).join(' · ')}</span>
    ${d.peut_qualifier ? `<div class="actions-ligne"><button class="discret" data-modifier-q="${q.id}">Modifier</button><button class="discret" data-retirer-q="${q.id}">Retirer</button></div>` : ''}</li>`;
  const demande = (c) => `<li class="membre"><div class="reco-tete"><strong>${echapper(periode(c.du, c.au))}</strong> <span class="sous">${nbJours(c.du, c.au)} jour${nbJours(c.du, c.au) > 1 ? 's' : ''} · ${echapper(c.motif)}</span>${pastilleDe(STATUTS_CONGE[c.statut])}</div>
    ${c.message ? `<p class="sous">« ${echapper(c.message)} »</p>` : ''}
    ${c.reponse ? `<p class="sous"><strong>Réponse${c.decide_par ? ' de ' + echapper(c.decide_par) : ''}</strong> : ${echapper(c.reponse)}</p>` : ''}
    ${d.soi && (c.statut === 'attente' || (c.statut === 'acceptee' && c.du > dansJours(0))) ? `<button class="discret" data-annuler="${c.id}">Annuler la demande</button>` : ''}</li>`;
  const peutDemander = d.soi && m.role !== 'superadmin';
  vue().innerHTML = `
    ${d.soi ? '<a class="retour" href="#/compte">← Compte</a>' : '<a class="retour" href="#/equipe">← Équipe</a>'}
    <div class="tete"><div><h1>${d.soi ? 'Ma fiche' : echapper(m.nom)}</h1>
      <p class="sous">${[roles[m.role], m.agence ? 'agence ' + m.agence : '', m.entree_le ? 'chez Maison Flamingo depuis le ' + dateCourte(m.entree_le) : '', m.remplacant_jusquau ? 'remplaçant jusqu\'au ' + dateCourte(m.remplacant_jusquau) : ''].filter(Boolean).map(echapper).join(' · ')}</p></div></div>

    <h2>Habilitations et formations</h2>
    ${d.soi ? '<p class="aide">Elles sont posées par votre responsable, qui a vu les justificatifs. Une date de fin approche : l\'outil le lui rappelle un mois avant.</p>' : `<p class="aide">L'outil prévient au pilotage ${d.echeance_proche} jours avant chaque date de fin.</p>`}
    ${d.qualifications.length ? `<ul class="liste">${d.qualifications.map(qualif).join('')}</ul>` : '<p class="aide">Aucune habilitation enregistrée.</p>'}
    ${d.peut_qualifier ? `
    <form id="qualif" class="carte">
      <h3 id="qualif-titre">Ajouter une habilitation ou une formation</h3>
      <input type="hidden" name="id" value="">
      <div class="deux"><label>Laquelle<select name="type">${Object.entries(d.types).map(([k, l]) => `<option value="${k}">${echapper(l)}</option>`).join('')}</select></label>
        <label>Précision <span class="aide">obligatoire pour une formation interne ou « Autre »</span><input name="intitule" maxlength="120"></label></div>
      <div class="deux"><label>Obtenue le<input type="date" name="obtenue_le" max="${dansJours(0)}"></label><label>Valable jusqu'au <span class="aide">vide si sans fin</span><input type="date" name="expire_le"></label></div>
      <label>Note<input name="note" maxlength="300" placeholder="Organisme, numéro, justificatif vu…"></label>
      <button class="principal">Enregistrer</button>
      <p class="message" id="qualif-m" role="status"></p>
    </form>` : ''}

    <h2>Congés</h2>
    ${peutDemander ? `
    <form id="conge" class="carte">
      <p class="aide">Votre responsable répond sous cinq jours, oui ou non avec la raison. Une fois acceptés, vos congés entrent dans les absences et votre tournée est confiée pour ces jours-là.</p>
      <div class="deux"><label>Du<input type="date" name="du" required min="${dansJours(0)}"></label><label>Au<input type="date" name="au" required max="${dansJours(365)}"></label></div>
      <label>Motif<select name="motif">${d.motifs.map((x) => `<option>${echapper(x)}</option>`).join('')}</select></label>
      <label>Un mot pour votre responsable <span class="aide">facultatif</span><input name="message" maxlength="500"></label>
      <button class="principal">Demander</button>
      <p class="message" id="conge-m" role="status"></p>
    </form>` : ''}
    ${d.demandes.length ? `<ul class="liste">${d.demandes.map(demande).join('')}</ul>` : `<p class="aide">Aucune demande de congés sur les douze derniers mois.</p>`}
    ${!d.soi && d.demandes.some((c) => c.statut === 'attente') ? '<p><a href="#/conges">Décider depuis Absences, onglet Congés →</a></p>' : ''}
    ${d.absences.length ? `<p class="sous">Absences à venir : ${d.absences.map((a) => echapper(periode(a.du, a.au) + ' (' + a.motif.toLowerCase() + ')')).join(', ')}.</p>` : ''}

    <h2>Repères</h2>
    <form id="reperes" class="carte">
      ${d.peut_tout_modifier ? `<label>Entrée chez Maison Flamingo<input type="date" name="entree_le" value="${echapper(m.entree_le || '')}"></label>` : ''}
      <p class="aide">Le contact d'urgence ne sert que si quelque chose arrive seul chez un client. Seuls ${d.soi ? 'vous et votre responsable le voient' : 'la personne et ses responsables le voient'}.</p>
      <div class="deux"><label>Contact d'urgence<input name="urgence_nom" maxlength="80" value="${echapper(m.urgence_nom)}" placeholder="Prénom, lien"></label>
        <label>Son téléphone<input name="urgence_tel" type="tel" maxlength="30" value="${echapper(m.urgence_tel)}"></label></div>
      <div class="trois-champs"><label>Taille haut<input name="taille_haut" maxlength="10" value="${echapper(m.taille_haut)}" placeholder="M"></label>
        <label>Taille bas<input name="taille_bas" maxlength="10" value="${echapper(m.taille_bas)}" placeholder="42"></label>
        <label>Pointure<input name="pointure" maxlength="10" value="${echapper(m.pointure)}" placeholder="43"></label></div>
      <button class="secondaire">Enregistrer</button>
      <p class="message" id="reperes-m" role="status"></p>
    </form>`;

  document.getElementById('reperes').addEventListener('submit', async (e) => {
    e.preventDefault();
    const f = e.target, corps = { id: d.soi ? 0 : m.id };
    ['urgence_nom', 'urgence_tel', 'taille_haut', 'taille_bas', 'pointure', ...(d.peut_tout_modifier ? ['entree_le'] : [])].forEach((k) => { corps[k] = f[k].value; });
    const r = await api('fiche_enregistrer', { methode: 'POST', corps });
    document.getElementById('reperes-m').textContent = r.ok ? 'Enregistré.' : { telephone: 'Ce numéro n\'a pas l\'air valable.', date: 'Cette date n\'est pas valable.' }[r.erreur] || 'Rien n\'a été enregistré.';
  });

  const fq = document.getElementById('qualif');
  if (fq) {
    fq.addEventListener('submit', async (e) => {
      e.preventDefault();
      const corps = { utilisateur_id: m.id, id: +fq.id.value || 0, type: fq.type.value, intitule: fq.intitule.value, obtenue_le: fq.obtenue_le.value, expire_le: fq.expire_le.value, note: fq.note.value };
      const r = await api('qualification_enregistrer', { methode: 'POST', corps });
      if (r.ok) { recharger(); return; }
      document.getElementById('qualif-m').textContent = { type: 'Précisez laquelle : une formation interne ou « Autre » demande un intitulé.', dates: 'Vérifiez les dates : obtenue dans le passé, et la fin après l\'obtention.' }[r.erreur] || 'Rien n\'a été enregistré.';
    });
    document.querySelectorAll('[data-modifier-q]').forEach((b) => b.addEventListener('click', () => {
      const q = d.qualifications.find((x) => x.id === +b.dataset.modifierQ);
      Object.entries({ id: q.id, type: q.type, intitule: q.intitule, obtenue_le: q.obtenue_le || '', expire_le: q.expire_le || '', note: q.note }).forEach(([k, v]) => { fq[k].value = v; });
      document.getElementById('qualif-titre').textContent = 'Modifier : ' + q.libelle;
      fq.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }));
    document.querySelectorAll('[data-retirer-q]').forEach((b) => b.addEventListener('click', async () => {
      if (!confirm('Retirer cette habilitation de la fiche ?')) return;
      const r = await api('qualification_supprimer', { methode: 'POST', corps: { id: +b.dataset.retirerQ } });
      if (r.ok) recharger();
    }));
  }

  const fc = document.getElementById('conge');
  if (fc) {
    fc.du.addEventListener('change', () => { if (!fc.au.value || fc.au.value < fc.du.value) fc.au.value = fc.du.value; fc.au.min = fc.du.value; });
    fc.addEventListener('submit', async (e) => {
      e.preventDefault();
      const r = await api('conge_demander', { methode: 'POST', corps: { du: fc.du.value, au: fc.au.value, motif: fc.motif.value, message: fc.message.value } });
      if (r.ok) { recharger(); return; }
      document.getElementById('conge-m').textContent = { dates: 'Vérifiez les dates : à partir d\'aujourd\'hui, un an au plus, et la fin après le début.', chevauche: 'Une demande ou une absence couvre déjà une partie de ces jours.' }[r.erreur] || 'La demande n\'est pas partie.';
    });
  }
  document.querySelectorAll('[data-annuler]').forEach((b) => b.addEventListener('click', async () => {
    if (!confirm('Annuler cette demande de congés ?')) return;
    const r = await api('conge_annuler', { methode: 'POST', corps: { id: +b.dataset.annuler } });
    if (r.ok) recharger(); else alert('Elle ne peut plus être annulée ici : voyez-le avec votre responsable.');
  }));
}

/* ---------- Les demandes à décider : un onglet des absences ---------- */

async function vueConges(agence) {
  vue().innerHTML = '<p class="chargement">Chargement…</p>';
  const d = await api('conges', { params: agence ? { agence } : {} });
  if (!d.ok) return;
  const carte = (c) => {
    const i = c.impact;
    return `<form class="carte couverture" data-id="${c.id}">
      <div class="reco-tete">${pastilleDe(c.depuis_jours > d.delai ? ['rouge', 'Depuis ' + c.depuis_jours + ' jours'] : ['orange', c.depuis_jours ? 'Depuis ' + c.depuis_jours + ' jour' + (c.depuis_jours > 1 ? 's' : '') : 'Aujourd\'hui'])}
        <strong>${echapper(c.nom)}</strong> <span>${echapper(periode(c.du, c.au))}, ${nbJours(c.du, c.au)} jour${nbJours(c.du, c.au) > 1 ? 's' : ''} · ${echapper(c.motif.toLowerCase())}</span></div>
      ${c.message ? `<p class="note">« ${echapper(c.message)} »</p>` : ''}
      <p class="sous">${i.jours.length ? `<strong>${i.jours.length} journée${i.jours.length > 1 ? 's' : ''} de tournée, ${i.clients} client${i.clients > 1 ? 's' : ''} à confier</strong> : ${i.jours.map((j) => echapper(dateCourte(j.date) + ' (' + j.clients + ')')).join(', ')}` : 'Aucune journée de tournée touchée.'}</p>
      ${i.deja_absents.length ? `<p class="bandeau alerte">Déjà absents sur ces dates : ${i.deja_absents.map((a) => echapper(a.nom + ' ' + periode(a.du, a.au))).join(', ')}.</p>` : ''}
      <label>Réponse <span class="aide">obligatoire pour un refus : la raison, et d'autres dates si possible</span><input name="reponse" maxlength="500"></label>
      <div class="actions-ligne"><button class="principal" data-decision="accepter">Accepter</button><button type="button" class="secondaire" data-decision="refuser">Refuser</button></div>
      <p class="message" role="status"></p>
    </form>`;
  };
  vue().innerHTML = `
    <div class="tete"><h1>Congés</h1>${choixAgence(d)}</div>
    ${ongletsAbsences('conges')}
    <p class="aide">Une réponse sous ${d.delai} jours, oui ou non avec la raison. Une demande acceptée devient une absence : sa tournée se couvre ensuite depuis l'onglet Absences.</p>
    <h2>À décider</h2>
    ${d.attente.length ? d.attente.map(carte).join('') : '<p class="aide">Aucune demande en attente.</p>'}
    <h2>Décidées récemment</h2>
    ${d.traitees.length ? `<ul class="liste">${d.traitees.map((c) => `<li class="membre"><div class="reco-tete"><strong>${echapper(c.nom)}</strong> <span>${echapper(periode(c.du, c.au))} · ${echapper(c.motif.toLowerCase())}</span>${pastilleDe(STATUTS_CONGE[c.statut])}</div>
      ${c.reponse ? `<span class="sous">${echapper(c.reponse)}</span>` : ''}</li>`).join('')}</ul>` : '<p class="aide">Rien sur les trois derniers mois.</p>'}`;
  const sel = document.getElementById('agence');
  if (sel) sel.addEventListener('change', () => aller(sel.value ? '#/conges/' + sel.value : '#/conges'));
  document.querySelectorAll('form.couverture').forEach((f) => {
    const m = f.querySelector('.message');
    const decider = async (decision) => {
      if (decision === 'refuser' && !f.reponse.value.trim()) { m.textContent = 'Un refus s\'explique : écrivez la raison, et d\'autres dates si possible.'; f.reponse.focus(); return; }
      const r = await api('conge_decider', { methode: 'POST', corps: { id: +f.dataset.id, decision, reponse: f.reponse.value } });
      if (!r.ok) { m.textContent = 'La décision n\'a pas été enregistrée.'; return; }
      f.innerHTML = decision === 'accepter'
        ? '<p class="bandeau calme">Accepté. La personne le voit sur sa fiche. Ses journées de tournée sont maintenant à couvrir dans <a href="#/absences">Absences</a>.</p>'
        : '<p class="bandeau calme">Refus enregistré, avec sa raison. La personne le voit sur sa fiche.</p>';
      majAFaire();
    };
    f.addEventListener('submit', (e) => { e.preventDefault(); decider('accepter'); });
    f.querySelector('[data-decision="refuser"]').addEventListener('click', () => decider('refuser'));
  });
}
