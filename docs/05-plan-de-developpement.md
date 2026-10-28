# Plan de développement

Treize lots, à faire dans l'ordre : chaque lot s'appuie sur les précédents. On lance un lot avec `/module <numéro>` dans Claude Code, on le relit avec `/revue`, puis un humain teste et fusionne. Les durées indiquées sont des jours ouvrés pour un développeur qui pilote Claude Code, relecture comprise.

**Un lot est terminé quand :** tous ses critères sont cochés ; `php artisan test`, Pint et Larastan passent ; un humain a testé les écrans du lot avec le rôle Gestionnaire et le rôle Responsable de département ; le journal technique est à jour.

## Lot 0. Socle (1 j)

Projet Laravel 13 + Filament 5, panneau `/admin`, langue française, fuseau `Africa/Brazzaville`, thème (couleurs, polices locales Archivo et Martian Mono), Pest, Pint, Larastan, seeders de base.

- [ ] L'application démarre sans aucun appel réseau externe (vérifié avec le Wi-Fi coupé). *Vérifié par les tests `HorsLigneTest` et par l'inspection des requêtes du navigateur (toutes vers localhost) ; reste l'essai Wi-Fi coupé, à faire par un humain.*
- [x] Connexion par identifiant (pas par e-mail).
- [x] Interface entièrement en français, y compris les messages de validation.
- [x] Le thème reprend couleurs et polices de `docs/04`.

## Lot 1. Structure et référentiels (1 j) · E34 à E37 · RG-11

Sites, départements (multi-sites), services, locaux, catégories (préfixe, règles par défaut), états, fournisseurs, types de documents.

- [x] Création, modification, désactivation ; pas de suppression d'un élément utilisé.
- [x] Départements affichés avec leurs services.
- [x] Seeders de référence (états, catégories par défaut) et de démonstration.

## Lot 2. Utilisateurs, droits et journal (1,5 j) · E01, E04, E38 à E40 · RG-70 à RG-72, RG-80 à RG-85

- [x] Cinq rôles livrés avec la matrice de droits par défaut ; matrice modifiable (Shield). *Matrice faite sans Shield, voir le journal technique.*
- [x] Périmètre par sites et départements appliqué par un scope global sur toutes les requêtes métier. *Appliqué aux sites, départements, services et locaux ; les biens le recevront au lot 3.*
- [x] Blocage après 5 échecs, déconnexion après inactivité, mot de passe provisoire à changer.
- [x] Désactivation d'un compte sans perte d'historique.
- [x] Journal des opérations en lecture seule avec avant et après et IP.
- [x] Test : un responsable de département ne voit aucun bien hors de son périmètre, même par l'URL directe. *Testé au lot 3 sur les biens : liste, URL directe, recherche par code, exports, documents, photo et journal.*

## Lot 3. Biens (2 j) · E05 à E08, E28, E43 · RG-01 à RG-05, RG-10 à RG-14, RG-65, RG-66, RG-86

- [x] Génération du numéro dans les trois formats, avec test de concurrence (créations simultanées sans doublon). *Test en processus parallèles sur SQLite et sur MariaDB.*
- [x] Numéro jamais modifiable ni réutilisé.
- [x] Liste avec tous les filtres de E05, recherche globale, exports.
- [x] Fiche avec onglets (ceux des lots suivants sont ajoutés au fil de l'eau).
- [x] Recherche par code avec douchette : saisie + Entrée ouvre la fiche ; code inconnu géré. *Test réel avec la douchette du client au lot 4.*
- [x] Photo et documents rattachés, stockés hors du dossier public.
- [x] Modification simultanée détectée (RG-86).

## Lot 4. Étiquettes (1 j) · E09, E10 · RG-95 à RG-97

- [x] Modèles paramétrables avec aperçu.
- [x] PDF planche A4 3 × 8 et rouleau 50 × 25 mm à l'échelle exacte, QR code ou Code 128.
- [x] Case de départ sur planche entamée ; impression unitaire, en lot, réimpression.
- [ ] Test humain : impression réelle sur l'imprimante du client, lecture du QR par la douchette.

## Lot 5. Acquisitions (1 j) · E11, E12 · RG-15, RG-16

- [x] Acquisition multi-lignes avec justificatifs.
- [x] Validation : une fiche par unité suivie, numéros consécutifs, paramètres d'amortissement de la catégorie appliqués.
- [x] Enchaînement vers l'impression des étiquettes des biens créés (redirection vers /admin/etiquettes?biens=…, page du lot 4).

## Lot 6. Mouvements et transferts (1 j) · E13, E14 · RG-20 à RG-24

- [x] Affectation initiale enregistrée comme premier mouvement.
- [x] Transfert unitaire et groupé ; l'affectation précédente est conservée.
- [x] Historique des mouvements sur la fiche et liste filtrable.

## Lot 7. Immobilisations et amortissements (2,5 j) · E30 à E33 · RG-30 à RG-43

À commencer par les tests : tous les cas de `tests/Fixtures/amortissements.json` en test paramétré.

- [x] `DepreciationCalculator` pur (sans base de données), linéaire avec prorata 360 jours, arrondi et dernière annuité (A1 à A5, A8). *Test paramétré lu depuis le fichier de cas ; A5 avec la date de sortie, l'action de sortie viendra au lot 10. Cas à faire signer par le comptable (Q9).*
- [x] Dégressif derrière un paramètre désactivé par défaut (A7).
- [x] Changement de paramètre prospectif avec motif et historique (A6).
- [x] Exercices : ouverture, clôture avec points d'attention, lignes figées après clôture. *Points d'attention « sorties » et « inventaires » affichés comme non vérifiables jusqu'aux lots 10 et 9.*
- [x] Plan par bien (E31), tableau des immobilisations par exercice (E30), règles par catégorie (E32). *Plus E33 (exercices), onglet Amortissement de la fiche et exports PDF et Excel du plan. Pas d'export du tableau E30 (rapports du lot 11).*
- [x] Commande `patrimoine:recalculer-plans` idempotente.

## Lot 8. Prêts, maintenance et signalements (1,5 j) · E15 à E17, E22 à E25 · RG-55 à RG-64

- [x] Prêt et emprunt, retour avec comparaison des états, statut restauré. *Fiche de prêt PDF au lot 11.*
- [x] Intervention : ouverture et clôture avec état et statut, proposition de sortie si irréparable. *Lien vers `/admin/sorties/create` proposé si la page des sorties (lot 10) existe.*
- [x] Signalement par le responsable, traitement ou rejet par le gestionnaire, statut visible par le responsable.

## Lot 9. Inventaires (2 j) · E18 à E21 · RG-44 à RG-49

- [x] Création par périmètre, liste attendue figée au démarrage. *Six types de périmètre, filtre croisé par site, périmètre de l'utilisateur respecté (QL9-6) ; un bien entré dans le périmètre après le démarrage n'entre pas dans la liste.*
- [x] Saisie à la douchette par plusieurs personnes en même temps ; compteurs à jour. *Test en processus parallèles sur SQLite et sur MariaDB ; règle des saisies simultanées en QL9-2. Essai réel avec la douchette et deux postes à faire par un humain.*
- [x] Tous les résultats de RG-45 et RG-46.
- [x] Rapport par type d'écart, régularisations tracées, validation et clôture, PV PDF et export Excel. *Sortie pour perte : lien vers la page du lot 10 quand elle existe et action publique `ResolveInventoryLine` (branchement à l'intégration).*

## Lot 10. Sorties du patrimoine (1 j) · E26, E27, E43 · RG-50 à RG-54, RG-39

- [x] Sortie par motif, confirmation par saisie du numéro, impossible à annuler. *Refusée pour un bien emprunté ou en maintenance (QL10-1) et dans un exercice clôturé (Q9.12, QL10-3) ; double sortie simultanée testée en processus parallèles sur SQLite et MariaDB.*
- [x] Amortissement arrêté à la date de sortie et valeurs figées (A5). *Testé de bout en bout et sur la démonstration (PAT-000034).*
- [x] Validation comptable ; fiche du bien sorti en lecture seule. *Point d'attention « sorties non validées » branché dans la clôture (RG-42).*

## Lot 11. Tableau de bord, alertes, rapports, espace responsable (2 j) · E02, E03, E29, E42, E45 · RG-90, RG-91

- [ ] Tableau de bord conforme à la maquette, chiffres cohérents avec les rapports.
- [ ] Alertes calculées toutes les heures, marquage comme lu.
- [ ] Tous les rapports de E29, avec les filtres de l'exigence 15.3, en PDF et Excel, limités au périmètre de l'utilisateur.
- [ ] Gabarits PDF de E45 (plan d'amortissement, PV d'inventaire, fiche de prêt).
- [ ] Espace du responsable de département.

## Lot 12. États système, installation et livrables (1,5 j) · E44

- [x] Pages 403, 404, 419 et message « serveur injoignable » en français. *Plus 401, 429, 500 et 503, aux couleurs du thème, sans ressource externe ; fenêtres du panneau (session expirée, serveur injoignable, erreur, accès refusé, maintenance) qui remplacent celles, anglaises, de Livewire.*
- [x] Script d'installation pour le serveur local et procédure écrite (`docs/07-deploiement-local.md`). *`install.ps1` + `php artisan patrimoine:installer` / `patrimoine:verifier`, relançables ; testés sur une copie (SQLite) et sur MariaDB 11.5 ; essai sur un poste vierge à faire.*
- [x] Commande de sauvegarde manuelle base + fichiers (si l'option est retenue, voir questions ouvertes). *`patrimoine:sauvegarder` avec contrôle d'intégrité, et `patrimoine:restaurer` sécurisée (Q20, QL12-3 à QL12-5) ; essai sur disque externe à faire.*
- [ ] Guide utilisateur par profil (PDF), documentation technique, base initialisée avec les référentiels du client.
- [ ] Recette : les 22 critères de la section 20 du cahier des charges vérifiés un par un et cochés dans `docs/09-recette.md`.

**Total développement : environ 19 jours ouvrés, soit 4 semaines.** Le calendrier réel dépend surtout des réponses du client aux questions ouvertes (bloquantes pour les lots 3, 4 et 7) et de la recette.
