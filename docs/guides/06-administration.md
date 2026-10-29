# Guide d'administration

Ce guide explique comment administrer l'application : comptes, rôles et droits, périmètres, structure de l'Église, paramètres, sauvegarde et restauration. Il s'adresse à l'administrateur. Les écrans sont décrits pas à pas ; les opérations sur le serveur (sauvegarde, restauration, mise à jour) se font en ligne de commande et sont détaillées dans `docs/07-deploiement-local.md`, que ce guide résume.

## 1. Principes à connaître

- **Rien ne se supprime.** Un compte se désactive, un site ou un département inutilisé se désactive. Les biens, mouvements, prêts, interventions, sorties et exercices ne se suppriment jamais.
- **Chaque personne a son compte**, avec un seul rôle et un périmètre. Le rôle dit *ce qu'on peut faire*, le périmètre dit *sur quels biens*.
- **Tout est tracé** dans le journal des opérations, avec l'utilisateur, l'heure, le poste et les valeurs avant et après.
- **Aucun e-mail** : un mot de passe provisoire se remet en main propre.

## 2. Gérer les utilisateurs

Menu **Administration > Utilisateurs**. La liste affiche le nom, l'identifiant, le rôle, le périmètre, la dernière connexion et le statut. Filtrez par rôle ou par statut (Actifs, Désactivés).

### 2.1 Créer un compte

1. Bouton de création en haut de la liste (« Créer »).
2. Section « Compte » : prénom, nom, **identifiant**, e-mail (facultatif), téléphone, fonction. L'identifiant utilise des lettres sans accent, des chiffres, des points et des tirets, et il est passé en minuscules (exemple : `a.okemba`). Il doit être unique.
3. Section « Rôle et périmètre » : choisissez le **rôle**, puis, si besoin, les **sites accessibles** et les **départements accessibles** (voir la section 3.3).
4. Enregistrez. L'application génère un **mot de passe provisoire** et l'affiche **une seule fois** dans une notification : « Mot de passe : … — à communiquer en main propre. Il ne sera plus affiché. » Notez-le et remettez-le à la personne.
5. À sa première connexion, la personne doit choisir son propre mot de passe (8 caractères minimum, dont une lettre et un chiffre, sans l'identifiant) avant d'aller plus loin.

### 2.2 Réinitialiser un mot de passe ou débloquer un compte

1. Sur la ligne de l'utilisateur, ouvrez le menu d'actions (ou la fiche du compte) puis **Réinitialiser le mot de passe**.
2. Confirmez. Un nouveau mot de passe provisoire est généré et affiché une seule fois. Le compte est **débloqué** et la personne devra changer son mot de passe à la prochaine connexion.

Après 5 mots de passe faux, un compte est bloqué 15 minutes. C'est la réinitialisation, ou l'attente, qui le débloque.

### 2.3 Désactiver et réactiver

- **Désactiver** : le compte ne peut plus se connecter, son historique reste. Vous ne pouvez pas désactiver votre propre compte. Confirmez dans la fenêtre.
- **Réactiver** : rend l'accès.

Désactivez toujours le compte d'une personne qui quitte ses fonctions : ne le supprimez pas, ce n'est pas possible.

### 2.4 Modifier un compte

Ouvrez la ligne puis modifiez le rôle, le périmètre, la fonction… Le changement s'applique à la prochaine action de la personne. L'identifiant peut changer ; l'historique suit le compte.

## 3. Rôles, droits et périmètres

### 3.1 Les cinq rôles livrés

| Rôle | En une phrase |
|---|---|
| Administrateur | Accès complet, y compris utilisateurs, rôles et paramètres. |
| Gestionnaire du patrimoine | Gère les biens et toutes les opérations courantes. |
| Comptable | Paramètre et valide les amortissements et les sorties. |
| Responsable de département | Consulte les biens de son département, signale et participe aux inventaires. |
| Consultation | Lecture seule sur le périmètre autorisé. |

### 3.2 La matrice des droits

Menu **Administration > Rôles et permissions**. Ouvrez un rôle : pour chaque module, six cases : **Consulter, Créer, Modifier, Supprimer / sortir, Valider, Exporter**. Le bouton de la ligne coche ou décoche tout le module.

Droits livrés par défaut (C = consulter, + = créer, M = modifier, S = supprimer ou sortir, V = valider, E = exporter) :

| Module | Gestionnaire | Comptable | Responsable de dép. | Consultation |
|---|---|---|---|---|
| Biens | C + M E | C E | C | C |
| Étiquettes | C + M E | | | C |
| Acquisitions | C + M E | C E | | C |
| Mouvements | C + M E | C E | C | C |
| Inventaires | C + M V E | C E | C M | C |
| Prêts | C + M E | C | C | C |
| Maintenance | C + M E | C E | C | C |
| Signalements | C + M V E | | C + | C |
| Sorties et réformes | C + E | C V E | | C |
| Immobilisations et amortissements | C E | C + M V E | | C |
| Documents | C + M E | C + E | C | C |
| Rapports | C E | C E | C E | C E |
| Structure | C | C | | C |
| Utilisateurs et rôles | | | | |
| Paramètres | | | | |
| Journal des opérations | C | C E | | |

Le rôle **Administrateur** garde toujours tous les droits : on ne peut pas les lui retirer.

Lecture de la matrice :

- « Supprimer / sortir » n'est donné à personne parmi les rôles livrés : la sortie d'un bien est portée par « Créer » du module Sorties et réformes.
- Pour ouvrir un **rapport**, il faut « Consulter » les rapports et « Exporter » le module concerné (biens, prêts, immobilisations…). C'est pourquoi le responsable de département et la Consultation n'ont aucun rapport avec la matrice livrée. Pour ouvrir l'inventaire d'un département à un responsable, cochez « Exporter » sur le module « Biens » de son rôle.
- Seuls l'administrateur et le comptable modifient les règles d'amortissement, valident les plans et les sorties et clôturent les exercices, quelle que soit la matrice.

### 3.3 Créer un rôle sur mesure

1. **Administration > Rôles et permissions**, bouton de création.
2. Donnez un **nom du rôle** unique, cochez les droits voulus module par module, enregistrez.
3. Affectez le rôle à un utilisateur dans sa fiche.

Un rôle livré ne se renomme pas et ne se supprime pas. Un rôle personnalisé se supprime seulement s'il n'a plus aucun utilisateur.

### 3.4 Le périmètre

Dans la fiche de l'utilisateur, section « Rôle et périmètre » :

- **Sites accessibles** : laissez vide pour « Tous les sites », ou choisissez un ou plusieurs sites.
- **Départements accessibles** : laissez vide pour « Tous les départements », ou choisissez-en.

Le périmètre filtre **toutes** les listes, recherches, rapports, exports, alertes et inventaires de la personne. Un responsable de département a normalement un ou plusieurs départements ; un gestionnaire d'une annexe peut être limité à son site. Un emprunt (bien d'un tiers) ne peut être enregistré que par un utilisateur sans périmètre restreint.

Seuls les sites et départements **actifs** peuvent être choisis.

## 4. La structure de l'Église

À l'installation, l'application ne contient aucun site, département ni local : rien n'est supposé sur votre Église. Saisissez-les dans le groupe **Structure** avant de créer les utilisateurs (pour leur donner un périmètre) et les biens.

Ordre conseillé :

1. **Sites** : nom du site, adresse ou localisation, description, case « Site actif ».
2. **Départements et services** : nom du département, **sites de rattachement** (un département peut exister sur plusieurs sites). Les **services** d'un département se gèrent dans l'onglet de sa fiche.
3. **Locaux** : nom, site, type, département associé. Un local appartient à un site.
4. **Catégories et référentiels** : onglets pour les **catégories de biens** (nom, préfixe de trois lettres pour la numérotation « par catégorie », amortissable ou non, méthode et durée par défaut, comptes d'immobilisation, d'amortissement et de dotation), les **états** (libellé, couleur du badge), les **types de documents**, les **fournisseurs** et la page **Statuts, motifs et financements** (en lecture : ces listes sont fixes).
5. **Modèles d'étiquettes** (groupe Patrimoine) : contenu et format des étiquettes.

Une valeur déjà utilisée ne se supprime pas : désactivez-la, elle n'est plus proposée mais l'historique reste lisible.

## 5. Les paramètres

Les paramètres de l'application se règlent dans l'écran **Administration > Paramètres** (`/admin/parametres`), réservé à l'administrateur. Le seuil d'immobilisation et la méthode dégressive restent sur leur écran propre : bouton **Règles d'amortissement** en haut de l'écran Paramètres, ou menu Immobilisations > Règles d'amortissement.

### 5.1 Enregistrer les réglages

L'écran a cinq onglets. Un seul bouton **Enregistrer** (en haut à droite) enregistre les changements de tous les onglets à la fois.

1. **Identité** : renseignez **Nom de l'Église** (120 caractères au plus) et **Sigle** (12 caractères au plus). Ils sont repris dans l'en-tête des documents PDF et sur les étiquettes. Renseignez-les avant d'imprimer quoi que ce soit : par défaut, ce sont des textes provisoires entre crochets.
2. **Numérotation** : choisissez le **Format du numéro** (« Numéro continu » PAT-000001, « Avec l'année, compteur annuel » PAT-2026-000001, ou « Par catégorie, préfixe de la catégorie » INF-000001) et le **Préfixe** (2 à 6 lettres ou chiffres, sans effet avec le format par catégorie). L'écran affiche un exemple et le prochain numéro attribué. **Dès qu'un bien existe, format et préfixe sont verrouillés** (un message orange l'indique) : un numéro attribué ne change jamais. Pour changer de format après coup, contactez Webtinix.
3. **Alertes** : quatre délais, modifiables dans leurs bornes : « Prévenir avant l'échéance d'un prêt » (0 à 60 jours, 3 par défaut), « Signaler une intervention ouverte depuis » (1 à 365 jours, 15), « Rappel d'un inventaire planifié » (0 à 60 jours avant, 7), « Prévenir avant la fin d'un amortissement » (1 à 24 mois, 3). Les nouveaux délais s'appliquent au prochain calcul des alertes, c'est-à-dire dans l'heure.
4. **Sécurité** : **Déconnexion après inactivité** (de 5 à 480 minutes) et **Taille maximale d'un document** en Mo. La durée d'inactivité s'applique dès la requête suivante de chaque utilisateur connecté. La taille ne peut pas dépasser la limite de PHP sur le serveur, indiquée sous le champ (voir l'onglet « État du serveur » et le guide d'installation). La section « Règles fixes (lecture seule) » rappelle : mot de passe de 8 caractères minimum avec un chiffre, blocage du compte pendant 15 minutes après 5 échecs consécutifs, réinitialisation d'un mot de passe oublié par l'administrateur (mot de passe provisoire à changer à la première connexion). Ces règles ne se règlent pas ici.
5. **État du serveur** : lecture seule. Version de PHP, extensions, base de données, limites d'envoi de fichiers, espace disque, planificateur (date du dernier calcul des alertes) et dernière sauvegarde. Chaque ligne est marquée OK, À surveiller ou Anomalie. Rien ne se modifie ici : consultez-la après une installation ou une mise à jour.

Cliquez sur **Enregistrer**. Trois résultats possibles :

| Message | Ce que cela veut dire | Quoi faire |
|---|---|---|
| « Paramètres enregistrés » | Les changements sont pris en compte et inscrits au journal des opérations (valeurs avant et après). | Rien. |
| « Aucune modification à enregistrer » | Rien ne diffère des valeurs en vigueur. | Modifiez une valeur. |
| « Paramètres non enregistrés » | Une valeur est hors bornes, vide, ou « Ce réglage a été modifié par un autre administrateur depuis l'ouverture de l'écran. » | Corrigez la valeur indiquée ; en cas de modification par un autre administrateur, rechargez la page puis recommencez. |

Seuls les champs que vous avez changés sont écrits. Un changement du format de numéro ne s'applique qu'aux **nouveaux** biens.

### 5.2 Valeurs par défaut

Tant que rien n'est enregistré, l'application utilise ces valeurs :

| Paramètre | Valeur par défaut |
|---|---|
| Nom et sigle de l'Église | un texte provisoire entre crochets |
| Format du numéro / préfixe | « Numéro continu » / `PAT` |
| Seuil d'immobilisation (écran Règles d'amortissement) | 100 000 FCFA |
| Taille maximale d'un document | 20 Mo |
| Délais d'alerte | 3 jours (prêt), 15 jours (intervention), 7 jours (inventaire), 3 mois (amortissement) |
| Déconnexion après inactivité | 30 minutes |

La durée d'inactivité par défaut vient du fichier `.env` du serveur (`SESSION_LIFETIME`, 30 minutes) : elle ne s'applique que **tant qu'aucune durée n'est enregistrée** dans l'écran Paramètres ; ensuite, la valeur de l'écran l'emporte. L'adresse de l'application (`APP_URL`) reste un réglage du `.env` ; après toute modification de `.env`, lancer `php artisan optimize`.

## 6. Surveiller et auditer

- **Journal des opérations** : qui a fait quoi, quand, d'où. Voir le guide de l'administrateur, section 5.1.
- **Alertes** : calculées automatiquement toutes les heures par le planificateur de Windows (tâche « Patrimoine - planificateur » qui lance `schedule:run` chaque minute). Si les alertes ne se mettent plus à jour, vérifier que cette tâche existe et que le serveur n'est pas en veille.
- **Documents** : les fichiers sont stockés sur le serveur, hors du dossier public, et servis seulement après contrôle des droits.

## 7. Sauvegarde, restauration, mise à jour

Les commandes se lancent dans une invite de commande (PowerShell) ouverte dans le dossier de l'application, par exemple `C:\patrimoine`. Le détail complet est dans `docs/07-deploiement-local.md`.

### 7.1 Sauvegarder (chaque semaine, et avant toute mise à jour)

```
php artisan patrimoine:sauvegarder E:\Sauvegardes
```

- Le dossier de destination doit **exister** (disque externe branché ou partage réseau), hors du dossier de l'application.
- La commande crée un dossier daté contenant la base, les fichiers privés (photos, documents) et un manifeste de contrôle.
- Elle relit et contrôle la sauvegarde. **Ne la considérez réussie que si le message « Sauvegarde terminée et contrôlée » s'affiche.**
- Elle **ne contient pas** le fichier `.env` (clé de l'application et mot de passe de la base) : gardez-en une copie à part, en lieu sûr. La sauvegarde n'est pas chiffrée : rangez le disque en lieu sûr.
- Aucune ancienne sauvegarde n'est supprimée automatiquement : gardez les quatre dernières plus la dernière de chaque mois, et faites le ménage à la main.

### 7.2 Restaurer (incident uniquement)

La restauration **remplace les données actuelles**.

1. Mettre l'application en maintenance : `php artisan down` (la commande refuse de restaurer sinon).
2. Lancer : `php artisan patrimoine:restaurer E:\Sauvegardes\patrimoine-20261028-143015` (le dossier daté voulu).
3. La commande contrôle la sauvegarde, affiche sa date, puis demande de **taper le nom de la base** à écraser. Elle sauvegarde d'abord l'état actuel (dossier `avant-restauration-…`) : pour revenir en arrière, restaurez ce dossier.
4. Terminer : `php artisan optimize:clear`, `php artisan optimize`, `php artisan up`, puis ouvrir l'application et contrôler un bien récent et une pièce jointe.

Restaurez vers la **même version** de l'application que celle de la sauvegarde.

### 7.3 Mettre à jour

1. `php artisan down`.
2. Sauvegarder (7.1) et vérifier le message de contrôle.
3. Remplacer les fichiers de l'application **sauf** `.env` et le dossier `storage\`.
4. `php artisan migrate --force` puis `php artisan optimize`.
5. `php artisan up`, ouvrir l'application et vérifier.

### 7.4 Contrôler l'installation

`php artisan patrimoine:verifier` rejoue les contrôles (version de PHP, extensions, dossiers, clé, base) sans rien modifier. Utile après un changement de poste ou une panne.

## 8. Messages fréquents

| Message | Quoi faire |
|---|---|
| « La valeur du champ identifiant est déjà utilisée. » ou refus de l'identifiant | Choisir un autre identifiant (lettres sans accent, chiffres, points, tirets). |
| « Un site inactif ne peut pas être choisi. » | Réactiver le site, ou en choisir un autre. |
| Un rôle ne peut pas être supprimé | Rôle livré, ou encore attribué à un utilisateur : changer le rôle des personnes concernées. |
| La commande de sauvegarde refuse la destination | Le dossier n'existe pas, n'est pas accessible en écriture, ou se trouve dans le dossier de l'application. |
| La restauration refuse de démarrer | Mettre d'abord l'application en maintenance avec `php artisan down`. |
| Envoi d'un document refusé (taille) | Le fichier dépasse 20 Mo, ou le `php.ini` du serveur web n'a pas `upload_max_filesize = 25M` et `post_max_size = 30M`. |
| Page 500 | Lire `storage\logs\laravel.log`, noter l'heure, prévenir Webtinix. |

## 9. À qui s'adresser

Webtinix pour toute anomalie, mise à jour ou question technique (installation, base de données, serveur). Préparez : l'heure, l'identifiant concerné, le message exact et, pour une erreur 500, l'extrait du fichier `storage\logs\laravel.log`.
