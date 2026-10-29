# Guide du comptable

Ce guide s'adresse à la personne qui suit les immobilisations : elle règle les règles d'amortissement, valide les plans et les sorties, et clôture les exercices. Il ne demande aucune connaissance en informatique.

L'application ne tient pas la comptabilité générale et ne génère aucune écriture comptable : elle calcule et conserve les plans d'amortissement et les valeurs à la date de sortie.

## 1. Se connecter

1. Ouvrez votre navigateur et allez à l'adresse donnée par l'administrateur, par exemple `http://patrimoine.local`.
2. Saisissez votre **identifiant** (par exemple `m.loubaki`) et votre mot de passe, puis validez.
3. Si votre mot de passe vient d'être créé ou réinitialisé par l'administrateur, choisissez-en un nouveau quand l'application vous le demande.

Après 30 minutes sans activité, vous êtes déconnecté. Après 5 mots de passe faux, le compte est bloqué 15 minutes. Les comptes de démonstration n'existent que sur une installation d'essai ; leur mot de passe est connu de l'administrateur technique (fichier `database/seeders/DemoSeeder.php`) et ne doit jamais servir sur le serveur réel.

## 2. Changer son mot de passe

1. Cliquez sur votre nom en haut à droite, puis **Mon profil**.
2. Section « Changer le mot de passe » : mot de passe actuel, nouveau mot de passe (8 caractères minimum, dont une lettre et un chiffre, sans l'identifiant), confirmation.
3. Enregistrez.

## 3. Ce que vous voyez et ce que vous pouvez faire

| Domaine | Ce que vous pouvez faire |
|---|---|
| Immobilisations et amortissements | Tout : consulter, créer (exercices), modifier (règles, paramètres), valider (plans, clôture), exporter |
| Sorties et réformes | Consulter, **valider** le traitement comptable, exporter |
| Biens, acquisitions, mouvements, inventaires, maintenance | Consulter et exporter |
| Prêts | Consulter |
| Documents | Consulter, ajouter, exporter |
| Rapports | Consulter et exporter |
| Structure | Consulter |
| Journal des opérations | Consulter et exporter |
| Étiquettes, signalements, utilisateurs, rôles, paramètres | Aucun accès |

Vous ne créez ni ne modifiez les biens : cela revient au gestionnaire du patrimoine. Votre périmètre (sites, départements) filtre toutes les listes et tous les rapports ; l'administrateur le règle.

Les écrans qui vous concernent sont dans le groupe **Immobilisations** du menu (Tableau des immobilisations, Règles d'amortissement, Exercices comptables), dans **Opérations > Sorties et réformes** et dans **Pilotage > Rapports**.

## 4. Les tâches courantes, pas à pas

### 4.1 Régler les règles d'amortissement

Menu **Immobilisations > Règles d'amortissement**.

1. Section « Règles générales » : le **seuil d'immobilisation** en FCFA (en dessous, le bien est suivi sans amortissement) et l'interrupteur « Autoriser la méthode dégressive » avec ses trois coefficients (3 à 4 ans, 5 à 6 ans, plus de 6 ans). La méthode dégressive est désactivée par défaut.
2. Cliquez sur **Enregistrer les règles**. Le message rappelle que les biens déjà enregistrés ne sont pas recalculés.
3. Section « Règles par catégorie » : méthode, durée en années, taux (calculé : 100 divisé par la durée), comptes d'immobilisation, d'amortissement et de dotation. Chaque cellule s'enregistre quand vous la modifiez. Les comptes sont indicatifs : confirmez-les avec votre référentiel comptable.

Les règles s'appliquent aux **nouveaux** biens ; chaque fiche peut ensuite différer de sa catégorie.

### 4.2 Consulter le tableau des immobilisations

Menu **Immobilisations > Tableau des immobilisations**.

1. Choisissez l'**exercice** en haut (l'exercice suivant apparaît en prévisionnel).
2. Lisez les compteurs, puis les onglets : **En cours d'amortissement**, **Échéance atteinte**, **Totalement amorties**, **Non amortissables**.
3. Regroupez par catégorie, site ou département, filtrez par catégorie, site, département ou méthode.
4. Cliquez sur un bien pour ouvrir son plan.

### 4.3 Lire, valider et corriger le plan d'un bien

La page du plan montre les paramètres du bien, le tableau d'amortissement par exercice avec son statut, l'évolution de la VNC (valeur nette comptable), l'historique des modifications et les versions du plan.

- **Valider le plan** : confirme la version courante. Elle est conservée telle quelle ; toute modification ultérieure crée une nouvelle version.
- **Modifier les paramètres** : durée, valeur résiduelle, valeur d'origine, date de mise en service, méthode. Le **motif est obligatoire**. Cliquez sur **Recalculer et enregistrer**. Seuls les exercices ouverts sont recalculés ; les exercices clôturés ne bougent jamais. L'ancienne et la nouvelle valeur sont gardées avec la date, l'auteur et le motif.
- **Exporter PDF** et **Exporter Excel** : le plan complet.
- Le bouton à côté du titre (« Fiche PAT-… ») revient à la fiche du bien.

Le calcul suit la règle par défaut : méthode linéaire, prorata en jours sur 360 jours, première année à partir de la date de mise en service, dotations arrondies au franc, la dernière dotation absorbant l'écart d'arrondi. Ces choix restent à confirmer par vous auprès de l'Église.

### 4.4 Valider une sortie

Menu **Opérations > Sorties et réformes**.

1. Ouvrez la sortie « À valider ». Le panneau donne la valeur d'origine, les amortissements cumulés et la VNC à la date de sortie.
2. Cliquez sur **Valider** puis **Valider la sortie** dans la fenêtre. La validation est définitive et tracée. Aucune écriture comptable n'est créée : c'est à vous de passer l'écriture dans votre logiciel de comptabilité.
3. **Fiche du bien** et **Plan d'amortissement arrêté** ouvrent les pages liées.

L'amortissement du bien est arrêté à la date de sortie, incluse.

### 4.5 Ouvrir et clôturer un exercice

Menu **Immobilisations > Exercices comptables**.

- **Nouvel exercice** : saisissez l'année. Un exercice se crée seulement après le dernier exercice existant ; les exercices passés ne se rouvrent pas.
- **Ouvrir** : passe un exercice « Préparé » à « Ouvert ».
- **Clôturer** : possible seulement à partir du dernier jour de l'exercice (le bouton est grisé avant, avec la date dans l'infobulle). La fenêtre liste les **points d'attention** : plans non validés, biens amortissables sans plan, sorties non validées par le comptable, inventaires non clôturés. Ils n'empêchent pas la clôture mais doivent être lus. Cochez « J'ai pris connaissance des points d'attention et je confirme la clôture, irréversible », puis **Clôturer définitivement**.

La clôture fige les dotations de l'exercice, valide les plans concernés et ouvre l'exercice suivant. **Elle ne peut pas être annulée** dans l'application. Avant de clôturer, validez les sorties en attente et assurez-vous que les inventaires sont clôturés. On clôture les exercices dans l'ordre : le plus ancien d'abord.

### 4.6 Sortir les rapports

Menu **Pilotage > Rapports**.

1. Choisissez un rapport dans la liste (groupe « Immobilisations » : Tableau des immobilisations, Dotations par exercice, Amortissements cumulés, Valeurs nettes comptables, Immobilisations totalement amorties ; groupe « Opérations » : notamment Sorties et réformes, Écarts d'inventaire).
2. Réglez les filtres (exercice, site, département, catégorie, période…), cliquez sur **Appliquer** : l'aperçu s'affiche.
3. **Exporter PDF** ou **Exporter Excel**.

Les chiffres du tableau de bord et ceux des rapports viennent de la même source de calcul.

### 4.7 Retrouver une trace

**Administration > Journal des opérations** (lecture seule) montre qui a fait quoi, quand, depuis quel poste, avec les valeurs avant et après. Filtres : utilisateur, domaine, période. Vous pouvez l'exporter.

## 5. Messages fréquents et quoi faire

| Message | Ce que cela veut dire | Quoi faire |
|---|---|---|
| « Le motif de la modification est obligatoire (RG-37). » | Modification d'un paramètre sans motif. | Saisissez un motif. |
| « Aucun paramètre n'a changé. » | Rien de différent à enregistrer. | Modifiez une valeur ou annulez. |
| « La valeur résiduelle doit être positive et inférieure au prix d'acquisition. » | Valeur résiduelle incohérente. | Corrigez-la. |
| « La méthode dégressive s'applique à une durée d'au moins 3 ans. » | Durée trop courte pour le dégressif. | Allongez la durée ou restez en linéaire. |
| « La méthode dégressive n'est pas activée. » | Méthode non autorisée. | Activez-la dans les règles générales. |
| « L'exercice … pourra être clôturé à partir du … » | Clôture avant la fin de l'exercice. | Attendez le dernier jour. |
| « Clôturez d'abord l'exercice … » | Ordre des clôtures. | Clôturez l'exercice précédent. |
| « Confirmez avoir pris connaissance des points d'attention… » | Case non cochée. | Cochez-la après lecture. |
| « Cette sortie est déjà validée. » | Double validation. | Rien à faire. |
| « Cette fiche a été enregistrée par … depuis son ouverture. » | Quelqu'un a modifié le bien en même temps. | Rechargez la page et recommencez. |
| « Un numéro de compte ne contient que des chiffres. » | Compte mal saisi. | Retirez lettres et espaces. |

## 6. Ce que vous ne pouvez pas faire

- Annuler une clôture d'exercice, une sortie ou une validation.
- Modifier un exercice clôturé ou un plan figé.
- Créer ou modifier un bien, un prêt, une intervention.
- Supprimer quoi que ce soit : les exercices, plans et sorties se conservent.

## 7. À qui s'adresser

- Compte bloqué, mot de passe oublié, périmètre à élargir : l'administrateur de l'application.
- Fiche d'un bien à corriger (prix, date, catégorie) : le gestionnaire du patrimoine.
- Anomalie de l'application ou de calcul : l'administrateur, qui prévient Webtinix.
