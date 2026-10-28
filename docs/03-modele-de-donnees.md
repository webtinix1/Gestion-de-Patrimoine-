# Modèle de données

Noms techniques en anglais, libellés d'interface en français. Toutes les tables ont `id`, `created_at`, `updated_at` ; les tables métier ont aussi `created_by` et `updated_by` (FK `users`). Montants en FCFA : `bigInteger`. Dates sans heure : `date`.

## Correspondance des termes

| Interface | Code | | Interface | Code |
|---|---|---|---|---|
| Bien | `Asset` | | Mouvement | `Movement` |
| Site | `Site` | | Inventaire | `Inventory` |
| Département | `Department` | | Ligne d'inventaire | `InventoryLine` |
| Service | `Service` | | Prêt / emprunt | `Loan` |
| Local | `Room` | | Intervention | `MaintenanceJob` |
| Catégorie | `AssetCategory` | | Signalement | `Report` (`AssetReport`) |
| État | `AssetCondition` | | Sortie | `Disposal` |
| Statut | `AssetStatus` (Enum) | | Plan d'amortissement | `DepreciationSchedule` |
| Acquisition | `Acquisition` | | Ligne de plan | `DepreciationLine` |
| Ligne d'acquisition | `AcquisitionLine` | | Exercice comptable | `FiscalYear` |
| Fournisseur | `Supplier` | | Document | `Document` |
| Modèle d'étiquette | `LabelTemplate` | | Paramètres | `Setting` |

## Structure

### Organisation

**sites** : `name`, `code` (unique), `address`, `manager_id` (users, null), `description`, `is_active`.

**departments** : `name`, `code` (unique), `manager_id` (users, null), `is_active`. Pivot **department_site** (un département peut exister sur plusieurs sites).

**services** : `department_id`, `name`, `code`, `is_active`.

**rooms** : `site_id`, `department_id` (null), `name`, `code` (unique), `type` (Enum : bureau, salle de culte, salle de réunion, salle informatique, magasin, salle de formation, local technique, autre), `is_active`.

### Référentiels

**asset_categories** : `name`, `prefix` (3 lettres, unique), `depreciable` (Enum : oui, selon seuil, non), `default_method` (Enum : linear, declining), `default_duration_years`, `asset_account`, `depreciation_account`, `expense_account`, `is_active`.

**asset_conditions** : `label`, `color`, `sort`, `is_active` (Neuf, Très bon état, Bon état, État moyen, À réparer, Hors service).

**suppliers** : `name`, `phone`, `address`, `is_active`.

**document_types** : `label`, `is_active`.

**AssetStatus** (Enum PHP, pas de table) : `in_service`, `in_stock`, `in_maintenance`, `on_loan`, `out_of_service`, `transferred_out` (Cédé), `retired` (Réformé).

### Biens

**asset_number_sequences** : `scope` (unique : `global`, `2026`, `INF`…), `last_value`. Verrou de ligne à chaque génération (RG-03).

**assets**
- Identification : `number` (unique, immuable), `designation`, `asset_category_id`, `brand`, `model`, `serial_number` (index ; unicité applicative parmi les biens actifs, RG-13), `description`, `photo_path`.
- Acquisition : `acquired_on`, `in_service_on`, `supplier_id`, `invoice_reference`, `acquisition_cost`, `funding_source` (Enum : fonds propres, don affecté, subvention), `acquisition_line_id` (null).
- Affectation courante (dénormalisée pour les listes, la vérité est dans `movements`) : `site_id`, `department_id`, `service_id`, `room_id`, `holder_id` (users ou **holders**, voir questions ouvertes), `assigned_since`.
- Situation : `asset_condition_id`, `status` (Enum), `notes`, `disposed_at` (null).
- Immobilisation : `is_depreciable`, `depreciation_method`, `duration_years`, `residual_value`, `declining_coefficient` (null), `asset_account`, `depreciation_account`.
- `lock_version` ou contrôle par `updated_at` pour RG-86.

**holders** (si le responsable n'est pas toujours un utilisateur) : `name`, `phone`, `department_id`, `user_id` (null).

### Acquisitions

**acquisitions** : `reference` (`ACQ-2026-039`), `acquired_on`, `supplier_id`, `invoice_reference`, `funding_source`, `site_id`, `department_id`, `total_amount`, `status` (brouillon, validée).

**acquisition_lines** : `acquisition_id`, `designation`, `asset_category_id`, `quantity`, `unit_price`, `tracked_individually` (bool), `line_total`.

### Mouvements

**movements** : `asset_id`, `type` (Enum : initial, department, service, room, site, holder, mixed), `moved_on`, `reason`, `notes`, colonnes `from_*` et `to_*` pour site, département, service, local, responsable, `batch_uuid` (transferts groupés), `created_by`.

### Inventaires

**inventories** : `reference` (`INV-2026-005`), `scope_type` (site, department, service, category, room, all), `scope_id` (null), `site_id` (null, filtre croisé), `starts_on`, `ends_on`, `manager_id`, `status` (planifié, en cours, à valider, clôturé), `notes`, `started_at`, `closed_at`, `closed_by`, `closing_comment`.

**inventory_participants** : `inventory_id`, `user_id`, `room_id` (null).

**inventory_lines** : `inventory_id`, `asset_id` (null si non enregistré), `expected` (bool), snapshot attendu (`expected_room_id`, `expected_department_id`, `expected_condition_id`), constat (`result` Enum : pending, present, missing, other_assignment, condition_mismatch, justified, out_of_scope, unregistered ; `found_room_id`, `found_department_id`, `found_condition_id`), `description` (pour non enregistré), `notes`, `checked_by`, `checked_at`, `resolution` (null, régularisation ultérieure : transfer, condition_update, disposal, created_asset) et `resolution_ref`.

### Prêts

**loans** : `reference` (`PRT-2026-064` ou `EMP-2026-004`), `direction` (out, in), `borrower_type` (interne, externe), `borrower_name`, `borrower_contact`, `department_id` (si interne), `out_on`, `due_on`, `returned_on`, `responsible_id`, `notes`, `status` (en cours, rendu).

**loan_items** : `loan_id`, `asset_id` (null pour un emprunt), `external_designation`, `external_reference` (`EXT-0005`), `external_value`, `condition_out_id`, `condition_back_id`, `previous_status`, `return_notes`.

### Maintenance et signalements

**maintenance_jobs** : `reference` (`MNT-2026-052`), `asset_id`, `nature` (Enum), `reason`, `starts_on`, `ends_on`, `provider_type` (interne, prestataire), `provider_name`, `cost`, `cost_reference`, `result` (Enum : réparé, conforme, partiel, irréparable), `condition_after_id`, `status_after`, `status` (planifiée, en cours, terminée), `notes`.

**asset_reports** (signalements) : `reference` (`SIG-2026-031`), `asset_id`, `type` (changement d'état, déplacement, besoin de maintenance), `reported_condition_id`, `reported_room_id`, `reported_department_id`, `urgency`, `description`, `photo_path`, `status` (nouveau, pris en charge, traité, rejeté), `rejection_reason`, `handled_by`, `handled_ref` (morph vers l'opération créée).

### Sorties

**disposals** : `reference` (`SRT-2026-023`), `asset_id` (unique), `disposed_on`, `reason` (Enum : cession, don, réforme, destruction, perte, vol, autre), `decided_by` (texte), `decision_reference`, `buyer_or_beneficiary`, `sale_price`, `notes`, valeurs figées (`original_value`, `accumulated_depreciation`, `net_book_value`), `accounting_status` (à valider, validée), `accounting_validated_by`, `accounting_validated_at`, `previous_status` (statut du bien avant la sortie), `created_by`, `updated_by`. Justificatifs par le pivot `documentables` (aussi rattachés au bien). Référence `SRT-AAAA-NNN` : compteur par année de la date de sortie dans `asset_number_sequences` (portée `SRT-2026`). *(colonne `previous_status` ajoutée au lot 10)*

### Amortissements

**fiscal_years** : `year` (2026, unique), `label` (2026), `starts_on`, `ends_on` (année civile, Q5), `status` (préparé, ouvert, clôturé), `closed_by`, `closed_at`, `closing_notes` (JSON : points d'attention confirmés, plans validés, anomalies ; RG-42). *(colonnes `year` et `closing_notes` ajoutées au lot 7)*

**depreciation_schedules** : `asset_id`, `version`, `method`, `original_value`, `base_amount`, `duration_years`, `rate` (taux du plan), `declining_coefficient` (null en linéaire), `residual_value`, `starts_on`, `ends_on`, `recalculated_from_year` (RG-37 : exercice d'effet du dernier changement de paramètre), `validated_by`, `validated_at`, `is_current` (un seul courant par bien). Index unique (`asset_id`, `version`). *(colonnes `original_value`, `declining_coefficient` et `recalculated_from_year` ajoutées au lot 7)*

**depreciation_lines** : `depreciation_schedule_id`, `fiscal_year` (l'année de l'exercice, reliée à `fiscal_years.year` quand l'exercice existe), `days` (ou `months` pour le dégressif), `base`, `rate` (null quand la dotation ne découle pas d'un taux annuel : durée restante recalculée, bascule du dégressif), `amount`, `accumulated`, `net_book_value`, `is_locked` (exercice clôturé ou antérieur, RG-36). Index unique (`depreciation_schedule_id`, `fiscal_year`). *(lot 7 : `fiscal_year` remplace `fiscal_year_id`, car un plan couvre des exercices pas encore créés et des années antérieures au premier exercice saisi)*

**depreciation_parameter_changes** : `asset_id`, `batch_uuid` (changements enregistrés ensemble), `parameter`, `old_value`, `new_value`, `reason`, `effective_year`, `effective_fiscal_year_id` (null si l'exercice n'existe pas encore), `changed_by`, `changed_at`. *(colonnes `batch_uuid` et `effective_year` ajoutées au lot 7)*

**assets** (lot 7) : `declining_coefficient` est renseigné au premier calcul d'un plan dégressif ; un changement ultérieur des paramètres généraux ne le modifie pas.

### Documents, étiquettes, paramètres

**documents** : `document_type_id`, `original_name`, `path`, `mime`, `size`, `uploaded_by`. Pivot polymorphe **documentables** (`document_id`, `documentable_type`, `documentable_id`).

**label_templates** : `name`, `width_mm`, `height_mm`, `sheet_layout` (a4_3x8, a4_2x7, roll), `code_type` (qr, code128), `header_text`, `show_designation`, `show_department`, `show_site`, `show_room`, `show_logo`, `is_default`.

**settings** : paires clé-valeur typées (nom et sigle de l'Église, logo, format de numéro, seuil d'immobilisation, prorata, arrondi, délais d'alertes, sécurité). Paquet `spatie/laravel-settings` possible, à justifier.

### Utilisateurs, droits, journal

**users** : `first_name`, `last_name`, `username` (unique), `email` (null), `phone`, `job_title`, `password`, `must_change_password`, `is_active`, `last_login_at`, `last_login_ip`, `failed_attempts`, `locked_until`.

Pivots de périmètre **site_user** et **department_user** (vide = tous).

Rôles et permissions : tables `spatie/laravel-permission`.

Journal : table `activity_log` de `spatie/laravel-activitylog`, avec `properties` (old, attributes) et l'IP dans `properties.ip`.

Alertes : table `notifications` de Laravel (canal base de données uniquement) + calcul planifié (`schedule:run` toutes les heures) pour les échéances.

## Données de démonstration (seeders)

Pour les tests et la recette, reprendre les données de la maquette : 4 sites (Siège, Paroisse Moungali, Annexe Talangaï, Centre de formation), 9 départements et leurs services, environ 40 locaux, 10 catégories, 14 utilisateurs couvrant les 5 rôles, environ 300 biens répartis, quelques acquisitions, mouvements, prêts (dont 2 en retard), interventions, signalements, 2 inventaires (un clôturé, un en cours), 3 sorties, exercices 2023 à 2025 clôturés et 2026 ouvert. Les biens cités dans les cas d'amortissement (`PAT-000125`, `PAT-000034`…) doivent exister avec les mêmes valeurs.
