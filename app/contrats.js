// Maison Flamingo · les contrats, côté équipe : les préparer depuis la fiche, suivre leur signature et leurs échéances,
// et l'identité de la société qui les porte. Le serveur refuse tout contrat tant que cette identité est incomplète.
'use strict';

const STATUTS_CONTRAT = { a_signer: ['orange', 'À signer'], signe: ['vert', 'Signé'], retracte: ['', 'Rétracté'], resilie: ['', 'Résilié'], annule: ['', 'Annulé'] };
const euros = (n) => Number(n).toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';

async function blocContrat(c) {
  const zoneContrat = document.getElementById('bloc-contrat');
  if (!zoneContrat) return;
  const d = await api('contrats', { params: { client_id: c.id } }).catch(() => ({ ok: false }));
  if (!d.ok) return;
  const ouvert = d.contrats.find((k) => k.statut === 'a_signer' || k.statut === 'signe');
  const formule = Object.keys(d.tarifs).includes(c.formule) ? c.formule : 'Piscine';
  zoneContrat.innerHTML = `
    <h2>Contrat</h2>
    <div class="carte">
      ${d.contrats.length ? `<ul class="liste">${d.contrats.map((k) => `<li class="membre"><div class="reco-tete"><strong>${echapper(k.numero)}</strong> <span>${echapper(k.formule)} · ${k.modalite === 'annuel' ? euros(k.montant_annuel) + ' par an' : euros(k.prix_mensuel) + ' par mois'}${k.majoration_pct ? (k.sur_devis ? ' · sur devis' : ' · +' + k.majoration_pct + ' % grand bassin') : ''}${k.fondateur ? ' · fondateur' : ''}</span>${pastilleDe(STATUTS_CONTRAT[k.statut])}</div>
        <span class="sous">${[k.signe_le ? 'signé le ' + dateFr(k.signe_le) + ' par ' + k.signe_nom : 'préparé le ' + dateFr(k.cree_le, false), 'début le ' + dateCourte(k.debut),
          k.echeance && k.statut === 'signe' ? 'échéance le ' + dateCourte(k.echeance) + (k.rappel_le ? ', rappel envoyé le ' + dateFr(k.rappel_le, false) : '') : '',
          k.resiliation_effet ? 'résiliation effective le ' + dateCourte(k.resiliation_effet) : '',
          k.statut === 'signe' && k.prix_revise && k.prix_revise !== k.prix_mensuel ? 'prix révisé avec les derniers indices : ' + euros(k.prix_revise) + ' par mois' : ''].filter(Boolean).map(echapper).join(' · ')}</span>
        <div class="actions-ligne"><a class="bouton secondaire" href="api.php?action=contrat_document&id=${k.id}" target="_blank" rel="noopener">Voir le document</a>
          ${k.statut === 'a_signer' && d.peut_preparer ? `<button class="discret" data-annuler-contrat="${k.id}">Annuler avant signature</button>` : ''}
          ${k.statut === 'signe' && k.echeance && d.peut_preparer ? `<button class="discret" data-rappel="${k.id}">Rappel de reconduction envoyé</button>` : ''}</div></li>`).join('')}</ul>` : '<p class="aide">Aucun contrat pour ce client.</p>'}
      ${d.peut_preparer && !ouvert ? (d.manques.length
        ? `<p class="bandeau alerte">Avant le premier contrat, l'identité de la société doit être complète et le modèle relu par un juriste. Il manque : ${d.manques.map(echapper).join(', ')}. <a href="#/societe">Compléter</a></p>`
        : `<form id="preparer" class="sous-forme">
          <div class="deux"><label>Formule<select name="formule">${Object.entries(d.tarifs).map(([f, p]) => `<option value="${echapper(f)}"${f === formule ? ' selected' : ''}>${echapper(f)} · ${p} € par mois</option>`).join('')}</select></label>
            <label>Paiement<select name="modalite">${Object.entries(d.modalites).map(([k, l]) => `<option value="${k}">${echapper(l)}${k === 'annuel' ? ' (−' + d.regle.remise_pct + ' %)' : ''}</option>`).join('')}</select></label></div>
          <div class="bassin">
            <div class="deux"><label>Surface d'eau en m² <span class="aide">${d.surface_diagnostic ? 'mesurée au diagnostic : ' + String(d.surface_diagnostic).replace('.', ',') + ' m²' : 'longueur × largeur'}</span><input name="surface" inputmode="decimal" value="${d.surface_diagnostic ?? ''}"></label>
              <label class="case"><input type="checkbox" name="debordement"> Bassin à débordement</label></div>
            <p class="aide">Jusqu'à ${d.regle.base_m2} m² : prix de la formule. De ${d.regle.base_m2} à ${d.regle.majoree_m2} m² : +${d.regle.majoration_pct} %. Au-delà, ou à débordement : sur devis.</p>
            <label class="devis" hidden>Prix mensuel sur devis, TTC<input name="prix_devis" inputmode="decimal"></label>
          </div>
          <div class="deux"><label>Début<input name="debut" type="date" min="${dansJours(0)}" max="${dansJours(180)}" required></label>
            <label class="case"><input type="checkbox" name="fondateur"> Fondateur : prix garanti trois ans, premier mois offert, satisfait ou résilié deux mois</label></div>
          <p class="bandeau calme" id="prix-calcule" role="status">…</p>
          <button class="principal">Préparer le contrat</button>
          <p class="aide">Le client le signe dans son espace, rubrique « Mon contrat ». Son espace doit être ouvert.</p>
          <p class="message" role="status"></p>
        </form>`) : ''}
    </div>`;
  const f = document.getElementById('preparer');
  if (f) {
    const choix = () => ({ client_id: c.id, formule: f.formule.value, modalite: f.modalite.value, surface: f.surface.value.replace(',', '.'), debordement: f.debordement.checked ? 1 : '',
      prix_devis: f.prix_devis.value.replace(',', '.'), fondateur: f.fondateur.checked ? 1 : '', debut: f.debut.value });
    const erreurs = { champs: 'Vérifiez la formule et la date de début (dans les six mois).', surface: 'Indiquez la surface d\'eau du bassin, en m².', deja: 'Ce client a déjà un contrat à signer ou en cours.', societe: 'L\'identité de la société est incomplète.' };
    // Le prix vient du serveur, qui seul le calcule : l'écran le redemande à chaque changement.
    const recalculer = async () => {
      const piscine = d.formules_piscine.includes(f.formule.value);
      f.querySelector('.bassin').hidden = !piscine;
      const surface = parseFloat(f.surface.value.replace(',', '.'));
      f.querySelector('.devis').hidden = !(piscine && (f.debordement.checked || surface > d.regle.majoree_m2));
      const { client_id, debut, ...params } = choix();
      const t = await api('contrat_tarif', { params });
      const z = document.getElementById('prix-calcule');
      if (!t.ok) { z.className = 'bandeau alerte'; z.textContent = t.erreur === 'devis' ? 'Sur devis : indiquez le prix mensuel, au moins ' + t.minimum + ' €.' : erreurs[t.erreur] || 'Prix incomplet.'; return; }
      z.className = 'bandeau calme';
      z.textContent = (t.majoration_pct && !t.sur_devis ? 'Majoration grand bassin +' + t.majoration_pct + ' % : ' : t.sur_devis ? 'Sur devis : ' : '') + euros(t.prix_mensuel) + ' par mois'
        + (t.modalite === 'annuel' ? ', soit ' + euros(t.montant_annuel) + ' payés d\'avance pour l\'année (' + t.mois_payants + ' mois, −' + t.remise_pct + ' %)' : '') + '.';
    };
    ['formule', 'modalite', 'debordement', 'fondateur'].forEach((n) => f[n].addEventListener('change', recalculer));
    ['surface', 'prix_devis'].forEach((n) => f[n].addEventListener('input', recalculer));
    recalculer();
    f.addEventListener('submit', async (e) => {
      e.preventDefault();
      const r = await api('contrat_preparer', { methode: 'POST', corps: choix() });
      if (r.ok) { blocContrat(c); return; }
      f.querySelector('.message').textContent = r.erreur === 'devis' ? 'Sur devis : le prix mensuel doit être d\'au moins ' + r.minimum + ' €.' : erreurs[r.erreur] || 'Le contrat n\'a pas été préparé.';
    });
  }
  zoneContrat.querySelectorAll('[data-annuler-contrat]').forEach((b) => b.addEventListener('click', async () => {
    if (!confirm('Annuler ce contrat avant sa signature ?')) return;
    const r = await api('contrat_annuler', { methode: 'POST', corps: { id: +b.dataset.annulerContrat } });
    if (r.ok) blocContrat(c);
  }));
  zoneContrat.querySelectorAll('[data-rappel]').forEach((b) => b.addEventListener('click', async () => {
    if (!confirm('Le client a bien reçu, par écrit, la date d\'échéance et sa liberté de ne pas reconduire ?')) return;
    const r = await api('contrat_rappel_noter', { methode: 'POST', corps: { id: +b.dataset.rappel } });
    if (r.ok) blocContrat(c);
  }));
}

async function vueSociete() {
  vue().innerHTML = '<p class="chargement">Chargement…</p>';
  const d = await api('societe');
  if (!d.ok) return;
  const champ = (k) => `<label>${echapper(d.libelles[k])}<input name="${k}" ${k === 'juriste_le' ? 'type="date"' : ''} value="${echapper(d.societe[k])}"${d.modifiable ? '' : ' disabled'}></label>`;
  vue().innerHTML = `
    <a class="retour" href="#/agences">← Agences</a>
    <h1>La société et ses contrats</h1>
    <p class="bandeau ${d.manques.length ? 'alerte' : 'calme'}">${d.manques.length ? 'Aucun contrat ne peut partir tant qu\'il manque : ' + d.manques.map(echapper).join(', ') + '.' : 'Identité complète : les contrats peuvent être préparés.'}</p>
    <p class="aide">Ces informations figurent sur chaque contrat (modèle ${echapper(d.modele)}). Le modèle cite les articles du Code de la consommation qui s'appliquent ; il doit être relu par un juriste avant le premier client : la date et le nom de cette relecture débloquent les contrats.</p>
    <form id="societe" class="carte">
      <div class="deux">${champ('raison_sociale')}${champ('forme')}</div>
      <div class="deux">${champ('siret')}${champ('rcs')}</div>
      ${champ('adresse')}
      <div class="deux">${champ('courriel')}${champ('telephone')}</div>
      ${champ('mention_tva')}
      <div class="deux">${champ('mediateur_nom')}${champ('mediateur_site')}</div>
      <div class="deux">${champ('assureur')}${champ('police')}</div>
      <div class="deux">${champ('juriste_nom')}${champ('juriste_le')}</div>
      <fieldset class="saisie-groupe"><legend>Révision des prix</legend>
        <p class="aide">Formule du contrat : P = P0 × (0,15 + 0,70 × S/S0 + 0,15 × C/C0). L'indexation sur le niveau général des prix est interdite (article L112-2 du Code monétaire et financier) : S suit le coût du travail des services, C le prix des produits. Le juriste choisit les indices exacts ; mettez à jour leurs valeurs à chaque publication de l'INSEE, chaque nouveau contrat prend les dernières comme référence.</p>
        ${champ('indice_travail_nom')}<div class="deux">${champ('indice_travail_valeur')}${champ('indice_travail_periode')}</div>
        ${champ('indice_produits_nom')}<div class="deux">${champ('indice_produits_valeur')}${champ('indice_produits_periode')}</div>
      </fieldset>
      ${d.modifiable ? '<button class="principal">Enregistrer</button>' : '<p class="aide">Seule la direction modifie ces informations.</p>'}
      <p class="message" id="m" role="status"></p>
    </form>`;
  const f = document.getElementById('societe');
  f.addEventListener('submit', async (e) => {
    e.preventDefault();
    const corps = Object.fromEntries([...f.querySelectorAll('input')].map((i) => [i.name, i.value.trim()]));
    const r = await api('societe_enregistrer', { methode: 'POST', corps });
    document.getElementById('m').textContent = r.ok ? (r.manques.length ? 'Enregistré. Il manque encore : ' + r.manques.join(', ') + '.' : 'Enregistré. Les contrats peuvent être préparés.') : r.erreur === 'indice' ? 'La valeur d\'un indice doit être un nombre positif.' : 'Vérifiez la date de relecture.';
  });
}

/* ---------- La facturation : un mois à la fois, vu avant d'être émis ---------- */

const STATUTS_FACTURE = { emise: ['orange', 'Émise'], prelevement_demande: ['orange', 'Prélèvement demandé'], payee: ['vert', 'Payée'], rejetee: ['rouge', 'Rejetée'], annulee: ['', 'Annulée'] };

async function vueFacturation(mois) {
  vue().innerHTML = '<p class="chargement">Chargement…</p>';
  const d = await api('facturation', { params: mois ? { mois } : {} });
  if (!d.ok) return;
  const a = d.apercu;
  const nouvelles = a.ok ? a.lignes.filter((l) => !l.facture) : [];
  vue().innerHTML = `
    <a class="retour" href="#/agences">← Agences</a>
    <div class="tete"><h1>Facturation</h1><input type="month" id="mois" value="${d.mois}" aria-label="Mois"></div>
    ${d.manques.length ? `<p class="bandeau alerte">Aucune facture ne peut partir tant que l'identité de la société est incomplète. <a href="#/societe">Compléter</a></p>` : ''}
    <p class="bandeau ${d.configure ? (d.environnement === 'live' ? 'calme' : 'alerte') : 'alerte'}">${!d.configure ? 'Le prélèvement GoCardless n\'est pas configuré : les factures peuvent être émises, rien n\'est prélevé.' : d.environnement === 'live' ? 'Prélèvement GoCardless en service réel.' : 'Prélèvement GoCardless en bac à sable : rien n\'est réellement prélevé.'}</p>
    <h2>${echapper(d.mois)} <span class="aide">${a.ok ? a.lignes.length + ' contrat' + (a.lignes.length > 1 ? 's' : '') + ' à facturer, ' + euros(a.total / 100) + ' TTC' : ''}</span></h2>
    ${a.ok && a.lignes.length ? `<div class="table-defile"><table><thead><tr><th>Contrat</th><th>Client</th><th>Montant</th><th>Facture</th><th>Mandat</th></tr></thead>
      <tbody>${a.lignes.map((l) => `<tr><td>${echapper(l.contrat)}</td><td>${echapper(l.client)}</td><td>${euros(l.montant / 100)}</td><td>${l.facture ? echapper(l.facture) : '<span class="sous">à émettre</span>'}</td><td>${l.mandat ? 'actif' : '<span class="sous">aucun : pas de prélèvement</span>'}</td></tr>`).join('')}</tbody></table></div>`
      : '<p class="aide">Aucun contrat à facturer ce mois-ci.</p>'}
    ${nouvelles.length && !d.manques.length ? `<div class="carte"><p>${nouvelles.length} facture${nouvelles.length > 1 ? 's' : ''} à émettre. Une facture émise ne se reprend pas : elle s'annule par un avoir.</p>
      <button class="principal" id="emettre">Émettre ${nouvelles.length > 1 ? 'les factures' : 'la facture'}${d.configure ? ' et demander les prélèvements' : ''}</button><p class="message" id="m-emettre" role="status"></p></div>` : ''}
    ${d.factures.length ? `<h2>Factures émises</h2><ul class="liste">${d.factures.map((f) => `<li class="membre"><div class="reco-tete"><strong>${echapper(f.numero)}</strong> <span>${echapper((f.prenom + ' ' + f.nom).trim())} · ${euros(f.montant / 100)}</span>${pastilleDe(STATUTS_FACTURE[f.statut] || ['', f.statut])}</div>
      ${f.rejet_motif ? `<span class="sous">${echapper(f.rejet_motif)}</span>` : ''}<a href="api.php?action=facture_document&id=${f.id}" target="_blank" rel="noopener">Voir la facture</a></li>`).join('')}</ul>` : ''}
    <h2>Le prélèvement</h2>
    <form id="gocardless" class="carte">
      <p class="aide">GoCardless garde les IBAN et les mandats : l'outil n'en voit aucun. Commencez par le <strong>bac à sable</strong>, faites passer un mois complet, puis seulement le service réel. Dans GoCardless, déclarez l'adresse de notification <code class="lien">${echapper(d.adresse_webhook)}</code>.</p>
      <label>Environnement<select name="environnement"><option value="sandbox"${d.environnement !== 'live' ? ' selected' : ''}>Bac à sable</option><option value="live"${d.environnement === 'live' ? ' selected' : ''}>Service réel</option></select></label>
      <div class="deux"><label>Jeton d'accès <span class="aide">jamais réaffiché</span><input name="jeton" type="password" autocomplete="new-password" required></label>
        <label>Secret de l'adresse de notification <span class="aide">jamais réaffiché</span><input name="secret_webhook" type="password" autocomplete="new-password" required></label></div>
      <label>Taux de TVA en % <span class="aide">vide en franchise de TVA : la mention de la société s'affiche alors</span><input name="taux_tva" inputmode="decimal" value="${d.taux_tva ?? ''}"></label>
      <button class="secondaire">${d.configure ? 'Remplacer les accès' : 'Enregistrer'}</button>
      <p class="message" id="m-gc" role="status"></p>
    </form>
    <section class="carte">
      <label class="case"><input type="checkbox" id="auto"${d.automatique ? ' checked' : ''}${d.configure ? '' : ' disabled'}> Émettre les factures et demander les prélèvements seul, le 1er de chaque mois</label>
      <p class="aide">À cocher après un premier mois mené à la main. Le relevé du lundi signale ensuite les rejets.</p>
      <p class="message" id="m-auto" role="status"></p>
    </section>`;
  document.getElementById('mois').addEventListener('change', (e) => aller('#/facturation/' + e.target.value));
  const emettre = document.getElementById('emettre');
  if (emettre) emettre.addEventListener('click', async () => {
    if (!confirm('Émettre ces factures ? Elles seront numérotées et ne pourront plus être modifiées.')) return;
    emettre.disabled = true;
    const r = await api('facturation_emettre', { methode: 'POST', corps: { mois: d.mois } });
    document.getElementById('m-emettre').textContent = r.ok ? 'Factures émises' + (r.prelevements && r.prelevements.ok ? ', ' + r.prelevements.demandes + ' prélèvement(s) demandé(s)' + (r.prelevements.echecs.length ? ', ' + r.prelevements.echecs.length + ' échec(s)' : '') : '') + '.' : { mois: 'On ne facture que le mois en cours ou le précédent.', societe: 'Identité de la société incomplète.' }[r.erreur] || 'Rien n\'a été émis.';
    setTimeout(() => vueFacturation(d.mois), 1500);
  });
  const f = document.getElementById('gocardless');
  f.addEventListener('submit', async (e) => {
    e.preventDefault();
    const r = await api('facturation_configurer', { methode: 'POST', corps: { environnement: f.environnement.value, jeton: f.jeton.value.trim(), secret_webhook: f.secret_webhook.value.trim(), taux_tva: f.taux_tva.value.trim() } });
    f.jeton.value = ''; f.secret_webhook.value = '';
    document.getElementById('m-gc').textContent = r.ok ? 'Accès enregistrés.' : r.erreur === 'environnement' ? 'Ce jeton ne correspond pas à l\'environnement choisi (un jeton de bac à sable commence par « sandbox_ »).' : 'Les deux champs sont nécessaires.';
    if (r.ok) setTimeout(() => vueFacturation(d.mois), 900);
  });
  document.getElementById('auto').addEventListener('change', async (e) => {
    const r = await api('facturation_automatique', { methode: 'POST', corps: { actif: e.target.checked } });
    document.getElementById('m-auto').textContent = r.ok ? (r.actif ? 'Activé : prochaine émission le 1er du mois.' : 'Coupé : la facturation se fait à la main.') : 'Configurez d\'abord le prélèvement.';
  });
}
