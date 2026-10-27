# Patrimoine Église : consignes pour Claude Code

## Le projet en bref

Application web de gestion du patrimoine matériel et des immobilisations d'une Église, développée par Webtinix. Elle tourne sur un serveur local (parfois un simple PC) et doit fonctionner sans Internet. Tout se passe dans un back-office Filament : il n'y a pas de site public.

Avant toute tâche, lis dans cet ordre :

1. `docs/02-regles-de-gestion.md` : les règles métier numérotées (RG-xx). Elles priment sur tout le reste.
2. `docs/03-modele-de-donnees.md` : tables, colonnes, relations.
3. `docs/04-ecrans-filament.md` : ce que chaque écran doit contenir et comment le construire avec Filament.
4. `docs/05-plan-de-developpement.md` : l'ordre des lots et leurs critères d'acceptation.
5. `docs/01-cahier-des-charges.md` : le cahier des charges du client, pour le contexte.

Si une règle manque ou se contredit, ne l'invente pas. Ajoute la question dans `docs/06-questions-ouvertes.md`, choisis la solution la plus prudente et réversible, et signale-le dans ton compte rendu.

## Stack

- PHP 8.3 ou plus, Laravel 13, Filament 5 (vérifie au démarrage les versions stables et leur compatibilité, et note-les dans `docs/08-journal-technique.md`).
- Base de données MySQL 8 ou MariaDB 10.11+. Le code doit rester compatible PostgreSQL : pas de SQL brut spécifique sans nécessité.
- Tests : Pest, avec les helpers de test Filament pour les ressources, formulaires et actions.
- Qualité : Laravel Pint (style), Larastan niveau 6 minimum.
- Paquets prévus (vérifier la compatibilité avec Filament 5 avant installation, et signaler tout blocage plutôt que de le contourner) :
  - `spatie/laravel-permission` + `bezhansalleh/filament-shield` pour les rôles et permissions ;
  - `spatie/laravel-activitylog` pour le journal des opérations ;
  - `barryvdh/laravel-dompdf` pour les PDF (rapports, plans d'amortissement, étiquettes, fiches de prêt, PV) ;
  - `maatwebsite/excel` pour les exports Excel ;
  - `endroid/qr-code` et `picqer/php-barcode-generator` pour les QR codes et codes-barres.

## Contraintes non négociables

- **Hors ligne.** Aucune ressource externe à l'exécution : pas de CDN, pas de Google Fonts, pas d'API tierce. Les polices (Archivo, Martian Mono) sont servies depuis `public/fonts`. Les fichiers sont stockés sur le disque local (`storage/app/private`).
- **Montants.** En FCFA, entiers, colonnes `bigInteger`. Jamais de `float` pour l'argent. Les taux se stockent en `decimal(7,4)`.
- **Numéro patrimonial.** Généré uniquement par `App\Services\AssetNumberGenerator`, dans une transaction avec verrou de ligne sur la table `asset_number_sequences`. Jamais modifiable, jamais réutilisé (RG-01 à RG-04). Test de concurrence obligatoire.
- **Rien ne se supprime.** Pas d'action de suppression sur les biens, mouvements, inventaires, prêts, interventions, sorties et plans. Un bien sort du patrimoine par une sortie (RG-50). Les référentiels se désactivent au lieu d'être supprimés s'ils sont utilisés.
- **Historique.** Toute opération listée en RG-70 est tracée avec l'utilisateur, la date et l'heure, et les valeurs avant et après.
- **Exercices clôturés.** Les lignes de plan d'amortissement d'un exercice clôturé sont figées. Aucun recalcul ne doit les modifier (RG-36).
- **Droits.** Chaque ressource, page et action passe par une Policy. Le responsable de département ne voit que les biens de son ou ses départements (RG-80 à RG-84).
- **Fuseau horaire** `Africa/Brazzaville`. Affichage des dates en jj/mm/aaaa, des montants avec espace comme séparateur de milliers.

## Conventions de code

- Code en anglais (classes, tables, colonnes, méthodes). Interface 100 % en français, y compris les messages de validation (`lang/fr`). Correspondance des termes dans `docs/03-modele-de-donnees.md`.
- La logique métier vit dans `app/Services` (calculs, générateurs) et `app/Actions` (une opération = une classe : `RecordTransfer`, `RecordLoanReturn`, `RecordDisposal`, `CloseInventory`, `CloseFiscalYear`…). Les ressources Filament appellent ces classes, elles ne contiennent pas de règle métier.
- Les statuts, états et motifs fixes sont des Enums PHP avec libellé français et couleur Filament.
- Une migration par table, avec clés étrangères et index. Des seeders de démonstration réalistes (voir `docs/03`), séparés des seeders de référence indispensables (rôles, statuts, catégories par défaut).
- Pas de commentaires évidents. Commenter seulement une règle de gestion, avec sa référence : `// RG-33 : la dernière dotation absorbe l'écart d'arrondi`.

## Interface (Filament)

- Panneau unique `/admin`, rendu sur la page d'accueil du serveur local.
- Thème : couleur primaire `#0B5D6B`, accent `#F2B544`, gris neutres, police Archivo, chiffres et numéros en Martian Mono. Badges de statut à angles droits, cartes à 12 px, pas d'ombres.
- Groupes de navigation et libellés exactement comme dans `docs/04-ecrans-filament.md`.
- La maquette de référence est sur Claude Design (lien dans `docs/04`). Elle fixe les contenus et les parcours. Pour les listes et formulaires standards, on garde la structure Filament ; les pages sur mesure (tableau de bord, saisie d'inventaire, étiquettes, plan d'amortissement, rapports) reprennent la maquette au plus près.

## Façon de travailler

Pour chaque lot du plan (`/module <numéro du lot>`) :

1. Relis les RG concernées et la section correspondante de `docs/04`. Écris un plan court (fichiers à créer ou modifier, tests prévus) avant de coder.
2. Écris d'abord les tests des règles de gestion (Pest), puis le code. Pour les amortissements, les cas de `tests/Fixtures/amortissements.json` doivent tous passer.
3. Lance `php artisan test`, `vendor/bin/pint` et `vendor/bin/phpstan analyse`. Ne déclare rien terminé tant qu'un de ces trois échoue.
4. Mets à jour `docs/08-journal-technique.md` : ce qui a été fait, les décisions, les écarts et les questions ouvertes.
5. Termine par un compte rendu de cinq lignes maximum, avec ce que l'humain doit vérifier à la main.

Ne fais jamais :

- inventer une règle comptable ou modifier les cas de test validés pour faire passer un calcul ;
- ajouter une fonctionnalité hors périmètre V1 (application mobile, e-mails, SMS, cloud, comptabilité générale, dons, membres, paie, budgets) ;
- installer un paquet non listé ici sans le justifier dans le journal technique ;
- pousser sur la branche `main` : une branche par lot, fusion après relecture humaine.

## Commandes utiles

```bash
composer install && npm install && npm run build
php artisan migrate:fresh --seed          # base de démonstration
php artisan test                          # tests
vendor/bin/pint && vendor/bin/phpstan analyse
php artisan patrimoine:recalculer-plans   # recalcul des exercices ouverts (lot 7)
```
