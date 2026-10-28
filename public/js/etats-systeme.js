/* États système du panneau (docs/04, E44) : session expirée, serveur injoignable, erreur, accès refusé.
 * Remplace les fenêtres anglaises de Livewire. Aucune dépendance, aucune ressource externe. */
(function () {
    'use strict';

    var racine = document.getElementById('etats-systeme');

    if (!racine) {
        return;
    }

    var sante = racine.getAttribute('data-sante') || '/up';
    var debug = racine.getAttribute('data-debug') === '1';
    var quitte = false;

    var sessionPerdue = false;

    // M8 : « beforeunload » part aussi quand l'utilisateur annule la fermeture ; le drapeau retombe donc vite,
    // et à chaque retour sur la page (cache avant/arrière).
    window.addEventListener('beforeunload', function () {
        quitte = true;
        setTimeout(function () { quitte = false; }, 2000);
    });
    window.addEventListener('pageshow', function () { quitte = false; });

    function fenetre(nom) {
        return document.getElementById('etat-systeme-' + nom);
    }

    function fermerTout() {
        racine.querySelectorAll('dialog[open]').forEach(function (d) { d.close(); });
    }

    function ouvrir(nom) {
        var d = fenetre(nom);

        if (!d || d.open || quitte) {
            return;
        }

        fermerTout();

        if (typeof d.showModal === 'function') {
            d.showModal();
        } else {
            d.setAttribute('open', '');
        }
    }

    function joignable() {
        return fetch(sante, { cache: 'no-store', credentials: 'omit' }).then(function (r) { return r.ok; }, function () { return false; });
    }

    function reessayer(bouton) {
        var d = bouton.closest('dialog');
        var suivi = d ? d.querySelector('[data-suivi]') : null;

        if (suivi) { suivi.textContent = 'Vérification en cours…'; }

        joignable().then(function (ok) {
            if (ok) {
                if (d) { d.close(); }
            } else if (suivi) {
                suivi.textContent = 'Le serveur ne répond toujours pas.';
            }
        });
    }

    racine.addEventListener('click', function (e) {
        var cible = e.target.closest('[data-action]');

        if (!cible) { return; }

        var action = cible.getAttribute('data-action');

        if (action === 'recharger') { window.location.reload(); }
        if (action === 'fermer') { cible.closest('dialog').close(); }
        if (action === 'reessayer') { reessayer(cible); }
    });

    window.addEventListener('offline', function () { ouvrir('injoignable'); });

    window.addEventListener('online', function () {
        var d = fenetre('injoignable');

        if (d && d.open) { joignable().then(function (ok) { if (ok) { d.close(); } }); }
    });

    var statuts = { 403: 'acces', 419: 'session', 503: 'maintenance', 500: 'erreur', 502: 'injoignable', 504: 'injoignable' };

    function brancher() {
        window.Livewire.interceptRequest(function (ctx) {
            // I8 : la session est perdue, Livewire n'a pas posé son propre drapeau (nous avons empêché sa fenêtre) :
            // on annule les requêtes suivantes (wire:poll, etc.) jusqu'au rechargement de la page.
            if (sessionPerdue) {
                try { ctx.request.cancel(); } catch (e) { /* requête déjà partie */ }

                return;
            }

            ctx.onFailure(function (infos) {
                var erreur = infos && infos.error;

                if (erreur && erreur.name === 'AbortError') { return; }

                ouvrir('injoignable');
            });

            ctx.onError(function (infos) {
                var nom = statuts[infos.response.status];

                if (!nom || (debug && infos.response.status === 500)) { return; }

                infos.preventDefault();

                if (infos.response.status === 419) { sessionPerdue = true; }

                ouvrir(nom);
            });
        });
    }

    if (window.Livewire && typeof window.Livewire.interceptRequest === 'function') {
        brancher();
    } else {
        document.addEventListener('livewire:init', brancher);
    }
})();
