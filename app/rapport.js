// Maison Flamingo · le rapport que reçoit le client.
// Il se lit sur un téléphone, en trente secondes, et dit d'abord si tout va bien.
'use strict';

(async function () {
  const zone = document.getElementById('rapport');
  const t = new URLSearchParams(location.search).get('t') || '';
  let d = null;
  try { d = await (await fetch('api.php?action=rapport&t=' + encodeURIComponent(t), { credentials: 'omit' })).json(); } catch (e) { d = null; }
  if (!d || !d.ok) {
    zone.innerHTML = '<section class="bloc"><h1>Ce rapport est introuvable</h1><p>Le lien est peut-être incomplet. Appelez-nous au 06 74 85 90 78, nous vous le renvoyons.</p></section>';
    return;
  }
  const r = d.rapport, c = r.contenu || {}, v = c.valeurs || {}, etats = c.etats || {};
  const form = FORMULAIRES[r.type] || FORMULAIRES.passage;
  const diag = r.type === 'diagnostic';
  document.title = (diag ? 'Le diagnostic de votre piscine' : 'Votre rapport de passage') + ' · Maison Flamingo';

  // Le verdict d'abord : ce que le client veut savoir avant tout le reste.
  const alertes = [];
  Object.keys(MESURES).forEach((k) => { const n = niveau(k, v[k]); if (n === 'orange' || n === 'rouge') alertes.push(n); });
  const nc = niveauCombine(chloreCombine(v)); if (nc === 'orange' || nc === 'rouge') alertes.push(nc);
  Object.values(etats).forEach((e) => { if (e && (e.etat === 'surveiller' || e.etat === 'prevoir')) alertes.push(e.etat); });
  const nb = alertes.length;
  const forts = alertes.filter((a) => a === 'rouge' || a === 'prevoir').length, faibles = nb - forts;
  const mot = (n) => ['', 'un', 'deux', 'trois', 'quatre', 'cinq', 'six', 'sept', 'huit', 'neuf'][n] || String(n);
  const morceau = (n, quoi) => mot(n) + (n > 1 ? ' points ' : ' point ') + quoi;
  const phrase = [forts ? morceau(forts, 'à corriger') : '', faibles ? (forts ? mot(faibles) + ' à surveiller' : morceau(faibles, 'à surveiller')) : ''].filter(Boolean).join(' et ');
  const verdict = nb === 0 ? 'Tout est en ordre.' : phrase.charAt(0).toUpperCase() + phrase.slice(1) + '.';

  const tuile = (k) => {
    const m = MESURES[k], n = niveau(k, v[k]);
    if (v[k] === undefined || v[k] === '') return '';
    return `<div class="tuile ${n}"><span class="t-lib">${m.libelle}</span><span class="t-val">${nombreFr(v[k])}<small>${m.unite ? ' ' + m.unite : ''}</small></span><span class="t-niv">${n ? MOTS_NIVEAU[n] : ''}</span></div>`;
  };

  const sections = form.sections.filter((s) => !s.interne && s.rapport !== false).map((s) => {
    let tuiles = '', lignes = '';
    s.champs.forEach((ch) => {
      if (ch.type === 'mesure') {
        tuiles += tuile(ch.cle);
        if (ch.cle === 'chlore_total') {
          const cc = chloreCombine(v), ncc = niveauCombine(cc);
          if (cc !== null) tuiles += `<div class="tuile ${ncc}"><span class="t-lib">Chlore combiné</span><span class="t-val">${nombreFr(cc)}<small> mg/L</small></span><span class="t-niv">${MOTS_NIVEAU[ncc]}</span></div>`;
        }
      } else if (ch.type === 'etat') {
        const e = etats[ch.cle];
        if (e && e.etat) lignes += `<li><span class="point ${e.etat}"></span><span>${echapper(ch.libelle)}</span><span class="etat-mot">${ETATS[e.etat]}${e.note ? ' · ' + echapper(e.note) : ''}</span></li>`;
      } else if (ch.type === 'volume') {
        if (c.volume) lignes += `<li><span class="point neutre"></span><span>Volume estimé</span><span class="etat-mot">${c.volume} m³</span></li>`;
      } else if (ch.type === 'multi') {
        const l = Array.isArray(v[ch.cle]) ? v[ch.cle] : [];
        if (l.length) lignes += l.map((x) => `<li><span class="point bon"></span><span>${echapper(x)}</span><span class="etat-mot"></span></li>`).join('');
      } else if (['choix', 'ouinon', 'nombre', 'texte'].includes(ch.type)) {
        if (v[ch.cle] !== undefined && v[ch.cle] !== '') lignes += `<li><span class="point neutre"></span><span>${echapper(ch.libelle)}</span><span class="etat-mot">${ch.type === 'nombre' ? nombreFr(v[ch.cle]) : echapper(v[ch.cle])}${ch.unite ? ' ' + echapper(ch.unite) : ''}</span></li>`;
      }
    });
    if (!tuiles && !lignes) return '';
    return `<section class="bloc"><h2>${echapper(s.titre)}</h2>${tuiles ? `<div class="tuiles">${tuiles}</div>` : ''}${lignes ? `<ul class="lignes">${lignes}</ul>` : ''}</section>`;
  }).join('');

  const constats = (c.constats || []).filter((x) => x.texte);
  const blocConstats = constats.length ? `<section class="bloc"><h2>Ce qu'on a vu</h2><ul class="constats">${constats.map((x) => `<li><span class="point ${x.niveau || 'neutre'}"></span><p>${echapper(x.texte)}</p></li>`).join('')}</ul></section>` : '';

  const blocPhotos = r.photos.length ? `<section class="bloc"><h2>Les photos</h2><div class="photos">${r.photos.map((p) => `<a href="${p.url}" target="_blank" rel="noopener"><img src="${p.url}" alt="${echapper(p.legende || 'Photo de la visite')}" loading="lazy">${p.legende ? `<span>${echapper(p.legende)}</span>` : ''}</a>`).join('')}</div></section>` : '';

  let blocProposition = '';
  const p = c.proposition || {};
  if (diag && TARIFS[p.formule]) {
    const t = TARIFS[p.formule];
    const sms = 'sms:+33674859078' + (/iPhone|iPad|iPod/.test(navigator.userAgent) ? '&' : '?') + 'body=' + encodeURIComponent('Bonjour, je souhaite réserver mon jour de passage Maison Flamingo.');
    // Le prix est celui figé dans la visite ; un bassin hors normes ou une formule sans prix se disent sans chiffre.
    const tarif = p.fondateur === false ? 'tarif standard' : 'tarif fondateurs';
    blocProposition = `<section class="bloc proposition">
      <h2>Notre proposition</h2>
      <p class="formule">${echapper(p.libelle || t.libelle)}</p>
      ${p.prix !== null && p.prix !== undefined ? `<p class="prix">${p.prix} € <small>TTC par mois, ${tarif}</small></p>` : `<p class="prix"><small>${p.raison === 'devis' ? 'Sur devis, après mesure de votre bassin' : 'Prix sur demande'}</small></p>`}
      <p class="detail">${echapper(t.detail)}${p.supplement ? '. Le tarif tient compte d\'un bassin de plus de ' + SURFACE_STANDARD + ' m²' : ''}.</p>
      ${p.note ? `<p class="mot">« ${echapper(p.note)} »</p>` : ''}
      <div class="actions"><a class="bouton" href="${sms}">Réserver mon jour de passage</a><a class="bouton clair" href="tel:+33674859078">Nous appeler</a></div>
      <p class="petit-mot">Ce diagnostic vous est offert. Il ne vous engage à rien.</p>
    </section>`;
  }

  zone.innerHTML = `
    <section class="titre">
      <p class="surtitre">${diag ? 'Diagnostic' : 'Rapport de passage'}</p>
      <h1>${diag ? 'Le diagnostic de votre piscine' : 'Votre Flamingo est passé'}</h1>
      <p class="quand">${echapper(dateFr(r.quand))}${r.commune ? ' · ' + echapper(r.commune) : ''} · par ${echapper(r.auteur)}</p>
      <p class="verdict ${nb ? 'a-voir' : 'ok'}">${verdict}</p>
    </section>
    ${sections}${blocConstats}${blocPhotos}${blocProposition}
    ${r.equipe && r.equipe.length ? `<section class="bloc equipe-rapport"><h2>Qui est passé</h2><ul>${r.equipe.map((p) => `<li>
      <div class="visage">${p.photo ? `<img src="${echapper(p.photo)}" alt="">` : `<span class="initiale">${echapper(p.prenom.charAt(0))}</span>`}</div>
      <div><strong>${echapper(p.prenom)}</strong><span>${echapper(p.titre)}</span>${p.telephone ? `<a href="tel:${p.telephone.replace(/[^\d+]/g, '')}">${echapper(p.telephone)}</a>` : ''}</div></li>`).join('')}</ul></section>` : ''}`;
})();
