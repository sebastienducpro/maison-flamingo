// Maison Flamingo · les contrats, côté équipe : les préparer depuis la fiche, suivre leur signature et leurs échéances,
// l'identité des deux sociétés qui les portent et la grille des prix. Le serveur refuse tout contrat d'une société
// dont l'identité est incomplète, et calcule seul chaque prix : l'écran affiche, il ne compte pas.
'use strict';

const STATUTS_CONTRAT = { a_signer: ['orange', 'À signer'], signe: ['vert', 'Signé'], retracte: ['', 'Rétracté'], resilie: ['', 'Résilié'], annule: ['', 'Annulé'] };
const euros = (n) => Number(n).toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
const eurosRonds = (n) => Number(n).toLocaleString('fr-FR', { maximumFractionDigits: 2 }) + ' €';

async function blocContrat(c) {
  const zoneContrat = document.getElementById('bloc-contrat');
  if (!zoneContrat) return;
  const d = await api('contrats', { params: { client_id: c.id } }).catch(() => ({ ok: false }));
  if (!d.ok) return;
  const occupees = new Set(d.contrats.filter((k) => k.statut === 'a_signer' || k.statut === 'signe').map((k) => k.societe));
  const vendables = Object.entries(d.grille).filter(([, g]) => g.prix !== null);
  const formule = vendables.some(([f]) => f === c.formule) ? c.formule : 'Piscine';
  zoneContrat.innerHTML = `
    <h2>Contrat</h2>
    <div class="carte">
      ${d.contrats.length ? `<ul class="liste">${d.contrats.map((k) => `<li class="membre"><div class="reco-tete"><strong>${echapper(k.numero)}</strong> <span>${echapper(k.formule)} · ${k.modalite === 'annuel' ? euros(k.montant_annuel) + ' par an' : euros(k.prix_mensuel) + ' par mois'}${k.majoration_pct ? (k.sur_devis ? ' · sur devis' : ' · +' + k.majoration_pct + ' % grand bassin') : ''}${k.remise_pack ? ' · remise pack −' + eurosRonds(k.remise_pack) : ''}${k.fondateur ? ' · fondateur' : ''}</span>${pastilleDe(STATUTS_CONTRAT[k.statut])}</div>
        <span class="sous">${[d.societes[k.societe] || k.societe, k.signe_le ? 'signé le ' + dateFr(k.signe_le) + ' par ' + k.signe_nom : 'préparé le ' + dateFr(k.cree_le, false), 'début le ' + dateCourte(k.debut),
          k.echeance && k.statut === 'signe' ? 'échéance le ' + dateCourte(k.echeance) + (k.rappel_le ? ', rappel envoyé le ' + dateFr(k.rappel_le, false) : '') : '',
          k.resiliation_effet ? 'résiliation effective le ' + dateCourte(k.resiliation_effet) : '',
          k.statut === 'signe' && k.prix_revise && k.prix_revise !== k.prix_mensuel ? 'prix révisé avec les derniers indices : ' + euros(k.prix_revise) + ' par mois' : ''].filter(Boolean).map(echapper).join(' · ')}</span>
        <div class="actions-ligne"><a class="bouton secondaire" href="api.php?action=contrat_document&id=${k.id}" target="_blank" rel="noopener">Voir le document</a>
          ${k.statut === 'a_signer' && d.peut_preparer ? `<button class="discret" data-annuler-contrat="${k.id}">Annuler avant signature</button>` : ''}
          ${k.statut === 'signe' && k.echeance && d.peut_preparer ? `<button class="discret" data-rappel="${k.id}">Rappel de reconduction envoyé</button>` : ''}</div></li>`).join('')}</ul>` : '<p class="aide">Aucun contrat pour ce client.</p>'}
      ${d.peut_preparer && occupees.size < Object.keys(d.societes).length ? `<form id="preparer" class="sous-forme">
          <div class="deux"><label>Formule<select name="formule">${Object.entries(d.grille).map(([f, g]) => `<option value="${echapper(f)}"${f === formule ? ' selected' : ''}${g.prix === null ? ' disabled' : ''}>${echapper(f)} · ${g.prix === null ? 'prix à fixer dans la grille' : eurosRonds(g.prix) + ' par mois'}</option>`).join('')}</select></label>
            <label>Paiement<select name="modalite">${Object.entries(d.modalites).map(([k, l]) => `<option value="${k}">${echapper(l)}${k === 'annuel' ? ' (−' + d.regle.remise_pct + ' %)' : ''}</option>`).join('')}</select></label></div>
          <div class="bassin">
            <div class="deux"><label>Surface d'eau en m² <span class="aide">${d.surface_diagnostic ? 'mesurée au diagnostic : ' + String(d.surface_diagnostic).replace('.', ',') + ' m²' : 'longueur × largeur'}</span><input name="surface" inputmode="decimal" value="${d.surface_diagnostic ?? ''}"></label>
              <label class="case"><input type="checkbox" name="debordement"> Bassin à débordement</label></div>
            <p class="aide">Jusqu'à ${d.regle.base_m2} m² : prix de la formule. De ${d.regle.base_m2} à ${d.regle.majoree_m2} m² : +${d.regle.majoration_pct} %. Au-delà, ou à débordement : sur devis.</p>
            <label class="devis" hidden>Prix mensuel de la piscine sur devis, TTC, avant remise de pack<input name="prix_devis" inputmode="decimal"></label>
          </div>
          <div class="deux"><label>Début<input name="debut" type="date" min="${dansJours(0)}" max="${dansJours(180)}" required></label>
            <label class="case"><input type="checkbox" name="fondateur"> Fondateur : prix garanti trois ans, premier mois offert, satisfait ou résilié deux mois</label></div>
          <p class="bandeau alerte" id="manques-societe" hidden></p>
          <p class="bandeau calme" id="prix-calcule" role="status">…</p>
          <button class="principal">Préparer le contrat</button>
          <p class="aide">Le client le signe dans son espace, rubrique « Mon contrat ». Son espace doit être ouvert. Un pack donne deux contrats, un par société, qui se signent ensemble.</p>
          <p class="message" role="status"></p>
        </form>` : ''}
    </div>`;
  const f = document.getElementById('preparer');
  if (f) {
    const choix = () => ({ client_id: c.id, formule: f.formule.value, modalite: f.modalite.value, surface: f.surface.value.replace(',', '.'), debordement: f.debordement.checked ? 1 : '',
      prix_devis: f.prix_devis.value.replace(',', '.'), fondateur: f.fondateur.checked ? 1 : '', debut: f.debut.value });
    const erreurs = { champs: 'Vérifiez la formule et la date de début (dans les six mois).', surface: 'Indiquez la surface d\'eau du bassin, en m².', deja: 'Ce client a déjà un contrat à signer ou en cours avec cette société.',
      societe: 'L\'identité de la société est incomplète.', prix: 'Cette formule n\'a pas encore de prix dans la grille.' };
    const societesDe = (formule) => { const g = d.grille[formule]; return g.pack ? g.pack.map((p) => d.grille[p].societe) : [g.societe]; };
    const recalculer = async () => {
      const g = d.grille[f.formule.value];
      f.querySelector('.bassin').hidden = !g.piscine;
      const surface = parseFloat(f.surface.value.replace(',', '.'));
      f.querySelector('.devis').hidden = !(g.piscine && (f.debordement.checked || surface > d.regle.majoree_m2));
      // Refus par défaut, dit à l'avance : une société incomplète ou déjà engagée bloque le bouton, le serveur refuserait de toute façon.
      const incompletes = societesDe(f.formule.value).filter((s) => d.manques[s].length);
      const prises = societesDe(f.formule.value).filter((s) => occupees.has(s));
      const zm = document.getElementById('manques-societe');
      zm.hidden = !incompletes.length && !prises.length;
      zm.innerHTML = prises.length ? 'Ce client a déjà un contrat en cours avec la ' + prises.map((s) => echapper(d.societes[s])).join(' et la ') + '.'
        : incompletes.map((s) => `Avant le premier contrat de la ${echapper(d.societes[s])}, il manque : ${d.manques[s].map(echapper).join(', ')}.`).join(' ') + ' <a href="#/societe">Compléter</a>';
      f.querySelector('.principal').disabled = !zm.hidden;
      const { client_id, debut, ...params } = choix();
      const t = await api('contrat_tarif', { params });
      const z = document.getElementById('prix-calcule');
      if (!t.ok) { z.className = 'bandeau alerte'; z.textContent = t.erreur === 'devis' ? 'Sur devis : indiquez le prix mensuel de la piscine, au moins ' + t.minimum + ' €.' : erreurs[t.erreur] || 'Prix incomplet.'; return; }
      z.className = 'bandeau calme';
      const detail = t.lignes.length > 1 ? ' (' + t.lignes.map((l) => l.formule + ' ' + eurosRonds(l.prix_mensuel) + (l.remise_pack ? ', remise de pack −' + eurosRonds(l.remise_pack) + ' comprise' : '')).join(' + ') + ')' : '';
      z.textContent = (t.majoration_pct && !t.sur_devis ? 'Majoration grand bassin +' + t.majoration_pct + ' % : ' : t.sur_devis ? 'Sur devis : ' : '') + euros(t.prix_mensuel) + ' par mois' + detail
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
    if (!confirm('Annuler ce contrat avant sa signature ? Pour un pack, les deux contrats sont annulés.')) return;
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
  const dis = d.modifiable ? '' : ' disabled';
  const champ = (libelles, valeurs, k, aide = '') => `<label>${echapper(libelles[k])}${aide ? ` <span class="aide">${aide}</span>` : ''}<input name="${k}" ${k === 'juriste_le' ? 'type="date"' : ''} value="${echapper(valeurs[k] ?? '')}"${dis}></label>`;
  const deux = (lib, val, a, b) => `<div class="deux">${champ(lib, val, a)}${champ(lib, val, b)}</div>`;
  const formSociete = (soc, s) => `
    <h2>${echapper(s.nom)}</h2>
    <p class="bandeau ${s.manques.length ? 'alerte' : 'calme'}">${s.manques.length ? 'Aucun contrat ni aucune facture de cette société ne part tant qu\'il manque : ' + s.manques.map(echapper).join(', ') + '.' : 'Identité complète : ses contrats peuvent être préparés.'}</p>
    <form class="carte" data-societe="${soc}">
      ${deux(d.libelles, s.valeurs, 'raison_sociale', 'forme')}${deux(d.libelles, s.valeurs, 'siret', 'rcs')}
      ${champ(d.libelles, s.valeurs, 'adresse')}
      ${deux(d.libelles, s.valeurs, 'courriel', 'telephone')}
      ${champ(d.libelles, s.valeurs, 'mention_tva')}
      ${deux(d.libelles, s.valeurs, 'mediateur_nom', 'mediateur_site')}${deux(d.libelles, s.valeurs, 'assureur', 'police')}
      ${soc === 'maison' ? champ(d.libelles, s.valeurs, 'sap_numero', 'laissez vide tant que la déclaration n\'est pas obtenue : aucun contrat, aucune facture ne parle alors de crédit d\'impôt') : ''}
      ${d.modifiable ? '<button class="principal">Enregistrer</button>' : ''}
      <p class="message" role="status"></p>
    </form>`;
  const lc = d.libelles_communs;
  const formules = Object.entries(d.grille).filter(([, g]) => !g.pack);
  const pack = Object.entries(d.grille).find(([, g]) => g.pack);
  vue().innerHTML = `
    <a class="retour" href="#/agences">← Agences</a>
    <h1>Les sociétés et leurs contrats</h1>
    <p class="aide">Deux sociétés sous la marque Maison Flamingo : la piscine d'un côté, les services à la maison de l'autre, chacune avec son identité, ses numéros de contrats et de factures, et son compte de prélèvement. Un pack donne deux contrats. Le modèle (${echapper(d.modele)}) cite les articles du Code de la consommation qui s'appliquent ; il doit être relu par un juriste avant le premier client.</p>
    ${Object.entries(d.societes).map(([soc, s]) => formSociete(soc, s)).join('')}
    <h2>Commun aux deux</h2>
    <form class="carte" data-societe="">
      ${deux(lc, d.communs, 'juriste_nom', 'juriste_le')}
      <fieldset class="saisie-groupe"><legend>Révision des prix</legend>
        <p class="aide">Formule du contrat : P = P0 × (0,15 + 0,70 × S/S0 + 0,15 × C/C0). L'indexation sur le niveau général des prix est interdite (article L112-2 du Code monétaire et financier) : S suit le coût du travail des services, C le prix des produits. Le juriste choisit les indices exacts ; mettez à jour leurs valeurs à chaque publication de l'INSEE, chaque nouveau contrat prend les dernières comme référence.</p>
        ${champ(lc, d.communs, 'indice_travail_nom')}${deux(lc, d.communs, 'indice_travail_valeur', 'indice_travail_periode')}
        ${champ(lc, d.communs, 'indice_produits_nom')}${deux(lc, d.communs, 'indice_produits_valeur', 'indice_produits_periode')}
      </fieldset>
      ${d.modifiable ? '<button class="principal">Enregistrer</button>' : '<p class="aide">Seule la direction modifie ces informations.</p>'}
      <p class="message" role="status"></p>
    </form>
    <h2>La grille des prix</h2>
    <form class="carte" id="grille">
      <p class="aide">Prix mensuels TTC, avant majoration grand bassin. Un nouveau prix vaut pour les contrats préparés ensuite : un contrat signé garde le sien. Une formule sans prix ne peut pas être vendue.</p>
      <div class="table-defile"><table><thead><tr><th>Formule</th><th>Prix</th><th>Fondateur</th></tr></thead><tbody>
        ${formules.map(([f, g]) => `<tr><td>${echapper(f)} <span class="sous">${g.societe === 'maison' ? 'société Maison' : 'société Piscine'}${g.engagement ? ', saison de ' + g.engagement + ' mois' : ', sans engagement'}</span></td>
          <td><input name="${g.cle}" inputmode="decimal" aria-label="Prix ${echapper(f)}" value="${g.prix ?? ''}"${dis}></td><td><input name="${g.cle}.fondateur" inputmode="decimal" aria-label="Prix fondateur ${echapper(f)}" value="${g.fondateur ?? ''}"${dis}></td></tr>`).join('')}
      </tbody></table></div>
      <label>Remise du pack, en € par mois sur la ligne piscine<input name="remise_pack" inputmode="decimal" value="${pack ? pack[1].remise : d.remise_pack_defaut}"${dis}></label>
      ${pack ? `<p class="bandeau calme" id="prix-pack">${echapper(pack[0])} : ${pack[1].prix !== null ? eurosRonds(pack[1].prix) : '–'} par mois, ${pack[1].fondateur !== null ? eurosRonds(pack[1].fondateur) : '–'} pour un fondateur. Si la Maison prend fin, la piscine repasse à son prix hors pack à l'échéance suivante.</p>` : ''}
      ${d.modifiable ? '<button class="principal">Enregistrer la grille</button>' : ''}
      <p class="message" role="status"></p>
    </form>`;
  vue().querySelectorAll('form[data-societe]').forEach((f) => f.addEventListener('submit', async (e) => {
    e.preventDefault();
    const corps = Object.fromEntries([...f.querySelectorAll('input')].map((i) => [i.name, i.value.trim()]));
    if (f.dataset.societe) corps.societe = f.dataset.societe;
    const r = await api('societe_enregistrer', { methode: 'POST', corps });
    const reste = r.ok ? Object.entries(r.manques).filter(([, m]) => m.length) : [];
    f.querySelector('.message').textContent = r.ok ? (reste.length ? 'Enregistré. Il manque encore, ' + reste.map(([s, m]) => 'pour la ' + d.societes[s].nom + ' : ' + m.join(', ')).join(' ; ') + '.' : 'Enregistré. Les contrats des deux sociétés peuvent être préparés.')
      : r.erreur === 'indice' ? 'La valeur d\'un indice doit être un nombre positif.' : 'Vérifiez la date de relecture.';
  }));
  const g = document.getElementById('grille');
  g.addEventListener('submit', async (e) => {
    e.preventDefault();
    const corps = Object.fromEntries([...g.querySelectorAll('input')].map((i) => [i.name, i.value.trim().replace(',', '.')]));
    const r = await api('grille_enregistrer', { methode: 'POST', corps });
    g.querySelector('.message').textContent = r.ok ? 'Grille enregistrée.' : r.erreur === 'prix' ? 'Un prix doit être un nombre positif (' + r.champ + ').' : 'La grille n\'a pas été enregistrée.';
    if (r.ok) setTimeout(vueSociete, 900);
  });
}

/* ---------- La facturation : un mois à la fois, vu avant d'être émis ---------- */

const STATUTS_FACTURE = { emise: ['orange', 'Émise'], prelevement_demande: ['orange', 'Prélèvement demandé'], payee: ['vert', 'Payée'], rejetee: ['rouge', 'Rejetée'], annulee: ['', 'Annulée'] };

async function vueFacturation(mois) {
  vue().innerHTML = '<p class="chargement">Chargement…</p>';
  const d = await api('facturation', { params: mois ? { mois } : {} });
  if (!d.ok) return;
  const a = d.apercu;
  const nom = (soc) => (d.societes[soc] || {}).nom || soc;
  const nouvelles = a.lignes.filter((l) => !l.facture && !l.societe_incomplete);
  const bloquees = a.lignes.filter((l) => !l.facture && l.societe_incomplete);
  const uneConfiguree = Object.values(d.societes).some((s) => s.configure);
  vue().innerHTML = `
    <a class="retour" href="#/agences">← Agences</a>
    <div class="tete"><h1>Facturation</h1><input type="month" id="mois" value="${d.mois}" aria-label="Mois"></div>
    ${Object.entries(d.societes).map(([soc, s]) => `<p class="bandeau ${s.manques.length || !s.configure || s.environnement !== 'live' ? 'alerte' : 'calme'}"><strong>${echapper(s.nom)}</strong> · ${s.manques.length ? 'identité incomplète : aucune facture ne part. <a href="#/societe">Compléter</a>'
      : !s.configure ? 'prélèvement non configuré : les factures peuvent être émises, rien n\'est prélevé.' : s.environnement === 'live' ? 'prélèvement en service réel.' : 'prélèvement en bac à sable : rien n\'est réellement prélevé.'}</p>`).join('')}
    <h2>${echapper(d.mois)} <span class="aide">${a.lignes.length} contrat${a.lignes.length > 1 ? 's' : ''} à facturer, ${euros(a.total / 100)} TTC</span></h2>
    ${a.lignes.length ? `<div class="table-defile"><table><thead><tr><th>Contrat</th><th>Société</th><th>Client</th><th>Montant</th><th>Facture</th><th>Mandat</th></tr></thead>
      <tbody>${a.lignes.map((l) => `<tr><td>${echapper(l.contrat)}</td><td>${echapper(nom(l.societe))}</td><td>${echapper(l.client)}</td><td>${euros(l.montant / 100)}</td><td>${l.facture ? echapper(l.facture) : l.societe_incomplete ? '<span class="sous">bloquée : société incomplète</span>' : '<span class="sous">à émettre</span>'}</td><td>${l.mandat ? 'actif' : '<span class="sous">aucun : pas de prélèvement</span>'}</td></tr>`).join('')}</tbody></table></div>`
      : '<p class="aide">Aucun contrat à facturer ce mois-ci.</p>'}
    ${nouvelles.length ? `<div class="carte"><p>${nouvelles.length} facture${nouvelles.length > 1 ? 's' : ''} à émettre${bloquees.length ? ' (' + bloquees.length + ' autre' + (bloquees.length > 1 ? 's' : '') + ' bloquée' + (bloquees.length > 1 ? 's' : '') + ' par une société incomplète)' : ''}. Une facture émise ne se reprend pas : elle s'annule par un avoir.</p>
      <button class="principal" id="emettre">Émettre ${nouvelles.length > 1 ? 'les factures' : 'la facture'}${uneConfiguree ? ' et demander les prélèvements' : ''}</button><p class="message" id="m-emettre" role="status"></p></div>` : ''}
    ${d.factures.length ? `<h2>Factures émises</h2><ul class="liste">${d.factures.map((f) => `<li class="membre"><div class="reco-tete"><strong>${echapper(f.numero)}</strong> <span>${echapper((f.prenom + ' ' + f.nom).trim())} · ${euros(f.montant / 100)}</span>${pastilleDe(STATUTS_FACTURE[f.statut] || ['', f.statut])}</div>
      <span class="sous">${echapper(nom(f.societe))}${f.rejet_motif ? ' · ' + echapper(f.rejet_motif) : ''}</span><a href="api.php?action=facture_document&id=${f.id}" target="_blank" rel="noopener">Voir la facture</a></li>`).join('')}</ul>` : ''}
    <h2>Le prélèvement</h2>
    <p class="aide">GoCardless garde les IBAN et les mandats : l'outil n'en voit aucun. Chaque société a son propre compte GoCardless, car un mandat n'appartient qu'à un créancier : le client d'un pack signe deux mandats. Commencez par le <strong>bac à sable</strong>, faites passer un mois complet, puis seulement le service réel.</p>
    ${Object.entries(d.societes).map(([soc, s]) => `<form class="carte gocardless" data-societe="${soc}">
      <h3>${echapper(s.nom)}</h3>
      <p class="aide">Dans GoCardless, déclarez l'adresse de notification <code class="lien">${echapper(s.adresse_webhook)}</code>.</p>
      <label>Environnement<select name="environnement"><option value="sandbox"${s.environnement !== 'live' ? ' selected' : ''}>Bac à sable</option><option value="live"${s.environnement === 'live' ? ' selected' : ''}>Service réel</option></select></label>
      <div class="deux"><label>Jeton d'accès <span class="aide">jamais réaffiché</span><input name="jeton" type="password" autocomplete="new-password" required></label>
        <label>Secret de l'adresse de notification <span class="aide">jamais réaffiché</span><input name="secret_webhook" type="password" autocomplete="new-password" required></label></div>
      <label>Taux de TVA en % <span class="aide">vide en franchise de TVA : la mention de la société s'affiche alors</span><input name="taux_tva" inputmode="decimal" value="${s.taux_tva ?? ''}"></label>
      <button class="secondaire">${s.configure ? 'Remplacer les accès' : 'Enregistrer'}</button>
      <p class="message" role="status"></p>
    </form>`).join('')}
    <section class="carte">
      <label class="case"><input type="checkbox" id="auto"${d.automatique ? ' checked' : ''}${uneConfiguree ? '' : ' disabled'}> Émettre les factures et demander les prélèvements seul, le 1er de chaque mois</label>
      <p class="aide">À cocher après un premier mois mené à la main. Le relevé du lundi signale ensuite les rejets.</p>
      <p class="message" id="m-auto" role="status"></p>
    </section>`;
  document.getElementById('mois').addEventListener('change', (e) => aller('#/facturation/' + e.target.value));
  const emettre = document.getElementById('emettre');
  if (emettre) emettre.addEventListener('click', async () => {
    if (!confirm('Émettre ces factures ? Elles seront numérotées et ne pourront plus être modifiées.')) return;
    emettre.disabled = true;
    const r = await api('facturation_emettre', { methode: 'POST', corps: { mois: d.mois } });
    document.getElementById('m-emettre').textContent = r.ok ? 'Factures émises' + (r.prelevements && r.prelevements.ok ? ', ' + r.prelevements.demandes + ' prélèvement(s) demandé(s)' + (r.prelevements.echecs.length ? ', ' + r.prelevements.echecs.length + ' échec(s)' : '') : '') + '.' : { mois: 'On ne facture que le mois en cours ou le précédent.' }[r.erreur] || 'Rien n\'a été émis.';
    setTimeout(() => vueFacturation(d.mois), 1500);
  });
  vue().querySelectorAll('form.gocardless').forEach((f) => f.addEventListener('submit', async (e) => {
    e.preventDefault();
    const r = await api('facturation_configurer', { methode: 'POST', corps: { societe: f.dataset.societe, environnement: f.environnement.value, jeton: f.jeton.value.trim(), secret_webhook: f.secret_webhook.value.trim(), taux_tva: f.taux_tva.value.trim() } });
    f.jeton.value = ''; f.secret_webhook.value = '';
    f.querySelector('.message').textContent = r.ok ? 'Accès enregistrés.' : r.erreur === 'environnement' ? 'Ce jeton ne correspond pas à l\'environnement choisi (un jeton de bac à sable commence par « sandbox_ »).' : 'Les deux champs sont nécessaires.';
    if (r.ok) setTimeout(() => vueFacturation(d.mois), 900);
  }));
  document.getElementById('auto').addEventListener('change', async (e) => {
    const r = await api('facturation_automatique', { methode: 'POST', corps: { actif: e.target.checked } });
    document.getElementById('m-auto').textContent = r.ok ? (r.actif ? 'Activé : prochaine émission le 1er du mois.' : 'Coupé : la facturation se fait à la main.') : 'Configurez d\'abord le prélèvement.';
  });
}
