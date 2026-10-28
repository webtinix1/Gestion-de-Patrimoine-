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

    window.addEventListener('beforeunload', function () { quitte = true; });

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
            ctx.onFailure(function (infos) {
                var erreur = infos && infos.error;

                if (erreur && erreur.name === 'AbortError') { return; }

                ouvrir('injoignable');
            });

            ctx.onError(function (infos) {
                var nom = statuts[infos.response.status];

                if (!nom || (debug && infos.response.status === 500)) { return; }

                infos.preventDefault();
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
