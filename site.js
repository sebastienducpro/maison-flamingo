// Maison Flamingo · comportements du site, sans dépendance.

// L'inscription part vers inscription.php, qui l'envoie à contact@ et l'archive.
// Si on vide cette adresse, le site repasse à l'ouverture de la messagerie du visiteur.
const ADRESSE_FORMULAIRE = 'inscription.php';
const COURRIEL_CONTACT = 'contact@maisonflamingo.fr';

document.documentElement.classList.add('js');

// La barre de navigation devient crème dès qu'on quitte le coucher de soleil,
// sinon le texte crème se perd sur les sections claires.
const nav = document.getElementById('nav');
const hero = document.querySelector('.hero');
if (nav && hero) {
  const surveiller = new IntersectionObserver(([e]) => {
    nav.classList.toggle('colle', !e.isIntersecting);
  }, { rootMargin: '-80px 0px 0px 0px' });
  surveiller.observe(hero);
  // Dans le coucher de soleil, un verre fumé dès qu'on défile : sans lui, le
  // texte de la page passe sous la barre et les deux se mélangent.
  const verre = () => nav.classList.toggle('defile', window.scrollY > 8);
  window.addEventListener('scroll', verre, { passive: true });
  verre();
}

// Apparition douce des blocs ; sans le script, ils restent simplement visibles.
const blocs = document.querySelectorAll('.revele');
if ('IntersectionObserver' in window) {
  const apparaitre = new IntersectionObserver((entrees) => {
    entrees.forEach((e) => {
      if (e.isIntersecting) { e.target.classList.add('vu'); apparaitre.unobserve(e.target); }
    });
  }, { threshold: 0.12 });
  blocs.forEach((b) => apparaitre.observe(b));
} else {
  blocs.forEach((b) => b.classList.add('vu'));
}

const annee = document.getElementById('annee');
if (annee) annee.textContent = new Date().getFullYear();

// Liste d'attente
const form = document.getElementById('formulaire');
const message = document.getElementById('form-message');

function dire(texte, type) {
  message.textContent = texte;
  message.className = 'form-message ' + (type || '');
}

if (form) {
  form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    // Un robot remplit le champ piège : on fait comme si tout allait bien.
    if (form.site.value) { dire('Merci, votre place est notée.', 'ok'); return; }

    const obligatoires = ['prenom', 'nom', 'telephone', 'commune'];
    let manque = false;
    obligatoires.forEach((n) => {
      const champ = form.elements[n];
      const vide = !champ.value.trim();
      champ.classList.toggle('invalide', vide);
      if (vide) manque = true;
    });
    if (manque) { dire('Il manque un prénom, un nom, un téléphone ou une commune.', 'erreur'); return; }
    if (!form.consentement.checked) { dire('Cochez la case d\'accord pour que nous puissions vous recontacter.', 'erreur'); return; }

    const donnees = {
      prenom: form.prenom.value.trim(),
      nom: form.nom.value.trim(),
      telephone: form.telephone.value.trim(),
      courriel: form.courriel.value.trim(),
      commune: form.commune.value,
      interets: [...form.querySelectorAll('input[name=interet]:checked')].map((c) => c.value),
      consentement: true,
      site: form.site.value,
    };

    if (ADRESSE_FORMULAIRE) {
      const bouton = form.querySelector('button[type=submit]');
      bouton.disabled = true;
      dire('Envoi en cours…');
      try {
        const rep = await fetch(ADRESSE_FORMULAIRE, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
          body: JSON.stringify(donnees),
        });
        if (rep.status === 429) {
          dire('Plusieurs demandes viennent d\'arriver de chez vous : nous les avons bien. Réessayez dans une heure si besoin.', 'erreur');
        } else if (rep.status === 422) {
          dire('Un champ n\'est pas lisible : vérifiez votre téléphone et votre courriel.', 'erreur');
        } else if (!rep.ok) {
          throw new Error(String(rep.status));
        } else {
          form.reset();
          dire('Merci ' + donnees.prenom + ', votre place est notée. Nous vous écrivons en mars.', 'ok');
        }
      } catch (e) {
        dire('L\'envoi n\'a pas abouti. Écrivez-nous à ' + COURRIEL_CONTACT + ' ou au 06 74 85 90 78.', 'erreur');
      } finally {
        bouton.disabled = false;
      }
      return;
    }

    const corps = [
      'Bonjour,', '', 'Je souhaite réserver ma place fondateur.', '',
      'Prénom : ' + donnees.prenom,
      'Nom : ' + donnees.nom,
      'Téléphone : ' + donnees.telephone,
      'Courriel : ' + (donnees.courriel || '-'),
      'Commune : ' + donnees.commune,
      'Intérêt : ' + (donnees.interets.join(', ') || '-'),
    ].join('\n');
    window.location.href = 'mailto:' + COURRIEL_CONTACT +
      '?subject=' + encodeURIComponent('Place fondateur · ' + donnees.prenom + ' ' + donnees.nom) +
      '&body=' + encodeURIComponent(corps);
    dire('Votre messagerie s\'ouvre avec votre demande : il ne reste qu\'à l\'envoyer.', 'ok');
  });
}
