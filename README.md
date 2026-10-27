# Patrimoine Église : kit de démarrage Claude Code

Ce dossier contient tout ce qu'il faut pour développer l'application avec Claude Code sur Laravel + Filament. Il devient la racine du dépôt Git : Claude Code y lira automatiquement `CLAUDE.md` à chaque session.

## Contenu

| Fichier | Rôle |
|---|---|
| `CLAUDE.md` | Consignes permanentes pour Claude Code : stack, contraintes, conventions, façon de travailler |
| `.claude/commands/module.md` | Commande `/module <n>` : développe un lot, tests d'abord |
| `.claude/commands/revue.md` | Commande `/revue` : relecture exigeante avant fusion |
| `docs/01-cahier-des-charges.md` | Cahier des charges du client (v3.0) |
| `docs/02-regles-de-gestion.md` | Règles métier numérotées RG-xx, la référence pour le code et les tests |
| `docs/03-modele-de-donnees.md` | Tables, colonnes, relations, données de démonstration |
| `docs/04-ecrans-filament.md` | Les 45 écrans de la maquette et leur construction Filament |
| `docs/05-plan-de-developpement.md` | 13 lots ordonnés avec critères d'acceptation |
| `docs/06-questions-ouvertes.md` | Points à faire trancher par l'Église, avec valeurs par défaut |
| `docs/07-deploiement-local.md` | Installation sur le serveur de l'Église |
| `docs/08-journal-technique.md` | Tenu par Claude Code à chaque lot |
| `docs/09-recette.md` | Les 22 critères de recette du client |
| `tests/Fixtures/amortissements.json` | 8 cas chiffrés d'amortissement, à faire valider par le comptable |

## Avant de lancer le développement

1. Envoyer `docs/06-questions-ouvertes.md` au client. Les questions Q1 à Q9 (comptable) bloquent le lot 7, Q10 à Q12 le lot 3, Q15 et Q16 le lot 4. Les autres lots peuvent avancer avec les valeurs par défaut.
2. Faire valider et signer les cas de `tests/Fixtures/amortissements.json` par le comptable.
3. Partager la maquette Claude Design avec l'équipe (lien dans `docs/04`).
4. Créer le dépôt Git privé et y pousser ce dossier sur `main`.
5. Poste du développeur : PHP 8.3+, Composer, Node 20+, MySQL 8, Claude Code.

## Démarrer

Ouvrir Claude Code à la racine du dépôt, puis :

```
Lis CLAUDE.md et tous les fichiers de docs/. Initialise le projet Laravel 13 dans ce dossier
sans écraser CLAUDE.md, README.md, docs/, .claude/ ni tests/Fixtures/, puis réalise le lot 0
du plan de développement en suivant la commande /module.
```

Ensuite, pour chaque lot :

```
/module 1
```

Puis, quand Claude Code annonce le lot terminé :

```
/revue
```

Le développeur teste les écrans du lot (rôle Gestionnaire et rôle Responsable de département), relit le journal technique et fusionne la branche dans `main`.

## Règles pour l'équipe

- Un humain relit et teste chaque lot avant fusion. Claude Code écrit le code, Webtinix en reste responsable.
- Ne jamais modifier les cas d'amortissement pour faire passer un test : soit le code est faux, soit le comptable doit valider par écrit un changement.
- Toute réponse du client se reporte dans `docs/06` (réponse + date), puis dans `docs/02` si elle change une règle. Claude Code relit ces fichiers à chaque lot.
- Les tests sur le matériel réel (douchette, imprimante, réseau du client) ne peuvent pas être faits par Claude Code : ils sont prévus aux lots 4, 9 et 12.

## Planning indicatif

Environ 19 jours ouvrés de développement (4 semaines) pour un développeur qui pilote Claude Code, plus la recette et l'installation chez le client. Le délai réel dépend surtout des réponses aux questions ouvertes.
