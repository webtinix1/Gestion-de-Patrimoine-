{{-- E44 : fenêtres d'état affichées par public/js/etats-systeme.js (PanelsRenderHook::BODY_END). --}}
<link rel="stylesheet" href="{{ asset('css/etats-systeme.css') }}">
<div id="etats-systeme" data-sante="{{ url('/up') }}" data-debug="{{ config('app.debug') ? '1' : '0' }}">
    <dialog id="etat-systeme-injoignable" class="etat-fenetre" aria-labelledby="etat-injoignable-titre">
        <div class="etat-carte">
            <h2 id="etat-injoignable-titre" class="etat-titre">Connexion au serveur perdue</h2>
            <p class="etat-texte">Impossible de joindre le serveur de l'application. Vérifiez le câble ou le Wi-Fi, et que le poste serveur est allumé. Vos saisies en cours restent affichées sur cet écran : n'actualisez pas la page, puis refaites l'action une fois la connexion revenue.</p>
            <p class="etat-suivi" data-suivi role="status"></p>
            <div class="etat-actions">
                <button type="button" class="etat-btn" data-action="fermer">Fermer</button>
                <button type="button" class="etat-btn primaire" data-action="reessayer">Réessayer</button>
            </div>
        </div>
    </dialog>

    <dialog id="etat-systeme-session" class="etat-fenetre" aria-labelledby="etat-session-titre">
        <div class="etat-carte">
            <h2 id="etat-session-titre" class="etat-titre">Votre session a expiré</h2>
            <p class="etat-texte">Par sécurité, vous êtes déconnecté après une période d'inactivité. Reconnectez-vous pour continuer : vous reviendrez sur la page en cours. Les saisies non enregistrées de cette page seront perdues.</p>
            <div class="etat-actions">
                <button type="button" class="etat-btn primaire" data-action="recharger">Se reconnecter</button>
            </div>
        </div>
    </dialog>

    <dialog id="etat-systeme-acces" class="etat-fenetre" aria-labelledby="etat-acces-titre">
        <div class="etat-carte">
            <h2 id="etat-acces-titre" class="etat-titre">Vous n'avez pas accès à cette action</h2>
            <p class="etat-texte">Votre rôle ne permet pas cette opération. Contactez l'administrateur si besoin.</p>
            <div class="etat-actions">
                <button type="button" class="etat-btn primaire" data-action="fermer">Fermer</button>
            </div>
        </div>
    </dialog>

    <dialog id="etat-systeme-maintenance" class="etat-fenetre" aria-labelledby="etat-maintenance-titre">
        <div class="etat-carte">
            <h2 id="etat-maintenance-titre" class="etat-titre">Maintenance en cours</h2>
            <p class="etat-texte">L'application est momentanément indisponible (mise à jour ou sauvegarde en cours). Réessayez dans quelques minutes.</p>
            <p class="etat-suivi" data-suivi role="status"></p>
            <div class="etat-actions">
                <button type="button" class="etat-btn" data-action="fermer">Fermer</button>
                <button type="button" class="etat-btn primaire" data-action="reessayer">Réessayer</button>
            </div>
        </div>
    </dialog>

    <dialog id="etat-systeme-erreur" class="etat-fenetre" aria-labelledby="etat-erreur-titre">
        <div class="etat-carte">
            <h2 id="etat-erreur-titre" class="etat-titre">Une erreur est survenue</h2>
            <p class="etat-texte">L'opération n'a pas pu être menée à bien et n'a pas été enregistrée. Réessayez ; si le problème persiste, prévenez l'administrateur en lui indiquant ce que vous faisiez.</p>
            <div class="etat-actions">
                <button type="button" class="etat-btn" data-action="fermer">Fermer</button>
                <button type="button" class="etat-btn primaire" data-action="recharger">Recharger la page</button>
            </div>
        </div>
    </dialog>
</div>
<script src="{{ asset('js/etats-systeme.js') }}"></script>
