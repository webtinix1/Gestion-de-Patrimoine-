# Guide du responsable de département

Ce guide s'adresse au responsable d'un département (Louange, Jeunesse, École du dimanche, Communication…). Vous suivez les biens de votre département, vous signalez un problème au gestionnaire du patrimoine et vous participez aux inventaires où l'on vous a désigné. Il ne demande aucune connaissance en informatique.

Vous **ne modifiez aucune fiche** de bien : si un bien a changé d'état, a été déplacé ou doit être réparé, vous le signalez et le gestionnaire s'en occupe.

## 1. Se connecter

1. Ouvrez votre navigateur (Chrome, Edge ou Firefox) et allez à l'adresse donnée par l'administrateur, par exemple `http://patrimoine.local`. Mettez-la en favori.
2. Saisissez votre **identifiant** (par exemple `e.mbemba`) et votre mot de passe, puis validez.
3. À votre première connexion, ou après une réinitialisation, l'application vous demande de choisir votre propre mot de passe.

Après 30 minutes sans rien faire, vous êtes déconnecté : reconnectez-vous. Après 5 mots de passe faux, le compte est bloqué 15 minutes. Les comptes de démonstration n'existent que sur une installation d'essai (leur mot de passe est connu de l'administrateur technique, fichier `database/seeders/DemoSeeder.php`) et ne servent jamais sur le serveur réel.

## 2. Changer son mot de passe

1. Cliquez sur votre nom en haut à droite, puis **Mon profil**.
2. Section « Changer le mot de passe » : mot de passe actuel, nouveau mot de passe (8 caractères minimum, dont une lettre et un chiffre, sans l'identifiant), confirmation.
3. Enregistrez.

Cette page rappelle aussi votre rôle et votre **périmètre** : les départements (et éventuellement les sites) dont vous voyez les biens.

## 3. Ce que vous voyez et ce que vous pouvez faire

| Domaine | Ce que vous pouvez faire |
|---|---|
| Biens | Consulter les biens de votre périmètre (pas de création ni de modification) |
| Mouvements, prêts, maintenance, documents | Consulter |
| Signalements | Consulter les vôtres et en **créer** |
| Inventaires | Consulter ; saisir ceux où vous êtes désigné |
| Rapports | Le droit « consulter » existe, mais les rapports exigent aussi un droit d'export par module que le rôle livré ne donne pas : vous n'en avez donc en principe aucun (voir section 6) |
| Étiquettes, acquisitions, sorties, amortissements, structure, utilisateurs, paramètres, journal | Aucun accès |

Votre page d'accueil est **Mon espace** (à l'adresse habituelle de l'application). Le menu est court : Mon espace, Biens, Recherche par code, Signalements, Inventaires, et les Alertes.

## 4. Les tâches courantes, pas à pas

### 4.1 Voir les biens de mon département

1. Ouvrez **Mon espace**. Vous y trouvez :
   - vos départements et sites, et des compteurs : biens affectés, en service, en maintenance, hors service, prêtés ;
   - la liste des biens du département (numéro, désignation, local, statut, état), avec le lien **Voir la liste complète** ;
   - vos inventaires et vos signalements ;
   - le raccourci **Scanner un bien**.
2. Pour chercher un bien précis, ouvrez **Patrimoine > Biens** : filtrez par statut, état, local, catégorie… ou utilisez la recherche en haut de l'écran (numéro, désignation ou numéro de série).
3. Cliquez sur un bien pour voir sa fiche : informations, affectation et mouvements, maintenance, prêts, documents, journal.

Vous ne voyez que les biens de votre périmètre. Un bien d'un autre département n'apparaît pas, même si vous connaissez son numéro.

### 4.2 Retrouver un bien avec son étiquette

1. Menu **Recherche par code**.
2. Cliquez dans le champ, puis passez la douchette sur le QR code ou le code-barres de l'étiquette (ou tapez le numéro, par exemple `PAT-000123`) et appuyez sur Entrée.
3. La fiche du bien s'affiche avec des actions rapides. Le message « Aucun bien avec ce code » signifie que le numéro est inconnu ou hors de votre périmètre. Un bien déjà sorti est marqué « Sorti du patrimoine ».

### 4.3 Signaler un problème sur un bien

Un signalement prévient le gestionnaire du patrimoine, sans rien modifier lui-même.

1. Ouvrez la fiche du bien et cliquez sur **Signaler**. Vous pouvez aussi aller dans **Opérations > Signalements** et cliquer sur **Faire un signalement**, puis choisir le bien.
2. Choisissez la **nature du signalement** :
   - **Changement d'état** : indiquez l'état constaté ;
   - **Déplacement** : indiquez où se trouve le bien maintenant (local) et quel département l'utilise ;
   - **Besoin de maintenance**.
3. Choisissez l'**urgence** : normale, ou urgente si le problème bloque une activité.
4. Décrivez le problème dans **Description** (obligatoire) et ajoutez au besoin une **photo** (5 Mo au plus).
5. Cliquez sur **Envoyer le signalement**. Un message confirme : « Signalement … envoyé ».

### 4.4 Suivre mes signalements

1. Ouvrez **Opérations > Signalements** (ou la zone « Mes signalements » de Mon espace).
2. Chaque signalement a un statut : **Nouveau** (envoyé), **Pris en charge** (le gestionnaire s'en occupe), **Traité** (l'opération a été faite), **Rejeté** (avec le motif donné par le gestionnaire).
3. Les onglets (À traiter, Nouveaux, Pris en charge, Traités, Rejetés, Tous) filtrent la liste.

### 4.5 Participer à un inventaire

Vous saisissez un inventaire seulement si le gestionnaire vous a **désigné participant**. Dans Mon espace, la zone « Mes inventaires » indique « Vous y êtes désigné » et affiche le bouton **Participer à la saisie** quand l'inventaire est en cours.

1. Cliquez sur **Participer à la saisie** (ou **Saisir** dans **Opérations > Inventaires**).
2. Placez le curseur dans le champ « Scanner l'étiquette du bien ou saisir son numéro » et passez la douchette sur chaque bien du local contrôlé. Le bien est noté **Présent**. Vous pouvez choisir le local en cours de contrôle.
3. Pour un bien qui n'est pas là, utilisez **Manquant** sur sa ligne. Si le bien est présent dans un autre état, choisissez l'état constaté. Si le bien est présent mais ailleurs que prévu, utilisez **Autre affectation** et indiquez le département et le local constatés.
4. Un bien trouvé qui n'a pas de fiche : **Signaler un bien trouvé** et décrivez-le.
5. Votre saisie est enregistrée au fur et à mesure. D'autres personnes peuvent saisir en même temps.
6. Quand vous avez fini, **Terminer et voir le rapport**. La clôture et les régularisations sont faites ensuite par le gestionnaire et le comptable.

Vous ne pouvez pas créer, démarrer ni clôturer un inventaire.

### 4.6 Lire mes alertes

La cloche en haut de l'écran compte les alertes non lues : prêt arrivant à échéance ou en retard, intervention ouverte depuis longtemps, bien hors service, inventaire à venir. Ouvrez **Alertes**, cliquez sur **Marquer comme lue** quand c'est fait. Une alerte disparaît seule quand sa cause est réglée. Vous ne voyez que les alertes de votre périmètre.

## 5. Messages fréquents et quoi faire

| Message | Ce que cela veut dire | Quoi faire |
|---|---|---|
| « Aucun bien avec ce code » | Numéro inconnu, mal lu ou hors de votre périmètre. | Retapez le numéro. Sinon, avertissez le gestionnaire. |
| « Ce bien est introuvable ou hors de votre périmètre. » | Le bien n'est pas dans votre département. | Contactez le gestionnaire si le bien devrait y être. |
| Page 403 « accès refusé » | Cet écran n'est pas prévu pour votre rôle. | Revenez à Mon espace. Demandez l'accès à l'administrateur si nécessaire. |
| « Ligne pointée entre-temps » (inventaire) | Quelqu'un a pointé ce bien avant vous. | Vérifiez la ligne, c'est normal. |
| « La saisie de … est terminée » | L'inventaire n'est plus ouvert à la saisie. | Voyez le gestionnaire. |
| « Connexion au serveur perdue » | Réseau coupé ou serveur arrêté. | Cliquez sur Réessayer sans actualiser la page : vos saisies restent à l'écran. |
| Page 419 | Session expirée après 30 minutes. | Reconnectez-vous. |
| Compte bloqué | 5 mots de passe faux. | Attendez 15 minutes ou contactez l'administrateur. |

## 6. Ce que vous ne pouvez pas faire

- Modifier un bien, le transférer, le prêter, le sortir : signalez-le, le gestionnaire agit.
- Voir les biens d'un autre département ou d'un autre site hors de votre périmètre.
- Imprimer des étiquettes, enregistrer une acquisition, consulter les amortissements.
- Produire des rapports : avec les droits livrés par défaut, les rapports ne sont pas ouverts au responsable de département. Si votre Église le souhaite, l'administrateur peut vous donner le droit d'exporter les biens (rôle « Responsable de département », module « Biens », colonne « Exporter ») : l'inventaire de votre département devient alors disponible dans Rapports.

## 7. À qui s'adresser

- Un bien à réparer, déplacer, réformer : faites un signalement, puis le gestionnaire du patrimoine.
- Mot de passe oublié, compte bloqué, périmètre incomplet (un département manque) : l'administrateur de l'application.
- Anomalie de l'application : l'administrateur, qui prévient Webtinix.
