# Guide du gestionnaire du patrimoine

Ce guide s'adresse à la personne qui tient le patrimoine au quotidien : elle enregistre les biens, les déplace, les prête, suit leur entretien et organise les inventaires. Il ne demande aucune connaissance en informatique.

Le nom et le sigle de l'Église s'affichent dans l'application et sur les documents imprimés : ils viennent des paramètres, pas de ce guide.

## 1. Se connecter

1. Ouvrez votre navigateur (Chrome, Edge ou Firefox) et allez à l'adresse donnée par l'administrateur, par exemple `http://patrimoine.local`. Mettez-la en favori.
2. Saisissez votre **identifiant** (par exemple `a.okemba`) et votre mot de passe, puis validez.
3. Si l'administrateur vient de créer votre compte ou de réinitialiser votre mot de passe, l'application vous demande de choisir votre propre mot de passe avant toute autre chose.

Après 30 minutes sans rien faire, vous êtes déconnecté : reconnectez-vous. Après 5 mots de passe faux, le compte est bloqué 15 minutes. Si vous avez oublié votre mot de passe, voyez la section 7.

Les comptes de démonstration (`admin`, `a.okemba`, `m.loubaki`, `e.mbemba`…) n'existent que sur une installation d'essai. Leur mot de passe est connu de l'administrateur technique (fichier `database/seeders/DemoSeeder.php`). Ils ne doivent jamais servir sur le serveur réel.

## 2. Changer son mot de passe

1. Cliquez sur votre nom en haut à droite, puis sur **Mon profil**.
2. Dans la section « Changer le mot de passe », saisissez le mot de passe actuel, le nouveau (8 caractères minimum, dont une lettre et un chiffre, sans l'identifiant) et sa confirmation.
3. Enregistrez.

La même page vous montre votre rôle, votre périmètre (les sites et départements que vous voyez) et vos dernières opérations.

## 3. Ce que vous voyez et ce que vous pouvez faire

Le rôle « Gestionnaire du patrimoine » gère les biens et toutes les opérations courantes. Il consulte aussi l'amortissement, mais ne le paramètre pas.

| Domaine | Ce que vous pouvez faire |
|---|---|
| Biens, étiquettes, acquisitions | Consulter, créer, modifier, exporter |
| Mouvements (transferts) | Consulter, créer, modifier, exporter |
| Prêts et emprunts, maintenance, documents | Consulter, créer, modifier, exporter |
| Signalements | Consulter, créer, modifier, valider (traiter), exporter |
| Inventaires | Consulter, créer, modifier, valider, exporter |
| Sorties et réformes | Consulter, créer (enregistrer une sortie), exporter. La validation comptable revient au comptable. |
| Immobilisations et amortissements | Consulter et exporter seulement |
| Rapports | Consulter et exporter |
| Structure (sites, départements, locaux, catégories) | Consulter seulement |
| Journal des opérations | Consulter |
| Utilisateurs, rôles, paramètres | Aucun accès |

Votre **périmètre** peut être limité à certains sites ou départements : dans ce cas, toutes les listes, recherches, rapports et exports ne montrent que ces biens. C'est l'administrateur qui le règle.

Menu de gauche, de haut en bas : Accueil (Tableau de bord, Alertes), Patrimoine (Biens, Recherche par code, Étiquettes, Acquisitions), Opérations (Mouvements, Prêts et emprunts, Maintenance, Signalements, Inventaires, Sorties et réformes), Immobilisations, Pilotage (Rapports, Documents), Structure. Le menu se réduit selon vos droits.

En haut de l'écran : la recherche (numéro, désignation ou numéro de série), la cloche des alertes et votre menu.

## 4. Les tâches courantes, pas à pas

### 4.1 Créer un bien

1. Menu **Patrimoine > Biens**, bouton **Nouveau bien**. La page s'intitule « Enregistrer un bien ».
2. Le numéro patrimonial affiche « Attribué à l'enregistrement » : il est créé par l'application, vous ne le saisissez jamais.
3. Remplissez les sections :
   - **Identification** : désignation, catégorie (elle fixe la règle d'amortissement par défaut), marque, modèle, numéro de série, description ;
   - **Acquisition** : date d'acquisition, date de mise en service, fournisseur, référence de facture, prix d'acquisition en FCFA, mode de financement ;
   - **Affectation** : site, département, service, local, responsable ;
   - **État et statut** : état (condition physique) et statut. Les statuts « En maintenance », « Emprunté », « Cédé » et « Réformé » ne se choisissent pas : ils sont posés par une opération ;
   - **Immobilisation** : durée, valeur résiduelle, comptes, et la case « Forcer non amortissable » si besoin. Laissez vide pour reprendre les valeurs de la catégorie ;
   - **Photo** : facultative, 5 Mo au plus.
4. Cliquez sur **Créer**. Le message « Bien enregistré sous le numéro PAT-… » s'affiche et vous arrivez sur la fiche.

Le prix d'acquisition est obligatoire pour un bien amortissable. Un numéro de série déjà utilisé par un autre bien actif est refusé, avec un lien vers ce bien.

### 4.2 Enregistrer une acquisition (une facture, plusieurs biens)

1. **Patrimoine > Acquisitions**, bouton **Nouvelle acquisition**.
2. Section « Facture et affectation » : date, fournisseur, référence de facture, mode de financement, site de rattachement, département bénéficiaire.
3. Section « Lignes de la facture » : une ligne par article, avec la quantité. Pour chaque ligne, le réglage « suivi individuel » décide si l'application crée une fiche par unité (quantité 3 = 3 fiches) ou aucune (consommable).
4. Section « Justificatifs » : joignez la facture ou le bon de livraison (PDF, image, Word ou Excel).
5. Enregistrez. L'acquisition reste modifiable tant qu'elle n'est pas validée.
6. Sur la fiche de l'acquisition, cliquez sur **Valider et créer les fiches**, puis confirmez. L'application annonce par exemple « 3 fiche(s) créée(s) : PAT-000041 à PAT-000043 » et ouvre la page d'impression des étiquettes. Après validation, l'acquisition n'est plus modifiable.

Plus tard, le bouton **Imprimer les étiquettes** de la fiche de l'acquisition rouvre cette page.

### 4.3 Imprimer des étiquettes

1. **Patrimoine > Étiquettes** (page « Impression des étiquettes »). Vous y arrivez aussi depuis la fiche d'un bien (**Imprimer l'étiquette**), depuis la liste des biens (cochez des lignes puis **Imprimer les étiquettes**) ou après une acquisition.
2. Ajoutez des biens avec le champ « Scanner ou saisir un numéro pour l'ajouter » ou « Ou chercher un bien ».
3. Choisissez le **Support** (planche A4 ou rouleau), le **Modèle**, le nombre de **copies par bien** et, pour une planche, la **case de départ** : utile pour réutiliser une planche déjà entamée.
4. Cliquez sur **Générer le PDF**. Ouvrez-le et imprimez-le à 100 %, sans marges ajoutées par le navigateur.
5. Le bouton **Modifier le modèle** mène aux modèles d'étiquettes.

Un bien sorti du patrimoine ou hors de votre périmètre n'est pas retenu : un message l'indique.

### 4.4 Transférer un ou plusieurs biens

1. Sur la fiche du bien, bouton **Transférer** ; ou dans la liste des biens, cochez plusieurs lignes puis **Transférer**.
2. La fenêtre rappelle l'affectation actuelle. Saisissez la nouvelle (site, département, service, local, responsable) et les détails : date du transfert (pas dans le futur), motif, observations, justificatif facultatif.
3. Si le site ou le département figure sur l'étiquette, une case propose de réimprimer l'étiquette.
4. Validez avec **Enregistrer le transfert**. Chaque bien garde son historique : l'ancienne affectation n'est jamais effacée.

La liste complète des déplacements se lit dans **Opérations > Mouvements** (lecture seule).

### 4.5 Prêter un bien et enregistrer le retour

1. Sur la fiche d'un bien « En service » ou « En stock », bouton **Prêter** ; ou **Opérations > Prêts et emprunts > Nouveau prêt ou emprunt**.
2. Choisissez le type d'opération (prêt ou emprunt). Pour un prêt : ajoutez les biens (seuls les biens disponibles sont proposés), le bénéficiaire (interne : un département ; externe : nom et contact), la date de sortie, la date de retour prévue, les observations.
3. Pendant le prêt, le statut du bien est « Emprunté ». La liste a quatre onglets : Prêts en cours, En retard, Biens empruntés, Historique.
4. Au retour : sur le prêt, bouton **Enregistrer le retour**. Indiquez la date de retour effective et l'état de chaque bien, puis **Valider le retour**. Si un bien revient dans un état dégradé, l'application le signale et propose d'ouvrir une intervention de maintenance.
5. Sur la fiche d'un prêt, **Fiche de prêt (PDF)** produit un document à faire signer.

Un emprunt (bien d'un tiers prêté à l'Église) reçoit une référence `EXT-…` et n'entre ni dans le patrimoine ni dans les amortissements. Il se restitue avec le bouton **Restituer**.

### 4.6 Suivre la maintenance

1. **Opérations > Maintenance > Nouvelle intervention** (ou bouton **Maintenance** sur la fiche d'un bien).
2. Choisissez le bien, la nature (réparation, entretien préventif, diagnostic, remplacement de pièce), le motif, la date de début (une date à venir crée une intervention planifiée), l'intervenant (interne ou prestataire), le coût et sa référence.
3. La case « Passer le bien au statut En maintenance pendant l'intervention » met le bien hors disponibilité.
4. Une intervention planifiée se lance avec **Démarrer l'intervention**.
5. À la fin, **Clôturer l'intervention** : résultat (réparé, conforme, réparation partielle, irréparable), statut après intervention, date de fin, coût final, observations.
6. Si le résultat est « Irréparable », un bouton propose la sortie du patrimoine.

Le coût d'une intervention n'augmente pas la valeur amortissable du bien. Un bien ne peut avoir qu'une seule intervention ouverte à la fois.

### 4.7 Traiter un signalement

Les responsables de département signalent un changement d'état, un déplacement ou un besoin de maintenance. Un signalement ne modifie jamais le bien tout seul.

1. **Opérations > Signalements** : l'onglet « À traiter » liste les nouveaux et les pris en charge. Ouvrez-en un.
2. Cliquez sur **Prendre en charge** pour montrer que vous vous en occupez.
3. Selon la nature, le menu **Traiter** propose : **Créer une intervention**, **Enregistrer le transfert**, **Mettre à jour l'état**, **Proposer la sortie**, ou **Marquer comme traité**.
4. Si le signalement n'a pas lieu d'être : **Rejeter**, avec un motif obligatoire. Le responsable voit le statut de son signalement (Nouveau, Pris en charge, Traité, Rejeté).

### 4.8 Faire un inventaire à la douchette

**Préparer**

1. **Opérations > Inventaires > Nouvel inventaire**.
2. Section « Périmètre » : choisissez un site, un département, un service, une catégorie, un local ou tout le patrimoine (avec « Limiter au site » si besoin).
3. Section « Organisation » : date de début, date de fin prévue, responsable de l'inventaire, **participants** (avec, pour chacun, un local confié facultatif). Seules les personnes désignées et le gestionnaire saisissent l'inventaire.
4. **Planifier** pour garder l'inventaire à plus tard, ou **Démarrer maintenant**. Le démarrage **fige** la liste des biens attendus : un bien arrivé ensuite n'y entre pas.
5. Depuis la liste des biens, on peut aussi cocher des biens et utiliser **Ajouter à un inventaire** (inventaire planifié seulement).
6. **Imprimer la liste de contrôle** (page de saisie) donne une feuille papier.

**Saisir**

1. Dans la liste des inventaires, la ligne affiche **Saisir** pour un inventaire en cours.
2. Placez le curseur dans le champ « Scanner l'étiquette du bien ou saisir son numéro » et passez la douchette : elle tape le numéro puis Entrée. Le bien est pointé **Présent**.
3. Pour un bien non scanné, utilisez les boutons de sa ligne : **Présent** ou **Manquant**, et choisissez l'état constaté si différent. Le bouton **Autre affectation** enregistre un bien présent mais affecté ailleurs (département et local constatés). Le bouton d'observation ajoute une remarque.
4. Un bien scanné qui n'est pas dans la liste est noté « Présent hors périmètre ». Un bien trouvé sans fiche : **Signaler un bien trouvé** (description, site, département, local, état).
5. Plusieurs personnes peuvent saisir en même temps. Si quelqu'un a pointé la même ligne avant vous, un message « Ligne pointée entre-temps » l'indique.
6. Quand tout est pointé, **Terminer et voir le rapport**. Pour les biens restants, **Déclarer manquants les N biens non vérifiés** les classe en un geste.

**Valider et régulariser**

1. Le rapport présente les écarts par type. Un inventaire « À valider » est clôturé par **Valider et clôturer l'inventaire** : commentaire facultatif et case d'attestation du comptage sur place obligatoire. **Reprendre la saisie** le rouvre tant qu'il n'est pas clôturé.
2. Après clôture, les résultats sont figés. Les fiches ne changent pas toutes seules : régularisez chaque écart depuis le rapport : **Régulariser** (transfert), **Mettre à jour l'état**, **Créer la fiche** (bien trouvé sans fiche), **Marquer retrouvé**, ou une sortie pour perte.
3. **Procès-verbal (PDF)** (avec emplacements de signature) et **Exporter Excel** sont disponibles.

### 4.9 Sortir un bien du patrimoine

Un bien n'est jamais supprimé : il sort par une sortie, qui ne peut pas être annulée.

1. Sur la fiche du bien, bouton **Sortir** ; ou **Opérations > Sorties et réformes > Enregistrer une sortie**.
2. Choisissez le bien, le motif (cession, don, réforme, destruction, perte, vol, autre), la date de sortie, la personne qui a décidé et la référence de la décision. Pour une cession ou un don, indiquez l'acquéreur ; pour une cession, le prix. Joignez les justificatifs.
3. Le panneau « Valeurs à la date de sortie » montre la valeur d'origine, le cumul d'amortissement et la valeur nette comptable.
4. Cliquez sur **Confirmer la sortie**, saisissez exactement le numéro du bien, puis **Sortir définitivement du patrimoine**.
5. La fiche passe en lecture seule (bandeau « Sorti du patrimoine »). Le traitement comptable reste « À valider » jusqu'à la validation du comptable.

Un bien emprunté ou en maintenance ne peut pas sortir : enregistrez d'abord le retour ou clôturez l'intervention. Une sortie est aussi refusée dans un exercice clôturé.

### 4.10 Autres écrans utiles

- **Recherche par code** : scannez ou saisissez un numéro, Entrée ouvre la fiche. « Aucun bien avec ce code » propose **Créer la fiche de ce bien**.
- **Documents** (Pilotage) : **Ajouter des documents** en les rattachant à des biens ; ouvrir, télécharger. Seul un document qui n'est plus rattaché à aucun objet peut être supprimé.
- **Rapports** (Pilotage) : choisissez le rapport, réglez les filtres, **Appliquer**, puis **Exporter PDF** ou **Exporter Excel**.
- **Alertes** (cloche) : prêts à échéance ou en retard, interventions ouvertes depuis longtemps, biens hors service, inventaires à venir, écarts non régularisés, fin d'amortissement. **Marquer comme lue** ou **Tout marquer comme lu**. Une alerte disparaît quand sa cause est réglée.

## 5. Messages fréquents et quoi faire

| Message | Ce que cela veut dire | Quoi faire |
|---|---|---|
| « Cette fiche a été enregistrée par … depuis son ouverture. Rechargez la page… » | Quelqu'un a modifié la fiche pendant que vous la modifiiez. | Rechargez la page, relisez, refaites votre modification. |
| « Ce statut est posé par une opération (prêt, maintenance, sortie)… » | Vous avez choisi à la main un statut réservé. | Passez par l'opération correspondante. |
| « Le prix d'acquisition est obligatoire pour un bien amortissable. » | Bien amortissable sans prix. | Saisissez le prix ou cochez « non amortissable ». |
| Numéro de série déjà utilisé | Doublon avec un bien actif. | Suivez le lien « Voir le bien… ». |
| « Certains biens sont introuvables ou hors de votre périmètre. » | Un bien choisi est hors de vos sites ou départements. | Demandez à l'administrateur d'élargir le périmètre. |
| « Ce bien est emprunté : enregistrez d'abord son retour… » | On ne met pas en maintenance un bien prêté. | Enregistrez le retour du prêt. |
| « Ce bien a déjà une intervention ouverte » | Une seule intervention à la fois. | Clôturez la première. |
| « Saisissez exactement le numéro du bien… » | Confirmation de sortie incorrecte. | Recopiez le numéro affiché. |
| « La date de retour prévue ne peut pas précéder la date de sortie. » | Dates incohérentes. | Corrigez les dates. |
| « La liste de … est figée depuis son démarrage » | Ajout à un inventaire déjà démarré. | Créez un autre inventaire. |
| « Connexion au serveur perdue » | Réseau coupé ou serveur arrêté. | Cliquez sur Réessayer, sans actualiser la page : vos saisies restent à l'écran. |
| Page 403, 404, 419 | Accès refusé, page absente, session expirée. | Retournez à l'accueil ; reconnectez-vous pour la 419. |

## 6. Ce que vous ne pouvez pas faire

- Supprimer un bien, un mouvement, un prêt, une intervention ou une sortie : rien ne se supprime.
- Modifier la fiche d'un bien sorti.
- Valider un plan d'amortissement, modifier ses paramètres, clôturer un exercice ou valider une sortie : c'est le rôle du comptable et de l'administrateur.
- Créer des utilisateurs, régler les rôles et les paramètres.

## 7. À qui s'adresser

- Mot de passe oublié, compte bloqué, périmètre à élargir : l'administrateur de l'application.
- Question sur un amortissement ou une sortie à valider : le comptable.
- Anomalie de l'application : l'administrateur, qui prévient Webtinix.
