# Recette

Deux parties : la **recette technique** faite par Claude Code le 28/10/2026 sur la branche `lot-12-recette` (application complète, lots 0 à 12), critère par critère, puis la **grille de recette avec le client**, à cocher sur place et à faire signer.

## Recette technique du 28/10/2026

### Méthode

- **Code vérifié** : commit `c980a79` (intégration du lot 12, partie 1), copie de travail `patrimoine-recette`.
- **Poste** : Windows 11, PHP 8.4.0 (WAMP), Node 24.22 pour `npm run build`, MariaDB 11.5.2 temporaire (127.0.0.1:3399, bases jetables `recette` et `recette_install`, supprimées à la fin).
- **Tests automatisés** : suite Pest complète lancée une fois, avec les variantes MariaDB des tests de concurrence activées (`CONCURRENCE_DB_DRIVER=mariadb`, base `recette`). Résultat en fin de document.
- **Application réelle** : `php artisan migrate:fresh --seed` (base SQLite de démonstration), serveur PHP sur `127.0.0.1:8123`, parcours dans le navigateur intégré avec les comptes `admin` (administrateur), `e.mbemba` (responsable de département) ; exports produits par un script qui appelle les actions de l'application avec `a.okemba` (gestionnaire) et `m.loubaki` (comptable), puis relus (Excel par PhpSpreadsheet, PDF par `tests/Support/PdfInspector`).
- **Statuts** : **Conforme** (prouvé ici, sans réserve) ; **Conforme avec réserve** (prouvé ici, mais dépend d'une hypothèse de `docs/06` non tranchée ou d'un essai sur le matériel du client) ; **Non conforme** (défaut qui empêche le critère) ; **Non vérifiable ici** (rien ne peut le prouver sur ce poste). Un critère non prouvé n'est pas coché.
- Données créées pendant la recette (base de démonstration locale, non versionnée) : biens PAT-001249 (amortissable) et PAT-001250 (non amortissable), deux transferts de PAT-001249, un compte `r.recette`, le droit « exporter les biens » ajouté au rôle Responsable de département, une lecture d'inventaire sur INV-2026-005.

### Critère 1 · Déploiement sur un serveur local ou un ordinateur configuré comme serveur

- **Statut : Conforme avec réserve.**
- **Preuves** :
  - `php artisan patrimoine:verifier` contre MariaDB : tous les contrôles listés (PHP, 15 extensions, dossiers inscriptibles, clé, interface compilée), la base absente signalée « BLOQUANT » avec un message clair ; `upload_max_filesize` (2M) et `post_max_size` (8M) du PHP de ligne de commande signalés « À corriger ».
  - `patrimoine:installer --mot-de-passe-stdin` sur une base MariaDB inexistante (`recette_install`) : base créée, migrations, référentiels (5 rôles, 10 catégories), premier administrateur actif sans changement forcé, aucune structure ni bien (QL12-1). Mot de passe lu sur l'entrée standard, jamais affiché.
  - Tests `tests/Feature/Systeme/InstallationTest.php` (installation, relance sans dégât, mot de passe faible refusé, démonstration refusée sur une base réelle) et `SauvegardeTest.php`.
- **Réserves** :
  - `install.ps1`, l'hôte virtuel Apache, la tâche planifiée Windows et le démarrage automatique n'ont pas été rejoués ici (essais décrits dans `docs/08`, lot 12). **Test humain** : installation complète sur le PC serveur du client, poste vierge, selon `docs/07`.
  - Dépend de Q19 (serveur Windows ou Linux : seul `install.ps1` existe, QL12-7) et Q20 / QL12-3 à QL12-5 (sauvegarde).

### Critère 2 · Accès depuis plusieurs ordinateurs du réseau local

- **Statut : Non vérifiable ici** (un seul poste).
- **Preuves partielles** :
  - Toutes les adresses de la page suivent l'hôte demandé, pas `APP_URL` : avec l'en-tête `Host: patrimoine.local`, la page de connexion référence `http://patrimoine.local/build/…`, `/css/…`, `/fonts/…` ; avec `127.0.0.1:8123`, les mêmes ressources en `127.0.0.1:8123`. Un poste qui passe par l'adresse IP du serveur reçoit donc des liens valides.
  - Sessions en base (`SESSION_DRIVER=database`) : plusieurs postes ouvrent chacun leur session.
  - `docs/07` §4 décrit pare-feu (port 80, profil Privé), hôte virtuel `Require all granted`, `APP_URL`.
- **Réserves** : **test humain** depuis 3 postes du réseau (Q17 : les autres sites n'ont pas d'accès en V1 ; Q18 : 10 utilisateurs simultanés par défaut).

### Critère 3 · Aucune installation sur les postes utilisateurs

- **Statut : Conforme avec réserve.**
- **Preuves** : application web servie par le serveur ; tout le parcours de cette recette s'est fait dans un navigateur, sans greffon ; impression par PDF ouvert dans le navigateur ; douchette en émulation clavier (saisie + Entrée, voir critère 10).
- **Réserves** : **test humain** sur un poste client réel (navigateur récent, lecteur PDF du navigateur pour l'impression des étiquettes à 100 %).

### Critère 4 · Fonctionnement sans connexion Internet permanente

- **Statut : Conforme avec réserve.**
- **Preuves** :
  - Pages servies sans aucune ressource externe : la page de connexion ne référence que l'hôte local (relevé de tous les `src` et `href`) ; seules mentions `http` externes du code livré : commentaires de licence Tailwind et espaces de noms SVG du W3C.
  - Recherche de toute URL externe dans `app`, `resources`, `public/css`, `public/js/etats-systeme.js`, `public/fonts/fonts.css`, `public/build`, `routes`, `config` : aucune utilisée (seulement des exemples de configuration Laravel inutilisés).
  - Tests `tests/Feature/Socle/HorsLigneTest.php` (connexion, tableau de bord, aucun CDN dans le code), `PagesErreurTest.php` (aucune ressource externe sur les pages d'erreur), `RapportsTest.php` « exporte en PDF hors ligne… sans aucune URL distante » ; DomPDF avec `enable_remote` désactivé ; polices, avatars et QR codes générés localement.
- **Réserves** : **test humain** imposé par la grille : câble Internet du routeur débranché pendant toute la recette avec le client.

### Critère 5 · Un administrateur peut créer des utilisateurs et gérer leurs permissions

- **Statut : Conforme.**
- **Preuves** :
  - À l'écran (`admin`) : création du compte `r.recette`, rôle « Responsable de département », département Informatique ; mot de passe provisoire généré, changement imposé à la première connexion (texte du formulaire). Le compteur du rôle passe de 9 à 10 comptes.
  - À l'écran : case « Biens · exporter » cochée sur le rôle Responsable de département, « Sauvegardé ». Effet vérifié en se connectant ensuite avec `e.mbemba` : la page Rapports propose désormais l'inventaire général, limité à ses 65 biens (Louange).
  - Tests `tests/Feature/Acces/` (`UtilisateursTest`, `RolesTest`, `DroitsEtPerimetreTest`, `SecuriteConnexionTest`, `JournalTest`) : création avec rôle et périmètre, désactivation sans suppression, matrice E39 tracée, blocage après 5 échecs, mot de passe provisoire.
- **Réserves** : aucune sur le critère. À noter pour la recette avec le client : la matrice livrée ne donne aucun rapport au responsable de département ni à la Consultation (QL11-1), à régler par la matrice.

### Critère 6 · Un utilisateur peut enregistrer un bien

- **Statut : Conforme avec réserve.**
- **Preuves** (à l'écran, compte `admin`) :
  - PAT-001249 « Recette - ordinateur amortissable », Matériel informatique, 450 000 FCFA, mise en service 21/10/2026 : fiche « Amortissable OUI », linéaire 3 ans, comptes 2441 / 28441, cumul au 31/12/2026 29 167, VNC 420 833 (450 000 × 1/3 × 70/360 = 29 166,7, arrondi 29 167 : exact).
  - PAT-001250 « Recette - agrafeuse électrique non amortissable », Matériel de bureau (catégorie « selon seuil »), 80 000 FCFA : « Amortissable NON », aucun plan (aucune ligne en base).
  - Tests `tests/Feature/Biens/` (`FicheBienTest`, `NumeroPatrimonialTest`, `PerimetreBiensTest`, `DocumentsTest`…).
- **Réserves** :
  - RG-30 dépend de Q2 (seuil 100 000 FCFA) et de l'interprétation des catégories : une catégorie « oui » (Mobilier, Matériel informatique…) rend amortissable un bien **sous** le seuil (une chaise de 80 000 FCFA en Mobilier aurait un plan, `app/Services/DepreciabilityResolver.php:25-27`), alors que RG-30 dit « si sa catégorie l'est **et** si son prix atteint le seuil ». Conforme à `docs/03` (énumération oui / selon seuil / non), mais à faire confirmer par le comptable avec Q2 et Q3.
  - Défaut D3 (mineur) : texte de développement visible dans le formulaire (voir la liste des défauts).

### Critère 7 · Chaque bien reçoit automatiquement un identifiant unique

- **Statut** : voir la mise à jour avec le résultat de la suite (section suivante).

### Critère 8 · Une étiquette peut être générée pour un bien

- **Statut : Conforme avec réserve.**
- **Preuves** :
  - À l'écran : « Imprimer l'étiquette » sur la fiche et redirection automatique vers `/admin/etiquettes?biens=301` après un transfert de site (RG-24) ; page « Impression des étiquettes » avec PAT-001249 et PAT-001250 présélectionnés, modèle « Standard · 70 × 37 mm · QR code », aperçu (sigle, numéro, désignation, département).
  - Tests `tests/Feature/Etiquettes/ContenuDuCodeTest.php` (le QR et le Code 128 générés sont **décodés** et ne contiennent que le numéro), `ImpressionDesEtiquettesTest.php` (PDF généré et journalisé), `PlancheEtRouleauTest.php` (positions relues dans le PDF à 0,05 mm).
- **Réserves** :
  - L'en-tête imprimé est « [SIGLE] » et les PDF portent « [Nom de l'Église] » tant que Q15 n'est pas tranchée (réglage en base, l'écran E41 n'existe pas).
  - **Test humain** : impression réelle sur l'imprimante et la planche du client (QL4-2 : décalage éventuel ; Q16 : matériel), lisibilité du texte de 5,5 pt.

### Critère 9 · Plusieurs étiquettes peuvent être générées et imprimées en lot

- **Statut : Conforme avec réserve.**
- **Preuves** : tests `PlancheEtRouleauTest.php` « place 24 étiquettes de 70 × 37 mm sur une planche A4 3 × 8 », « passe à une deuxième planche quand la première est pleine », « met la 25e étiquette en haut à gauche de la deuxième planche », « produit 500 étiquettes en moins de 60 secondes » ; action groupée « Imprimer les étiquettes » sur la liste des biens (`ImpressionDesEtiquettesTest.php`) ; à l'écran, aperçu « 1 planche A4 · 2 étiquettes · cases 1 à 2 » et case de départ pour une planche entamée.
- **Réserves** : **test humain** : imprimer une vraie planche de 24 (QL4-2) ; limite de 500 étiquettes par PDF et temps de génération à mesurer sur le serveur du client (Apache de WAMP à 120 s).

### Critère 10 · Un QR code ou code-barres permet de retrouver le bien

- **Statut : Conforme avec réserve.**
- **Preuves** :
  - À l'écran, « Recherche par code » : le champ a le focus à l'ouverture ; frappe de `PAT-001249` puis Entrée au clavier (ce que fait une douchette en émulation clavier) → ouverture directe de la fiche PAT-001249.
  - À l'écran, saisie d'inventaire INV-2026-005 : lecture de `PAT-001249` + Entrée → « n'est pas dans la liste attendue : enregistré « présent hors périmètre » », focus rendu au champ.
  - Tests : décodage du QR et du Code 128 (`ContenuDuCodeTest.php`), `RechercheParCodeTest.php` (fiche ouverte, minuscules et espaces acceptés, code inconnu signalé, focus), bien hors périmètre « introuvable ».
- **Réserves** : **test humain** avec la douchette du client sur une étiquette imprimée (douchette **2D** indispensable pour le QR code, Q16).

### Critère 11 · Les biens peuvent être affectés à des départements, services, locaux et sites

- **Statut : Conforme.**
- **Preuves** :
  - À l'écran : PAT-001249 créé au Siège / Informatique ; transfert vers le local « Salle informatique » ; puis transfert vers Paroisse Moungali / Administration : le local du Siège est vidé automatiquement (un local appartient à un site), la fiche affiche la nouvelle affectation. Les listes de services et de locaux suivent le département et le site choisis.
  - Tests `tests/Feature/Mouvements/TransfertTest.php` (service hors du département et local hors du site refusés, référentiel désactivé refusé), `tests/Feature/Structure/`.
- **Réserves** : Q14 (liste réelle des sites, départements, services, locaux) à saisir par l'Église ; Q60 (transfert hors périmètre refusé) et Q61 (modification d'affectation depuis la fiche tracée en mouvement) à confirmer.

## Grille de recette avec le client

Les 22 critères de la section 20 du cahier des charges, avec le lot qui les livre et la façon de les vérifier. À cocher avec le client lors de la recette, et à faire signer.

| N° | Critère | Lot | Vérification | OK |
|---|---|---|---|---|
| 1 | Déploiement sur un serveur local ou un PC faisant office de serveur | 12 | Installation sur la machine du client selon `07-deploiement-local.md` | [ ] |
| 2 | Accès depuis plusieurs ordinateurs du réseau local | 12 | Connexion depuis 3 postes différents | [ ] |
| 3 | Aucune installation sur les postes utilisateurs | 12 | Accès par navigateur seul | [ ] |
| 4 | Fonctionnement sans Internet | 0, 12 | Câble Internet du routeur débranché pendant toute la recette | [ ] |
| 5 | Création d'utilisateurs et gestion des permissions | 2 | Créer un compte de chaque rôle, modifier un droit | [ ] |
| 6 | Enregistrement d'un bien | 3 | Créer un bien amortissable et un non amortissable | [ ] |
| 7 | Identifiant unique automatique | 3 | Deux postes créent un bien en même temps : numéros différents | [ ] |
| 8 | Génération d'une étiquette | 4 | Imprimer l'étiquette d'un bien | [ ] |
| 9 | Étiquettes en lot | 4 | Imprimer une planche de 24 étiquettes | [ ] |
| 10 | QR code ou code-barres qui retrouve le bien | 3, 4 | Scanner une étiquette imprimée avec la douchette | [ ] |
| 11 | Affectation à département, service, local et site | 3, 6 | Créer et modifier une affectation complète | [ ] |
| 12 | Transferts enregistrés et historisés | 6 | Transférer un bien deux fois, consulter l'historique | [ ] |
| 13 | Inventaires et écarts | 9 | Inventaire d'un local avec un manquant, un non enregistré, un écart d'état | [ ] |
| 14 | Maintenance, prêts, réformes suivis | 8, 10 | Un prêt avec retour, une intervention, une sortie | [ ] |
| 15 | Rapports consultables et exportables | 11 | Exporter 3 rapports en PDF et Excel | [ ] |
| 16 | Suivi des valeurs patrimoniales et des immobilisations | 7, 11 | Tableau des immobilisations et tableau de bord concordants | [ ] |
| 17 | Calcul automatique des amortissements | 7 | Les 8 cas validés par le comptable passent | [ ] |
| 18 | Plan d'amortissement par immobilisation | 7 | Ouvrir le plan d'un bien amortissable | [ ] |
| 19 | Cumuls et VNC calculés | 7 | Comparer avec le calcul du comptable sur 3 biens réels | [ ] |
| 20 | Plans consultables et exportables | 7, 11 | Export PDF et Excel d'un plan | [ ] |
| 21 | Historique des opérations conservé | 2 | Retrouver dans le journal toutes les opérations de la recette | [ ] |
| 22 | Plusieurs utilisateurs sur la même base | 2, 3 | Deux utilisateurs modifient la même fiche : avertissement RG-86 | [ ] |

Signature Webtinix : ................................ Signature de l'Église : ................................ Date : ....../....../..........
