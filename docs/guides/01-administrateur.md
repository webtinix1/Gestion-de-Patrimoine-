# Guide de l'administrateur

Ce guide présente le rôle « Administrateur » : ce qu'il voit, ce qu'il peut faire et comment l'utiliser au quotidien. Il s'adresse à la personne de confiance qui gère l'application pour l'Église. Aucune connaissance en informatique n'est nécessaire pour la partie « utilisation » ; la partie technique (comptes, rôles, sauvegardes) est détaillée dans le **guide d'administration**.

L'administrateur a **tous les droits**, y compris ceux du gestionnaire, du comptable, du responsable de département et de la consultation. Pour les tâches de ces profils, reportez-vous à leur guide : le pas à pas est le même.

## 1. Se connecter

1. Ouvrez votre navigateur et allez à l'adresse du serveur, par exemple `http://patrimoine.local`.
2. Saisissez votre **identifiant** (le premier administrateur est créé à l'installation ; sur l'installation de démonstration, le compte s'appelle `admin`) et votre mot de passe.
3. Validez.

Après 30 minutes sans activité, la session se ferme. Après 5 mots de passe faux, le compte est bloqué 15 minutes ; un autre administrateur peut le débloquer en réinitialisant le mot de passe (voir le guide d'administration). Ne partagez jamais le compte administrateur : chaque personne a son propre compte, pour que le journal des opérations dise qui a fait quoi.

Le mot de passe des comptes de démonstration est dans `database/seeders/DemoSeeder.php` : ils ne servent jamais sur le serveur réel.

## 2. Changer son mot de passe

1. Cliquez sur votre nom en haut à droite, puis **Mon profil**.
2. Section « Changer le mot de passe » : mot de passe actuel, nouveau (8 caractères minimum, dont une lettre et un chiffre, sans l'identifiant), confirmation.
3. Enregistrez.

Le premier administrateur choisit à l'installation un mot de passe d'au moins 12 caractères, avec lettres et chiffres. Choisissez-en un long, que vous seul connaissez.

## 3. Ce que vous voyez

Le menu de gauche contient tous les groupes :

| Groupe | Écrans |
|---|---|
| Accueil | Tableau de bord, Alertes |
| Patrimoine | Biens, Recherche par code, Étiquettes, Acquisitions |
| Opérations | Mouvements, Prêts et emprunts, Maintenance, Signalements, Inventaires, Sorties et réformes |
| Immobilisations | Tableau des immobilisations, Règles d'amortissement, Exercices comptables |
| Pilotage | Rapports, Documents |
| Structure | Sites, Départements et services, Locaux, Catégories et référentiels |
| Administration | Utilisateurs, Rôles et permissions, Journal des opérations |

En haut : la recherche (numéro, désignation, numéro de série), la cloche des alertes et votre menu.

Le **Tableau de bord** donne d'un coup d'œil : la valeur d'acquisition, la valeur nette comptable et les dotations de l'exercice ; les compteurs par statut (total, en service, en stock, en maintenance, hors service, prêtés) ; les biens par département ; la répartition par statut et par site ; les acquisitions et transferts récents ; les biens nécessitant une intervention ; le dernier inventaire.

## 4. Vos responsabilités propres

| Tâche | Où | Détail |
|---|---|---|
| Créer les comptes, choisir le rôle et le périmètre | Administration > Utilisateurs | Guide d'administration, section 2 |
| Réinitialiser un mot de passe, débloquer un compte, désactiver un compte | Administration > Utilisateurs | Guide d'administration, section 2 |
| Régler les droits de chaque rôle | Administration > Rôles et permissions | Guide d'administration, section 3 |
| Saisir la structure de l'Église (sites, départements, services, locaux, catégories) | Groupe Structure | Guide d'administration, section 4 |
| Surveiller l'activité | Administration > Journal des opérations | Section 5.1 ci-dessous |
| Sauvegarder et restaurer | Ligne de commande sur le serveur | Guide d'administration, section 7 |
| Régler les règles d'amortissement, clôturer l'exercice | Groupe Immobilisations | Guide du comptable |

Avec le comptable, vous êtes aussi les deux seuls profils à pouvoir valider un plan d'amortissement, modifier ses paramètres, valider une sortie et clôturer un exercice.

## 5. Les tâches courantes

### 5.1 Lire le journal des opérations

1. Menu **Administration > Journal des opérations** (lecture seule : personne ne peut le modifier ni le vider).
2. Filtrez par **utilisateur**, **domaine** et **période**.
3. Ouvrez une ligne pour voir la date et l'heure, l'utilisateur, l'opération, l'objet, le poste (adresse IP) et les **valeurs avant et après**.

Le journal garde notamment la création et la modification des biens, les transferts, les inventaires, la maintenance, les prêts, les sorties, les changements de paramètres d'amortissement, les validations de plans, les clôtures d'exercice, les connexions (réussies ou non) et la gestion des comptes et des rôles.

### 5.2 Faire le tour de la semaine

1. Ouvrez les **Alertes** : prêts en retard, interventions ouvertes depuis longtemps, biens hors service, inventaires à venir, écarts d'inventaire non régularisés, fins d'amortissement.
2. Ouvrez **Opérations > Signalements** (onglet « À traiter ») pour vérifier que les signalements ne s'accumulent pas.
3. Lancez une **sauvegarde** (guide d'administration, section 7).

### 5.3 Dépanner un collègue

- « Je n'arrive plus à me connecter » : Utilisateurs, ligne de la personne, menu d'actions, **Réinitialiser le mot de passe**. Remettez le mot de passe provisoire en main propre.
- « Je ne vois pas tel bien ou tel écran » : vérifiez le **périmètre** (sites, départements) et le **rôle** de la personne.
- « Je ne peux pas cliquer sur tel bouton » : vérifiez la matrice des droits du rôle (Rôles et permissions).

## 6. Messages fréquents

| Message | Ce que cela veut dire | Quoi faire |
|---|---|---|
| « Compte bloqué après plusieurs essais infructueux… » | 5 échecs de mot de passe. | Attendre 15 minutes ou réinitialiser le mot de passe. |
| Page 403 | Droit manquant. | Contrôler le rôle et le périmètre. |
| Page 419 | Session expirée (30 minutes d'inactivité par défaut ; durée réglable dans Administration > Paramètres, onglet Sécurité). | Se reconnecter. |
| Page 500 | Erreur du serveur. | Noter l'heure ; le détail est dans le fichier `storage\logs\laravel.log` du serveur ; prévenir Webtinix. |
| Page 503 | Application en maintenance (sauvegarde ou mise à jour). | Attendre la fin, ou vérifier que `php artisan up` a été lancé. |
| « Connexion au serveur perdue » | Réseau coupé ou serveur arrêté. | Vérifier que le serveur est allumé et que WAMP est en ligne. |

## 7. Ce que l'application ne fait pas

- Supprimer un bien, un mouvement, un prêt, une intervention, une sortie, un exercice ou une entrée du journal. Un compte se désactive, il ne se supprime pas.
- Annuler une sortie ou une clôture d'exercice.
- Envoyer des e-mails ou des SMS : les alertes ne s'affichent que dans l'application.
- Tenir la comptabilité générale.

## 8. À qui s'adresser

Pour une anomalie de l'application, une question de configuration du serveur ou une mise à jour : Webtinix, avec la date, l'heure, l'identifiant concerné et le message affiché.
