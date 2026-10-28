# Journal technique

Tenu par Claude Code à la fin de chaque lot, relu par le développeur. Une entrée par lot, la plus récente en haut.

## Versions installées

| Composant | Version | Date |
|---|---|---|
| PHP | 8.4.0 sur le poste de développement (WAMP) ; dépendances résolues pour PHP 8.3 (`config.platform.php`) | 27/10/2026 |
| Laravel | 13.38.0 (squelette `laravel/laravel` 13.11) | 27/10/2026 |
| Filament | 5.11.0 | 27/10/2026 |
| Livewire | 4.4.8 | 27/10/2026 |
| Pest | 4.7.8 | 27/10/2026 |
| Larastan / PHPStan | 3.13.2, niveau 6 | 27/10/2026 |
| Laravel Pint | 1.32.1 | 27/10/2026 |
| Node / npm (build du thème) | Node 24.22 / npm 11.19 | 27/10/2026 |
| MySQL / MariaDB | Non installé par le lot 0 : `.env.example` cible MySQL ; WAMP du poste fournit MySQL 9.1 et MariaDB 11.5 | 27/10/2026 |
| spatie/laravel-permission | 8.3.2 | 27/10/2026 |
| spatie/laravel-activitylog | 4.12.3 (la v5 exige PHP 8.4) | 27/10/2026 |
| barryvdh/laravel-dompdf | 3.1.2 (dompdf 3.1.6) | 27/10/2026 |
| maatwebsite/excel | 4.0.6 | 27/10/2026 |
| Laravel (mise à jour au lot 3 par `composer require -W`) | 13.39.0 | 27/10/2026 |

Compatibilité vérifiée sur Packagist le 27/10/2026 : Laravel 13 exige PHP ^8.3, Filament 5 PHP ^8.2 et Livewire ^4, Pint PHP ^8.3. Pest 5 (dernière version) exige PHP ^8.4 : on reste sur Pest 4 pour que le projet s'installe sur un poste en PHP 8.3, comme le prévoit `CLAUDE.md`.

**Composer sur ce poste** : `composer require` et `composer dump-autoload` se figent pendant les scripts lancés après la génération de l'autoloader (aucune activité processeur). Contournement : `composer dump-autoload -n --no-scripts`, puis `php artisan package:discover` et `php artisan filament:upgrade`. Toujours rediriger l'entrée standard (`</dev/null`).

## Lot 10 · Sorties du patrimoine · 28/10/2026

- **Fait** :
  - Table `disposals` (migration `2026_10_28_100001`) conforme à `docs/03`, plus `previous_status`, `created_by`, `updated_by` ; `asset_id` unique (un bien ne sort qu'une fois). Modèle `Disposal` : visible si son bien l'est (scope de périmètre du bien), ne se modifie ni ne se supprime ; seule la validation comptable, une fois, est admise (garde dans le modèle). `Asset` : relation `disposal()` et garde « un bien sorti le reste » (date de sortie et statut d'un bien sorti ne bougent plus, quel que soit le chemin).
  - Référence `SRT-2026-001` (`App\Services\DisposalReferenceGenerator`) : compteur par année de la date de sortie dans `asset_number_sequences` (portée `SRT-2026`), incrément d'abord sous verrou exclusif, comme `AssetNumberGenerator`.
  - `App\Actions\Disposals\RecordDisposal`, une seule transaction (retentative 5 fois sur la transaction la plus externe ; la page Filament n'en ouvre pas) : verrou partagé sur l'exercice de la date de sortie (la clôture le verrouille en exclusif), verrou de ligne du bien lu à travers le scope de périmètre, puis contrôles : droit `disposals.create`, version RG-86, bien déjà sorti, bien emprunté ou en maintenance, date obligatoire, ni future, ni antérieure à l'acquisition ou à la mise en service, ni dans un exercice figé, numéro du bien saisi exactement (RG-53), décideur, motif, acquéreur ou bénéficiaire et prix entier pour une cession (RG-51). Puis justificatifs (rattachés au bien et à la sortie), statut Cédé ou Réformé et `disposed_at` (RG-52), plan arrêté par `SyncDepreciationSchedule` (RG-39), valeurs lues dans le plan arrêté et figées sur la sortie, journal. Un échec annule tout, y compris les fichiers enregistrés.
  - Valeurs à la date de sortie (`App\Services\DisposalValuation`, `DisposalValues`) : le panneau de E26 les calcule en direct sans rien écrire (`SyncDepreciationSchedule::previewDisposal`, ajout d'une méthode au lot 7) ; la sortie les relit dans le plan enregistré. Les deux passent par `DepreciationCalculator` (déjà testé avec A5). Plus ou moins-value indicative pour une cession seulement (Q8), sans écriture.
  - **Cas A5 exact de bout en bout** : sortie au 26/10/2026 → dotation 2026 = 493 333, cumul 2 620 000, VNC 2 180 000, lignes 2022 à 2025 figées inchangées, plus aucune ligne après 2026. Un plan déjà validé est conservé tel quel et la sortie crée la version suivante (RG-38).
  - `App\Actions\Disposals\ValidateDisposal` (E27) : administrateur et comptable seulement, en dur (`DepreciationAuthority::isAccountingRole`) et selon la matrice (`disposals.validate`), compte actif, sortie du périmètre, une seule fois, tracée. Ne touche ni au plan ni aux valeurs figées ; possible après la clôture de l'exercice.
  - RG-42 : `App\Services\FiscalYearClosing\DisposalsCheck` remplace le point provisoire : les sorties à valider datées jusqu'à la fin de l'exercice (tous périmètres) sont listées en point d'attention à confirmer ; « toutes validées » sinon.
  - Écrans : ressource « Sorties et réformes » (`/admin/sorties`, groupe Opérations, `navigationSort` 90) : liste (compteurs en sous-titre, recherche, motif, exercice, validation), création E26 (formulaire selon le motif, panneau des valeurs à la date de sortie et « Après confirmation » en direct, justificatifs, action « Confirmer la sortie » avec saisie du numéro), fiche de la sortie avec « Valider », liens vers la fiche et le plan arrêté. Pas d'écran de modification ni de suppression. Fiche du bien : action « Sortir » (désactivée pour un bien emprunté ou en maintenance) et, pour un bien sorti (E43), bandeau enrichi (référence, motif) et section des valeurs figées et de la validation. Journal : domaine « Sorties du patrimoine ».
  - Contrat avec les lots 8 et 9 : `/admin/sorties/create?bien=<id>&motif=<valeur de DisposalReason>` préremplit (bien filtré par le périmètre et non sorti, motif validé, sinon ignorés) ; `inventaire_ligne=<id>` est gardé côté serveur et, si `App\Actions\Inventories\ResolveInventoryLine` existe, appelé dans la transaction de la sortie avec (ligne, 'disposal', sortie) — la ligne est passée en modèle si `App\Models\InventoryLine` existe, sinon en identifiant. À ajuster à l'intégration.
  - Démonstration : `DisposalsDemoSeeder` (appelé par `DemoSeeder` juste après les amortissements, idempotent, par l'action métier) : SRT-2026-001 don validé par M. Loubaki, SRT-2026-002 perte et SRT-2026-003 réforme de PAT-000034 au 26/10/2026 (cas A5), à valider.
  - Tests (`tests/Feature/Sorties`, 54 tests, 78 cas avec les jeux de données) : A5 de bout en bout, chaque motif et son statut, champs obligatoires, confirmation par numéro, refus (déjà sorti, prêté, en maintenance, dates, exercice clôturé ou antérieur, périmètre, droits, version périmée), irréversibilité par tous les chemins, RG-36, journal, validation par les seuls rôles autorisés (y compris par l'action directe et avec un rôle personnalisé qui aurait le droit dans la matrice), point d'attention et clôture, écrans (droits, préremplissage, manipulation Livewire : appel direct de `create`, bien hors périmètre injecté, propriétés verrouillées), fiche E43 et plan arrêté, démonstration. Concurrence en processus parallèles (`tests/Support/concurrent-disposals.php`) : 6 sorties simultanées du même bien → une seule passe ; 6 biens différents → 6 références distinctes. **Passé sur SQLite et sur MariaDB 11.5** (base `lot10`). Suite complète : 509 tests, 496 réussis, 12 ignorés (bases MySQL et MariaDB non indiquées), 1 délai dépassé (`migrate:fresh` du test de concurrence SQLite, poste saturé par les autres lots) ; ce test relancé seul passe. Pint et PHPStan niveau 6 : 0 erreur.
- **Décisions** :
  - Bien emprunté ou en maintenance : sortie refusée, on enregistre d'abord le retour ou la clôture de l'intervention (QL10-1). Bien hors service ou en stock : accepté.
  - Sortie datée dans un exercice clôturé ou dans une année antérieure au premier exercice recalculable : refusée (Q9.12, option prudente de `docs/06`, QL10-3). Garantie RG-36 : la date est contrôlée sous verrou de l'exercice, le plan ne recalcule que les exercices ouverts, et un plan validé est conservé (nouvelle version).
  - Bien sans plan (non amortissable ou sans mise en service) : cumul 0 et VNC = valeur d'origine (QL10-2).
  - Confirmation : comparaison exacte avec le numéro (majuscules comprises), espaces autour ignorés. Elle est exigée par l'action métier : appeler `create` sans passer par la fenêtre est refusé.
  - Montants : entiers seulement (chiffres, espaces de milliers tolérés), de 0 à 999 999 999 999 ; prix et acquéreur ignorés hors cession (bénéficiaire pour un don).
  - Justificatifs facultatifs (QL10-7), type « Document de réforme » proposé. Date du constat et référence de déclaration de la maquette (vol, perte) saisies dans « Référence de la décision » et « Observations » (QL10-6).
  - Une seule entrée de journal « Sortie du patrimoine » (statut avant et après, date, référence, motif, valeurs figées) : l'entrée automatique « Modification d'une fiche » est neutralisée pour cette écriture.
  - Le comptable peut encore valider le plan arrêté d'un bien sorti depuis E31 (validation RG-38, pas une modification de la fiche) ; « Modifier les paramètres » reste masqué (lot 7).
  - Fichiers partagés modifiés a minima : `Asset` (relation et garde), `ViewAsset` (une action), `AssetInfolist` (bandeau et section, deux lignes), `ActivityResource` (domaine), `DemoSeeder` (une ligne), `DisposalReason` (couleurs, statut résultant, champs selon le motif), lot 7 : `SyncDepreciationSchedule::previewDisposal` et `FiscalYearAttentionPoints` (fournisseur des sorties). `PendingModuleCheck::disposals()` n'est plus utilisé mais laissé en place (fichier aussi touché par le lot 9). Tests de démonstration des lots 3 et 7 adaptés : PAT-000034 est désormais sorti (3 biens sortis, plan A5 complet, version validée conservée).
- **Écarts** : pas d'export de la liste des sorties (le droit « exporter » existe ; rapport des sorties au lot 11). Validation de démonstration datée du jour du chargement. En cas d'interblocage rejoué, les fichiers d'une tentative abandonnée peuvent rester sur le disque (rare).
- **Questions ajoutées** : QL10-1 à QL10-8 dans `docs/06`.
- **À faire valider par le comptable** : Q9.12 / QL10-3 (refus d'une sortie antidatée dans un exercice clôturé), QL10-2 (valeurs d'un bien sans plan), QL10-5 (validation définitive, possible après clôture), QL10-8, la moins-value indicative (Q8) et le cas A5.
- **À tester à la main** :
  - Compte gestionnaire (`a.okemba`) : depuis la fiche PAT-000125, « Sortir », choisir « Cession » (acquéreur, prix 100 000), vérifier le panneau des valeurs et la plus-value indicative, confirmer avec un numéro erroné puis exact ; la fiche passe en lecture seule (ni Modifier, ni Transférer, ni documents), le plan s'arrête à la date de sortie.
  - Essayer une date au 31/12/2025 (refus) et une date future (refus).
  - Compte comptable (`m.loubaki`) : liste des sorties, « Valider » SRT-2026-002 ; vérifier le journal ; sur une copie de la base au 31/12/2026, voir le point d'attention « sorties non validées » avant la clôture.
  - Compte responsable de département : aucun accès aux sorties ; fiche d'un bien sorti de son département en lecture seule.
  - Lancer `npm run build` avant les essais (nouvelles classes Tailwind du panneau).

## Lot 5 · Acquisitions · 28/10/2026

- **Fait** :
  - Tables `acquisitions` et `acquisition_lines` (docs/03), montants `bigInteger`. `total_amount` et `line_total` sont toujours calculés (`App\Services\AcquisitionRules`), jamais repris de la saisie.
  - Référence `ACQ-2026-039` (`App\Services\AcquisitionReferenceGenerator`) : un compteur par année dans la table `asset_number_sequences` (portée `ACQ-2026`), incrémenté sous verrou de ligne exclusif dans la transaction de création, comme le numéro patrimonial. Jamais réutilisée, même après suppression d'un brouillon.
  - Actions `CreateAcquisition`, `UpdateAcquisition` (remplace les lignes d'un brouillon), `DeleteAcquisition`, `ValidateAcquisition`, `AttachAcquisitionDocuments`, `DetachAcquisitionDocument`.
  - Validation (RG-15) : une seule transaction, verrou sur l'acquisition, une fiche par unité des lignes « suivi individuel » via `CreateAsset` (numéros consécutifs, paramètres d'amortissement de la catégorie par `ApplyAssetRules`), avec `acquisition_line_id`, fournisseur, facture, financement, site, département et prix unitaire ; une ligne non suivie ne crée rien. Une panne en cours de route annule toutes les fiches (testé). Une acquisition validée n'est plus modifiable ni supprimable (modèle, action, policy, page de modification en 403).
  - RG-16 : après validation, redirection vers `url('/admin/etiquettes') . '?biens=<id1>,<id2>,…'` (`App\Support\LabelsUrl`, contrat avec le lot 4). Sans fiche créée (consommables seuls), retour à la fiche de l'acquisition. Bouton « Imprimer les étiquettes » sur une acquisition validée.
  - Justificatifs : documents polymorphes (`documentables`), ajout à la création ou depuis l'onglet « Justificatifs » d'un brouillon ; case « Rattacher aussi aux biens créés » dans la fenêtre de validation. `Document::visible()` tient compte des acquisitions (les justificatifs apparaissent dans E28).
  - Écrans : ressource « Acquisitions » (groupe Patrimoine, après Étiquettes) : liste avec recherche, exercice, période, site, département, financement, statut ; formulaire en trois blocs avec Repeater en tableau (désignation, catégorie, quantité, prix, total calculé, suivi individuel) et récapitulatif en direct ; fiche en lecture avec lignes et fiches créées.
  - Droits : policy sur le module `acquisitions` appliquée telle quelle (consulter, créer, modifier, valider). Périmètre : scope global sur le site et le département de l'acquisition, et refus d'enregistrer une acquisition hors du périmètre de l'utilisateur.
  - Journal (RG-70) : création, modification, validation (avec la liste des numéros créés), ajout et retrait de justificatifs.
  - Démonstration : `AcquisitionsDemoSeeder` (appelé par `DemoSeeder`, idempotent) : ACQ-2026-038 validée et reliée à PAT-001248, ACQ-2026-039 en brouillon (valide de la maquette : PAT-001249 à PAT-001254), 037 et 036 en brouillon.
- **Décisions** :
  - Le gestionnaire n'a pas « valider » dans la matrice : seul l'administrateur valide (Q5.1).
  - Fiches créées : état « Neuf » (à défaut, le premier état actif), statut « En service », mise en service vide (Q5.2).
  - Supprimer un brouillon demande le droit « modifier » (Q5.3). Les fichiers des justificatifs d'un brouillon supprimé sont conservés.
  - Quantité limitée à 500 par ligne et à 500 unités suivies par acquisition (contrôle à la saisie et à la validation : verrou du compteur tenu pendant la validation, durée, longueur de l'URL des étiquettes).
  - L'année de la date d'acquisition d'un brouillon ne peut plus changer : la référence la porte (Q5.3).
  - Correctifs de revue : les justificatifs d'acquisition ne sont visibles dans E28 qu'avec le droit `acquisitions.view` ; ajout et retrait d'un justificatif relisent l'état sous verrou ; RG-86 sur les brouillons (`lock_version`, `DetectsConcurrentEdits`, contrôle sous verrou dans `UpdateAcquisition`) ; la validation n'est plus proposée sur l'écran de modification (elle abandonnerait les modifications non enregistrées) ; fournisseur désactivé refusé ; journal des modifications d'un brouillon (en-tête et lignes, avant et après lisibles).
  - Test de concurrence de la validation en processus parallèles (`tests/Feature/Acquisitions/ValidationAcquisitionConcurrenceTest.php`) : SQLite toujours, MySQL ou MariaDB avec les variables `CONCURRENCE_DB_*`.
  - Les acquisitions de démonstration autres que 038 sont des brouillons : valider les 8 de la maquette créerait une trentaine de fiches et fausserait les 300 biens et le prochain numéro de la démonstration.
  - Fichiers partagés touchés a minima : `Asset` (`acquisition_line_id` ajouté aux champs remplissables) et `Document` (relation `acquisitions`, visibilité), `DemoSeeder` (une ligne). `CreateAsset`, `AssetResource` et `AssetInfolist` ne sont pas modifiés.
- **Écarts par rapport aux docs** : la colonne « Étiquettes » (à imprimer / imprimées) et le compteur de la maquette ne sont pas affichés, faute de suivi des impressions (Q5.4). La liste affiche un résumé en sous-titre plutôt que les quatre cartes. Pas d'export de la liste. La clé `assets.acquisition_line_id` n'a pas de contrainte étrangère (colonne du lot 3).
- **Questions ajoutées** : Q5.1 à Q5.4 dans `docs/06`.
- **À tester à la main** :
  - Créer l'acquisition de la maquette (3 portables, 3 onduleurs, 6 câbles non suivis) avec une facture PDF, vérifier le total 2 280 000 et le récapitulatif « 6 fiches ».
  - Valider avec le compte administrateur : 6 fiches PAT-001249 à PAT-001254, redirection vers /admin/etiquettes?biens=… (page livrée par le lot 4, à vérifier après fusion), case « Rattacher aussi aux biens créés ».
  - Se connecter en gestionnaire : le bouton de validation n'apparaît pas. Se connecter avec un périmètre restreint : les autres acquisitions sont introuvables.
  - Après validation, vérifier que la modification et la suppression sont refusées.
## Lot 6 · Mouvements et transferts · 28/10/2026

- **Fait** :
  - Table `movements` (colonnes `from_*` et `to_*` pour site, département, service, local, responsable ; `batch_uuid` ; `created_by`). Un mouvement ne se modifie ni ne se supprime (modèle et policy). Type déduit des éléments modifiés (`MovementType` : initial, département, service, local, site, responsable, multiple) par `App\Services\MovementRecorder`.
  - RG-22 : `RecordInitialAssignment`, appelée dans la transaction de `CreateAsset` (une ligne ajoutée), donc aussi pour les biens que créera le lot 5. `MovementsDemoSeeder` (idempotent, branché dans `DemoSeeder`) donne leur mouvement initial aux 300 biens de démonstration et ajoute 8 transferts d'exemple.
  - RG-21, RG-23 : `App\Actions\Movements\TransferAssets`, tout ou rien : un mouvement par bien, même `batch_uuid` quand il y a plusieurs biens, mise à jour de l'affectation courante et de `assigned_since`, version RG-86 incrémentée (contrôle de la version d'ouverture sous verrou pour le transfert unitaire), refus d'un bien sorti, d'un bien hors périmètre, d'une affectation identique, d'une date future, d'un service hors du département, d'un local hors du site, d'un référentiel désactivé. Justificatif facultatif rattaché aux biens (type « Document de transfert »). Journal RG-70 : une entrée « Transfert d'un bien » par bien, avec avant et après.
  - Écrans : action « Transférer » unitaire (en-tête de la fiche) et groupée (liste des biens) avec affectation actuelle en lecture, champs modifiés en évidence, compteur de champs modifiés, motif libre avec suggestions (Q22), case de réimpression d'étiquette (RG-24, renvoi vers `/admin/etiquettes?biens=…`) ; ressource « Mouvements » en lecture seule (Opérations, première entrée) avec filtres bien, type, site, département, utilisateur et période ; onglet « Affectation et mouvements » sur la fiche.
- **Décisions** :
  - Une modification d'affectation faite depuis le formulaire du bien (lot 3) enregistre aussi un mouvement dont le type est déduit des champs modifiés et dont le motif est « Modification de la fiche » ; la date d'affectation passe à aujourd'hui dans la même mise à jour (Q61).
  - Q60, option prudente : un site ou un département de destination hors du périmètre de l'utilisateur est refusé (message en français), à confirmer par l'Église. Le journal écrit les noms réels sans passer par les scopes de périmètre, et l'historique affiche « (hors de votre périmètre) » pour un élément que l'utilisateur ne voit pas.
  - Transfert groupé : les versions des fiches sélectionnées sont mémorisées à l'ouverture de la fenêtre et contrôlées à l'enregistrement (RG-86), comme pour le transfert unitaire.
  - RG-24 : la case de réimpression et la redirection n'existent que si la page `admin/etiquettes` est présente dans l'application.
  - Les identifiants passés à la page des étiquettes sont des identifiants de biens séparés par des virgules (Q62), à ajuster si le lot 4 attend les numéros patrimoniaux.
- **Questions ajoutées** : Q60, Q61, Q62, Q63, Q64 (numérotées à part pour ne pas entrer en collision avec les autres lots).
- **À tester à la main** : transférer un bien depuis sa fiche (champs mis en évidence, case d'étiquette, redirection quand la page des étiquettes existe) ; transférer 3 biens depuis la liste ; ouvrir l'onglet « Affectation et mouvements » ; filtrer la liste des mouvements ; vérifier qu'un responsable de département ne voit pas le bouton « Transférer ».
## Lot 7 · Immobilisations et amortissements · 28/10/2026

- **Fait** :
  - Calcul (RG-32 à RG-35, RG-37, RG-39) : `App\Services\DepreciationCalculator`, pur (ni base ni horloge), entrées et sorties en objets valeur (`App\Services\Depreciation\*`). Tout en entiers : prorata 30/360 (le 31 compte comme le 30, première année selon RG-32), arrondi au franc demi-unité supérieure par division entière, dernière dotation qui absorbe l'écart (RG-33), cumul plafonné à la base (RG-34), arrêt à la date de sortie incluse (RG-39), dégressif avec bascule en linéaire (RG-35), lignes figées reprises telles quelles et changement prospectif : la VNC au début du premier exercice ouvert est répartie sur les jours restants de la nouvelle durée (RG-37). Un plan impossible sans toucher un exercice figé est refusé (`ImpossibleDepreciationPlan`).
  - **Les 8 cas de `tests/Fixtures/amortissements.json` passent tels quels**, en test paramétré lu depuis le fichier (`tests/Unit/DepreciationCalculatorTest.php`), plus leurs invariants RG-33 et RG-34 et 7 tests de bord (le 31, arrondi, taux, lignes figées, plans impossibles, dégressif sous 3 ans, mois du dégressif). Aucune incohérence trouvée dans le fichier.
  - Tables `fiscal_years`, `depreciation_schedules`, `depreciation_lines`, `depreciation_parameter_changes` (migrations `2026_10_28_07xxxx`). Garde-fous dans les modèles : une ligne figée ne se modifie ni ne se supprime (RG-36), une version validée ne change plus (RG-38), un exercice clôturé ne change plus et rien ne se supprime (RG-43), l'historique des paramètres est immuable.
  - Plans (`App\Actions\Depreciation\SyncDepreciationSchedule`) : un plan courant par bien amortissable, généré à la création (`CreateAsset`) et recalculé à la modification de la fiche (`UpdateAsset`), sous verrou de ligne du bien. Lignes figées : exercice clôturé ou antérieur au premier exercice non clôturé. Plan non validé recalculé en place (lignes non figées seulement) ; plan validé : nouvelle version, l'ancienne est conservée (RG-38). Idempotent : sans changement, rien n'est écrit.
  - Changement de paramètre (E31, `ChangeDepreciationParameters`) : durée, valeur résiduelle, valeur d'origine, mise en service, méthode (si le dégressif est activé) ; motif obligatoire, contrôle RG-86, une ligne d'historique par paramètre (ancienne et nouvelle valeur, exercice d'effet, utilisateur, date, motif), journal RG-70. Validation du plan par le comptable (`ValidateDepreciationSchedule`), tracée.
  - Exercices (E33) : création « préparé » (année civile), ouverture, clôture (`CloseFiscalYear`) à partir du dernier jour par l'administrateur ou le comptable (RG-41, contrôle dans l'action et dans la policy), dans l'ordre chronologique, avec points d'attention à confirmer (RG-42 : dotations calculées, biens amortissables sans plan, plans à valider ; sorties et inventaires affichés « non vérifiables » jusqu'aux lots 10 et 9, mécanisme `AttentionPointProvider` à compléter par ces lots). La clôture recalcule une dernière fois les plans, fige les lignes de l'exercice, valide les plans courants concernés, ouvre l'exercice suivant (ou le crée), est tracée et irréversible (RG-43).
  - Paramètres (`Settings`) : prorata (`days_360`, seul mode défini), dégressif désactivé par défaut, coefficients 1,5 / 2 / 2,5 ; le seuil existant (100 000 FCFA) est modifiable depuis E32. `ApplyAssetRules` prend la méthode via `DepreciationRules` (toujours linéaire tant que le dégressif est désactivé).
  - Droits (RG-83) : `DepreciationAuthority` exige le rôle Administrateur ou Comptable en plus du droit de la matrice pour modifier les règles et paramètres, valider un plan, créer, ouvrir et clôturer un exercice. Policies `DepreciationSchedulePolicy` et `FiscalYearPolicy`. Le responsable de département n'a pas accès aux écrans d'amortissement (matrice) ; tableau et plans limités aux biens du périmètre (404 hors périmètre).
  - Écrans, groupe « Immobilisations » : tableau des immobilisations E30 (sélecteur d'exercice dont le prévisionnel suivant, compteurs, onglets, regroupement par catégorie, site ou département, filtres, récapitulatif par catégorie), règles d'amortissement E32 (paramètres généraux et table éditable par catégorie, en lecture seule pour les autres rôles), exercices E33. Plan d'amortissement E31 en page de la ressource Biens (`/admin/biens/{id}/amortissement`) : paramètres, tableau avec statut, graphique VNC en HTML (aucune bibliothèque), historique, versions, actions « Modifier les paramètres », « Valider le plan », exports PDF (gabarit E45 avec emplacements de signature) et Excel. Fiche E06 : compteurs (valeur d'origine, cumul et VNC à la fin de l'exercice en cours, durée restante) et onglet Amortissement.
  - Commande `php artisan patrimoine:recalculer-plans` (exercices ouverts uniquement, rapport en français, anomalies signalées) ; relancée, elle affiche « Aucun changement ».
  - Démonstration (`DepreciationDemoSeeder`, appelé par `DemoSeeder`, idempotent) : 2023 à 2025 clôturés par M. Loubaki, 2026 ouvert, plans de tous les biens amortissables mis en service, validés à la clôture de 2025. PAT-000125 = A2, PAT-000034 = A5 sans la sortie (2022 à 2025 identiques, puis 600 000 par an jusqu'en 2030), PAT-001248 = A3.
  - 77 tests du lot (29 unitaires dont les 8 cas, 48 fonctionnels), passés sur SQLite et sur MariaDB 11.5 en mode strict.
- **Corrections après la revue indépendante** (A2 à A6 et les cas de bord revérifiés à la main sans écart ; scénarios P1 à P4 et R1 à R7 repris en tests de non-régression, `tests/Feature/Amortissements/RevueTest.php` et `tests/Unit/DepreciationCalculatorRevueTest.php`) :
  - Bloquant RG-36 : un exercice antérieur créé après coup rendait recalculables des lignes d'exercices clôturés. Désormais tout exercice jusqu'au dernier exercice clôturé inclus est figé, le premier exercice recalculable est celui qui suit le dernier clôturé, et `CreateFiscalYear` refuse un exercice antérieur au dernier exercice existant.
  - Bloquant `decimal(7,4)` : une ligne recalculée (RG-37) ou en bascule dégressive pouvait porter un « taux » de 1 894 % ou 1 200 %, refusé par MariaDB strict. Ces dotations ne découlent pas d'un taux annuel mais de la VNC restante : la ligne enregistre un taux null, affiché « — », et le taux du plan reste dans les paramètres (Q9.11). Élargir la colonne aurait conservé un chiffre sans sens comptable. Le coefficient dégressif est plafonné à 10, ce qui garde le taux du plan dans la colonne.
  - Mise en service repoussée au-delà d'un exercice figé portant une dotation, ou avancée dans un exercice clôturé sans ligne : refusée avec un message (Q9.10).
  - Messages : les erreurs du calcul portent un message métier et le champ en cause (`InvalidDepreciationInput`, `ImpossibleDepreciationPlan::$field`) ; montants hors plafond refusés en français.
  - Dégressif : une fiche garde sa méthode même si le dégressif est désactivé ensuite (le linéaire ne s'impose qu'aux nouveaux biens) ; le coefficient est écrit sur la fiche au premier calcul et un changement des paramètres généraux ne touche que les nouveaux biens ; il est recalculé quand la durée ou la méthode change par « Modifier les paramètres ».
  - Clôture : les plans qui ne pourront pas être recalculés sont affichés comme point d'attention à confirmer avant la clôture (vérification sans écriture, `PlanAnomaliesCheck`) ; le journal de la clôture cite les biens dont le plan est validé.
  - RG-83 vérifié aussi dans `ChangeDepreciationParameters` et `ValidateDepreciationSchedule`.
  - Sortie comparée à la fin du plan sur l'échelle 30/360 : un bien mis en service le 31/01/2024 sur 3 ans et sorti le 29/01/2027 est entièrement amorti.
  - `docs/03` mis à jour pour les écarts du modèle ; questions Q9.8 à Q9.12 ajoutées.
- **Décisions** (et pourquoi) :
  - Interprétation de RG-37 : le recalcul part de l'exercice d'effet (premier exercice non clôturé au moment du changement, gardé sur le plan en `recalculated_from_year`) ; les dotations suivantes = VNC amortissable à cette date × jours de l'exercice / jours restants (30/360) jusqu'à la nouvelle fin, la dernière absorbant l'écart. Sans changement de paramètre, la formule RG-32 d'origine continue de s'appliquer (pas de dérive d'arrondi). C'est exactement le cas A6.
  - Modification depuis la fiche (E07) : tant que le plan n'est ni validé ni figé, il est recalculé sans motif (correction de saisie, le journal du bien garde avant et après) ; dès qu'il est engagé, la fiche refuse et renvoie vers « Modifier les paramètres » avec motif. Prudent et réversible ; voir Q9.2.
  - Exercice d'une ligne : colonne `depreciation_lines.fiscal_year` (l'année), reliée à `fiscal_years.year`, au lieu de `fiscal_year_id` : un plan couvre des exercices futurs qui n'existent pas encore (A3 jusqu'en 2031) et des années antérieures au premier exercice saisi (A5 en 2022). Index unique (plan, exercice) conservé.
  - Statuts affichés des lignes : Clôturé, En cours, Préparé, Prévisionnel (exercice pas encore créé), Antérieur (année avant le premier exercice recalculable, figée).
  - Un bien enregistré après coup avec une mise en service ancienne reçoit les lignes théoriques des exercices passés, aussitôt figées (Q9.1).
  - La clôture valide les plans de l'exercice au nom de la personne qui clôture et impose l'ordre chronologique (Q9.6).
  - E32 n'affiche que les options définies : le prorata 360 jours, le départ à la mise en service, l'arrondi au franc et la valeur résiduelle par bien sont affichés en lecture, faute de règle pour les autres choix de la maquette (Q1). Une règle de catégorie modifiée ne recalcule pas les biens existants (maquette).
  - Fichiers partagés modifiés a minima : `CreateAsset` et `UpdateAsset` (une ligne d'appel chacun, plus l'import que Pint impose), `ApplyAssetRules` (la ligne de la méthode), `AssetResource` (la page du plan), `AssetInfolist` (compteurs et onglet, deux lignes), `ActivityResource` (domaine « Immobilisations et amortissements » du journal), `DemoSeeder` (une ligne), `Settings` (clés du lot).
- **Écarts par rapport aux docs** : colonnes ajoutées (`fiscal_years.year`, `closing_notes` ; `depreciation_schedules.original_value`, `declining_coefficient`, `recalculated_from_year` ; `depreciation_lines.fiscal_year` à la place de `fiscal_year_id` ; `depreciation_parameter_changes.batch_uuid`, `effective_year`). Pas d'export Excel ou PDF du tableau E30 (rapports du lot 11). Pas de relation `Asset::depreciationSchedules()` pour ne pas toucher au modèle partagé.
- **Questions ajoutées** : Q9.1 à Q9.12 dans `docs/06` (lignes des exercices passés, correction de saisie, changement impossible, détails du dégressif, bien sans date de mise en service, validation par la clôture, exercice non civil ; puis février et le 31, bien saisi après clôture, mise en service corrigée après clôture, taux affiché après recalcul, sortie saisie après clôture).
- **À faire valider par le comptable** : les 8 cas (Q9) et les choix Q1, Q4 à Q7, Q9.1 à Q9.12 ; les comptes par catégorie (Q3, Q23).
- **À tester à la main** :
  - Compte comptable (`m.loubaki`) : ouvrir PAT-000125, vérifier le plan A2, « Modifier les paramètres » (durée 4, motif) et retrouver A6 (98 695 en 2026 et 2027, 21 110 en 2028), l'historique et la version 2.
  - Exporter ce plan en PDF et en Excel et vérifier l'impression.
  - Tableau des immobilisations : changer d'exercice (2025 clôturé, 2027 prévisionnel), d'onglet, de regroupement.
  - Règles d'amortissement : activer le dégressif, modifier une durée de catégorie ; vérifier que le gestionnaire voit tout en lecture seule.
  - Exercices : la clôture de 2026 est grisée avant le 31/12/2026 ; essayer sur une copie de la base en avançant la date du poste.
  - Compte gestionnaire : modifier le prix de PAT-001248 (plan non figé) puis de PAT-000125 (refus avec renvoi vers le plan).
  - Compte responsable de département : aucun écran d'amortissement, pas d'onglet Amortissement sur la fiche.
  - Lancer `npm run build` avant les essais (nouvelles classes Tailwind des écrans).

## Lot 3 · Biens · 27/10/2026

- **Fait** :
  - Numéro patrimonial (RG-01 à RG-04) : seul `App\Services\AssetNumberGenerator` l'attribue, dans la transaction de création (`App\Actions\Assets\CreateAsset`). Trois formats selon le paramètre `number_format` : `PAT-000001`, `PAT-2026-000001` (compteur annuel), `INF-000001` (par préfixe de catégorie). Le compteur est incrémenté sous verrou exclusif (`UPDATE … SET last_value = last_value + 1`, la ligne étant créée au premier besoin), et la transaction de création est rejouée 5 fois en cas d'interblocage. Le numéro est bloqué en modification par le modèle, protégé par un index unique, et jamais réattribué (pas de suppression).
  - Test de concurrence en vrais processus PHP parallèles (`tests/Feature/Biens/NumeroPatrimonialConcurrenceTest.php`, script `tests/Support/concurrent-assets.php`) : 6 processus × 15 numéros, puis 6 × 10 créations de biens, sans doublon ni échec. Il tourne toujours sur SQLite et, si une base jetable est indiquée par les variables `CONCURRENCE_DB_*`, sur MySQL ou MariaDB. **Passé sur MariaDB 11.5** (serveur temporaire de WAMP).
  - Paramètres : table `settings` et service `App\Services\Settings` (format et préfixe du numéro, seuil d'immobilisation 100 000 FCFA, nom et sigle de l'Église, taille maximale des documents), avec les valeurs par défaut de `docs/06` tant que l'écran E41 n'existe pas.
  - Biens : table `assets` conforme à `docs/03` (+ `depreciation_forced_off` pour RG-30, + `lock_version` pour RG-86), table `holders` (Q11 : responsable lié ou non à un compte). Règles dans `App\Actions\Assets\ApplyAssetRules` : champs obligatoires (RG-10), prix exigé si amortissable, statuts réservés aux opérations (RG-12), numéro de série unique parmi les biens actifs (RG-13, `App\Services\SerialNumberGuard`), seuil et forçage (RG-30, `App\Services\DepreciabilityResolver`), paramètres d'amortissement repris de la catégorie (RG-31). Aucune suppression (policy et modèle), fiche d'un bien sorti en lecture seule (E43). Création et modification tracées avec avant et après, numéro compris.
  - Périmètre : scope global sur `Asset` ; les documents, la photo, les exports, la recherche par code, la recherche globale et le journal des opérations le respectent.
  - Écrans : liste E05 (tous les filtres, biens sortis masqués par défaut, recherche par numéro, désignation, marque, modèle et n° de série, exports Excel et PDF), fiche E06 (en-tête, onglets Informations, Documents et Journal ; les autres onglets viendront avec leurs lots), formulaire E07 en sections avec aperçu du prochain numéro et lien vers le bien qui porte déjà un numéro de série, recherche par code E08 (douchette : saisie + Entrée ouvre la fiche, code inconnu signalé avec proposition de création, historique des 10 derniers codes du poste), documents E28.
  - RG-86 : version entière `lock_version` incrémentée à chaque enregistrement ; le formulaire retient la version d'ouverture (propriété verrouillée côté navigateur) et `UpdateAsset` la compare sous verrou de ligne. Détecte aussi deux enregistrements dans la même seconde.
  - Documents (RG-14, RG-65, RG-66) : tables `documents` et `documentables` (polymorphe), disque privé, PDF, images, Word et Excel, 20 Mo par défaut ; fichiers servis uniquement par une route contrôlée (droit et périmètre), en-têtes `nosniff` et CSP `sandbox` ; actions `AttachDocuments`, `DetachDocument`, `DeleteDocument` (un document encore rattaché ne se supprime pas) ; ajout et retrait tracés. La photo du bien passe aussi par une route contrôlée.
  - Exports : `App\Services\AssetListReport`, Excel (`App\Exports\AssetsExport`, montants en nombres, textes forcés en texte pour éviter les formules) et PDF A4 paysage (DomPDF, police DejaVu Sans, chargement distant désactivé), avec filtres actifs, nombre de biens et total.
  - Démonstration : 300 biens (`AssetsDemoSeeder`) dont PAT-000125 (cas A2), PAT-000034 (A5) et PAT-001248 (A3) avec les valeurs des cas d'amortissement ; le compteur est aligné, le prochain bien créé reçoit PAT-001249.
  - Dates affichées en jj/mm/aaaa partout (réglage global des tableaux, fiches et sélecteurs de date).
- **Organisation** : socle (numéro, modèle, règles, écrans, recherche par code) fait ici ; documents d'une part, exports et démonstration d'autre part, confiés à deux agents Sonnet en parallèle ; revue complète par un agent Opus, qui a relevé 3 points bloquants (interblocages du compteur sous MariaDB, test de concurrence qui ne couvrait que SQLite, journal qui laissait voir des biens hors périmètre) et 4 points à corriger (RG-86 peu fiable, RG-13 seulement dans le formulaire, plafond d'envoi de 12 Mo de Livewire, photo servie par URL signée). Tous corrigés et testés.
- **Décisions** (et pourquoi) :
  - SQLite (base de développement et de test) : transactions `IMMEDIATE` et `busy_timeout` de 10 s, pour sérialiser les écritures comme le ferait le verrou de ligne de MySQL.
  - La recherche par code ouvre directement la fiche (RG-05), plutôt que la carte de résultat de la maquette E08.
  - Un bien hors périmètre est « introuvable » (404), y compris dans la recherche par code, pour ne pas révéler son existence. Seul le contrôle du numéro de série cite le bien existant même hors périmètre : un doublon reste un doublon.
  - Le formulaire d'un bien affiche encore l'aperçu de la photo par une URL signée temporaire de Filament (réservée aux utilisateurs qui peuvent modifier la fiche) ; la fiche et toute la consultation passent par la route contrôlée.
  - Deux créations simultanées avec le même numéro de série ne sont pas bloquées par la base (pas d'index unique possible « parmi les actifs » en MySQL sans colonne calculée) : risque jugé très faible, à reprendre si besoin.
- **Écarts par rapport aux docs** : colonnes ajoutées à `assets` (`depreciation_forced_off`, `lock_version`). Pas d'actions groupées sur la liste (étiquettes, transfert, inventaire) : elles arrivent aux lots 4, 6 et 9.
- **Questions ajoutées** : aucune. Q10 à Q12 restent avec leurs valeurs par défaut (format `PAT-000001`, responsable personne simple, une fiche par unité).
- **À tester à la main** :
  - Créer un bien amortissable puis un non amortissable ; vérifier le numéro attribué (PAT-001249 sur la démo).
  - Essayer un numéro de série déjà utilisé : le lien « Voir le bien » doit ouvrir la fiche existante.
  - Ouvrir la même fiche dans deux onglets, enregistrer dans le premier puis dans le second : message d'avertissement.
  - Recherche par code avec une douchette USB (au lot 4 avec les étiquettes réelles).
  - Ajouter un PDF et une photo à une fiche, les ouvrir ; vérifier qu'un responsable d'un autre département n'y a pas accès.
  - Exporter la liste filtrée en Excel et en PDF.
  - Sur le serveur du client : régler `upload_max_filesize` et `post_max_size` (voir `docs/07`).

## Lot 2 · Utilisateurs, droits et journal · 27/10/2026

- **Fait** :
  - Droits (RG-80) : 96 permissions `module.droit` (16 modules de la maquette E39 × consulter, créer, modifier, supprimer/sortir, valider, exporter), enums `PermissionModule` et `PermissionAbility`. Cinq rôles livrés avec la matrice par défaut de la maquette (`App\Support\DefaultRoles`), chargés par `RolesSeeder` (appelé par `ReferenceSeeder`) sans réécrire un rôle déjà modifié, sauf l'Administrateur qui garde toujours tous les droits.
  - Écran Rôles et permissions (E39) : une ligne de cases par module, enregistrement par `SyncRolePermissions` (droits ajoutés et retirés tracés). Les rôles livrés ne se renomment ni ne se suppriment ; un rôle créé se supprime s'il n'est attribué à personne.
  - Policies branchées sur la matrice : structure (`ReferentialPolicy`), utilisateurs, rôles, journal. Le menu se réduit tout seul selon les droits.
  - Périmètre (RG-81) : pivots `site_user` et `department_user` (vides = tous), scope global `PerimeterScope` via le trait `HasPerimeter`, appliqué aux sites, départements, services et locaux. Un local sans département (salle de culte commune) reste visible d'un responsable de département. Hors connexion (console, seeders), aucun filtre. Un élément hors périmètre donne une erreur 404, même par l'URL directe.
  - Sécurité (RG-85) : mot de passe de 8 caractères dont un chiffre (`Password::defaults`), blocage 15 minutes après 5 échecs (`RecordFailedLogin`, message en français indiquant l'heure de fin), compteur remis à zéro à la connexion, déconnexion après 30 minutes d'inactivité (`SESSION_LIFETIME=30`), mot de passe provisoire généré à la création et à la réinitialisation, puis changement obligatoire (middleware `EnsurePasswordIsChanged` qui renvoie vers Mon profil). Message « mot de passe oublié : contactez l'administrateur » sur la connexion.
  - Utilisateurs (E38) : création avec rôle, sites et départements accessibles, mot de passe provisoire affiché une seule fois ; actions Réinitialiser, Désactiver, Réactiver ; aucune suppression (RG-84) ; on ne peut pas désactiver son propre compte.
  - Journal (RG-70 à 72, E40) : `spatie/laravel-activitylog`, modèle `App\Models\Activity` qui ajoute l'IP du poste et refuse toute modification ou suppression ; commande de purge non planifiée. Sont tracés : connexion, blocage, création et modification d'un utilisateur (avant et après, sans le mot de passe), changement de rôle et de périmètre, réinitialisation du mot de passe, création et matrice des rôles. Écran en lecture seule avec filtres utilisateur, domaine et période, et le détail avant / après.
  - Mon profil (E04) : informations personnelles, changement de mot de passe (mot de passe actuel exigé), rôle, périmètre et dernière connexion en lecture, dernières opérations.
  - Démo : 15 comptes de la maquette sur les 5 rôles (mot de passe commun dans `DemoSeeder`), avec périmètres, responsables des sites et des départements, et un compte désactivé.
  - 44 nouveaux tests (135 au total) ; Pint et PHPStan au vert.
- **Décisions** (et pourquoi) :
  - **Pas de Filament Shield**, pourtant prévu par `CLAUDE.md` (installé puis retiré). Shield crée une permission par méthode de policy et par ressource ; la règle RG-80 et la maquette E39 demandent une matrice par module métier (un module « Structure » couvre 6 écrans, « Biens » couvrira la fiche, la recherche, les documents…). RG-80 primant, la matrice est faite avec `spatie/laravel-permission` seul et un écran dédié. À valider par Webtinix.
  - Pas de case « Rester connecté » (présente sur la maquette E01) : elle contournerait la déconnexion après 30 minutes d'inactivité de RG-85.
  - Pas de « super administrateur » qui contourne les policies : l'Administrateur a toutes les permissions, mais les règles comme « pas de suppression d'un élément utilisé » s'appliquent à lui aussi.
  - Le blocage se compte par compte (5 échecs consécutifs sur le même identifiant) ; la limite de Filament par poste (5 essais par minute) reste en plus.
  - Le gestionnaire voit la Structure en lecture seule et le journal, conformément à la matrice de la maquette.
  - Traductions : Filament 5.11 n'a pas traduit 173 textes en français (clés brutes visibles, par exemple sous les tableaux). Surcharges dans `lang/vendor/filament-*/fr/`, et un test vérifie qu'aucune clé ne manque.
  - Composer se bloque sur ce poste à l'étape finale de `require` (aucune activité processeur) : l'arrêter puis lancer `composer dump-autoload` suffit.
- **Écarts par rapport aux docs** : pas de Shield (voir ci-dessus) ; pas de case « Rester connecté ». Le critère « un responsable ne voit aucun bien hors de son périmètre » n'est pas encore cochable : il n'y a pas de biens avant le lot 3.
- **Questions ajoutées** : aucune.
- **À tester à la main** :
  - Se connecter avec plusieurs comptes de démo (`a.okemba` gestionnaire, `e.mbemba` responsable Louange, `m.loubaki` comptable, `pasteur` consultation) et comparer les menus.
  - Échouer 5 fois avec un compte, vérifier le message de blocage, puis le déblocage par « Réinitialiser le mot de passe ».
  - Créer un compte, noter le mot de passe provisoire, s'y connecter : l'application doit imposer le changement.
  - Modifier la matrice du rôle Consultation, puis retrouver l'opération dans le journal avec les droits ajoutés et retirés.
  - Laisser l'application inactive 30 minutes : la session doit expirer.

## Lot 1 · Structure et référentiels · 27/10/2026

- **Fait** :
  - Tables (une migration chacune) : `sites`, `departments`, `department_site` (un département sur plusieurs sites), `services`, `rooms`, `asset_categories`, `asset_conditions`, `suppliers`, `document_types`. Toutes ont `is_active`, `created_by`, `updated_by`, des clés étrangères et des index. Les codes sont uniques (code de service unique dans son département, préfixe de catégorie unique).
  - Enums avec libellé français et couleur : `AssetStatus` (7 statuts, avec ceux qu'on peut choisir à la main selon RG-12), `Depreciability`, `DepreciationMethod`, `RoomType`, `DisposalReason`, `FundingSource`.
  - Règle « pas de suppression d'un élément utilisé » : trait `IsReferential` (`usageRelations()`, `isInUse()`, scope `active()`), avec un verrou dans le modèle (exception `ReferentialInUseException`) et une policy par modèle (`ReferentialPolicy`) qui n'autorise la suppression que d'un élément inutilisé. Les listes de relations s'enrichiront aux lots suivants (biens, acquisitions, documents).
  - Désactivation et réactivation par l'action `App\Actions\Referentials\SetReferentialActive`, accessible depuis chaque liste. Les listes de choix ne proposent que les éléments actifs ; un site inactif est refusé à l'enregistrement.
  - Filament, groupe Structure : Sites, Départements et services (liste avec badges de services et sites en sous-titre, onglet Services sur la fiche), Locaux (groupés par site, filtres site, type et département), puis le cluster « Catégories et référentiels » avec sous-navigation : Catégories de biens, États (réordonnables par glisser-déposer), Fournisseurs, Types de documents, et Statuts, motifs et financements (lecture seule).
  - Catégories : préfixe de 3 lettres, amortissable (oui, selon seuil, non), méthode, durée et taux calculé à l'écran (RG-31), comptes.
  - Seeders : `ReferenceSeeder` charge 6 états, 10 catégories et 10 types de documents (`firstOrCreate`, sans écraser les modifications du client). `StructureDemoSeeder`, appelé par `DemoSeeder`, charge 5 sites dont 1 inactif, 9 départements, 22 services, 16 locaux et 7 fournisseurs repris de la maquette.
  - 57 nouveaux tests (91 au total, 952 assertions) ; Pint et PHPStan au vert. Écrans vérifiés dans le navigateur.
- **Décisions** (et pourquoi) :
  - Les droits fins par rôle arrivent au lot 2 : en attendant, `ReferentialPolicy` autorise tout compte actif à gérer les référentiels. Le lot 2 remplacera ces méthodes par les permissions Shield.
  - Méthode dégressive non proposée dans le formulaire de catégorie (RG-35, désactivée par défaut) ; l'Enum la prévoit pour le lot 7.
  - Un site lié à des départements ou à des locaux est considéré comme utilisé (prudent) ; un département avec des services ou des locaux aussi.
  - URL en français (`/admin/sites`, `/admin/departements`, `/admin/locaux`, `/admin/referentiels/...`) ; titres sans majuscules à chaque mot.
  - Comptes SYSCOHADA : seuls ceux du matériel informatique viennent de la maquette, les autres restent vides plutôt qu'inventés (Q23).
  - Responsables des sites et départements laissés vides dans la démo : les comptes utilisateurs de démonstration arrivent au lot 2.
- **Écarts par rapport aux docs** : la maquette (E37) liste des « motifs de transfert » que le modèle de données ne prévoit pas (texte libre) : non créés, question Q22. Le compteur « Biens » des écrans Sites et Départements arrivera avec le lot 3.
- **Questions ajoutées** : Q22 (motifs de transfert), Q23 (comptes SYSCOHADA des catégories).
- **À tester à la main** :
  - Créer, modifier, désactiver puis réactiver un site, un département, un service, un local, une catégorie, un état, un fournisseur et un type de document.
  - Vérifier que « Supprimer » n'apparaît pas sur un site qui a des locaux, ni sur un département qui a des services.
  - Réordonner les états par glisser-déposer.
  - Contrôler les données de démonstration (noms, codes, rattachements) par rapport à la maquette.
  - Le test avec le rôle Responsable de département n'est pas possible avant le lot 2 (rôles).

## Lot 0 · Socle · 27/10/2026

- **Fait** :
  - Projet Laravel 13 créé dans un dossier temporaire puis déplacé à la racine sans toucher à `CLAUDE.md`, `README.md`, `docs/`, `.claude/` ni `tests/Fixtures/`. Les fichiers `CLAUDE.md`, `AGENTS.md` et `README.md` du squelette ont été écartés. `.gitignore` du squelette fusionné (le dépôt n'en avait pas).
  - Panneau Filament unique `/admin` (`AdminPanelProvider`), `/` redirige vers `/admin`. Groupes de navigation de `docs/04` déclarés dans l'ordre.
  - Langue `fr` (avec repli `fr`), Faker `fr_FR`, fuseau `Africa/Brazzaville` (`APP_TIMEZONE`). Fichiers `lang/fr` (validation, auth, pagination, passwords) écrits à la main ; Filament fournit ses propres traductions françaises.
  - Connexion par identifiant : page `App\Filament\Pages\Auth\Login` (champ `username`, message d'échec « Identifiant ou mot de passe incorrect. »). Un compte désactivé est refusé (`canAccessPanel`, RG-84).
  - Table `users` au modèle de `docs/03` : `first_name`, `last_name`, `username` unique, `email` facultatif, `phone`, `job_title`, `must_change_password`, `is_active`, `last_login_at`, `last_login_ip`, `failed_attempts`, `locked_until`. Seules les colonnes existent : blocage, inactivité et mot de passe provisoire (RG-85) sont au lot 2.
  - Thème : palettes fixées dans `App\Filament\Theme`, avec la primaire `#0B5D6B` en nuance 600, l'accent `#F2B544` et les statuts (fond 50, texte 600) de `docs/04`. Le CSS Vite `resources/css/filament/admin/theme.css` ajoute les badges à angles droits en capitales de 11 px, les cartes à 12 px sans ombre, le menu latéral `#262626` avec repère accent, les titres de page en 800, les sections en capitales espacées et les zones cliquables de 44 px. Mode sombre désactivé.
  - Polices Archivo (variable) et Martian Mono (400, 500, 700) en `woff2` dans `public/fonts`, sous-ensembles latin et latin étendu, licences OFL jointes ; déclarées dans `public/fonts/fonts.css` et branchées par `LocalFontProvider` (texte et `monoFont`). La classe CSS `pat-mono` est prévue pour les numéros et montants.
  - Seeders : `DatabaseSeeder` appelle `ReferenceSeeder` (vide pour l'instant, chargé aussi en production) puis `DemoSeeder` (hors production) qui crée le compte administrateur de démonstration ; identifiant et mot de passe dans `DemoSeeder`.
  - Outillage : Pest (avec les plugins Laravel et Livewire), Pint (`pint.json`, preset laravel), Larastan (`phpstan.neon`, niveau 6). 34 tests (275 assertions), Pint et PHPStan au vert.
- **Décisions** (et pourquoi) :
  - Le fournisseur d'avatars par défaut de Filament charge les images depuis ui-avatars.com : remplacé par `InitialsAvatarProvider`, qui génère un SVG local en data URI.
  - Le fournisseur de polices par défaut d'un panneau personnalisé est Bunny Fonts (CDN) : `LocalFontProvider` est imposé explicitement. La configuration Vite du squelette appelait aussi Bunny Fonts : supprimée, avec `welcome.blade.php`, `app.css` et `app.js` (pas de site public).
  - Palettes de couleurs écrites à la main : la palette générée par `Color::hex('#0B5D6B')` est trop vive et l'algorithme de contraste de Filament retenait la nuance 400 (bouton cyan clair). Un test vérifie que les boutons principaux utilisent la nuance 600.
  - `public/css/filament`, `public/js/filament` et `public/fonts/filament` sont ignorés par Git : ils sont republiés par `php artisan filament:upgrade` (ajouté au `post-autoload-dump`).
  - `optionalDependencies` `@laravel/multiplex` et `concurrently` retirés du `package.json` : inutiles ici, et `npm install` échouait. npm 10.8 (fourni avec Node 20) plante sur ce projet (« Cannot read properties of null (reading 'edgesOut') ») ; le build passe avec Node 24 / npm 11. **Utiliser Node 22 ou plus.**
  - `.env.example` cible MySQL (base `patrimoine`) ; les tests tournent sur SQLite en mémoire (`phpunit.xml`).
  - Aucun paquet hors liste installé (Pest, ses plugins, Larastan et Pint font partie de la stack prévue).
- **Écarts par rapport aux docs** : `CLAUDE.md` indique Node 20+ ; dans les faits, npm 10 de Node 20 échoue (voir ci-dessus). Les vues d'erreur et la page de profil restent celles de Filament (lots 2 et 12).
- **Questions ajoutées** : aucune.
- **À tester à la main** :
  - Couper le Wi-Fi, lancer l'application et vérifier que la connexion, le tableau de bord et les polices fonctionnent (critère 1 du lot 0, non coché).
  - Connexion avec le compte de démonstration ; échec avec un mauvais mot de passe (message en français).
  - Aspect : bouton « Connexion » en bleu pétrole `#0B5D6B`, menu latéral foncé avec repère jaune, police Archivo.

## Modèle d'entrée

### Lot N · titre · jj/mm/aaaa

- **Fait** :
- **Décisions** (et pourquoi) :
- **Écarts par rapport aux docs** :
- **Questions ajoutées** (renvoi vers `06-questions-ouvertes.md`) :
- **À tester à la main** :
