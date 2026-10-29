# Documentation technique

Ce document s'adresse au développeur ou au technicien qui reprend l'application. Il décrit l'architecture, les mécanismes sensibles, les commandes, les tests et les conventions. Les règles métier sont dans `docs/02`, le modèle de données dans `docs/03`, les écrans dans `docs/04`, l'installation dans `docs/07`, l'historique des décisions dans `docs/08`. Les guides utilisateur sont dans `docs/guides/`.

## 1. Vue d'ensemble

| Élément | Choix |
|---|---|
| Application | Laravel 13, Filament 5 (Livewire 4), un seul panneau `/admin` (la racine `/` redirige vers `/admin`) |
| PHP | 8.3 minimum (développé sous 8.4) |
| Base de données | MySQL 8 ou MariaDB 10.11+ en production, et MySQL pour les tests (SQLite possible pour un essai rapide) ; pas de SQL propre à un moteur sans nécessité, et pas d'expression brute qui nomme une colonne (`LAST_VALUE`, par exemple, est un mot réservé de MySQL 8) |
| Tests | Pest 4 et les aides de test Filament |
| Qualité | Laravel Pint, Larastan niveau 6 sans exception (`ignoreErrors`) |
| Paquets métier | `spatie/laravel-permission` (rôles et droits), `spatie/laravel-activitylog` (journal), `barryvdh/laravel-dompdf` (PDF), `maatwebsite/excel` (Excel), `endroid/qr-code` et `picqer/php-barcode-generator` (étiquettes) |
| Hors ligne | Aucune ressource externe à l'exécution : polices servies depuis `public/fonts`, fichiers sur le disque local `storage/app/private` |
| Fuseau et formats | `Africa/Brazzaville`, dates `jj/mm/aaaa`, montants entiers en FCFA avec espace insécable comme séparateur de milliers (`App\Support\Money`) |

Les versions exactes installées sont dans `docs/08` (tableau « Versions installées »).

## 2. Architecture

### 2.1 Dossiers

| Dossier | Contenu |
|---|---|
| `app/Actions/<Domaine>` | Une opération métier = une classe invocable (`RecordDisposal`, `TransferAssets`, `CloseInventory`, `CloseFiscalYear`…). C'est ici que vivent les règles de gestion, les transactions et les verrous. |
| `app/Services` | Calculs et générateurs sans interface : `AssetNumberGenerator`, `DepreciationCalculator`, `DepreciationRegister`, `DisposalValuation`, `Settings`, références d'opérations, sous-dossiers `Alerts`, `Depreciation`, `FiscalYearClosing`, `Inventories`, `Labels`, `Reporting`, `System` (sauvegarde et restauration, contrôle de l'environnement). |
| `app/Filament` | Écrans seulement : `Resources/<Ressource>` (Pages, Schemas, Tables, RelationManagers, Actions), `Pages` (pages sur mesure : tableau de bord, recherche par code, étiquettes, rapports, amortissements), `Clusters/Referentials`, `Actions` (actions partagées : transfert, retour de prêt, sortie, maintenance, ajout à un inventaire, signalements), `Support` (champs, verrouillage, aides). Aucune règle métier ne doit y vivre : elles appellent les Actions. |
| `app/Models` | Modèles Eloquent, `Concerns` (`HasPerimeter`, `IsReferential`, `RecordsAuthors`) et `Scopes` (filtres de périmètre). |
| `app/Policies` | Une policy par ressource. |
| `app/Enums` | Statuts, états, motifs, types : libellé français (`getLabel`) et couleur Filament (`getColor`). |
| `app/Support` | Outils transverses : `DefaultRoles` (matrice des droits livrée), `Money`, `FileSize`, `OperationLabels`, `Pdf/PdfFactory`, `Reports/*` (catalogue des 16 rapports), `Labels/*`. |
| `app/Exports` | Exports Excel (`AssetsExport`, `ReportExport`, `InventoryReportExport`, `DepreciationScheduleExport`). |
| `app/Console/Commands` | Commandes `patrimoine:*` (section 5). |
| `app/Exceptions` | Exceptions métier (`ConcurrentModificationException`, `BackupException`). |
| `resources/views` | Gabarits Blade : pages Filament sur mesure, `pdf/*` (documents imprimés), `errors/*` (pages d'erreur françaises). |
| `database/migrations`, `database/seeders` | Une migration par table ; seeders de référence (`ReferenceSeeder`, `RolesSeeder`) séparés des seeders de démonstration (`DemoSeeder` et ses `*DemoSeeder`). |
| `tests` | `Feature/<Domaine>`, `Unit`, `Fixtures/amortissements.json`, `Support` (aides de test, scripts de concurrence). |
| `docs` | Documentation (ce dossier) et `docs/guides` (guides utilisateur en Markdown). |

### 2.2 Principe : écran, Action, modèle

Un écran Filament collecte les saisies, vérifie l'autorisation (policy), appelle **une Action** et affiche le résultat ou l'erreur. L'Action ouvre la transaction, pose les verrous, applique les règles (RG-xx), écrit le journal et lève une `ValidationException` ou une `DomainException` avec un message français. Les messages d'erreur vus par les utilisateurs viennent donc des Actions : pour changer une règle, on modifie l'Action et son test, pas l'écran.

Exemples : créer un bien (`Actions/Assets/CreateAsset`), transférer (`Movements/TransferAssets`), prêter et retourner (`Loans/CreateLoan`, `RecordLoanReturn`), sortir (`Disposals/RecordDisposal`), clôturer un exercice (`FiscalYears/CloseFiscalYear`), clôturer un inventaire (`Inventories/CloseInventory`).

### 2.3 Droits : policies et matrice

- Les droits sont des permissions `module.capacité` (par exemple `assets.view`, `disposals.validate`). Les modules sont dans `App\Enums\PermissionModule` (16), les capacités dans `PermissionAbility` : consulter, créer, modifier, supprimer ou sortir, valider, exporter.
- `App\Support\DefaultRoles` donne la matrice des cinq rôles livrés ; `RolesSeeder` l'installe sans écraser les droits modifiés ensuite par l'administrateur. L'Administrateur reçoit toutes les permissions.
- Chaque ressource, page et action passe par une policy. Les cas particuliers : l'amortissement (`DepreciationAuthority` réserve la gestion aux rôles Administrateur et Comptable, RG-83), les inventaires (`InventoryPolicy` : saisie réservée aux participants désignés ou au droit « créer », périmètre contenu), les rapports (aperçu : droit « consulter » des rapports et « exporter » du module concerné ; export : en plus « exporter » des rapports, QL11-1).
- `suppression` : les policies refusent `delete` sur tout ce qui ne doit pas disparaître (biens, mouvements, prêts, sorties, inventaires, exercices, plans). Seuls un document non rattaché et un rôle personnalisé sans utilisateur se suppriment.

### 2.4 Périmètre (RG-81)

Un utilisateur a des sites et des départements autorisés (tables pivot, vide = tous). Le trait `HasPerimeter` pose le scope global `PerimeterScope` sur les modèles concernés (biens, acquisitions, sorties, interventions, signalements…) ; des scopes dédiés existent pour les prêts (`LoanPerimeterScope`, `LoanItemPerimeterScope`), les mouvements, les inventaires et leurs lignes. Chaque modèle déclare ses colonnes de périmètre dans `perimeterColumns()`. Le scope filtre listes, recherches, rapports, exports, alertes et journal. Les Actions revérifient le périmètre pour les identifiants reçus du navigateur : ne jamais faire confiance à un identifiant venu de Livewire sans le filtrer.

Pour contourner volontairement le scope (travail de fond, démonstration), `withoutGlobalScopes()` doit être justifié par une règle écrite.

### 2.5 Journal des opérations (RG-70 à RG-72)

`spatie/laravel-activitylog` écrit chaque opération avec l'utilisateur, l'heure, le poste (adresse IP), l'objet et les valeurs avant et après (`logOnly` sur les modèles). Le modèle `App\Models\Activity` est exposé en lecture seule par `ActivityResource` (aucune édition, aucune suppression). Les connexions réussies et échouées passent par les écouteurs `RecordSuccessfulLogin` et `RecordFailedLogin` (blocage de 15 minutes après 5 échecs, RG-85). Les libellés lisibles viennent de `App\Support\OperationLabels`. Les tests de concurrence désactivent le journal (`ACTIVITY_LOGGER_ENABLED=false`).

### 2.6 Accès simultanés : verrous et `lock_version` (RG-86)

Deux mécanismes complémentaires :

1. **Version optimiste.** Les fiches modifiables portent une colonne `lock_version`. L'écran lit la version à l'ouverture et la garde dans une propriété Livewire verrouillée (`DetectsConcurrentEdits`, `HoldsOperationVersions`, jamais un champ caché modifiable par le navigateur). L'Action relit la fiche **sous verrou** (`lockForUpdate`), compare la version et lève `ConcurrentModificationException` (« Cette fiche a été enregistrée par … depuis son ouverture. Rechargez la page… ») si elle a changé ; sinon elle incrémente `lock_version`. Utilisée par `UpdateAsset`, `TransferAssets`, `RecordDisposal`, `ChangeDepreciationParameters`, `UpdateAcquisition`, `UpdateMaintenanceJob`, `UpdateInventory`, `RecordInventoryEntry` (version de la ligne) et `Concerns/GuardsOperations`.
2. **Verrous de ligne dans des transactions courtes.** Toute opération qui change le statut d'un bien (prêt, passage en maintenance, sortie, clôture d'exercice) verrouille d'abord la ligne concernée, relit l'état puis écrit. Sous MariaDB, les interblocages possibles sont rejoués : `CloseFiscalYear` rejoue sa transaction jusqu'à 3 fois (revue du lot 10).

### 2.7 Numéros et références sous verrou (RG-01 à RG-04)

`App\Services\AssetNumberGenerator` est la **seule** source de numéros patrimoniaux. Il incrémente d'abord le compteur de `asset_number_sequences` (une ligne par portée : `PAT`, `PAT-2026`, ou préfixe de catégorie selon `Settings::NUMBER_FORMAT`) dans une transaction avec verrou de ligne, puis le numéro est écrit sous un index unique. Il n'est jamais modifié ni réutilisé. Les autres références utilisent le même schéma « incrément d'abord » dans la même table, avec des portées plus longues pour éviter toute collision :

| Générateur | Référence | Portée |
|---|---|---|
| `AcquisitionReferenceGenerator` | `ACQ-2026-039` | `ACQ-2026` |
| `DisposalReferenceGenerator` | `SRT-2026-001` | `SRT-2026` (année de la date de sortie) |
| `OperationReferenceGenerator` | `PRT-`, `EMP-`, `MNT-`, `SIG-2026-…` et `EXT-0001` | `PRT-2026`, `EMP-2026`, `MNT-2026`, `SIG-2026`, `EXTERNAL` |
| `Inventories\InventoryReferenceGenerator` | référence d'inventaire | par année |

Un numéro est consommé même si l'opération échoue ensuite hors de la transaction englobante : l'unicité prime sur l'absence de trous.

### 2.8 Amortissements et exercices

- **Calcul pur** : `App\Services\DepreciationCalculator` (sans base de données) produit un plan à partir d'une `DepreciationInput`. Les huit cas validés par le comptable sont dans `tests/Fixtures/amortissements.json` et `tests/Unit` : ils font foi (RG-40). Montants entiers, prorata 30/360, dernière dotation absorbant l'arrondi.
- **Règles** : `DepreciationRules` (seuil, dégressif, coefficients depuis `Settings`), `DepreciabilityResolver` (un bien est-il amortissable ?), `Actions/Assets/ApplyAssetRules` (paramètres d'une fiche depuis sa catégorie).
- **Persistance** : `Actions/Depreciation/SyncDepreciationSchedule` recalcule et enregistre les lignes des exercices **ouverts** ; `SyncOutcome::Blocked` signale un plan qui ne peut pas être recalculé. `ChangeDepreciationParameters` exige un motif, historise l'ancienne et la nouvelle valeur (RG-37). `ValidateDepreciationSchedule` fige une version (RG-38).
- **Exercices** : `FiscalYear` (préparé, ouvert, clôturé). `CloseFiscalYear` exige le dernier jour de l'exercice, l'ordre chronologique, la confirmation des points d'attention (`FiscalYearClosing\*` : plans, sorties, inventaires, anomalies), fige les lignes, valide les plans et ouvre l'exercice suivant. Tout exercice jusqu'au dernier exercice clôturé inclus est figé (RG-36) ; `CreateFiscalYear` refuse un exercice antérieur au dernier existant.
- **Sortie** : `DisposalValuation` / `DisposalValues` arrêtent le plan à la date de sortie incluse et figent valeur d'origine, cumul et VNC sur la sortie (RG-39). Une sortie est refusée si le plan du bien est engagé mais non recalculable.
- **Registre et chiffres** : `DepreciationRegister` alimente le tableau des immobilisations ; `Reporting\PatrimonyFigures` est l'unique source des chiffres du tableau de bord et des rapports.
- **Recalcul manuel** : `php artisan patrimoine:recalculer-plans`.

### 2.9 Alertes et planificateur

`Services\Alerts\AlertGenerator` détecte les sept types de `AlertType` (prêt à échéance, prêt en retard, intervention ouverte, bien hors service, inventaire à venir, écarts d'inventaire, fin d'amortissement) sur l'ensemble du patrimoine ; `Actions\Alerts\RefreshAlerts` synchronise la table `alerts` par empreinte unique (idempotent : une cause levée lève l'alerte, les alertes levées sont purgées après 30 jours). Les lectures sont dans `alert_reads` (par utilisateur). L'affichage (`AlertPresenter`, `AlertCounter`, page `Alerts`, cloche du panneau) filtre selon périmètre et droits. Les délais viennent de `Settings` (3 jours, 15 jours, 7 jours, 3 mois par défaut).

Planification : `routes/console.php` déclare `patrimoine:calculer-alertes` toutes les heures, sans chevauchement. Le planificateur de Laravel ne tourne que si le système appelle `php artisan schedule:run` chaque minute : tâche Windows « Patrimoine - planificateur » (`install.ps1 -TachePlanifiee`, voir `docs/07`). Aucune file d'attente n'est nécessaire.

### 2.10 PDF et Excel

- **PDF** : DomPDF via `App\Support\Pdf\PdfFactory::view()` : A4, `enable_remote = false`, `enable_php = false`, police DejaVu Sans incluse, « Page x / y » écrit par le canvas. Gabarit commun `resources/views/pdf/layouts/document.blade.php` (en-tête avec nom et sigle de l'Église lus dans `Settings`, bande de titre, pied de page, zones de signature) hérité par les documents : liste des biens, plan d'amortissement, procès-verbal et liste de contrôle d'inventaire, fiche de prêt, rapports (paysage), guides utilisateur. Les étiquettes (`pdf/labels`) sont générées à l'échelle exacte par `Services\Labels` (`LabelComposer`, `LabelSheetBuilder`, `LabelCodeRenderer`) : QR code ou Code 128 qui ne contient que le numéro.
- **Excel** : `maatwebsite/excel` via les classes de `app/Exports`. Tous les textes sont forcés en texte (une désignation `=1+1` n'est jamais une formule) ; montants en entiers.
- **Guides** : `php artisan patrimoine:guides` convertit `docs/guides/*.md` en PDF (CommonMark avec tableaux GFM, balises HTML retirées) avec le gabarit commun.

### 2.11 Fichiers et documents (RG-65, RG-66)

Les fichiers sont stockés sur le disque `local` (`storage/app/private`), jamais dans `public`. Ils ne sortent que par des routes authentifiées du panneau (`documents/{document}/fichier`, `biens/{asset}/photo`, `signalements/{report}/photo`) qui contrôlent les droits. Taille maximale : paramètre `document_max_size_kb` (20 Mo par défaut, réglable dans l'écran Paramètres, plafonnée par la limite de PHP) ; il faut régler `upload_max_filesize` et `post_max_size` dans le `php.ini` du serveur web (voir `docs/07`).

### 2.12 États système

Pages d'erreur françaises dans `resources/views/errors` (401, 403, 404, 419, 429, 500, 503) sur le gabarit `shell.blade.php`, sans ressource externe. Fenêtres d'état du panneau (session expirée, serveur injoignable, erreur) : `resources/views/filament/partials/etats-systeme.blade.php` et `public/js/etats-systeme.js`, accrochés par `PanelsRenderHook::BODY_END`. Aucun détail technique n'est montré en 500 ; il va dans `storage/logs/laravel.log`.

### 2.13 Écran Paramètres (E41)

- **`Filament\Pages\SettingsPage`** (Administration > Paramètres, `/admin/parametres`) : onglets Identité, Numérotation, Alertes, Sécurité, État du serveur ; un bouton « Enregistrer » unique. Le format et le préfixe sont désactivés dès que `UpdateSettings::numberingLocked()` est vrai (un bien existe ou un compteur a avancé). La page renvoie à l'Action les valeurs chargées à l'ouverture, pour détecter un changement concurrent.
- **`Actions\Settings\UpdateSettings`** : seul point d'écriture. Revalide tout (bornes des alertes `ALERT_BOUNDS`, nom 120 et sigle 12 caractères, durée de session 5 à 480 min, taille des documents entre 1 Mo et le plus bas du plafond de 100 Mo et de la limite PHP). N'écrit que les champs présents dans `$data` et réellement modifiés. Refuse l'enregistrement si une valeur a changé en base depuis le chargement de l'écran (message « modifié par un autre administrateur »). Le tout dans une transaction, avec verrou sur `asset_number_sequences` pour ne pas croiser une création de bien. Les anciennes et nouvelles valeurs vont au journal (RG-70, log `parametres`).
- **`Policies\SettingPolicy`** : réservée au rôle Administrateur actif, en plus du droit « Paramètres » de la matrice ; un rôle personnalisé n'y accède pas. Ni création ni suppression.
- **`Http\Middleware\ApplySessionLifetime`** : avant le démarrage de la session, remplace `session.lifetime` par le paramètre `session_lifetime_minutes` s'il est enregistré et compris entre 5 et 480 ; sinon `SESSION_LIFETIME` du `.env` reste la valeur par défaut. La valeur est mise en cache 60 s (`Cache::remember`, vidé par `Settings::set`) pour éviter une requête SQL par page ; base injoignable : le `.env` s'applique.
- **`Services\System\ServerStatus`** : contrôles en lecture seule de l'onglet « État du serveur » (PHP, extensions, base, limites d'envoi via `EnvironmentCheck`, espace disque, planificateur d'après le dernier calcul des alertes, dernière sauvegarde) ; chaque ligne est `ok`, `warn` ou `fail`.

## 3. Modèle de données

Le détail des tables, colonnes et relations est dans `docs/03-modele-de-donnees.md`. En résumé :

| Famille | Tables principales |
|---|---|
| Organisation | `sites`, `departments`, `department_site`, `services`, `rooms`, `holders` |
| Référentiels | `asset_categories`, `asset_conditions`, `suppliers`, `document_types`, `label_templates` |
| Biens | `assets` (numéro, affectation, état, statut, paramètres d'amortissement, `lock_version`, `disposed_at`), `asset_number_sequences` (compteurs sous verrou) |
| Acquisitions | `acquisitions`, `acquisition_lines` |
| Mouvements | `movements` (jamais modifiés ni supprimés) |
| Inventaires | `inventories`, `inventory_lines`, `inventory_participants` |
| Prêts | `loans`, `loan_items` |
| Maintenance et signalements | `maintenance_jobs`, `asset_reports` |
| Sorties | `disposals` |
| Amortissements | `fiscal_years`, `depreciation_schedules` (versions), `depreciation_lines`, `depreciation_parameter_changes` |
| Documents, paramètres, alertes | `documents` et `documentables` (rattachement polymorphe), `settings`, `alerts`, `alert_reads` |
| Utilisateurs et droits | `users`, `roles`, `permissions` et pivots (spatie), pivots de périmètre `site_user` et `department_user`, `activity_log` |

Conventions : montants en `bigInteger` (FCFA), taux en `decimal(7,4)`, clés étrangères et index à chaque migration, une migration par table, noms de tables et colonnes en anglais (correspondance avec les termes français dans `docs/03`).

## 4. Données de démarrage et de démonstration

- `ReferenceSeeder` et `RolesSeeder` : rôles, états, catégories par défaut, types de documents, formats d'étiquettes. Rejouables sans écraser les valeurs modifiées. Installés par `patrimoine:installer`.
- `DemoSeeder` : structure fictive, utilisateurs (`admin`, `a.okemba`, `m.loubaki`, `e.mbemba`…), biens, acquisitions, mouvements, prêts, maintenance, sorties, inventaires, amortissements, alertes. Refusé en production et dès que la base contient des données. Les mots de passe de démonstration sont dans ce seeder et ne doivent jamais servir sur un vrai serveur.
- Le client n'a pas encore fourni ses référentiels (sites, départements, catégories…) : voir QL12-10 dans `docs/06`.

## 5. Commandes Artisan du projet

Liste obtenue par `php artisan list patrimoine`.

| Commande | Rôle |
|---|---|
| `patrimoine:installer` | Installe l'application : contrôles, base, migrations, référentiels, premier administrateur (mot de passe saisi sans écho, 12 caractères minimum, lettres et chiffres). Relançable sans risque. |
| `patrimoine:verifier` | Rejoue les contrôles d'installation (PHP, extensions, dossiers, clé, base) sans rien modifier. |
| `patrimoine:sauvegarder {destination}` | Sauvegarde base et fichiers privés dans un dossier daté avec manifeste (taille et SHA-256), relu et contrôlé. Exclut `.env`. Option `--bin` pour indiquer `mysqldump`. |
| `patrimoine:restaurer {source}` | Restaure depuis un dossier de sauvegarde ; exige le mode maintenance, confirmation par saisie du nom de la base (`--base=`, `--force` en production), sauvegarde de sécurité préalable. |
| `patrimoine:recalculer-plans` | Recalcule les plans d'amortissement des exercices ouverts ; les exercices clôturés ne bougent pas. |
| `patrimoine:calculer-alertes` | Calcule les alertes internes (planifié toutes les heures). |
| `patrimoine:guides {--sortie=storage/app/guides}` | Génère les guides utilisateur en PDF à partir de `docs/guides`. |

Autres commandes utiles : `php artisan optimize` / `optimize:clear` (cache de configuration, routes, vues, à relancer après toute modification de `.env`), `php artisan down` / `up` (maintenance), `php artisan schedule:list`.

Outils de qualité : `vendor/bin/pint`, `vendor/bin/phpstan analyse`.

## 6. Tests

### 6.1 Organisation

- `tests/Feature/<Domaine>` : un dossier par domaine (`Acces`, `Acquisitions`, `Alertes`, `Amortissements`, `Biens`, `Documents`, `Etiquettes`, `Inventaires`, `Maintenance`, `Mouvements`, `Prets`, `Rapports`, `Signalements`, `Socle`, `Sorties`, `Structure`, `Systeme`, `TableauDeBord`). Convention de nommage : tests de règles et d'écrans, tests de démonstration (`Demo*Test`), tests de revue (`Revue*Test`, `CorrectionsRevue*Test`) qui reprennent les sondes des relecteurs en non-régression, tests de concurrence (`*Concurrence*Test`).
- `tests/Unit` : le calculateur d'amortissement et ses cas de revue ; `tests/Fixtures/amortissements.json` : les cas validés, qu'on ne modifie jamais pour faire passer un calcul.
- `tests/Support` : `OperationsWorld` (jeu de données), `PdfInspector` (lit les PDF DomPDF : pages, textes, positions), `CodeReader` (décode QR et Code 128), scripts `concurrent-*.php`.
- Configuration :
  - `phpunit.xml` : **MySQL**, comme en production, sur la base jetable `patrimoine_test` (127.0.0.1:3306) ; journal et files en mode test.
  - L'identifiant et le mot de passe viennent de `DB_USERNAME` et `DB_PASSWORD` (dans le `.env`, ou dans l'environnement en CI).
  - `tests/Pest.php` applique `RefreshDatabase` à tous les tests `Feature`.
  - Garde-fou dans `tests/TestCase.php` : les tests refusent toute base dont le nom ne finit pas par `_test` (ou autre que SQLite `:memory:`).
- Poste de développement :
  - Démarrer le MySQL de WAMP.
  - Créer les bases `patrimoine_test` et `patrimoine_concurrence`, accessibles au compte du `.env`, avec `log_bin_trust_function_creators=1` (voir docs/07, « Verrou du journal »).
  - Pour un essai rapide sans serveur : `DB_CONNECTION=sqlite DB_DATABASE=:memory: php vendor/bin/pest`.
- Commande : `php artisan test` (suite complète, environ une demi-heure), ou un seul dossier : `php artisan test tests/Feature/Systeme`.
- **Les tests partagent la même base : ne jamais lancer deux suites MySQL en même temps.**
- CI GitHub : `.github/workflows/tests.yml`. Elle lance la suite sur MySQL 9.1 et sur MariaDB 11.4, avec un compte sans privilège SUPER, puis Pint et PHPStan, à chaque PR et sur main.

### 6.2 Tests de concurrence

Ils lancent de **vrais processus PHP parallèles** (scripts `tests/Support/concurrent-*.php`) sur une base partagée : numéro patrimonial, validation d'acquisition, saisie d'inventaire, sorties, prêt et maintenance simultanés, clôture d'exercice et sortie.

- Variante SQLite : toujours exécutée (transactions `IMMEDIATE`).
- Variante MySQL / MariaDB : elle tourne sur la **base jetable** `patrimoine_concurrence`. `phpunit.xml` la fixe avec les variables `CONCURRENCE_DB_DRIVER` (`mysql`), `CONCURRENCE_DB_HOST`, `CONCURRENCE_DB_PORT` et `CONCURRENCE_DB_DATABASE`.
  - Identifiant et mot de passe : `CONCURRENCE_DB_USERNAME` et `CONCURRENCE_DB_PASSWORD`, ou à défaut ceux de la connexion de test (fonction `concurrencyDb()` de `tests/Pest.php`).
  - Seul le moteur indiqué tourne. L'autre est compté « ignoré ».
  - **La base indiquée est entièrement vidée par le test** : ne jamais pointer vers une base de production.
  - Exemple sur MariaDB :

```
CONCURRENCE_DB_DRIVER=mariadb CONCURRENCE_DB_PORT=3307 php artisan test --filter=Concurrence
```

Tout nouveau verrou ou toute nouvelle référence doit venir avec son test de concurrence sur les deux moteurs.

### 6.3 Avant de livrer

`php artisan test`, `vendor/bin/pint`, `vendor/bin/phpstan analyse` (niveau 6, 0 erreur, aucune exception ajoutée). Un lot n'est pas terminé tant qu'un des trois échoue.

## 7. Conventions (résumé de `CLAUDE.md`)

- Code, tables, colonnes et méthodes en anglais ; interface, messages et validations en français (`lang/fr`).
- Une Action par opération métier ; les ressources Filament n'embarquent pas de règle de gestion.
- Statuts, états et motifs fixes : Enums avec libellé et couleur.
- Commentaires seulement pour une règle de gestion, avec sa référence (`// RG-33 : la dernière dotation absorbe l'écart d'arrondi`).
- Montants entiers, jamais de `float` ; taux en `decimal(7,4)`.
- Rien ne se supprime ; les référentiels se désactivent.
- Aucune ressource externe à l'exécution ; aucun nouveau paquet sans justification dans `docs/08`.
- Hors périmètre V1 : application mobile, e-mails, SMS, cloud, comptabilité générale, dons, membres, paie, budgets.
- Produit commercialisable : rien de spécifique à une Église réelle dans le code ; nom, sigle et valeurs viennent des paramètres ou des référentiels.
- Une branche par lot (`lot-N`), fusion après relecture humaine, jamais de poussée directe sur `main`.
- Sur le poste de développement : toujours rediriger l'entrée standard des commandes (`</dev/null`) ; en cas de blocage de Composer, voir la note dans `docs/08`.

## 8. Ajouter un module

Suivre la commande de projet `.claude/commands/module.md` (`/module <numéro du lot>`), qui impose l'ordre : lire les règles de gestion (`docs/02`), le modèle (`docs/03`) et les écrans (`docs/04`) ; créer la branche du lot ; écrire un plan court ; écrire les tests Pest d'abord, les voir échouer, puis implémenter ; lancer tests, Pint et Larastan ; cocher les critères dans `docs/05` et compléter `docs/08`. La revue indépendante se fait ensuite avec `.claude/commands/revue.md`.

Pour un nouveau module concret :

1. Migration(s) et modèle(s), avec `HasPerimeter` si le périmètre s'applique, `RecordsAuthors`, journal (`logOnly`).
2. Permission : ajouter un cas à `PermissionModule`, la ligne correspondante de `DefaultRoles::MATRIX`, et rejouer `RolesSeeder` sur les installations existantes.
3. Policy qui refuse `delete` si la donnée ne doit pas disparaître.
4. Actions (une par opération) avec transaction, verrous, `lock_version` si la fiche est modifiable, journal.
5. Ressource ou page Filament dans le bon groupe de navigation (`AdminPanelProvider::NAVIGATION_GROUPS`), qui appelle les Actions.
6. Enums, libellés français, messages de validation en français.
7. Tests : règles de gestion, écrans, droits et périmètre, démonstration (seeder), concurrence si un compteur ou un statut est en jeu.
8. Rapports : si le module produit des chiffres, les brancher sur `PatrimonyFigures` et le catalogue `ReportCatalog` plutôt que de recalculer ailleurs.
9. Mettre à jour `docs/02`, `03`, `04`, `06` (questions), `08` (journal) et le guide du profil concerné dans `docs/guides`.

## 9. Points de vigilance (relevés des revues, `docs/08`)

- **Ne jamais écrire sans relire sous verrou.** Plusieurs revues ont trouvé des fenêtres de course entre lecture et écriture (statut d'un bien, version d'une fiche, compteur). Toujours : transaction, `lockForUpdate`, relecture, contrôle de version, écriture.
- **Les identifiants venus du navigateur ne sont pas fiables.** Versions de fiche, identifiants de biens, de lignes, de départements : les garder dans des propriétés Livewire verrouillées, les filtrer par périmètre et par statut côté serveur (revues des lots 6, 8, 9).
- **Périmètre complet.** Une personne désignée sur un inventaire plus large que son périmètre ne doit pas valider des biens qu'elle ne voit pas (`InventoryScope::isContainedIn`). Une entrée du journal portant sur un objet hors périmètre ne doit pas être visible.
- **Exercices figés.** Tout exercice jusqu'au dernier clôturé inclus est figé ; un exercice antérieur ne se crée pas après coup ; une modification de date de mise en service qui toucherait un exercice figé est refusée.
- **Taux et lignes recalculées.** Une ligne issue d'un recalcul ou d'une bascule dégressive enregistre un taux nul (la colonne `decimal(7,4)` refuserait un taux de plusieurs centaines de pour cent sous MariaDB strict).
- **Bien sorti.** Le modèle `Asset` refuse toute modification d'une fiche sortie (hors `updated_at`, `updated_by`, `lock_version`). Une sortie est refusée si le plan du bien est engagé mais non recalculable, et dans un exercice clôturé.
- **Interblocages MariaDB.** La clôture d'exercice qui crée l'exercice suivant peut croiser une sortie : transaction rejouée 3 fois. Surveiller tout nouveau chemin qui verrouille plusieurs tables dans un ordre différent.
- **Dates.** N'accepter que le format `AAAA-MM-JJ` avec contrôle d'aller-retour pour les dates saisies à la main ou venues d'une URL.
- **Étiquettes.** Le numéro n'est jamais tronqué : si le modèle ne le contient pas à 6 pt, l'impression est refusée ; vérifier avec le plus long numéro du format configuré (`PAT-2026-000001`). L'image du code est calculée une seule fois par numéro (performance).
- **Sauvegarde et restauration.** Le manifeste est la seule liste de fichiers restaurés ; un fichier en trop fait refuser la restauration ; l'ancien dossier privé est renommé, jamais supprimé ; la restauration exige la maintenance. Ne pas relâcher ces garde-fous.
- **Tâche planifiée Windows.** Séparer l'exécutable et ses arguments (`New-ScheduledTaskAction -Execute … -Argument …`), sans quoi les chemins avec espaces cassent la commande.
- **Démonstration.** `--demo` est refusée dès qu'il existe un utilisateur, un site, un département ou un bien : elle réécrit le compte `admin`.
- **Excel.** Forcer chaque texte en texte (aucune formule injectée par une désignation).
- **Écran Paramètres.** Il existe depuis le lot 12 (E41, §2.13). Le seuil d'immobilisation et le dégressif restent sur leur écran propre (Règles d'amortissement), joint par un bouton de l'écran Paramètres.
- **Navigateur.** `public/js/etats-systeme.js` n'a pas été exécuté dans un vrai navigateur par les tests : à vérifier à la main à chaque changement de Livewire.
- **Poste de développement.** Composer se fige après certains scripts : `composer dump-autoload -n --no-scripts`, puis `php artisan package:discover` et `php artisan filament:upgrade`.
