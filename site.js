// Maison Flamingo · comportements du site, sans dépendance.

// Adresse d'un service de formulaire (Tally, Formspree, n8n...). Tant qu'elle
// est vide, l'inscription part par courriel : rien ne se perd, même sans serveur.
const ADRESSE_FORMULAIRE = '';
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
      date: new Date().toISOString(),
    };

    if (ADRESSE_FORMULAIRE) {
      try {
        const rep = await fetch(ADRESSE_FORMULAIRE, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
          body: JSON.stringify(donnees),
        });
        if (!rep.ok) throw new Error(String(rep.status));
        form.reset();
        dire('Merci ' + donnees.prenom + ', votre place est notée. Nous vous écrivons en mars.', 'ok');
      } catch (e) {
        dire('L\'envoi n\'a pas abouti. Écrivez-nous à ' + COURRIEL_CONTACT + ' ou au 06 74 85 90 78.', 'erreur');
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
