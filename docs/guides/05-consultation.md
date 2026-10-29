# Guide du profil Consultation

Ce guide s'adresse aux personnes qui ont besoin de **voir** le patrimoine sans rien modifier : un pasteur, un conseil, un auditeur. Ce profil est en lecture seule. Il ne demande aucune connaissance en informatique.

## 1. Se connecter

1. Ouvrez votre navigateur et allez à l'adresse donnée par l'administrateur, par exemple `http://patrimoine.local`.
2. Saisissez votre **identifiant** (par exemple `pasteur` sur l'installation de démonstration) et votre mot de passe, puis validez.
3. Si votre mot de passe vient d'être créé ou réinitialisé, choisissez-en un nouveau quand l'application vous le demande.

Après 30 minutes sans activité, vous êtes déconnecté. Après 5 mots de passe faux, le compte est bloqué 15 minutes. Les comptes de démonstration n'existent que sur une installation d'essai ; leur mot de passe est connu de l'administrateur technique (fichier `database/seeders/DemoSeeder.php`).

## 2. Changer son mot de passe

1. Cliquez sur votre nom en haut à droite, puis **Mon profil**.
2. Section « Changer le mot de passe » : mot de passe actuel, nouveau (8 caractères minimum, dont une lettre et un chiffre, sans l'identifiant), confirmation.
3. Enregistrez.

## 3. Ce que vous voyez et ce que vous pouvez faire

Le rôle « Consultation » donne la **lecture seule** sur les modules du patrimoine : biens, étiquettes, acquisitions, mouvements, inventaires, prêts, maintenance, signalements, sorties, immobilisations et amortissements, documents, rapports et structure. Il donne aussi le droit « exporter » des rapports.

Vous ne pouvez rien créer, modifier, valider ni supprimer. Vous n'avez pas accès aux utilisateurs, aux rôles, aux paramètres ni au journal des opérations.

Votre **périmètre** (sites et départements) peut être limité par l'administrateur : vous ne voyez alors que ces biens.

À noter : il faut aussi un droit d'export sur chaque module concerné pour ouvrir un rapport. Avec les droits livrés par défaut, le profil Consultation ne reçoit pas ces droits : l'entrée **Rapports** n'apparaît donc pas dans votre menu. Si votre Église veut que vous produisiez des rapports, demandez à l'administrateur d'activer la colonne « Exporter » sur les modules voulus pour votre rôle.

## 4. Les tâches courantes, pas à pas

### 4.1 Parcourir la liste des biens

1. Menu **Patrimoine > Biens**.
2. Filtrez par catégorie, site, département, service, statut, état, local, responsable ou période d'acquisition.
3. Cliquez sur un bien pour ouvrir sa fiche : informations, affectation et mouvements, amortissement, maintenance, prêts, documents, journal.

La recherche en haut de l'écran trouve un bien par son numéro, sa désignation ou son numéro de série.

### 4.2 Retrouver un bien avec son étiquette

1. Menu **Patrimoine > Recherche par code**.
2. Cliquez dans le champ et passez la douchette sur l'étiquette, ou tapez le numéro (par exemple `PAT-000123`), puis Entrée.
3. La fiche s'affiche. « Aucun bien avec ce code » signifie que le numéro est inconnu ou hors de votre périmètre.

### 4.3 Suivre les opérations

- **Opérations > Mouvements** : tous les déplacements (qui, quand, de où à où, pourquoi).
- **Opérations > Prêts et emprunts** : onglets Prêts en cours, En retard, Biens empruntés, Historique.
- **Opérations > Maintenance** : interventions planifiées, en cours, terminées.
- **Opérations > Inventaires** : le bouton **Rapport** de chaque ligne ouvre les résultats ; le procès-verbal est disponible quand vous avez le droit d'export.
- **Opérations > Sorties et réformes** : biens sortis du patrimoine, avec leur valeur à la date de sortie.

### 4.4 Consulter l'amortissement

Menu **Immobilisations > Tableau des immobilisations** : choisissez l'exercice, parcourez les onglets (En cours d'amortissement, Échéance atteinte, Totalement amorties, Non amortissables) et ouvrez un bien pour voir son plan. Les règles et les exercices sont consultables, pas modifiables.

### 4.5 Lire le tableau de bord et les alertes

Le **Tableau de bord** (page d'accueil) résume les valeurs, les compteurs par statut, la répartition par département et par site, les acquisitions et transferts récents et le dernier inventaire. Les blocs s'adaptent à vos droits. La cloche en haut de l'écran ouvre les **Alertes** ; **Marquer comme lue** les range.

### 4.6 Ouvrir un document

Menu **Pilotage > Documents** : filtrez par type ou période, **Ouvrir** ou **Télécharger** un justificatif.

## 5. Messages fréquents et quoi faire

| Message | Ce que cela veut dire | Quoi faire |
|---|---|---|
| Page 403 « accès refusé » | Cet écran ou cette action n'est pas prévu pour votre rôle. | Revenez à l'accueil ; demandez un droit à l'administrateur si nécessaire. |
| Liste vide | Votre périmètre ne contient aucun bien correspondant, ou les filtres sont trop stricts. | Retirez les filtres ; sinon voyez l'administrateur. |
| Page 419 | Session expirée après 30 minutes. | Reconnectez-vous. |
| « Connexion au serveur perdue » | Réseau coupé ou serveur arrêté. | Cliquez sur Réessayer sans actualiser la page. |
| Compte bloqué | 5 mots de passe faux. | Attendez 15 minutes ou contactez l'administrateur. |

## 6. Ce que vous ne pouvez pas faire

Tout ce qui écrit : créer ou modifier un bien, transférer, prêter, saisir un inventaire, signaler, sortir, valider, régler les amortissements, ajouter un document.

## 7. À qui s'adresser

- Droit manquant, périmètre à élargir, mot de passe oublié : l'administrateur de l'application.
- Question sur le contenu d'une fiche : le gestionnaire du patrimoine.
