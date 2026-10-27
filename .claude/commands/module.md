---
description: Développe un lot du plan de développement, tests d'abord
argument-hint: <numéro du lot, ex. 3>
---

Développe le lot $ARGUMENTS décrit dans `docs/05-plan-de-developpement.md`.

1. Lis la section du lot, les règles de gestion qu'il cite dans `docs/02-regles-de-gestion.md`, les tables concernées dans `docs/03-modele-de-donnees.md` et les écrans dans `docs/04-ecrans-filament.md`.
2. Crée la branche `lot-$ARGUMENTS`.
3. Présente un plan court : fichiers à créer ou modifier, tests prévus, points flous. S'il y a un point flou bloquant, arrête-toi et pose la question ; sinon continue.
4. Écris les tests Pest des critères d'acceptation et des règles de gestion du lot, vérifie qu'ils échouent, puis implémente.
5. Lance `php artisan test`, `vendor/bin/pint`, `vendor/bin/phpstan analyse` jusqu'à ce que tout passe.
6. Coche les critères d'acceptation atteints dans `docs/05-plan-de-developpement.md` et complète `docs/08-journal-technique.md`.
7. Fais un commit par étape logique, puis termine par un compte rendu court : ce qui est fait, ce que l'humain doit tester à la main (écrans, impression, scanner), les questions ajoutées à `docs/06-questions-ouvertes.md`.
