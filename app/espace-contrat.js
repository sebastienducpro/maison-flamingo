// Maison Flamingo · le contrat, côté client : le lire, le signer, se rétracter dans les quatorze jours, résilier en ligne.
// Le serveur décide de tout (qui signe, jusqu'à quand on se rétracte, quand la résiliation prend effet) ; cette page affiche.
'use strict';

const MOTS_CONTRAT = { a_signer: 'À signer', signe: 'Signé', retracte: 'Rétracté', resilie: 'Résilié' };
const jourFr = (iso) => new Date(iso + 'T12:00:00').toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' });

async function vueContratClient(message = '') {
  zone().innerHTML = '<p class="attente">Chargement…</p>';
  const d = await appel('espace_contrat');
  if (!d.ok) return vueConnexionClient();
  const carte = (k) => `
    <section class="carte-espace" data-id="${k.id}">
      <p class="surtitre">Contrat ${echapper(k.numero)} · ${MOTS_CONTRAT[k.statut] || ''}</p>
      <h2>${echapper(k.formule)} · ${k.modalite === 'annuel' ? Number(k.montant_annuel).toLocaleString('fr-FR') + ' € TTC par an, payés d\'avance' : Number(k.prix_mensuel).toLocaleString('fr-FR', { minimumFractionDigits: 2 }) + ' € TTC par mois'}</h2>
      <p>Début le ${echapper(jourFr(k.debut))}${k.engagement_mois ? `, pour une saison de ${k.engagement_mois} mois reconduite avec rappel écrit` : ', sans engagement'}${k.fondateur ? ' · offre fondateur' : ''}.</p>
      <p><a class="bouton" href="api.php?action=contrat_document&id=${k.id}" target="_blank" rel="noopener">Lire le contrat complet</a></p>
      ${k.statut === 'a_signer' ? `
      <form class="signer">
        <label class="case-client"><input type="checkbox" name="lu" required> J'ai lu le contrat complet, ses conditions et le formulaire de rétractation.</label>
        <label class="case-client"><input type="checkbox" name="execution_anticipee"> Je demande que le service commence avant la fin de mon délai de rétractation de ${d.retractation_jours} jours. Si je me rétracte ensuite, je paie la part du service déjà faite.</label>
        <label>Pour signer, tapez vos prénom et nom<input name="nom" autocomplete="name" required></label>
        <button>Signer le contrat</button>
        <p class="message" role="status"></p>
      </form>` : ''}
      ${k.statut === 'signe' ? `<p class="aide">Signé le ${echapper(dateFr(k.signe_le))} par ${echapper(k.signe_nom)}.${k.peut_se_retracter ? ` Vous pouvez vous rétracter jusqu'au ${echapper(jourFr(k.retractation_jusquau))}, sans motif ni frais.` : ''}</p>
        <div class="actions-espace">
          ${k.peut_se_retracter ? '<button class="secondaire" data-retracter>Me rétracter</button>' : ''}
          <button class="secondaire" data-resilier>Résilier mon contrat</button>
        </div>
        <form class="resilier" hidden>
          <label>La raison <span class="aide">elle peut changer la date d'effet</span><select name="motif">
            <option value="autre">Je préfère ne pas la donner</option><option value="vente">Vente ou déménagement</option><option value="emploi">Perte d'emploi</option><option value="deces">Décès</option></select></label>
          <button>Confirmer la résiliation</button>
          <p class="message" role="status"></p>
        </form>` : ''}
      ${k.statut === 'resilie' ? `<p class="aide">Résiliation reçue le ${echapper(dateFr(k.resilie_le))}, effective le ${echapper(jourFr(k.resiliation_effet))}.</p>` : ''}
      ${k.statut === 'retracte' ? `<p class="aide">Rétractation reçue le ${echapper(dateFr(k.resilie_le))}.</p>` : ''}
    </section>`;
  zone().innerHTML = `
    <section class="tete-carnet courte"><p class="surtitre">Mon contrat</p><h1>Votre abonnement, noir sur blanc.</h1></section>
    ${message ? `<p class="verdict ok">${message}</p>` : ''}
    ${d.contrats.length ? d.contrats.map(carte).join('') : '<section class="carte-espace"><p>Aucun contrat pour l\'instant. Votre Flamingo vous le préparera après le diagnostic.</p></section>'}
    <div id="factures-client"></div>
    <p class="compte-liens"><a href="#/sante">← Mon carnet</a></p>`;
  window.scrollTo(0, 0);
  if (d.contrats.some((k) => k.statut === 'signe')) facturesClient();

  zone().querySelectorAll('section[data-id]').forEach((s) => {
    const id = +s.dataset.id;
    const signer = s.querySelector('form.signer');
    if (signer) signer.addEventListener('submit', async (e) => {
      e.preventDefault();
      const r = await appel('espace_contrat_signer', { methode: 'POST', corps: { id, lu: signer.lu.checked, execution_anticipee: signer.execution_anticipee.checked, nom: signer.nom.value } });
      if (r.ok) return vueContratClient('Contrat signé. Il reste disponible ici, et nous en sommes prévenus.');
      signer.querySelector('.message').textContent = r.erreur === 'champs' ? 'Cochez la première case et tapez votre nom tel qu\'il figure au contrat' + (r.attendu ? ' : ' + r.attendu : '') + '.' : 'La signature n\'a pas abouti. Appelez-nous.';
    });
    const retracter = s.querySelector('[data-retracter]');
    if (retracter) retracter.addEventListener('click', async () => {
      if (!confirm('Vous rétracter de ce contrat ? Il prend fin, sans frais.')) return;
      const r = await appel('espace_contrat_retracter', { methode: 'POST', corps: { id } });
      vueContratClient(r.ok ? 'Votre rétractation est enregistrée. Nous vous écrivons pour la confirmer.' : '');
    });
    const ouvrir = s.querySelector('[data-resilier]');
    const resilier = s.querySelector('form.resilier');
    if (ouvrir) ouvrir.addEventListener('click', () => { resilier.hidden = false; ouvrir.hidden = true; });
    if (resilier) resilier.addEventListener('submit', async (e) => {
      e.preventDefault();
      const r = await appel('espace_resilier', { methode: 'POST', corps: { id, motif: resilier.motif.value } });
      // L'accusé de réception s'affiche tout de suite, avec la date et l'heure de la demande et la date d'effet.
      if (r.ok) return vueContratClient(`Votre demande de résiliation est bien reçue, le ${dateFr(r.recu_le)}. Elle prend effet le ${jourFr(r.effet)}. Gardez cette page : elle vaut accusé de réception.`);
      resilier.querySelector('.message').textContent = 'La résiliation n\'a pas abouti. Écrivez-nous ou appelez-nous.';
    });
  });
}

// Le prélèvement et les factures : le mandat se pose sur la page de GoCardless, l'IBAN ne passe jamais par Maison Flamingo.
async function facturesClient() {
  const d = await appel('espace_factures');
  const z = document.getElementById('factures-client');
  if (!d.ok || !z) return;
  const MOTS = { emise: 'émise', prelevement_demande: 'prélèvement en cours', payee: 'payée', rejetee: 'prélèvement rejeté', annulee: 'annulée' };
  z.innerHTML = `
    ${d.prelevement_possible && d.mandat !== 'actif' ? `<section class="carte-espace"><h2>Le prélèvement mensuel</h2>
      <p>${d.mandat === 'en_attente' ? 'Votre autorisation de prélèvement est en cours de validation.' : 'Votre abonnement se règle par prélèvement SEPA. Vous saisissez votre IBAN sur la page sécurisée de GoCardless, notre prestataire de paiement : il ne passe jamais par Maison Flamingo.'}</p>
      ${d.mandat === 'en_attente' ? '' : '<button id="mandat">Mettre en place le prélèvement</button><p class="message" id="m-mandat" role="status"></p>'}</section>` : ''}
    ${d.mandat === 'actif' ? '<p class="aide">Prélèvement SEPA en place.</p>' : ''}
    ${d.factures.length ? `<h2 class="intertitre">Mes factures</h2><ul class="postes-client">${d.factures.map((f) => `<li><div><a href="api.php?action=facture_document&id=${f.id}" target="_blank" rel="noopener"><strong>${echapper(f.numero)}</strong></a><span>${(f.montant / 100).toLocaleString('fr-FR', { minimumFractionDigits: 2 })} € · ${echapper(MOTS[f.statut] || f.statut)}</span></div></li>`).join('')}</ul>` : ''}`;
  const b = document.getElementById('mandat');
  if (b) b.addEventListener('click', async () => {
    b.disabled = true;
    const r = await appel('espace_mandat', { methode: 'POST', corps: {} });
    if (r.ok && /^https:\/\//.test(r.url)) { location.href = r.url; return; }
    document.getElementById('m-mandat').textContent = 'La page de prélèvement n\'est pas disponible pour l\'instant. Réessayez plus tard ou appelez-nous.';
    b.disabled = false;
  });
}

// Sur l'accueil : un contrat à signer s'annonce en tête, sans masquer le carnet.
async function annoncerContrat() {
  const d = await appel('espace_contrat').catch(() => null);
  if (!d || !d.ok || !d.contrats.some((k) => k.statut === 'a_signer')) return;
  const tete = zone().querySelector('.tete-carnet');
  if (tete && !zone().querySelector('.annonce-contrat')) tete.insertAdjacentHTML('afterend', '<p class="verdict a-voir annonce-contrat">Votre contrat est prêt. <a href="#/contrat">Le lire et le signer</a></p>');
}
