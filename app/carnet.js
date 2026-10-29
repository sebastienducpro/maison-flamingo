// Maison Flamingo · le carnet de la maison, côté équipe : ce que le client lira.
// Chaque champ ici apparaît tel quel chez lui : on écrit court, au présent, sans jargon.
'use strict';

const ETATS_CARNET = { bon: 'En ordre', surveiller: 'À surveiller', prevoir: 'À prévoir', absent: 'Sans objet' };
const ORGANISE = { nous: 'Organisé par nous', choisir: 'Le client choisit un créneau', client: 'À faire par le client' };

async function vueCarnet(id) {
  vue().innerHTML = '<p class="chargement">Chargement…</p>';
  const d = await api('carnet', { params: { id } });
  if (!d.ok) { vue().innerHTML = '<section class="carte"><p>Client introuvable.</p><a href="#/clients">Retour</a></section>'; return; }
  const c = d.client, pilote = APP.utilisateur.role !== 'technicien';
  const dateCourte = (s) => (s ? new Date(s + 'T12:00:00').toLocaleDateString('fr-FR', { weekday: 'short', day: 'numeric', month: 'long' }) : '');
  vue().innerHTML = `
    <a class="retour" href="#/client/${c.id}">← ${echapper((c.prenom + ' ' + c.nom).trim())}</a>
    <h1>Carnet de la maison</h1>
    <p class="aide">Ce que le client voit dans son espace. Le poste piscine se met à jour seul après chaque visite.</p>

    <h2>Poste par poste</h2>
    <ul class="liste postes">${d.postes.map((p) => `<li class="poste" data-cle="${p.cle}">
      <span class="nom">${echapper(p.libelle)}</span>
      <div class="puces">${Object.entries(ETATS_CARNET).map(([k, l]) => `<button type="button" class="puce${p.etat === k ? ' choisie' : ''}" data-etat="${k}">${l}</button>`).join('')}</div>
      <input class="detail" value="${echapper(p.detail)}" placeholder="Une ligne : ce qui a été fait ou vu" aria-label="Détail ${echapper(p.libelle)}">
    </li>`).join('')}</ul>
    <p class="message" id="m-postes" role="status"></p>

    <h2>À venir <button type="button" class="secondaire petit" id="saison">Poser la saison type</button></h2>
    <ul class="liste">${d.evenements.map((e) => `<li class="demande${e.statut !== 'prevu' ? ' traitee' : ''}">
      <span class="nom">${echapper(e.titre)}</span> <span class="sous">${e.quand ? echapper(dateCourte(e.quand)) : echapper(e.echeance)} · ${ORGANISE[e.organise]}${e.statut === 'fait' ? ' · fait' : e.statut === 'annule' ? ' · annulé' : ''}</span>
      ${e.detail ? `<p>${echapper(e.detail)}</p>` : ''}
      <div class="actions-ligne">${e.statut === 'prevu' ? `<button class="secondaire" data-fait="${e.id}">Marquer fait</button>` : ''}<button class="discret" data-suppr-ev="${e.id}">Retirer</button></div>
    </li>`).join('') || '<li class="vide">Rien de prévu.</li>'}</ul>
    <form id="f-ev" class="carte">
      <div class="deux"><label>Quoi<input name="titre" required placeholder="Entretien de la chaudière"></label>
        <label>Date<input name="quand" type="date"></label></div>
      <div class="deux"><label>Ou une échéance<input name="echeance" placeholder="avant le 1er déc."></label>
        <label>Qui s'en charge<select name="organise">${Object.entries(ORGANISE).map(([k, l]) => `<option value="${k}">${l}</option>`).join('')}</select></label></div>
      <label>Pour le client<textarea name="detail" rows="2" placeholder="Chauffagiste partenaire à 9 h. Nous ouvrons la maison et restons sur place."></textarea></label>
      <button class="principal">Ajouter au calendrier</button>
    </form>

    <h2>Les équipements</h2>
    <ul class="liste">${d.equipements.map((q) => `<li class="demande"><span class="nom">${echapper(q.nom)}</span> <span class="sous">${echapper(q.detail)}</span>
      <div class="actions-ligne"><button class="discret" data-suppr-eq="${q.id}">Retirer</button></div></li>`).join('') || '<li class="vide">Aucun équipement noté. Le diagnostic est le bon moment pour faire l\'inventaire.</li>'}</ul>
    <form id="f-eq" class="carte">
      <div class="deux"><label>Équipement<input name="nom" required placeholder="Pompe de filtration"></label>
        <label>Repères<input name="detail" placeholder="posée en 2024 · garantie 2029"></label></div>
      <button class="secondaire">Ajouter l'équipement</button>
    </form>

    <h2>Les documents</h2>
    <ul class="liste">${d.documents.map((x) => `<li class="demande"><a class="nom" href="api.php?action=document&id=${x.id}" target="_blank" rel="noopener">${echapper(x.titre)}</a>
      <span class="sous">${x.type === 'application/pdf' ? 'PDF' : 'Image'} · ${echapper(dateFr(x.cree_le, false))}</span>
      ${pilote ? `<div class="actions-ligne"><button class="discret danger" data-suppr-doc="${x.id}">Supprimer</button></div>` : ''}</li>`).join('') || '<li class="vide">Aucun document.</li>'}</ul>
    <form id="f-doc" class="carte">
      <label>Titre lu par le client<input name="titre" required placeholder="Attestation d'entretien chaudière 2026"></label>
      <label class="bouton secondaire fichier">Choisir un PDF ou une photo<input name="fichier" type="file" accept="application/pdf,image/jpeg,image/png" required hidden></label>
      <p class="aide" id="nom-fichier"></p>
      <button class="principal">Ajouter au carnet</button>
      <p class="message" id="m-doc" role="status"></p>
    </form>`;

  const m = document.getElementById('m-postes');
  // Un poste s'enregistre dès qu'on le touche : pas de bouton à oublier au bord du bassin.
  const enregistrerPoste = async (li) => {
    const choisie = li.querySelector('.puce.choisie');
    const r = await api('carnet_poste', { methode: 'POST', corps: { id: c.id, cle: li.dataset.cle, etat: choisie ? choisie.dataset.etat : '', detail: li.querySelector('.detail').value } });
    m.textContent = r.ok ? 'Enregistré.' : 'Pas enregistré : réessayez.';
  };
  vue().querySelectorAll('.poste').forEach((li) => {
    li.querySelectorAll('.puce').forEach((b) => b.addEventListener('click', () => {
      const deja = b.classList.contains('choisie');
      li.querySelectorAll('.puce').forEach((p) => p.classList.remove('choisie'));
      if (!deja) b.classList.add('choisie');
      enregistrerPoste(li);
    }));
    li.querySelector('.detail').addEventListener('change', () => enregistrerPoste(li));
  });
  document.getElementById('saison').addEventListener('click', async () => {
    const r = await api('carnet_saison', { methode: 'POST', corps: { id: c.id } });
    if (r.ok) vueCarnet(c.id); else alert('La saison type dépend de la formule du client : renseignez-la dans la fiche (Piscine, Maison fermée…).');
  });
  document.getElementById('f-ev').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    await api('carnet_evenement', { methode: 'POST', corps: { id: c.id, ...Object.fromEntries(new FormData(ev.target).entries()) } });
    vueCarnet(c.id);
  });
  vue().querySelectorAll('[data-fait]').forEach((b) => b.addEventListener('click', async () => {
    const e = d.evenements.find((x) => x.id === +b.dataset.fait);
    await api('carnet_evenement', { methode: 'POST', corps: { id: c.id, evenement_id: e.id, ...e, statut: 'fait' } });
    vueCarnet(c.id);
  }));
  vue().querySelectorAll('[data-suppr-ev]').forEach((b) => b.addEventListener('click', async () => {
    await api('carnet_evenement_supprimer', { methode: 'POST', corps: { id: c.id, evenement_id: +b.dataset.supprEv } });
    vueCarnet(c.id);
  }));
  document.getElementById('f-eq').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    await api('carnet_equipement', { methode: 'POST', corps: { id: c.id, ...Object.fromEntries(new FormData(ev.target).entries()) } });
    vueCarnet(c.id);
  });
  vue().querySelectorAll('[data-suppr-eq]').forEach((b) => b.addEventListener('click', async () => {
    await api('carnet_equipement_supprimer', { methode: 'POST', corps: { id: c.id, equipement_id: +b.dataset.supprEq } });
    vueCarnet(c.id);
  }));
  const fdoc = document.getElementById('f-doc');
  fdoc.fichier.addEventListener('change', () => { document.getElementById('nom-fichier').textContent = fdoc.fichier.files[0] ? fdoc.fichier.files[0].name : ''; });
  fdoc.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const md = document.getElementById('m-doc');
    if (!fdoc.fichier.files[0]) { md.textContent = 'Choisissez un fichier.'; return; }
    const fd = new FormData(fdoc);
    fd.append('id', c.id);
    md.textContent = 'Envoi…';
    const r = await api('carnet_document', { methode: 'POST', formulaire: fd });
    if (r.ok) vueCarnet(c.id); else md.textContent = r.erreur === 'type' ? 'Seuls les PDF et les photos sont acceptés.' : 'Le fichier n\'est pas passé (8 Mo au plus).';
  });
  vue().querySelectorAll('[data-suppr-doc]').forEach((b) => b.addEventListener('click', async () => {
    if (!confirm('Supprimer ce document du carnet du client ?')) return;
    await api('carnet_document_supprimer', { methode: 'POST', corps: { id: c.id, document_id: +b.dataset.supprDoc } });
    vueCarnet(c.id);
  }));
}
