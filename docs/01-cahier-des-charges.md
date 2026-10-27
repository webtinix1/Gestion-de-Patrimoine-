CAHIER DES CHARGES FONCTIONNEL ET TECHNIQUE

Application web de gestion du patrimoine matériel et des immobilisations de l’Église

Version : 3.0 — Version consolidée
Projet : Développement d’une application de gestion du patrimoine
Type de solution : Application web centralisée
Mode de déploiement : Serveur local ou ordinateur faisant office de serveur
Utilisateurs cibles : Administration, responsables de départements, gestionnaires du patrimoine et utilisateurs autorisés

⸻

1. Présentation générale du projet

1.1. Contexte

L’Église dispose d’un patrimoine matériel composé de différents biens, équipements, mobiliers et matériels répartis entre ses sites, départements, services et locaux.

La gestion de ces biens nécessite un outil centralisé permettant de connaître précisément :

* Les biens détenus par l’Église.
* Leur localisation physique.
* Leur département ou service d’affectation.
* Leur responsable.
* Leur état de fonctionnement.
* Leur valeur patrimoniale.
* Leur historique d’acquisition et de mouvement.
* Leur situation lors des inventaires physiques.
* Leur valeur comptable après amortissement.

Le projet consiste à concevoir et développer une application web permettant d’assurer la gestion et le suivi du patrimoine matériel de l’Église.

L’application devra être accessible depuis plusieurs ordinateurs connectés au réseau local, au moyen d’un navigateur web.

Elle pourra être déployée sur un serveur local dédié ou sur un ordinateur configuré pour faire office de serveur.

La solution devra fonctionner sans dépendance obligatoire à une connexion Internet permanente.

1.2. Objectifs du projet

Les objectifs principaux sont les suivants :

1. Centraliser les informations relatives aux biens de l’Église.
2. Mettre en place un système d’identification unique des matériels.
3. Générer et imprimer des étiquettes d’identification.
4. Suivre les affectations des biens par site, département, service et responsable.
5. Historiser les mouvements et changements d’affectation.
6. Faciliter les inventaires physiques.
7. Suivre l’état et la valeur patrimoniale des biens.
8. Calculer automatiquement les amortissements des immobilisations.
9. Générer les plans d’amortissement individuels et les états récapitulatifs.
10. Gérer les prêts, les maintenances et les sorties du patrimoine.
11. Produire des rapports et états de gestion.
12. Assurer la traçabilité des opérations réalisées dans l’application.

1.3. Périmètre général

La première version de l’application comprendra les modules suivants :

* Gestion des utilisateurs et des permissions.
* Gestion des sites, départements et services.
* Gestion des catégories de biens.
* Gestion du patrimoine matériel.
* Génération des identifiants et étiquettes.
* Gestion des acquisitions.
* Gestion des affectations et mouvements.
* Gestion des inventaires.
* Gestion des prêts et emprunts.
* Gestion de la maintenance.
* Gestion des immobilisations et amortissements.
* Gestion des sorties et réformes.
* Gestion documentaire.
* Tableau de bord et rapports.

L’application ne sera pas une application mobile native. Elle sera accessible par navigateur web depuis les postes autorisés.

⸻

2. Architecture et mode de déploiement

2.1. Type d’application

La solution devra être une application web centralisée.

Elle devra être :

* Accessible depuis un navigateur web.
* Utilisable depuis plusieurs ordinateurs.
* Déployable sur un serveur local.
* Déployable sur un ordinateur configuré comme serveur.
* Utilisable sur le réseau local sans connexion Internet permanente.
* Dotée d’une base de données centralisée.
* Adaptée à un usage administratif quotidien.

L’installation de l’application ne devra pas nécessiter une installation complète sur chaque ordinateur utilisateur.

2.2. Déploiement local

L’application pourra être installée sur :

* Un serveur local dédié.
* Un ordinateur de bureau ou une machine dédiée configurée pour héberger l’application.
* Une infrastructure locale adaptée aux besoins de l’Église.

Les utilisateurs autorisés accéderont à l’application à travers une adresse locale, par exemple :

http://patrimoine.local

L’adresse exacte dépendra de la configuration du réseau et du serveur.

Le serveur local devra héberger les composants nécessaires au fonctionnement de l’application, notamment :

* L’application web.
* La base de données.
* Les fichiers et documents associés aux biens.
* Les composants nécessaires à la génération des rapports et étiquettes.

2.3. Accès des utilisateurs

Plusieurs utilisateurs pourront accéder simultanément à l’application depuis leurs ordinateurs, sous réserve des capacités de l’infrastructure retenue.

Les données seront centralisées afin d’éviter la création de plusieurs bases de données indépendantes.

Les modifications effectuées par un utilisateur devront être visibles par les autres utilisateurs autorisés après actualisation des données.

2.4. Fonctionnement sans Internet

L’application devra fonctionner sur le réseau local, sans dépendance obligatoire à des services cloud.

Les fonctions principales devront rester opérationnelles lorsque la connexion Internet est indisponible, notamment :

* Consultation du patrimoine.
* Création et modification des biens.
* Gestion des affectations.
* Gestion des mouvements.
* Réalisation des inventaires.
* Génération des étiquettes.
* Calcul des amortissements.
* Impression des rapports.
* Consultation des historiques.

2.5. Évolutivité

L’architecture devra permettre une évolution ultérieure vers :

* Un déploiement sur serveur distant.
* Un hébergement cloud.
* Un accès sécurisé à distance.
* Une gestion multi-sites étendue.
* Une sauvegarde automatisée.
* Une restauration des données.
* Une synchronisation éventuelle entre plusieurs infrastructures.

Ces évolutions ne font pas partie du périmètre de la première version.

⸻

3. Gestion des utilisateurs et des droits d’accès

L’application devra intégrer un système d’authentification permettant de sécuriser l’accès aux données.

3.1. Profils utilisateurs

Administrateur

L’administrateur pourra :

* Créer, modifier et désactiver les utilisateurs.
* Gérer les rôles et permissions.
* Configurer les paramètres de l’application.
* Gérer les sites, départements et services.
* Gérer les catégories de biens.
* Accéder à l’ensemble du patrimoine.
* Créer et modifier les biens.
* Gérer les affectations et mouvements.
* Générer et imprimer les étiquettes.
* Réaliser les inventaires.
* Consulter les rapports.
* Consulter les historiques.
* Configurer les paramètres d’amortissement.

Gestionnaire du patrimoine

Le gestionnaire pourra :

* Enregistrer les nouveaux biens.
* Modifier les fiches des biens selon ses permissions.
* Affecter les biens aux départements et services.
* Enregistrer les mouvements.
* Réaliser les inventaires.
* Générer les étiquettes.
* Enregistrer les prêts et retours.
* Enregistrer les opérations de maintenance.
* Consulter les rapports et historiques.
* Consulter les informations patrimoniales et les plans d’amortissement.

Responsable de département

Le responsable de département pourra :

* Consulter les biens affectés à son département.
* Consulter les fiches des biens.
* Signaler un changement d’état.
* Signaler un déplacement ou un besoin de maintenance.
* Participer aux opérations d’inventaire.
* Consulter les rapports autorisés.

Utilisateur en consultation

Ce profil pourra consulter les informations auxquelles il est autorisé à accéder, sans pouvoir modifier les données.

Les rôles devront être paramétrables afin de pouvoir adapter les droits aux besoins de l’Église.

⸻

4. Gestion de la structure organisationnelle

4.1. Gestion des sites

L’administrateur pourra créer et gérer les différents sites ou lieux de rattachement.

Exemples :

* Siège.
* Église locale.
* Annexe.
* Centre administratif.
* Établissement ou structure rattachée.

Chaque site devra disposer d’une fiche contenant :

* Nom du site.
* Code du site.
* Adresse ou localisation.
* Responsable.
* Description.
* Statut actif ou inactif.

4.2. Gestion des départements et services

L’application devra permettre de créer et gérer les départements et services.

Exemples :

* Administration.
* Finances.
* Communication.
* Informatique.
* Louange.
* Jeunesse.
* École du dimanche.
* Protocole.
* Logistique.

Chaque département pourra être rattaché à un site.

Les départements et services devront être personnalisables par l’administrateur.

4.3. Gestion des locaux

L’application devra permettre d’enregistrer les différents locaux d’un site.

Exemples :

* Bureau administratif.
* Salle de réunion.
* Salle de culte.
* Salle informatique.
* Magasin.
* Salle de formation.

Chaque local pourra être associé à un site et, si nécessaire, à un département.

⸻

5. Gestion du patrimoine matériel

5.1. Enregistrement des biens

L’application devra permettre d’enregistrer chaque bien individuellement.

La fiche d’un bien devra contenir les informations suivantes :

Champ	Description
Identifiant patrimonial	Numéro unique généré automatiquement
Désignation	Nom du matériel
Catégorie	Catégorie du bien
Marque	Marque du matériel
Modèle	Modèle ou référence
Numéro de série	Numéro de série constructeur
Description	Description complémentaire
Date d’acquisition	Date d’achat ou d’entrée dans le patrimoine
Fournisseur	Fournisseur du bien
Référence de facture	Référence du justificatif d’achat
Prix d’acquisition	Valeur d’acquisition
Date de mise en service	Date de début d’utilisation
Site	Site de rattachement
Département	Département d’affectation
Local	Localisation physique
Responsable	Responsable ou utilisateur affectataire
État	État actuel du bien
Statut	Situation du bien
Photo	Photo facultative
Documents	Documents justificatifs associés
Observations	Informations complémentaires

Certains champs pourront être facultatifs selon la nature du bien.

5.2. Catégories de biens

L’application devra permettre de créer et gérer des catégories de biens.

Exemples :

* Matériel informatique.
* Mobilier.
* Matériel audiovisuel.
* Matériel électrique.
* Matériel de bureau.
* Matériel de sonorisation.
* Matériel de transport.
* Matériel de culte.
* Matériel de cuisine.
* Autres équipements.

Les catégories devront être personnalisables.

5.3. État des biens

Chaque bien devra disposer d’un état permettant de connaître sa condition physique ou fonctionnelle.

Exemples :

* Neuf.
* Très bon état.
* Bon état.
* État moyen.
* À réparer.
* Hors service.

5.4. Statut des biens

Le statut devra indiquer la situation administrative du bien.

Exemples :

* En service.
* En stock.
* En maintenance.
* Emprunté.
* Hors service.
* Cédé.
* Réformé.

L’état et le statut devront être deux informations distinctes.

⸻

6. Identification des biens et génération des étiquettes

Cette fonctionnalité constitue un élément central de l’application.

6.1. Identifiant patrimonial unique

Chaque bien enregistré devra recevoir automatiquement un numéro d’identification unique.

Cet identifiant devra :

* Être généré automatiquement.
* Être unique dans l’application.
* Être permanent.
* Ne pas être modifié lors d’un changement de département ou de site.
* Être affiché sur la fiche du bien.
* Être utilisé pour les recherches.
* Être imprimable sur une étiquette.

Exemple de format :

PAT-000001

D’autres formats pourront être définis selon les besoins de l’Église :

* PAT-2026-000001.
* INF-000001 pour le matériel informatique.
* MOB-000001 pour le mobilier.

Le format devra être configurable.

6.2. Génération des étiquettes

L’application devra permettre de générer des étiquettes d’identification pour les biens enregistrés.

Chaque étiquette pourra comporter :

* Le nom ou le sigle de l’Église.
* Le numéro unique du bien.
* La désignation du matériel.
* Le département ou service d’affectation.
* Le site de rattachement, si nécessaire.
* Un QR code ou un code-barres contenant l’identifiant patrimonial.

Exemple :

ÉGLISE — GESTION DU PATRIMOINE

N° : PAT-000125

Ordinateur portable HP

Département : Administration

QR code contenant : PAT-000125

Le modèle d’étiquette devra être personnalisable.

6.3. Impression des étiquettes

L’application devra permettre :

* L’impression de l’étiquette d’un seul bien.
* La sélection de plusieurs biens.
* L’impression des étiquettes en lot.
* La génération d’une planche d’étiquettes.
* La prévisualisation avant impression.
* La réimpression d’une étiquette.
* Le choix du format d’étiquette.

Les étiquettes devront pouvoir être imprimées sur une imprimante classique ou une imprimante d’étiquettes compatible.

La mise en page devra être adaptée aux formats courants.

6.4. Recherche par QR code ou code-barres

L’application devra permettre de retrouver un bien à partir de son identifiant.

La recherche pourra se faire :

* Par saisie manuelle du numéro.
* Par lecture d’un QR code.
* Par lecture d’un code-barres avec un lecteur compatible.

La lecture d’un identifiant devra permettre d’ouvrir rapidement la fiche correspondante.

Les lecteurs de codes USB fonctionnant comme des claviers devront pouvoir être utilisés sans installation complexe.

6.5. Identifiants non réutilisables

Un identifiant attribué à un bien ne devra jamais être réutilisé pour un autre bien, même si le premier bien est réformé, cédé ou supprimé de la liste active.

Les biens sortis du patrimoine devront conserver leur historique.

⸻

7. Gestion des acquisitions

L’application devra permettre d’enregistrer les acquisitions de nouveaux biens.

Les informations suivantes devront être disponibles :

* Date d’acquisition.
* Fournisseur.
* Référence de la facture.
* Désignation du bien.
* Quantité.
* Prix unitaire.
* Montant total.
* Mode de financement, si nécessaire.
* Département bénéficiaire.
* Site de rattachement.
* Documents justificatifs.

Une acquisition pourra comporter plusieurs biens.

Lorsqu’une acquisition comprend plusieurs matériels identifiables individuellement, chaque matériel devra disposer de sa propre fiche et de son propre identifiant patrimonial.

L’application devra permettre de générer les étiquettes des biens nouvellement acquis à la suite de leur enregistrement.

⸻

8. Gestion des affectations et mouvements

8.1. Affectation initiale

Lors de l’enregistrement d’un bien, l’application devra permettre de définir :

* Le site.
* Le département.
* Le service.
* Le local.
* Le responsable ou utilisateur affectataire.

8.2. Transfert d’un bien

Un transfert pourra être réalisé entre :

* Deux départements.
* Deux services.
* Deux locaux.
* Deux sites.
* Deux responsables.

Chaque transfert devra enregistrer :

* Le bien concerné.
* L’ancienne affectation.
* La nouvelle affectation.
* La date du transfert.
* Le motif.
* L’utilisateur ayant réalisé l’opération.
* Les observations éventuelles.

8.3. Historique des mouvements

Chaque bien devra disposer d’un historique consultable de ses mouvements.

Cet historique devra permettre de connaître :

* L’ancienne localisation.
* Le département précédent.
* Le responsable précédent.
* La nouvelle localisation.
* Le nouveau département.
* Le nouveau responsable.
* La date du changement.
* L’utilisateur ayant enregistré l’opération.

Un changement d’affectation ne devra pas effacer les anciennes informations.

⸻

9. Gestion des immobilisations et calcul des amortissements

9.1. Objectif

L’application devra intégrer un module de gestion des immobilisations permettant de suivre la valeur patrimoniale et comptable des biens.

Ce module devra permettre de connaître, pour chaque bien immobilisable :

* Sa valeur d’origine.
* Sa date de mise en service.
* Sa durée d’amortissement.
* Sa méthode d’amortissement.
* Son taux d’amortissement.
* Sa dotation annuelle.
* Sa dotation par exercice.
* Ses amortissements cumulés.
* Sa valeur nette comptable.
* La durée restante d’amortissement.

Le calcul des amortissements devra être une fonctionnalité opérationnelle de la V1.

9.2. Paramètres d’immobilisation

Pour chaque bien concerné, l’application devra permettre de renseigner :

* La valeur d’acquisition.
* La date d’acquisition.
* La date de mise en service.
* La durée d’amortissement.
* La méthode d’amortissement.
* Le taux d’amortissement.
* La valeur résiduelle, si applicable.
* Le compte d’immobilisation, si nécessaire.
* Le compte d’amortissement, si nécessaire.
* Le statut de l’immobilisation.

Les durées et taux d’amortissement devront pouvoir être configurés selon la catégorie du bien.

L’application devra permettre de distinguer les biens amortissables des biens non amortissables.

9.3. Méthodes d’amortissement

9.3.1. Amortissement linéaire

L’application devra prendre en charge l’amortissement linéaire.

Le montant amortissable sera réparti sur la durée d’utilisation prévue du bien.

Le montant amortissable sera calculé comme suit :

Valeur amortissable = Valeur d’acquisition − Valeur résiduelle

Lorsque la valeur résiduelle n’est pas renseignée, elle sera considérée comme nulle, sous réserve des paramètres retenus par l’Église.

Le taux annuel théorique sera calculé selon la formule :

Taux annuel = 100 / Durée d’amortissement en années

La dotation annuelle théorique sera calculée selon la formule :

Dotation annuelle = Valeur amortissable / Durée d’amortissement

Le calcul de la dotation de la première et de la dernière période devra tenir compte de la date de mise en service et de la durée effective d’utilisation.

9.3.2. Amortissement dégressif

La méthode dégressive pourra être prise en charge si elle est retenue par l’Église et validée au regard des règles comptables applicables.

Elle devra être paramétrable et distincte de la méthode linéaire.

L’application devra permettre de sélectionner la méthode applicable à chaque immobilisation.

9.4. Calcul automatique des amortissements

L’application devra calculer automatiquement :

* Le taux d’amortissement.
* La dotation annuelle théorique.
* La dotation de chaque exercice.
* Les amortissements cumulés.
* La valeur nette comptable.
* La durée restante d’amortissement.

Les calculs devront être réalisés à partir des paramètres enregistrés sur la fiche de l’immobilisation.

Le calcul devra tenir compte :

* De la date de mise en service.
* De la durée d’amortissement.
* De la méthode choisie.
* De la valeur amortissable.
* Des règles de prorata applicables.
* Des exercices comptables définis dans l’application.

Les amortissements cumulés ne devront jamais dépasser la valeur amortissable du bien.

La valeur nette comptable ne devra pas être négative.

9.5. Plan d’amortissement

L’application devra générer automatiquement un plan d’amortissement pour chaque immobilisation.

Le plan devra présenter, pour chaque exercice :

Information	Description
Exercice	Exercice comptable concerné
Valeur d’origine	Valeur d’acquisition du bien
Base amortissable	Valeur amortissable retenue
Taux	Taux d’amortissement applicable
Dotation de l’exercice	Montant amorti sur l’exercice
Amortissements cumulés	Total des amortissements constatés
Valeur nette comptable	Valeur résiduelle comptable en fin d’exercice

Le plan d’amortissement devra être consultable depuis la fiche du bien.

Il devra pouvoir être exporté en PDF et en Excel.

9.6. Exemple de calcul

Pour un matériel acquis à 600 000 FCFA, amorti sur 3 ans selon la méthode linéaire, avec une valeur résiduelle nulle :

Élément	Valeur
Valeur d’acquisition	600 000 FCFA
Durée d’amortissement	3 ans
Taux annuel théorique	33,33 %
Dotation annuelle théorique	200 000 FCFA
Amortissement cumulé après 1 an	200 000 FCFA
VNC après 1 an	400 000 FCFA
Amortissement cumulé après 2 ans	400 000 FCFA
VNC après 2 ans	200 000 FCFA
Amortissement cumulé après 3 ans	600 000 FCFA
VNC après 3 ans	0 FCFA

Cet exemple est donné à titre illustratif. Les calculs réels devront tenir compte de la date de mise en service et des règles de prorata retenues.

9.7. Suivi des amortissements

L’application devra permettre de consulter :

* Les immobilisations en cours d’amortissement.
* Les immobilisations totalement amorties.
* Les dotations annuelles.
* Les amortissements cumulés.
* Les valeurs nettes comptables.
* Les immobilisations dont la durée d’amortissement est arrivée à échéance.
* Les immobilisations par site.
* Les immobilisations par département.
* Les immobilisations par catégorie.

9.8. Modification des paramètres d’amortissement

Toute modification d’un paramètre ayant une incidence sur le calcul des amortissements devra être historisée.

L’application devra conserver :

* Le paramètre modifié.
* L’ancienne valeur.
* La nouvelle valeur.
* La date de modification.
* L’utilisateur ayant effectué la modification.
* Le motif de la modification, si nécessaire.

La modification d’un plan d’amortissement devra être encadrée afin de préserver la cohérence des exercices antérieurs.

Les plans d’amortissement validés devront être conservés.

9.9. Rapports d’amortissement

L’application devra produire :

* Un tableau général des immobilisations.
* Un état des dotations par exercice.
* Un état des amortissements cumulés.
* Un état des valeurs nettes comptables.
* Un état des immobilisations totalement amorties.
* Un état des immobilisations par catégorie.
* Un état des immobilisations par site.
* Un état des immobilisations par département.

Les rapports devront pouvoir être exportés en PDF et en Excel.

9.10. Précision comptable

Le module devra permettre le suivi patrimonial des immobilisations et le calcul de leurs amortissements.

Il ne devra pas être assimilé à un logiciel complet de comptabilité générale.

Les paramètres comptables, les durées d’amortissement et les règles de calcul devront être validés avec le responsable comptable de l’Église, notamment au regard du référentiel SYSCOHADA applicable.

⸻

10. Gestion des prêts et emprunts de matériels

L’application devra permettre de gérer les biens prêtés ou empruntés.

Les informations à enregistrer seront :

* Le bien concerné.
* Le bénéficiaire.
* La date de sortie.
* La date de retour prévue.
* La date de retour effective.
* L’état du bien au départ.
* L’état du bien au retour.
* Le responsable de l’opération.
* Les observations.

L’application devra permettre de consulter :

* Les biens actuellement prêtés.
* Les prêts en cours.
* Les retards de retour.
* L’historique des prêts.
* Les biens empruntés par l’Église, le cas échéant.

⸻

11. Gestion de la maintenance et des réparations

L’application devra permettre de suivre les opérations de maintenance des biens.

Pour chaque intervention, il devra être possible d’enregistrer :

* Le bien concerné.
* La nature de l’intervention.
* La date de début.
* La date de fin.
* Le prestataire, si applicable.
* Le coût de l’intervention.
* Le motif.
* Le résultat de l’intervention.
* Les observations.

L’historique des interventions devra être consultable depuis la fiche du bien.

Le statut du bien pourra être mis à jour en fonction de l’intervention.

⸻

12. Gestion des inventaires physiques

L’application devra permettre de réaliser des inventaires périodiques du patrimoine.

12.1. Création d’un inventaire

Un inventaire pourra être créé pour :

* Un site.
* Un département.
* Un service.
* Une catégorie de biens.
* Un local.
* L’ensemble du patrimoine.

Chaque inventaire devra comporter :

* Un numéro.
* Une date de début.
* Une date de fin.
* Un périmètre.
* Un responsable.
* Un statut.
* Des observations.

12.2. Réalisation de l’inventaire

L’application devra permettre de :

* Lister les biens attendus.
* Confirmer la présence d’un bien.
* Identifier les biens manquants.
* Identifier les biens non enregistrés.
* Vérifier l’affectation réelle.
* Vérifier l’état du matériel.
* Enregistrer les observations.
* Identifier les écarts entre les données de l’application et la réalité physique.

12.3. Inventaire par QR code ou code-barres

L’application devra permettre de retrouver les biens à partir de leur identifiant.

La lecture d’un QR code ou d’un code-barres devra faciliter la confirmation de présence d’un bien lors d’un inventaire.

Un lecteur USB compatible pourra être utilisé depuis un ordinateur connecté au réseau local.

12.4. Rapport d’inventaire

À la fin d’un inventaire, l’application devra produire un rapport permettant de distinguer :

* Les biens présents.
* Les biens manquants.
* Les biens retrouvés mais non enregistrés.
* Les biens affectés à un autre département.
* Les biens dont l’état diffère de celui enregistré.
* Les observations des responsables.

La validation de l’inventaire devra conserver son résultat et son historique.

⸻

13. Gestion des sorties et réformes

L’application devra permettre d’enregistrer la sortie définitive d’un bien du patrimoine.

Les motifs pourront inclure :

* Cession.
* Don.
* Réforme.
* Destruction.
* Perte.
* Vol.
* Autre motif.

Chaque sortie devra enregistrer :

* Le bien concerné.
* La date de sortie.
* Le motif.
* Le responsable de la décision.
* Les observations.
* Les documents justificatifs éventuels.

Un bien sorti du patrimoine ne devra pas être supprimé de l’historique.

Son statut devra être mis à jour et sa fiche conservée pour consultation.

Lorsqu’un bien amortissable sort du patrimoine, l’application devra conserver les informations relatives à sa valeur d’origine, à ses amortissements cumulés et à sa valeur nette comptable à la date de sortie.

Le traitement comptable de la sortie devra être validé par le responsable comptable.

⸻

14. Gestion documentaire

L’application devra permettre d’associer des documents aux biens et opérations.

Exemples :

* Factures d’achat.
* Bons de livraison.
* Procès-verbaux de réception.
* Documents de transfert.
* Rapports d’inventaire.
* Documents de réforme.
* Justificatifs de maintenance.
* Documents relatifs aux prêts.
* Plans d’amortissement.

Les documents devront être consultables depuis les fiches concernées.

Les fichiers seront stockés sur l’infrastructure locale retenue.

⸻

15. Tableau de bord et rapports

L’application devra proposer un tableau de bord synthétique.

15.1. Indicateurs

Les indicateurs pourront inclure :

* Nombre total de biens.
* Nombre de biens en service.
* Nombre de biens en stock.
* Nombre de biens en maintenance.
* Nombre de biens hors service.
* Nombre de biens par département.
* Nombre de biens par site.
* Valeur totale du patrimoine.
* Valeur nette comptable totale.
* Dotations d’amortissement de l’exercice.
* Biens récemment acquis.
* Biens récemment transférés.
* Biens actuellement prêtés.
* Biens nécessitant une intervention.
* Résultats des derniers inventaires.

15.2. Rapports

L’application devra permettre de générer des rapports portant notamment sur :

* L’inventaire général.
* Le patrimoine par département.
* Le patrimoine par site.
* Le patrimoine par catégorie.
* Le patrimoine par local.
* Les acquisitions.
* Les mouvements.
* Les prêts.
* Les opérations de maintenance.
* Les immobilisations.
* Les amortissements.
* Les valeurs nettes comptables.
* Les sorties et réformes.
* Les écarts d’inventaire.

Les rapports devront pouvoir être exportés en PDF et, lorsque cela est pertinent, en Excel.

15.3. Filtres des rapports

Les rapports devront pouvoir être filtrés selon :

* Le site.
* Le département.
* Le service.
* La catégorie.
* Le statut.
* La période.
* Le responsable.
* L’exercice comptable.

⸻

16. Recherche et consultation

L’application devra intégrer une recherche permettant de retrouver rapidement un bien.

Les filtres pourront porter sur :

* Le numéro d’identification.
* La désignation.
* La catégorie.
* Le site.
* Le département.
* Le service.
* Le local.
* Le statut.
* La date d’acquisition.
* Le responsable.
* Le numéro de série.

La recherche devra être accessible depuis l’interface principale.

La fiche d’un bien devra présenter les informations essentielles ainsi que son historique.

Pour les immobilisations, la fiche devra également présenter :

* La valeur d’acquisition.
* La date de mise en service.
* La durée d’amortissement.
* La méthode d’amortissement.
* Les amortissements cumulés.
* La valeur nette comptable.
* Le plan d’amortissement.

⸻

17. Notifications et alertes

L’application pourra intégrer des alertes internes concernant :

* Les prêts arrivant à échéance.
* Les retards de retour.
* Les biens en maintenance.
* Les interventions en cours.
* Les biens déclarés hors service.
* Les inventaires à réaliser.
* Les écarts d’inventaire.
* Les immobilisations arrivant en fin d’amortissement.

Ces alertes devront être visibles dans l’interface de l’application.

Les notifications par e-mail ou par SMS ne sont pas exigées dans la première version.

⸻

18. Exigences techniques et sécurité

18.1. Interface utilisateur

L’application devra proposer :

* Une interface moderne et professionnelle.
* Une navigation simple.
* Un tableau de bord clair.
* Des formulaires faciles à utiliser.
* Des tableaux de données avec recherche et filtres.
* Une interface adaptée à un usage administratif quotidien.
* Une prise en main rapide par des utilisateurs non techniques.

18.2. Fonctionnement sur le réseau local

L’application devra être accessible depuis les ordinateurs autorisés connectés au réseau local.

Elle devra permettre un accès centralisé aux données.

Les opérations réalisées par un utilisateur devront être prises en compte dans la base de données centrale.

18.3. Authentification et sécurité

L’application devra intégrer :

* Une authentification sécurisée.
* Une gestion des rôles et permissions.
* Une protection des données.
* Une protection contre les accès non autorisés.
* Une gestion des sessions utilisateurs.
* Une journalisation des opérations importantes.

18.4. Traçabilité

Les opérations importantes devront être tracées, notamment :

* Création d’un bien.
* Modification d’une fiche.
* Affectation.
* Transfert.
* Inventaire.
* Maintenance.
* Prêt.
* Réforme ou sortie du patrimoine.
* Modification d’un paramètre d’amortissement.
* Validation d’un plan d’amortissement.

L’historique devra permettre d’identifier :

* L’utilisateur.
* La date et l’heure.
* La nature de l’opération.
* Le bien concerné.
* Les informations pertinentes avant et après modification, lorsque cela est nécessaire.

18.5. Gestion des accès simultanés

L’application devra permettre à plusieurs utilisateurs de travailler simultanément.

Les mécanismes de gestion des données devront limiter les risques de conflits lors des modifications.

Les identifiants patrimoniaux devront rester uniques, même en cas de créations simultanées.

18.6. Sauvegarde et infrastructure

La sauvegarde automatisée, la restauration automatisée et la gestion d’une infrastructure distante ne font pas partie du périmètre de la première version.

L’application sera déployée sur une infrastructure locale.

L’architecture devra toutefois permettre d’intégrer ultérieurement :

* La sauvegarde des données.
* La restauration.
* La sauvegarde des documents.
* La gestion d’un serveur distant.
* La sécurisation des accès à distance.

⸻

19. Modèle de données indicatif

L’application pourra s’appuyer sur les entités suivantes :

* Utilisateur.
* Rôle.
* Permission.
* Site.
* Département.
* Service.
* Localisation.
* Catégorie de bien.
* Bien.
* Acquisition.
* Affectation.
* Mouvement.
* Inventaire.
* Ligne d’inventaire.
* Emprunt ou prêt.
* Intervention de maintenance.
* Immobilisation.
* Plan d’amortissement.
* Ligne de plan d’amortissement.
* Exercice comptable.
* Sortie ou réforme.
* Document.
* Journal des opérations.

Les relations devront permettre de conserver l’historique des biens sans perdre les informations relatives aux anciennes affectations.

Chaque immobilisation devra être liée à son plan d’amortissement et aux lignes de calcul correspondantes.

⸻

20. Critères de validation et recette

La solution sera considérée comme conforme lorsque les points suivants auront été vérifiés :

1. L’application peut être déployée sur un serveur local ou un ordinateur configuré comme serveur.
2. L’application est accessible depuis plusieurs ordinateurs connectés au réseau local.
3. Les utilisateurs n’ont pas besoin d’installer individuellement l’application sur leur poste.
4. L’application fonctionne sans connexion Internet permanente.
5. Un administrateur peut créer des utilisateurs et gérer leurs permissions.
6. Un utilisateur peut enregistrer un bien.
7. Chaque bien reçoit automatiquement un identifiant unique.
8. Une étiquette peut être générée pour un bien.
9. Plusieurs étiquettes peuvent être générées et imprimées en lot.
10. Un QR code ou code-barres permet de retrouver le bien correspondant.
11. Les biens peuvent être affectés à des départements, services, locaux et sites.
12. Les transferts sont enregistrés et historisés.
13. Les inventaires peuvent être réalisés et les écarts identifiés.
14. Les opérations de maintenance, de prêt et de réforme sont suivies.
15. Les rapports peuvent être consultés et exportés.
16. Les informations patrimoniales et les valeurs des immobilisations peuvent être suivies.
17. L’application calcule automatiquement les amortissements des immobilisations concernées.
18. Un plan d’amortissement est généré pour chaque immobilisation amortissable.
19. Les amortissements cumulés et la valeur nette comptable sont calculés automatiquement.
20. Les plans d’amortissement peuvent être consultés et exportés.
21. L’historique des opérations est conservé.
22. Plusieurs utilisateurs peuvent travailler sur la même base de données.

⸻

21. Éléments hors périmètre de la V1

Les fonctionnalités suivantes ne sont pas incluses dans la première version :

* Application mobile native.
* Application mobile dédiée.
* Déploiement cloud obligatoire.
* Accès distant depuis Internet.
* Synchronisation entre plusieurs infrastructures indépendantes.
* Sauvegarde automatisée.
* Restauration automatisée.
* Gestion complète de la comptabilité générale.
* Gestion des offrandes et des dons.
* Gestion des membres de l’Église.
* Gestion de la paie.
* Gestion complète des achats et fournisseurs.
* Gestion des budgets et engagements financiers.
* Notifications par SMS.
* Notifications par e-mail.

Le calcul des amortissements et la génération des plans d’amortissement ne sont pas hors périmètre : ils font partie intégrante de la V1.

Les fonctionnalités exclues pourront faire l’objet d’évolutions ultérieures.

⸻

22. Livrables attendus

Le prestataire devra fournir :

* L’application web fonctionnelle.
* Le code source.
* La base de données initialisée.
* Le processus d’installation et de déploiement.
* La documentation technique.
* Le guide utilisateur.
* Les modèles d’étiquettes.
* Les rapports et exports prévus.
* Le module de calcul des amortissements.
* Les modèles de plans d’amortissement.
* Une session de formation des utilisateurs.
* Une période de correction des anomalies après mise en production.

Le déploiement devra être documenté de manière à permettre une réinstallation de l’application sur une infrastructure compatible.

⸻

23. Phasage indicatif du projet

Phase 1 — Cadrage

* Validation des besoins.
* Identification des départements et sites.
* Définition du format des numéros d’identification.
* Validation des modèles d’étiquettes.
* Validation des catégories de biens.
* Validation des profils utilisateurs.
* Validation du périmètre patrimonial et comptable.
* Validation des règles d’amortissement.

Phase 2 — Conception

* Conception de l’interface.
* Conception de la base de données.
* Définition des profils utilisateurs.
* Définition des règles de gestion.
* Définition du fonctionnement des étiquettes.
* Définition du mode de déploiement local.
* Conception du module des immobilisations.
* Conception des calculs et plans d’amortissement.

Phase 3 — Développement

* Gestion des utilisateurs.
* Gestion des sites et départements.
* Gestion du patrimoine.
* Gestion des catégories.
* Génération des identifiants.
* Génération et impression des étiquettes.
* Gestion des acquisitions.
* Gestion des mouvements.
* Gestion des inventaires.
* Gestion de la maintenance et des prêts.
* Gestion des immobilisations.
* Calcul automatique des amortissements.
* Génération des plans d’amortissement.
* Gestion des sorties et réformes.
* Rapports et tableaux de bord.

Phase 4 — Tests et validation

* Tests fonctionnels.
* Tests d’accès depuis plusieurs postes.
* Tests d’impression des étiquettes.
* Tests de recherche par identifiant.
* Tests d’inventaire.
* Tests des rapports.
* Tests de gestion des permissions.
* Tests des calculs d’amortissement.
* Tests des plans d’amortissement.
* Validation par les utilisateurs.

Phase 5 — Déploiement et formation

* Installation sur le serveur local ou l’ordinateur retenu.
* Configuration du réseau local.
* Configuration initiale.
* Création des utilisateurs.
* Formation.
* Mise en production.

⸻

24. Conclusion

L’application devra constituer un outil centralisé, fiable et pratique pour la gestion du patrimoine matériel de l’Église.

Elle devra permettre à l’administration de savoir précisément :

* Quels biens l’Église possède.
* Où se trouvent ces biens.
* À quels départements ils sont affectés.
* Qui en est responsable.
* Dans quel état ils se trouvent.
* Quelle est leur valeur patrimoniale.
* Quelle est leur valeur nette comptable.
* Quels amortissements ont été constatés.
* Quels biens sont présents ou manquants lors des inventaires.

La génération d’étiquettes d’identification uniques devra faciliter le suivi physique des matériels et rendre les inventaires plus rapides et plus fiables.

Le calcul automatique des amortissements et la génération des plans d’amortissement devront permettre de suivre l’évolution de la valeur comptable des immobilisations au fil des exercices.

La première version sera une application web déployée sur une infrastructure locale, accessible depuis plusieurs ordinateurs via le réseau de l’Église.

Elle ne nécessitera pas d’application mobile et ne dépendra pas d’une connexion Internet permanente.

L’architecture devra permettre une évolution ultérieure vers un déploiement distant, des mécanismes de sauvegarde et une gestion étendue des accès, sans imposer ces fonctionnalités dans le périmètre initial.