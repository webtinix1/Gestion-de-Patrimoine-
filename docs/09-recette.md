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

### Critère 12 · Les transferts sont enregistrés et historisés

- **Statut : Conforme.**
- **Preuves** :
  - À l'écran : deux transferts de PAT-001249 (changement de local, puis site et département). La liste « Mouvements » montre, du plus récent au plus ancien : « Transfert multiple » Siège · Informatique · Salle informatique → Paroisse Moungali · Administration ; « Changement de local » ; « Affectation initiale » (RG-22), avec l'auteur. Le journal porte une entrée « Transfert d'un bien » par transfert, avec avant et après. Le changement de site propose la réimpression de l'étiquette (RG-24).
  - Tests `tests/Feature/Mouvements/` (`TransfertTest`, `EcransMouvementsTest`, `RevueMouvementsTest`, `DemoMouvementsTest`) : un mouvement par bien, lot groupé, mouvement non modifiable ni supprimable, contrôle de version.
- **Réserves** : Q22 (motifs : texte libre avec suggestions), Q63 et Q64 (transfert groupé) à confirmer. Le journal double chaque transfert d'une entrée « Modification d'une fiche » aux valeurs techniques (défaut D2).

### Critère 13 · Les inventaires peuvent être réalisés et les écarts identifiés

- **Statut : Conforme avec réserve.**
- **Preuves** :
  - À l'écran : saisie de INV-2026-005 (en cours) : lecture d'un bien hors liste enregistrée « présent hors périmètre », compteurs mis à jour, focus rendu au champ de lecture. Rapport de INV-2026-004 (clôturé) : 168 attendus, 165 présents, 3 manquants, 1 non enregistré, 2 autres affectations, 2 états différents, chacun avec son action de régularisation (transfert, mise à jour d'état, création de fiche, retrouvé, déclarer perdu) ; PV PDF et export Excel proposés.
  - Tests `tests/Feature/Inventaires/` (création et démarrage, saisie, clôture et régularisations, écrans et droits, revue) dont `SaisieSimultaneeConcurrenceTest.php` (5 postes lisent en parallèle, 6 postes appuient sur la même ligne : une seule saisie retenue), sur SQLite et MariaDB (résultat de la suite ci-dessous).
- **Réserves** :
  - **Tests humains** : douchette réelle sur l'écran de saisie (lectures rapides en série) ; deux postes sur le même inventaire.
  - Hypothèses QL9-1 à QL9-12 à confirmer (clôture refusée tant qu'il reste des biens à vérifier, règle de saisie simultanée, etc.).
  - Défaut D5 (mineur, démonstration) : INV-2026-004, clôturé le 30/09/2026, compte PAT-001248 (acquis le 22/10/2026) parmi ses manquants.

### Critère 14 · Les opérations de maintenance, de prêt et de réforme sont suivies

- **Statut : Conforme avec réserve.**
- **Preuves** (à l'écran, compte `a.okemba`, gestionnaire) :
  - Prêt PRT-2026-068 de PAT-001249 à « Famille Recette », bien passé « Emprunté » ; retour avec état « État moyen » (départ « Neuf ») : état de la fiche mis à jour, statut précédent restauré, puis redirection automatique vers une nouvelle intervention avec le bien présélectionné (RG-57).
  - Intervention MNT-2026-053 (réparation, prestataire, devis 25 000 FCFA, « passer En maintenance ») : bien « En maintenance » ; valeur d'origine inchangée (450 000), le coût n'entre pas dans la base amortissable.
  - Sortie SRT-2026-004 (réforme) de PAT-001250 : panneau des valeurs à la date de sortie (bien sans plan : cumul 0, VNC 80 000, QL10-2), confirmation par saisie du numéro, statut « Réformé », fiche en lecture seule, validation comptable « À valider ».
  - Journal : « Prêt d'un bien », « Retour d'un bien prêté », « Ouverture d'une intervention », « Sortie du patrimoine », chacune avec l'auteur.
  - Tests `tests/Feature/Prets/`, `Maintenance/`, `Signalements/`, `Sorties/` (dont le cas A5 de bout en bout, sortie au 26/10/2026 : dotation 493 333, VNC 2 180 000, vérifiée aussi dans la base de démonstration sur PAT-000034), et les tests de concurrence `OperationsConcurrenceTest.php`, `SortieConcurrenceTest.php`.
- **Réserves** : Q21 (qui valide les sorties), QL10-1 à QL10-8 (sortie d'un bien prêté refusée, sortie antidatée dans un exercice clôturé refusée, aucune annulation), QL8-1 à QL8-11 à confirmer ; Q9.12 / QL10-3 à valider par le comptable.

### Critère 15 · Les rapports peuvent être consultés et exportés

- **Statut : Conforme avec réserve.**
- **Preuves** :
  - À l'écran (`e.mbemba`, après ajout du droit « exporter les biens ») : page « Rapports et états », filtres de l'exigence 15.3, aperçu « 65 ligne(s) » limité à son périmètre, boutons « Exporter Excel » et « Exporter PDF ».
  - Exports réels produits et relus : « Inventaire général » (299 lignes), « Mouvements » (313), « Prêts et emprunts » (12), « Écarts d'inventaire » (12) pour le gestionnaire ; « Tableau des immobilisations » (228) et « Valeurs nettes comptables » (225) pour le comptable : six PDF commençant par `%PDF` et six classeurs Excel relus (en-tête de l'Église, filtres, auteur, lignes, total).
  - Tests `tests/Feature/Rapports/` : tous les rapports du catalogue produits en aperçu et en PDF sur la démonstration, PDF sans URL distante, Excel sans formule injectable, périmètre, droits.
- **Réserves** :
  - QL11-1 : avec la matrice livrée, le responsable de département et la Consultation n'ont **aucun** rapport ; à régler avec l'Église dans la matrice (vérifié possible ici).
  - Défaut D1 (important) : dans les exports Excel, les montants nuls sont des cellules vides.
  - En-tête « [Nom de l'Église] ([SIGLE]) » tant que Q15 n'est pas tranchée. PDF d'environ 0,9 Mo même pour 12 lignes (polices DejaVu incluses en entier) : sans gravité.

### Critère 16 · Les informations patrimoniales et les valeurs des immobilisations peuvent être suivies

- **Statut : Conforme.**
- **Preuves** :
  - À l'écran, tableau de bord (`admin`) et tableau des immobilisations 2026, sur la même base : VNC 37 874 816 et dotations 2026 18 699 281 FCFA identiques des deux côtés ; valeur d'origine 129 509 000 − cumul 91 634 184 = VNC 37 874 816 ; sommes par site du tableau de bord égales aux totaux (valeur d'acquisition 137 890 500, VNC 37 874 816) ; statuts 268 + 12 + 2 + 9 + 6 + 3 = 300 biens.
  - Après la création de PAT-001249, l'export « Tableau des immobilisations » donne une VNC totale de 38 295 649 = 37 874 816 + 420 833.
  - Tests `tests/Feature/TableauDeBord/ChiffresEtPerimetreTest.php` (« affiche sur le tableau de bord les mêmes chiffres que les rapports », une seule source `PatrimonyFigures`), `DemoRapportsTest.php`.
- **Réserves** : définitions des chiffres QL11-6 à valider par le comptable (valeur d'acquisition hors biens sortis, total des biens tous statuts).

### Critère 17 · L'application calcule automatiquement les amortissements des immobilisations concernées

- **Statut : Conforme avec réserve.**
- **Preuves** :
  - `tests/Unit/DepreciationCalculatorTest.php` lit `tests/Fixtures/amortissements.json` (inchangé depuis le kit de démarrage) et reproduit exactement les 8 cas A1 à A8, plus les invariants RG-33 et RG-34 et les cas de bord : 29 tests, 297 assertions, tous passés (`pest tests/Unit`).
  - De bout en bout dans la base de démonstration : PAT-000125 = A2, PAT-001248 = A3, PAT-000034 = A5 (sortie au 26/10/2026), ligne par ligne (jours, dotation, cumul, VNC).
  - Plan calculé automatiquement à la création de PAT-001249 (aucune action manuelle) ; aucun plan pour PAT-001250 sous le seuil.
- **Réserves** : **les 8 cas ne sont pas validés par le comptable** (Q9 « non validés ») ; A7 (dégressif) est marqué « PROVISOIRE » dans le fichier et le dégressif est désactivé par défaut (Q4). Q1 (prorata 30/360), Q5, Q6, Q7, Q9.1 à Q9.12 restent des hypothèses. Le critère ne peut être coché définitivement qu'après la signature du comptable sur les 8 cas.

### Critère 18 · Un plan d'amortissement est généré pour chaque immobilisation amortissable

- **Statut : Conforme.**
- **Preuves** :
  - Base de démonstration : 228 plans courants (225 biens amortissables au patrimoine plus 3 biens sortis dont le plan est arrêté) ; le tableau des immobilisations comptait 224 biens amortissables et 73 non amortissables avant la recette.
  - À l'écran : fiche de PAT-001249 avec l'onglet « Amortissement », les compteurs cumul / VNC / durée restante ; plan de PAT-000125 avec paramètres, tableau par exercice et statut (Clôturé, En cours, Prévisionnel), historique, version validée.
  - Tests `tests/Feature/Amortissements/PlansTest.php`, `EcransTest.php`, `DemoEtCommandeTest.php` (plans générés à la création et à la modification, versions RG-38, commande `patrimoine:recalculer-plans`).
- **Réserves** : Q9.5 (bien amortissable sans date de mise en service : pas de plan tant que la date manque, signalé à la clôture).

### Critère 19 · Les amortissements cumulés et la valeur nette comptable sont calculés automatiquement

- **Statut : Conforme avec réserve.**
- **Preuves** :
  - Recalcul **indépendant** (script de recette, formule 30/360 de RG-32, arrondi au franc, dernière dotation qui absorbe l'écart, arrêt à la date de sortie) des **228 plans courants** de la base de démonstration : 0 écart sur les jours, dotations, cumuls et VNC.
  - Contrôles à la main : PAT-000004 (390 000, 3 ans, mise en service 13/06/2024 : 198 jours, 71 500 la première année) ; PAT-001249 (70 jours, 29 167) ; PAT-000125 (A2).
  - Cumul et VNC affichés sur la fiche, le plan, le tableau des immobilisations et le tableau de bord, concordants (critère 16).
- **Réserves** : **comparaison avec le calcul du comptable sur 3 biens réels** non faisable ici (test humain de la grille) ; mêmes hypothèses que le critère 17.

### Critère 20 · Les plans d'amortissement peuvent être consultés et exportés

- **Statut : Conforme avec réserve.**
- **Preuves** :
  - Plan de PAT-000125 exporté par l'action de l'application : PDF relu (en-tête, paramètres, tableau 2024 à 2027 identique à A2 avec VNC finale « 0 », historique, emplacements de signature, « Page 1 / 1 ») et Excel relu (mêmes lignes).
  - Test `tests/Feature/Amortissements/EcransTest.php` « exporte le plan en PDF et en Excel » ; `RapportsTest.php` « harmonise le plan d'amortissement avec le gabarit E45 ».
- **Réserves** : défaut D1 : dans l'Excel du plan, la VNC finale (0) est une cellule vide. **Test humain** : impression du PDF.

### Critère 21 · L'historique des opérations est conservé

- **Statut : Conforme avec réserve.**
- **Preuves** :
  - Toutes les opérations de cette recette se retrouvent dans le journal avec l'utilisateur, la date et l'heure, le poste (IP) et l'objet : connexions (`admin`, `e.mbemba`, `a.okemba`), création des deux biens, deux transferts, modification de fiche, création de l'utilisateur et de ses droits, modification de la matrice du rôle, saisie d'inventaire, sortie, prêt, retour, ouverture d'intervention.
  - Conservation : `config/activitylog.php` garde les entrées 36 500 jours ; aucune purge planifiée (`routes/console.php` ne planifie que les alertes) ; modèle `App\Models\Activity` qui refuse modification et suppression (RG-72) ; aucune action de suppression à l'écran.
  - Tests `tests/Feature/Acces/JournalTest.php` et les tests de journal de chaque module.
- **Réserves** : défaut D2 (mineur) : les entrées automatiques « Modification d'une fiche » montrent des noms de colonnes et des valeurs techniques. Le journal est conservé en base : il n'est protégé d'une panne que par la sauvegarde (Q20, option hors V1).

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
