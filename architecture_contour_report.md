# Architecture Contour Report

## 1. Executive summary

Observed:

- The analyzed contour is not a single subsystem. It is a composite of five interacting contours: estimate calculation and reporting, imported price lists, materials catalog and browser-assisted price capture, automatic and manual operations pricing, and labor norm-rate resolution.
- The current architecture is optimized for shipping usable estimates with mixed pricing sources, not for enforcing one universal price-source model.
- The strongest implemented source-backed flows are imported operation prices, facade quote aggregation, and labor profile-rate justification snapshots.
- The weakest implemented flows are plate and edge material pricing inside the estimate calculator, fittings and expenses pricing, and any attempt to explain one applied estimate total through one uniform provenance model.

Inferred:

- The architecture evolved by adding new traceability layers beside older numeric fields rather than replacing the older fields. That preserved delivery speed, but it created a split-brain design.
- Operations and facades are closer to a future universal pricing architecture than plates, edges, fittings, and expenses.

Missing / not found:

- A first-class universal price-source entity that can represent the applied price for materials, operations, labor rates, fittings, and expenses through one uniform contract.
- A universal rule engine for automatic operation tariffs by thickness, material type, or product characteristics.
- End-to-end evidence coverage for every estimate line. Evidence support exists, but it is partial and split across legacy revision runs and newer generic evidence runs.

Concrete conclusion:

- The system can be extended, but not safely by layering more special cases onto the current split model. The most critical extension gap is that the estimate engine still prices core material cost drivers from direct numeric fields while newer contours already assume versioned, linked, and partially evidenced price records.

## 2. Sources analyzed

### Database tables / SQL

- `server/database/migrations/2026_02_02_000001_create_suppliers_table.php`: defines supplier identity for imported pricing and supplier-linked source provenance.
- `server/database/migrations/2026_02_02_000002_create_price_lists_table.php`: defines the price list container and domain split.
- `server/database/migrations/2026_02_02_000003_create_price_list_versions_table.php`: defines imported versioned price sources with file/url metadata, status, and hash.
- `server/database/migrations/2026_02_02_000004_create_price_import_sessions_table.php`: defines import-session lifecycle for Excel and similar imports.
- `server/database/migrations/2026_02_02_000006_create_operation_prices_table.php`: defines imported operation price rows.
- `server/database/migrations/2026_02_02_000007_create_material_prices_table.php`: defines imported material price rows.
- `server/database/migrations/2026_02_04_000004_create_supplier_operation_prices_table.php`: shows legacy supplier-operation pricing lineage.
- `server/database/migrations/2026_02_04_000005_update_material_prices_add_supplier.php`: adds supplier linkage to material price rows.
- `server/database/migrations/2026_02_05_000001_update_price_list_versions_status_and_source.php`: defines source-type and status semantics for imported versions.
- `server/database/migrations/2026_02_06_000001_extend_operation_prices_for_snapshot_architecture.php`: proves operation pricing was explicitly upgraded toward snapshot traceability.
- `server/database/migrations/2026_02_06_000003_remove_cost_per_unit_from_operations.php`: shows canonical operations were intentionally separated from direct numeric pricing.
- `server/database/migrations/2026_02_13_100001_create_project_price_list_versions_table.php`: defines project-level linkage to price-list versions actually used.
- `server/database/migrations/2026_02_13_200001_add_price_aggregation_to_project_positions.php`: adds facade/project-position price aggregation fields.
- `server/database/migrations/2026_02_13_200002_create_project_position_price_quotes_table.php`: defines facade quote snapshots per project position.
- `server/database/migrations/2026_01_11_create_project_normohour_sources_table.php`: defines project-side normohour source records.
- `server/database/migrations/2026_01_11_create_project_labor_works_table.php`: defines labor work rows inside estimates.
- `server/database/migrations/2026_01_14_103032_create_global_normohour_sources_table.php`: defines global labor-rate sources.
- `server/database/migrations/2026_01_14_add_locked_fields_to_project_profile_rates.php`: proves profile rates are snapshotted and lockable.
- `server/database/migrations/2026_01_14_add_rate_fields_to_project_labor_works.php`: shows labor works persist resolved rate numbers.
- `server/database/migrations/2026_01_15_000001_create_project_labor_work_steps_table.php`: defines step-level labor decomposition.
- `server/database/migrations/2026_02_23_000002_extend_material_price_histories_for_observations.php`: defines observation-based material price history with source type and proof fields.
- `server/database/migrations/2026_03_05_000001_extend_material_price_histories_for_revision_gate.php`: adds normalization and scoring fields for reusable observations.
- `server/database/migrations/2026_03_29_000001_create_evidence_records_table.php`: defines the newer generic evidence record layer.
- `server/database/migrations/2026_03_29_000002_create_estimate_evidence_runs_table.php`: defines the newer estimate evidence run and item layer.
- `server/database/migrations/2026_03_29_000003_add_evidence_record_id_to_material_price_histories.php`: bridges material observations to generic evidence records.
- `server/database/migrations/2026_03_29_100001_add_snapshot_and_item_fields.php`: adds snapshot and value fields to evidence runs/items.
- `server/database/schema/mysql-schema.sql`: used to confirm the existence and shape of `project_profile_rates`, which was not cleanly traceable through the normal migration chain.

### Backend files

- `server/app/Services/Smeta/SmetaCalculator.php`: current calculation source of truth for plates, edges, facades, operations, fittings, and expenses.
- `server/app/Service/EstimateCalculator.php`: legacy calculation path that still reveals earlier direct-field assumptions.
- `server/app/Service/AutoOperationCalculator.php`: legacy auto-operation generation logic and selection conditions.
- `server/app/Service/ReportService.php`: assembles the report DTO and shows what reporting considers authoritative.
- `server/app/Services/PriceAggregationService.php`: defines facade quote aggregation behavior.
- `server/app/Services/FacadeQuoteService.php`: confirms facade quote sourcing and aggregation workflow.
- `server/app/Services/PriceImport/PriceImportSessionService.php`: defines import session lifecycle.
- `server/app/Services/PriceImport/PriceImportExecutorV2.php`: defines import execution into versioned price tables.
- `server/app/Services/PriceImport/OperationPriceResolver.php`: defines how operation prices are chosen at runtime.
- `server/app/Services/NormohourRateService.php`: defines labor-rate calculation from source sets.
- `server/app/Services/ProjectProfileRateResolver.php`: defines project-side rate resolution and preview logic.
- `server/app/Services/SnapshotService.php`: proves revisions are created from report DTO snapshots.
- `server/app/Services/EvidenceRunItemCollector.php`: defines the newer estimate evidence item generation path.
- `server/app/Services/EvidenceRunFinalizer.php`: defines newer evidence snapshot finalization.
- `server/app/Services/EstimateEvidencePdfBuilder.php`: defines the newer evidence PDF output contour.
- `server/app/Services/MaterialConfirmationService.php`: defines freshness and reuse rules for proof-backed material observations.
- `server/app/Services/ChromeExtractService.php`: defines one-click material extraction and material price observation capture.
- `server/app/Services/GenericChromeCaptureService.php`: defines the newer generic Chrome evidence capture and deterministic auto-link.
- `server/app/Http/Controllers/Api/PriceImportController.php`: import API entry point.
- `server/app/Http/Controllers/Api/PriceListVersionController.php`: audit/download entry point for imported versions.
- `server/app/Http/Controllers/Api/ProjectPositionController.php`: project-position creation/update and facade price linkage.
- `server/app/Http/Controllers/Api/ProjectsOperationsController.php`: runtime assembly of automatic and manual operations with pricing.
- `server/app/Http/Controllers/Api/ProjectManualOperationController.php`: manual operation CRUD entry point.
- `server/app/Http/Controllers/Api/ProjectProfileRateController.php`: project rate control entry point.
- `server/app/Http/Controllers/Api/LaborWorkRateController.php`: labor rate binding/recalculation entry point.
- `server/app/Http/Controllers/Api/EvidenceRunController.php`: newer evidence-run entry point.
- `server/app/Http/Controllers/Api/RevisionRunController.php`: legacy revision-evidence contour and price-justification snapshot creation.
- `server/app/Http/Controllers/Api/ProjectRevisionController.php`: revision PDF and price-justification PDF output.
- `server/app/Http/Controllers/Api/GenericChromeController.php`: generic Chrome capture endpoints and one-click material-plus-evidence bridge.
- `server/app/Http/Controllers/Api/ChromeExtensionController.php`: extension-facing extraction/auth/template endpoints.
- `server/app/Http/Controllers/Api/MaterialCatalogController.php`: catalog browse/detail/refresh/observation endpoints.
- `server/routes/api.php`: used as the authoritative runtime entry-point map.
- `server/app/Models/Project.php`, `ProjectPosition.php`, `ProjectManualOperation.php`, `ProjectNormohourSource.php`, `ProjectLaborWork.php`, `ProjectProfileRate.php`, `ProjectPriceListVersion.php`, `ProjectPositionPriceQuote.php`: project-side domain entities.
- `server/app/Models/Material.php`, `MaterialPrice.php`, `MaterialPriceHistory.php`, `Operation.php`, `OperationPrice.php`, `PriceList.php`, `PriceListVersion.php`, `PriceImportSession.php`, `GlobalNormohourSource.php`, `PositionProfile.php`, `EvidenceRecord.php`, `EstimateEvidenceRun.php`, `EstimateEvidenceItem.php`, `RevisionRun.php`, `ProjectRevision.php`: canonical, snapshot, evidence, and revision entities.

### Frontend files

- `client/src/views/ProjectEditorView.vue`: the actual estimate workspace and user entry point.
- `client/src/views/SmetaEditorView.vue`: effectively a stub, important because it shows the real estimate editor moved elsewhere.
- `client/src/components/PriceImportDialog.vue`: user-facing imported price workflow.
- `client/src/views/PriceListVersionShow.vue`: imported price version audit UI.
- `client/src/components/ProfileRatesSection.vue`: project labor-rate justification and lock UI.
- `client/src/components/evidence/EvidenceRunPanel.vue`: newer evidence-run UI.
- `client/src/composables/useEvidenceRun.ts`: evidence-run frontend orchestration logic.
- `client/src/api/priceLists.ts`, `client/src/api/evidenceRun.ts`, `client/src/api/revisionRun.ts`, `client/src/api/materialCatalog.ts`, `client/src/api/laborWorks.ts`: API surface used by the frontend for the analyzed contours.

### Config / route artifacts

- `server/routes/api.php`: not a config file in the strict sense, but it is the authoritative cross-contour runtime map.

Missing / not found:

- No dedicated pricing domain configuration file was found that centralizes pricing strategy. Pricing rules are primarily encoded in services, controllers, and schema.

### Existing docs

- `docs/PRICE_JUSTIFICATION_PDF_ARCHITECTURE.md`: describes current price-justification PDF intent and pipeline.
- `docs/PDF_GENERATION_ARCHITECTURE.md`: explains report DTO to DomPDF path.
- `docs/evidence-revision-architecture.md`: documents the legacy revision evidence contour.
- `docs/evidence-rollout-status.md`: shows the rollout status and current coverage gaps for evidence.
- `docs/price-justification-mvp-implementation.md`: documents the intended MVP shape of price justification.
- `docs/chrome-extension-architecture.md`: explains extension architecture and server interaction.
- `docs/chrome-extension-and-material-types.md`: explains material-type handling and extension integration assumptions.

### Screenshots / UI references if provided

Missing / not provided:

- No user-provided screenshots or external UI captures were provided for this report.

## 3. Contour map

### Contour A: Estimate calculation and reporting

Purpose:

- Produce the live estimate totals, line items, revisions, and PDFs.

Main entities:

- `projects`, `project_positions`, `project_manual_operations`, `project_fittings`, `project_expenses`, `project_labor_works`, `project_revisions`.

Main flows:

- Project positions are loaded.
- Material, edge, facade, operation, fitting, expense, and labor totals are calculated.
- `ReportService` converts them into a report DTO.
- Revisions snapshot that DTO.
- PDF endpoints render current or snapshotted reports.

Entry points:

- `GET /api/projects/{project}` and related project editor APIs.
- `GET /api/smeta/pdf/{projectId}`.
- `POST /api/projects/{id}/revisions`.
- `GET /api/projects/{id}/revisions/{number}/pdf`.

Outputs:

- Live project totals.
- Report DTO.
- Locked revision snapshot JSON.
- Estimate PDF and price-justification PDF.

Dependencies on other contours:

- Depends on imported operation prices, direct material prices, facade quote snapshots, project-linked price-list versions, labor rate snapshots, and evidence/revision data.

### Contour B: Imported price lists and price snapshots

Purpose:

- Import supplier price lists from file/paste/url-like flows, normalize them, map them, resolve matches, and persist versioned material and operation price rows.

Main entities:

- `suppliers`, `price_lists`, `price_list_versions`, `price_import_sessions`, `material_prices`, `operation_prices`, `supplier_product_aliases`.

Main flows:

- User uploads or pastes a price source.
- Import session parses raw rows.
- Mapping and resolution queues align raw rows to canonical materials/operations.
- Execution persists versioned rows.
- Projects later link the actually used price-list versions.

Entry points:

- `/api/price-import/*` session endpoints.
- `/api/price-lists/*` and `/api/price-list-versions/*` audit endpoints.

Outputs:

- Active/inactive imported price versions.
- Versioned material price rows.
- Versioned operation price rows.
- Audit metadata including source file/url and hash.

Dependencies on other contours:

- Supplies operation prices directly to estimate calculation.
- Supplies facade quote candidates and some default project-position pricing.
- Supplies project price-source references through `project_price_list_versions`.

### Contour C: Materials catalog and browser-assisted capture

Purpose:

- Create and maintain material catalog entries, store observed material prices, and capture supporting evidence from parsing and Chrome extension flows.

Main entities:

- `materials`, `material_price_histories`, `user_material_library`, `parser_supplier_collect_profiles`, `evidence_records`, `generic_evidence_assets`.

Main flows:

- Material parsed or captured from URL/Chrome.
- Material is created or updated.
- Observation is written into `material_price_histories`.
- Optional generic evidence record and screenshot are attached.
- Trust score and confirmation state are recalculated.

Entry points:

- `/api/materials/catalog/*`.
- `/api/chrome/extract`, `/api/chrome/extract-with-evidence`, `/api/chrome/capture-observation`.

Outputs:

- Catalog materials.
- Observation history.
- Screenshot-backed proof for some material observations.

Dependencies on other contours:

- Supplies direct numeric material prices still used by estimate calculation.
- Supplies evidence-reuse candidates to legacy and newer proof flows.

### Contour D: Automatic and manual operations pricing

Purpose:

- Derive base operations from project positions and detail types, merge them with manual operations, and assign prices.

Main entities:

- `operations`, `detail_type_operations`, `project_manual_operations`, `operation_prices`, `project_price_list_versions`.

Main flows:

- Base operations are derived from detail types, cutting, edging, and project geometry/material choices.
- Manual operations add explicit user-selected operations.
- Operation price resolution selects project-linked or fallback snapshot prices.

Entry points:

- `/api/projects/{project}/operations`.
- `/api/projects/{project}/manual-operations`.

Outputs:

- Operation lines with quantity, unit cost, total cost, and aggregation in reports.

Dependencies on other contours:

- Depends on imported operation prices.
- Depends on project positions and material parameters for operation generation.

### Contour E: Labor norm rates and project labor works

Purpose:

- Resolve labor norm rates from source sets, store project profile rates, and apply them to labor work rows.

Main entities:

- `global_normohour_sources`, `project_profile_rates`, `position_profiles`, `project_labor_works`, `project_labor_work_steps`, `project_normohour_sources`.

Main flows:

- Source rates are gathered.
- Resolver computes preview or fixed rate.
- Project profile rates can be fixed, locked, and snapshotted.
- Labor works bind resolved rates and store effective totals.

Entry points:

- `/api/projects/{project}/profile-rates/*`.
- `/api/projects/{project}/bind-labor-work-rates` and related labor APIs.

Outputs:

- Project-specific effective labor rates.
- Rate justification snapshot data for reports.

Dependencies on other contours:

- Depends on position profiles and labor work rows.
- Feeds reporting, but uses a different source model than materials and operations.

### Contour F: Evidence, revision, and proof reporting

Purpose:

- Capture supporting proof for estimate lines, produce revision snapshots, and output evidence-aware PDFs.

Main entities:

- Legacy: `revision_runs`, `revision_run_items`, `evidence_artifacts`, `evidence_assets`.
- Newer: `estimate_evidence_runs`, `estimate_evidence_items`, `evidence_records`, `generic_evidence_assets`, `evidence_links`.

Main flows:

- Revision run or evidence run is created.
- Cost-driver items are collected.
- Existing proof may auto-resolve items.
- Chrome/manual capture can resolve remaining items.
- Finalized snapshots generate PDFs and appendices.

Entry points:

- `/api/projects/{project}/revision-runs/*`.
- `/api/projects/{project}/evidence-runs/*`.
- `/api/chrome/generic-items`, `/api/chrome/generic-items/{itemId}/capture`.
- Revision and evidence PDF endpoints.

Outputs:

- Evidence coverage state.
- Price-justification rows in revision snapshots.
- Evidence PDF and price-justification PDF.

Dependencies on other contours:

- Depends on report DTO structure, material observations, Chrome capture, and project cost drivers.

### Cross-contour dependencies

Observed:

- Imported operation prices flow directly into estimate calculation through `OperationPriceResolver`, while imported material prices do not have an equivalent mandatory role for plate and edge calculations.
- `ProjectPositionController` links used `price_list_version_id` values into `project_price_list_versions`, and `ReportService` uses that link table as the report-level source list.
- Facade positions bridge imported material prices and estimate rows through `project_position_price_quotes`, making facade pricing more traceable than plate and edge pricing.
- `SnapshotService` snapshots whatever `ReportService` emits, so any inconsistency in live calculation becomes a frozen revision truth.
- `GenericChromeController` can bridge a new evidence record back into `material_price_histories`, but no equivalent bridge was found for `operation_prices`.

Inferred:

- Cross-contour coupling is highest at `ProjectPosition`, `ReportService`, and revision snapshot creation. Those are the chain-effect hubs.
- Future changes to the pricing model will cascade into the report DTO, revision snapshots, evidence collection logic, and PDF builders because those layers depend on current DTO shape rather than on an abstract pricing provenance contract.

Missing / not found:

- No single boundary object was found that represents an "applied price" across all contours.
- No single contour owns the concept of estimate-line evidence. Legacy revision runs and newer evidence runs both partially claim it.

## 4. Current data model

Observed:

### Core estimate entities

| Entity | Responsibility | Key fields | Relationships | Lifecycle role | Authority |
| --- | --- | --- | --- | --- | --- |
| `projects` | Root estimate aggregate | coefficients, defaults, normohour fields, freshness days | has many positions, manual operations, labor works, revisions, price-list version links | long-lived project root | authoritative |
| `project_positions` | Estimate line for plate/facade detail | kind, material ids, dimensions, edge scheme, `price_per_m2`, `material_price_id`, price aggregation fields | belongs to project, material, facade material; has many quote snapshots | mutable working line | mixed: partly authoritative, partly derived |
| `project_manual_operations` | User-added operation assignment | `operation_id`, quantity, note | belongs to project and operation | mutable working line | authoritative for quantity, not for price provenance |
| `project_fittings` | Project-local fitting cost rows | quantity, price, amount-style fields | belongs to project | mutable working line | authoritative numeric override |
| `project_expenses` | Project-local expense rows | name, amount, quantity, unit price | belongs to project | mutable working line | authoritative numeric override |
| `project_labor_works` | Labor work line with hours and effective rate | profile, hours, effective rate, amount | belongs to project and position profile; has many steps | mutable working line with later binding | authoritative row with derived rate values |
| `project_labor_work_steps` | Step-level labor decomposition | operation/step fields, hours, order | belongs to labor work | optional detailed decomposition | derived / supporting |
| `project_revisions` | Locked snapshot of report state | number, status, `snapshot_json`, `snapshot_hash`, engine version | belongs to project, has publications | immutable after creation except status | authoritative for published snapshot |

### Canonical catalog entities

| Entity | Responsibility | Key fields | Relationships | Lifecycle role | Authority |
| --- | --- | --- | --- | --- | --- |
| `materials` | Canonical material catalog | type, unit, dimensions, `price_per_unit`, `source_url`, trust fields | has many observations, material prices, used by positions | long-lived catalog entry | authoritative for identity, still partially authoritative for price |
| `operations` | Canonical operation catalog | name, unit, search fields | has many operation prices, referenced by detail types and manual ops | long-lived catalog entry | authoritative for identity, not for current imported price |
| `detail_type_operations` | Mapping from detail type to base operation | detail type id, operation id, quantity rules/order | joins detail types to operations | static rule seed | authoritative for base operation membership |
| `position_profiles` | Labor profile definition | name, base rate semantics, `rate_model` | referenced by labor works and project profile rates | long-lived catalog entry | authoritative for profile identity |

### Imported pricing entities

| Entity | Responsibility | Key fields | Relationships | Lifecycle role | Authority |
| --- | --- | --- | --- | --- | --- |
| `suppliers` | Supplier identity and metadata | name, contacts | has many price lists | long-lived | authoritative |
| `price_lists` | Logical container for imported prices | name, supplier id, domain | has many versions | long-lived | authoritative |
| `price_list_versions` | Specific imported source snapshot | version number, status, source type, source url, original filename, sha256, effective/captured dates | belongs to price list; has many material and operation prices | append-only historical snapshot | authoritative for source snapshot |
| `material_prices` | Imported material price rows | material id, version id, supplier id, price per internal unit | belongs to material and version | created by import | authoritative within imported pricing contour |
| `operation_prices` | Imported operation price rows | operation id, version id, supplier id, source name, external key, match confidence, meta | belongs to operation and version | created by import | authoritative within imported operation pricing contour |
| `price_import_sessions` | Import orchestration state | source type, statuses, raw data, mappings, resolution queues | orchestrates import process | ephemeral workflow record | authoritative for session state |

### Project-side pricing provenance entities

| Entity | Responsibility | Key fields | Relationships | Lifecycle role | Authority |
| --- | --- | --- | --- | --- | --- |
| `project_price_list_versions` | Project links to price versions actually used | project id, price list version id, role, linked at | belongs to project and version | appended when pricing is used | derived provenance index |
| `project_position_price_quotes` | Facade quote snapshot rows | project position id, material price id, version id, supplier id, price snapshot, mismatch flags | belongs to project position and material price/version | replaced when quotes change | authoritative for facade quote provenance |

### Labor source entities

| Entity | Responsibility | Key fields | Relationships | Lifecycle role | Authority |
| --- | --- | --- | --- | --- | --- |
| `global_normohour_sources` | Global source rows for labor rates | source name, region/context, rate, metadata | consumed by rate services | reference data | authoritative for upstream source values |
| `project_normohour_sources` | Project-attached normohour source set | project id and source-related fields | belongs to project | mutable project evidence/support | authoritative within project context |
| `project_profile_rates` | Project-specific resolved/fixed profile rates | profile id, effective rate, fixed/locked flags, `sources_snapshot`, `justification_snapshot` | belongs to project/profile | mutable until fixed/locked | authoritative for applied project labor rate |

### Evidence and observation entities

| Entity | Responsibility | Key fields | Relationships | Lifecycle role | Authority |
| --- | --- | --- | --- | --- | --- |
| `material_price_histories` | Observed material prices from parser/manual/Chrome/price list | price, normalized url, source type, observed at, screenshot/snapshot paths, evidence record id | belongs to material; may belong to evidence record | append-only observation history | authoritative for observation contour, not for estimate application |
| `evidence_records` | Generic proof record | cost component, source type, capture method, source url, observed price, trust/confidence | has many assets and links; linked from evidence items and some material observations | append-only proof record | authoritative in newer evidence contour |
| `generic_evidence_assets` | Files attached to generic evidence | screenshot/document file data | belongs to evidence record | append-only | authoritative supporting artifact |
| `evidence_links` | Morph links from evidence record to domain objects | evidence record id, linkable type/id, relation type | belongs to evidence record and target | append-only | derived linkage map |
| `estimate_evidence_runs` | Newer evidence collection session | status, counts, snapshot json | belongs to project; has many items | workflow to finalized evidence snapshot | authoritative for newer run state |
| `estimate_evidence_items` | Evidence target row in newer contour | cost component, status, subject morph, evidence record id, source url, value fields | belongs to evidence run and optional evidence record | workflow item | authoritative for newer item state |
| `revision_runs` | Legacy revision evidence session | status, revision linkage | belongs to project | legacy workflow | authoritative in legacy contour |
| `revision_run_items` | Legacy evidence target row | cost driver type, price history linkage, statuses | belongs to revision run | legacy workflow item | authoritative in legacy contour |
| `evidence_artifacts`, `evidence_assets` | Legacy proof record and files | capture source, URL/domain, file paths | linked to revision items | legacy proof store | authoritative only inside legacy contour |

Inferred:

- The authoritative boundary is inconsistent by domain. Materials use `materials.price_per_unit` as an applied price in core estimate calculation, operations use `operation_prices`, facades use `project_position_price_quotes` plus aggregated fields, and labor works use `project_profile_rates` plus copied numeric values.

Missing / not found:

- No first-class entity exists for an applied estimate-line price with uniform fields such as business subject, source snapshot, evidence record, resolution method, currency, validity window, and explanation.
- No first-class tariff-rule entity exists for automatic operation pricing.
- No clean standard migration was found for creating `project_profile_rates`; only the schema dump and later alteration migration clearly prove its presence.

## 5. Current runtime flow

### Flow A: Imported price list to applied operation price

1. User action: a user opens the price import UI and uploads/pastes a supplier price source.
2. Frontend logic: `PriceImportDialog.vue` orchestrates upload, mapping, and resolution steps against `/api/price-import/*`.
3. Backend logic: `PriceImportController` and `PriceImportSessionService` create a `price_import_sessions` record with lifecycle states such as created, mapping required, resolution required, execution running, and completed.
4. Backend logic: `PriceFileParser` and import services parse raw rows.
5. Backend logic: mapping and resolution associate rows with canonical operations/materials, optionally through aliases.
6. Persistence: `PriceImportExecutorV2` writes `price_list_versions`, `operation_prices`, and `material_prices`.
7. Automated calculation: later, `ProjectsOperationsController` and `SmetaCalculator::calculateOperationData()` call `OperationPriceResolver`.
8. Automated calculation: resolver preference order is project-linked version, then a usable linked/fallback version, then a global median fallback.
9. Persistence: if a project position or quote uses a specific version, controllers add a `project_price_list_versions` link.
10. Reporting/export: `ReportService::buildPriceSources()` reads linked versions and exposes them in the report snapshot.

Observed separation:

- Backend logic is heavily stateful and version-aware.
- Frontend logic is a workflow wrapper, not the decision maker.
- Persistence is explicit and append-oriented.
- Automated calculation is strong for operations.

### Flow B: Material catalog / Chrome capture to material observation

1. User action: user parses by URL, creates a catalog material, refreshes a material, or captures from the Chrome extension.
2. Frontend logic: materials catalog pages and Chrome-related flows call material catalog or Chrome endpoints.
3. Backend logic: `ChromeExtractService` or `MaterialParseService` creates or updates `materials`.
4. Persistence: `material_price_histories` gets a new observation with source type, observed time, URL normalization, and optional screenshot/snapshot paths.
5. Backend logic: when generic evidence is enabled, `GenericChromeController::extractWithEvidence()` also creates or reuses an `evidence_records` row and may bridge its id back into the material observation.
6. Backend logic: `TrustScoreService` recalculates material trust level from observations and evidence assets.
7. Persistence: `materials.price_per_unit` is updated during manual observation addition and material refresh-related flows.
8. Reporting/export: legacy and newer evidence flows can reuse recent material observations, but the estimate calculator still primarily reads direct material numeric fields for plates and edges.

Observed separation:

- User action and frontend trigger observation capture.
- Backend owns normalization, deduplication, and proof reuse.
- Persistence mixes canonical material price field updates with append-only observation history.

### Flow C: Project position to estimate totals

1. User action: user edits positions in `ProjectEditorView.vue`.
2. Frontend logic: project editor hits project position, operation, fitting, expense, and labor endpoints.
3. Backend logic: `ProjectPositionController` stores plate or facade-specific fields. For facade pricing it may derive a default from latest active `MaterialPrice` or persist aggregated quote snapshots.
4. Automated calculation: `SmetaCalculator` loads positions and calculates plates, edges, facades, operations, fittings, and expenses.
5. Automated calculation: plate and edge totals read `Material.price_per_unit` from related materials; facade totals read project-position aggregated values; operation totals use `OperationPriceResolver`; fittings and expenses read project-local numeric fields.
6. Backend logic: `ReportService` aggregates outputs, merges labor work data and profile rate justifications, and attaches linked price-list versions.
7. Persistence: snapshot creation uses `SnapshotService`, which freezes the report DTO into `project_revisions.snapshot_json`.
8. Reporting/export: PDF endpoints render either current report data or a stored revision snapshot.

Observed separation:

- Automated calculations are backend-owned.
- Frontend mostly edits inputs and triggers refresh.
- Persistence stores a mix of canonical ids, numeric snapshots, and revision snapshots.

### Flow D: Revision and evidence generation

1. User action: user starts a revision run or an evidence run.
2. Frontend logic: `EvidenceRunPanel.vue` and revision UI call run creation, refresh, resolve, finalize, and PDF endpoints.
3. Backend logic: legacy `RevisionRunController` and newer `EvidenceRunController` collect estimate cost drivers into items.
4. Automated calculation: collectors attempt to auto-resolve items from existing evidence or fresh material observations.
5. User action: remaining items can be resolved manually or through Chrome capture.
6. Backend logic: `GenericChromeCaptureService` either creates a new `evidence_records` row or reuses a fresh equivalent, stores screenshots, and auto-links to exactly one matching unresolved evidence item when possible.
7. Persistence: finalized runs store counters and snapshot JSON; legacy revision finalization also injects `price_justifications` and `evidence_summary` into the revision snapshot.
8. Reporting/export: price-justification and evidence PDFs are generated from finalized snapshots, not from live unresolved state.

Observed separation:

- There are two parallel evidence flows.
- Reporting prefers finalized snapshots.

### Different source-type flows

Observed:

- Excel/file-style import is versioned and normalized through `price_import_sessions`, `price_list_versions`, and imported price rows.
- Manual material observation is stored as a new `material_price_histories` record and also updates `materials.price_per_unit`.
- Chrome one-click material capture behaves like catalog ingestion plus optional generic evidence bridging.
- Manual operations do not run through a separate price import flow. They reuse canonical operations and their currently resolvable operation price context.

Inferred:

- Different source types do not converge into one common "applied price" workflow before estimate calculation. They remain separate until some of them are flattened into numeric fields.

## 6. Price handling model

### Current price-bearing domains

| Domain | Where price comes from | Where stored | How linked | Versioning | Historical traceability | Evidence / attachments | Current consistency |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Plate materials | current material catalog field or refreshed observation rolled into material field | `materials.price_per_unit` | via `project_positions.material_id` | no applied-version concept | observation history exists separately | only indirect via `material_price_histories` and possible evidence record | weak |
| Edge materials | current material catalog field | `materials.price_per_unit` | via edge material on position | no applied-version concept | observation history exists separately | only indirect | weak |
| Facades | imported `material_prices` aggregated into project quote snapshots, with fallback to material field in some paths | `project_position_price_quotes`, `project_positions.price_per_m2`, optional `material_price_id` | direct project-position linkage | yes for quote sources | yes for quote rows and linked versions | source file/url trace via versions, not full generic evidence per quote row | strong relative to other material domains |
| Automatic operations | imported `operation_prices` selected by resolver | `operation_prices`, transient resolved value in report | via `operations`, project-linked price versions | yes | yes for imported price rows and linked versions | no generic evidence bridge found for operation prices | medium-strong |
| Manual operations | same as automatic operations after operation selection | no manual-op price row; resolved from operation context | `project_manual_operations.operation_id` + resolver | yes at operation price layer, not at manual-op row | partial | no manual-op evidence model found | medium |
| Labor norm rates | source set resolved into project-specific profile rate snapshot | `global_normohour_sources`, `project_profile_rates`, copied rate fields on labor works | via profile and project | snapshot-style, not price-list style | yes through source snapshots/justifications | justification snapshot exists, but not generic evidence record | medium-strong |
| Fittings | project-local numeric input | project fitting fields | direct row ownership | no | no separate history found | legacy revision/evidence may reference some fitting items | weak |
| Expenses | project-local numeric input | project expense fields | direct row ownership | no | no separate history found | limited legacy expense evidence support | weak |

Observed:

- Price is handled through multiple incompatible mechanisms.
- `price_list_versions` provides true source snapshots with file/url/hash metadata.
- `project_price_list_versions` acts as a report-level provenance index of used versions.
- `materials.price_per_unit` remains a live mutable numeric field used directly by estimate calculation.
- `material_price_histories` provides observation history and optional proof, but estimate application does not consistently bind to a specific observation row.
- `operation_prices` is the clearest imported and versioned pricing model.
- `project_profile_rates` is a domain-specific snapshot of applied labor rates with justification, but it is not unified with price-list versions.

Inferred:

- The system treats price as traceable sourced data only in selected contours. In the core estimate engine it still often treats price as raw numeric state.
- The report's `price_sources` section can explain which imported price-list versions were used by the project, but not always which exact applied numeric value produced each plate, edge, fitting, or expense total.

Missing / not found:

- No universal representation exists for "applied price + source + evidence + explanation".
- No evidence attachment model was found for `operation_prices` analogous to the bridge from `material_price_histories` to `evidence_records`.

### Explicit answers

Is price treated as raw numeric data, or as a traceable sourced record?

- Observed: both. Operations, imported facades, and labor profile rates are relatively traceable. Plate materials, edge materials, fittings, and expenses are still treated as raw numeric or project-local values in the estimate engine.

Can the system explain why a specific price was used?

- Observed: partially. It can often explain imported operation pricing and facade quote origins through linked versions and quote snapshots. It cannot reliably explain why a particular plate or edge numeric value was used beyond "this was the current material field value" unless a human manually correlates it to material observation history.

Can the same mechanism work for materials, operations, and norm rates?

- Observed: no. Materials use catalog field plus observation history, operations use imported snapshot rows plus resolver logic, and norm rates use dedicated source-set snapshots and project profile rates.
- Inferred: those mechanisms can converge conceptually, but not without model changes.

What breaks if non-Excel sources are introduced?

- Observed: non-Excel sources already exist for material observations through parsing and Chrome capture, but those sources do not become the mandatory applied pricing mechanism for plate and edge estimate totals.
- Inferred: introducing more non-Excel sources will increase inconsistency unless applied material pricing stops reading mutable catalog numeric fields.

What is missing to support a universal pricing model?

- Missing / not found: a first-class applied-price record, a shared provenance contract across all cost drivers, a common evidence link strategy for all price-bearing entities, and a mandatory resolution layer that estimate calculation must use instead of direct numeric fallback fields.

## 7. Automatic operations model

Observed:

- Automatic/base operations are defined through canonical operations linked to detail types and through calculation logic for cutting and edging.
- `AutoOperationCalculator` and current project-operation assembly use detail type, material parameters, edge scheme, exclusion groups, and thickness ranges to decide which operation rows should exist.
- Quantity calculation is based on project position geometry, edge lengths, area/perimeter logic, and operation-specific units.
- Price determination does not use a generic tariff-rule engine. It delegates to `OperationPriceResolver`, which resolves an operation price from versioned imported rows with fallback behavior.

How automatic/base operations are defined:

- Observed: by `detail_type_operations` mappings, by hardcoded calculation branches for cutting and edging, and by canonical operation ids/names.

How quantity is calculated:

- Observed: from position dimensions, edge schemes, detail count, and calculation formulas inside the calculator/services.

How price is determined:

- Observed: through `OperationPriceResolver`, which prefers project-linked imported versions and falls back to median-like behavior when no explicit project-linked price is available.

Whether pricing supports conditions:

- Observed: only indirectly. Conditions are used to choose which operation applies, not to choose among multiple tariffs for the same operation.

Whether material parameters are used:

- Observed: yes for operation generation. Thickness, edge scheme, and material grouping influence which operation gets generated.
- Observed: no general proof was found that the same parameters drive tariff selection within one operation.

Whether tariff rules exist:

- Missing / not found: no first-class tariff-rule table or policy layer was found for operation pricing.

Whether one operation can have multiple applicable rates:

- Observed: yes only in the sense that multiple imported price rows may exist across versions/suppliers and the resolver chooses one context.
- Missing / not found: no generic rule-priority engine was found for selecting among multiple rates based on business conditions.

### Rule-based pricing capability assessment

Different price by material thickness:

- Observed: partially supported for choosing the operation row or cut/edge variant, not for pricing the same operation through formal tariff rules.
- Verdict: blocked for true tariff-rule pricing.

Different price by material type:

- Observed: partially supported through operation generation differences and supplier-specific imported operation prices.
- Verdict: partially supported, but not as a general tariff-rule model.

Different price by product characteristics:

- Missing / not found: no generic product-characteristic tariff mechanism.
- Verdict: blocked.

Fallback tariffs:

- Observed: yes, via resolver fallbacks and median/global selection behavior.
- Verdict: partially supported.

Prioritization of tariff rules:

- Missing / not found: no explicit tariff priority model.
- Verdict: blocked.

Inferred:

- The current automatic operations model supports deterministic generation better than deterministic rule-based pricing. It is an operation-selection engine with snapshot price lookup, not a tariff engine.

## 8. Manual operations and labor norm rates

### Manual operations

Observed:

- Manual operations are created as `project_manual_operations` rows referencing canonical `operations`.
- They store quantity and note-like project context, not a dedicated manual-operation price source record.
- When project operations are assembled, manual operations are merged into the same runtime pricing flow as automatic operations.
- Imported price lists can price manual operations indirectly because manual operations reuse canonical operations and `OperationPriceResolver`.

Missing / not found:

- No dedicated manual-operation evidence attachment model.
- No dedicated manual override source entity for a manual operation's applied price.
- No proof was found that a user can manually set a manual operation price together with first-class evidence and keep that as a structured provenance record.

Conclusion:

- Manual operations are proper domain entities for "operation assignment to project," but not proper domain entities for pricing provenance. Their price is borrowed from the operation pricing contour.

### Labor norm rates

Observed:

- Global norm-rate sources exist in `global_normohour_sources`.
- Project-side applied profile rates exist in `project_profile_rates` with fields such as fixed/locked markers, snapshots, and justifications.
- `ProjectProfileRateResolver` and `NormohourRateService` compute preview and effective rates from source sets.
- Labor works then copy or bind effective rate values into project-side rows.
- `ProfileRatesSection.vue` exposes justification, recalculation, lock/unlock, and deletion flows to users.

Whether they behave like a price list:

- Observed: partially. They behave like a specialized source-backed rate snapshot system.
- Inferred: they are conceptually similar to a price list, but the implementation is separate and profile-centric rather than version-centric.

Whether they are versioned and source-linked:

- Observed: source-linked yes, through snapshots/justification payloads and resolver logic.
- Observed: versioned no, not in the same explicit price-list-version sense used for imported material/operation prices.

Conclusion:

- Labor norm rates are closer to proper domain entities than manual operations pricing. They have a dedicated source-backed resolution contour, but it is isolated from the main imported price-version architecture.

## 9. Reporting and evidence model

Observed:

- Source links are stored for imported price versions through `price_list_versions` and `project_price_list_versions`.
- Files and hashes for imported sources are stored on `price_list_versions`.
- Material observation screenshots and snapshots are stored on `material_price_histories`, and newer generic assets are stored on `generic_evidence_assets`.
- Revision snapshots can include `price_justifications` and `evidence_summary`.
- There are dedicated PDF outputs for the main estimate, revision snapshot, price justification, and newer estimate evidence.
- The UI can inspect imported price-list versions and download original uploaded files through the audit surface.

Legacy evidence contour:

- `revision_runs`, `revision_run_items`, `evidence_artifacts`, and `evidence_assets` support price-justification and revision proof workflows.
- `docs/evidence-rollout-status.md` explicitly states known limitations, including no operations/labor/expenses support yet in that rollout status snapshot.

Newer evidence contour:

- `estimate_evidence_runs`, `estimate_evidence_items`, `evidence_records`, and `generic_evidence_assets` support generic item collection, resolution, and finalized evidence snapshots.
- Chrome generic capture can auto-link a proof record to exactly one unresolved evidence item based on normalized URL and cost component.

Whether reports can include appendices:

- Observed: yes, in a limited sense. Price-justification and evidence PDFs behave as appendices or proof packets.

Whether a user can open/download original sources:

- Observed: imported price-list files can be downloaded through version audit flows.
- Observed: screenshot proof can be opened when attached.

Whether each estimate line can point to supporting evidence:

- Observed: partially.
- Facade quote rows and imported operation version links provide provenance at the line-family level.
- Material observations can be linked to proof.
- Legacy and newer evidence items can point to subjects and proof records.

Missing / not found:

- No complete proof was found that every estimate line across materials, operations, labor, fittings, and expenses always has a first-class supporting evidence pointer.
- No uniform appendix generator was found that assembles all pricing domains from one provenance schema.

Hard conclusion:

- Evidence/reporting support exists and is non-trivial, but it is partial, split across two contours, and not aligned with one universal applied-price model.

## 10. Architectural strengths

Observed:

- Imported operation pricing is already versioned, source-aware, and decoupled from the canonical operation catalog.
- Facade pricing has the best implemented project-level provenance model through quote snapshots and aggregation metadata.
- `project_price_list_versions` gives the reporting layer a compact way to explain which imported sources participated in a project.
- Material catalog ingestion is strong: manual, parser, and Chrome-assisted capture all exist, and observation history is append-oriented.
- Generic evidence capture has deterministic URL normalization, duplicate detection, fresh-proof reuse, and auto-link behavior.
- Revision snapshots are built from one report DTO via `SnapshotService`, which gives published PDFs deterministic frozen state.
- Labor profile rates already support source snapshots, lock/fix semantics, and user-facing justifications.
- Frontend audit surfaces exist for imported price versions, evidence runs, and profile rates. This is not hidden plumbing only.

## 11. Architectural weaknesses and risks

### Data model problems

- Plate and edge applied prices still come from `materials.price_per_unit` inside `SmetaCalculator`. This matters because a mutable catalog field is acting as the applied estimate price without binding to a specific source snapshot. It blocks universal traceable pricing for core material cost drivers.
- `project_profile_rates` is not cleanly traceable in the standard migration chain. This matters because deployment and schema drift risk increase when a critical pricing table depends on schema dumps or unclear migration history. It blocks confident rollout and refactor safety.
- Fittings and expenses remain loose numeric project fields rather than source-backed price entities. This matters because those domains cannot join the universal pricing model without bespoke retrofit work. It blocks end-to-end price explainability.

### Coupling problems

- `ReportService` is coupled to the exact mixture of pricing mechanisms used by the calculators and project rows. This matters because every pricing-model change has to be reflected through report DTO shape and snapshot logic. It blocks low-risk refactoring.
- `ProjectPosition` mixes geometry, material references, facade quote aggregation results, and applied price state in one entity. This matters because one model carries both domain input and pricing outcome. It blocks clean separation of "what is being estimated" from "why this price applied".
- Evidence logic depends on current cost-driver DTO structure and route-level workflows rather than a stable applied-price contract. This matters because proof generation will keep breaking whenever estimate internals change. It blocks reusable appendix generation.

### Scalability problems

- Pricing logic is distributed across legacy calculators, newer services, controllers, and report assembly. This matters because adding new pricing rules requires touching multiple layers. It blocks controlled growth of pricing domains.
- Automatic operation pricing lacks a data-driven tariff-rule model. This matters because every new pricing condition trends toward more hardcoded branching. It blocks scaling to supplier-specific or characteristic-specific tariff policies.

### UX / workflow problems

- The real estimate editor is `ProjectEditorView.vue` while `SmetaEditorView.vue` is effectively inert. This matters because contour ownership is not obvious and increases maintenance confusion. It blocks predictable UI evolution.
- Users can inspect imported source versions and some evidence, but not all applied prices expose equally clear provenance. This matters because workflow expectations become inconsistent across domains. It blocks user trust in estimate proof.

### Traceability / audit problems

- `project_price_list_versions` tells which versions were used by the project, but not necessarily which exact applied price instance produced each material line. This matters because report-level provenance is weaker than line-level provenance. It blocks audit-grade explanation.
- `operation_prices` are traceable, but no symmetric generic evidence linkage was found for them. This matters because operations remain source-aware without becoming proof-aware. It blocks parity with newer evidence features.
- Material observations and generic evidence are bridged, but estimate application still often bypasses the observation id and reads the mutable material price field. This matters because proof can exist without being the actual applied source. It blocks defensible justification.

### Reporting problems

- Legacy revision evidence and newer estimate evidence both exist. This matters because two proof stacks compete for ownership of the same business outcome. It blocks one coherent reporting pipeline.
- Price-justification coverage is explicitly incomplete for some cost-driver categories. This matters because the system cannot yet claim complete estimate proof coverage. It blocks universal appendix generation.

### Future extensibility blockers

- The architecture assumes that some domains can remain numeric while others become source-backed. This matters because future universal pricing requires the opposite assumption. It blocks convergence.
- The system has no first-class applied-price abstraction. This matters because every new price-bearing contour will otherwise invent its own provenance pattern. It blocks safe long-term extension.

## 12. Gap analysis against target architecture

| Target capability | Status | Why |
| --- | --- | --- |
| Universal price-source model | blocked by current architecture | Applied prices are represented through incompatible mechanisms: direct material fields, imported operation rows, facade quote snapshots, and labor-rate snapshots. |
| Non-Excel price ingestion | partially supported | Materials already support parser/Chrome/manual observation capture, but those sources are not the mandatory applied-price mechanism for the estimate engine. |
| Unified operation pricing | partially supported | Operation pricing is centralized around imported snapshot rows and a resolver, but manual operations still piggyback on that flow and no tariff-rule abstraction exists. |
| Rule-based pricing for automatic operations | blocked by current architecture | Operation selection uses conditions, but pricing itself lacks a formal tariff-rule model with priorities and characteristics. |
| Traceable evidence for estimate prices | partially supported | Evidence records, revision proof, screenshots, and source links exist, but they do not cover all cost drivers through one mandatory applied-price/evidence chain. |
| Reusable report appendix generation | partially supported | Price-justification and evidence PDFs exist, but the underlying data model is split and coverage is incomplete by cost-driver category. |

Observed:

- The system already contains enough traceability primitives to support the target direction.

Inferred:

- The blocker is not absence of all building blocks. The blocker is that those building blocks were introduced in parallel rather than consolidated.

## 13. Recommended change strategy

### A. Minimal invasive changes

Expected benefit:

- Improves auditability quickly without destabilizing the whole estimate engine.

Technical scope:

- Introduce an explicit applied-price payload in report DTO rows for plates, edges, facades, operations, labor, fittings, and expenses.
- Stop reporting only project-level source versions; report exact source row ids where available.
- Add evidence linkage for operation price rows or for applied operation price selections.
- Add explicit provenance metadata to manual operations, fittings, and expenses even if they still store numeric values.

Implementation risk:

- Low to medium. Mostly additive, but touches report DTOs, snapshot schema, and evidence builders.

Likely impact radius:

- `ReportService`, PDF builders, evidence collectors, frontend evidence/report viewers.

### B. Medium refactor path

Expected benefit:

- Aligns applied estimate pricing across materials, operations, and labor without a full platform rewrite.

Technical scope:

- Introduce a project-side applied-price table or value object per estimate subject.
- Make plate and edge calculations resolve through a bound material price/observation record instead of `materials.price_per_unit`.
- Make manual operations persist the resolved price source, not only the selected operation id.
- Normalize labor profile-rate snapshots to the same provenance contract shape used by other applied prices.

Implementation risk:

- Medium. Calculation, reporting, revision snapshots, and some editor flows all change.

Likely impact radius:

- Project positions, calculators, operations controller flows, labor services, revision/evidence collectors, PDFs.

### C. Strategic redesign path

Expected benefit:

- Converts the system into a universal traceable estimate platform where every applied price is explainable and evidence-aware.

Technical scope:

- Create a first-class universal applied-price model with source snapshot, evidence links, resolution method, currency, validity/freshness, and explanation payload.
- Create a universal source registry that can represent imported price-list rows, Chrome observations, manual approvals, and labor source sets through one interface.
- Replace ad hoc operation pricing logic with a tariff-rule engine that supports conditions, fallback, and priority.
- Rebuild report and evidence generation to consume the universal applied-price model rather than contour-specific DTO assumptions.

Implementation risk:

- High. This is a domain redesign, not a local refactor.

Likely impact radius:

- Schema, services, controllers, reporting, evidence, extension bridge logic, and frontend editing flows.

## 14. Proposed next implementation blocks

### Block 1: Applied price provenance for report rows

Goal:

- Make the current report say exactly which source record produced each line total where possible.

Exact scope:

- Add per-line provenance fields to report DTOs and snapshots.
- Include source row ids, version ids, resolution method, and confidence/traceability level.

Dependencies:

- Existing report DTO and revision snapshot flow.

What should not be included in this block:

- No calculator rewrite.
- No new tariff engine.

### Block 2: Bind plate and edge pricing to explicit source records

Goal:

- Remove direct dependence on mutable `materials.price_per_unit` as the applied estimate truth.

Exact scope:

- Introduce explicit applied material price binding for plate and edge calculations.
- Persist the selected source row or approved observation id on project-side estimate data.

Dependencies:

- Block 1 provenance fields.

What should not be included in this block:

- No redesign of facade quote aggregation.
- No generic evidence UI expansion.

### Block 3: Manual operation pricing provenance

Goal:

- Make manual operations explainable at the same level as automatic operations.

Exact scope:

- Persist resolved operation price source on manual operation lines.
- Add optional manual override path with structured provenance payload.

Dependencies:

- Existing `OperationPriceResolver` behavior.

What should not be included in this block:

- No tariff-rule engine yet.

### Block 4: Unified evidence bridge for applied prices

Goal:

- Let materials, operations, and labor rates all point to generic evidence through one pattern.

Exact scope:

- Extend generic evidence linkage beyond material observations.
- Let applied price records reference `evidence_records` directly or through a stable join.

Dependencies:

- Block 2 or an equivalent applied-price binding concept.

What should not be included in this block:

- No PDF redesign yet.

### Block 5: Tariff-rule model for automatic operations

Goal:

- Replace hardcoded condition-to-operation-pricing assumptions with data-driven tariff resolution.

Exact scope:

- Introduce tariff rule entities and resolver priority order.
- Support conditions by thickness, material type, and product characteristics.
- Preserve existing operation generation while replacing only price selection logic first.

Dependencies:

- Stable applied operation pricing provenance.

What should not be included in this block:

- No attempt to redesign all estimate contours at once.

### Block 6: Converged appendix generation

Goal:

- Generate one reusable appendix/proof packet from one provenance model.

Exact scope:

- Make report appendix generation consume applied price provenance and generic evidence uniformly.
- Retire dual proof logic where feasible or clearly boundary it.

Dependencies:

- Blocks 1 through 4.

What should not be included in this block:

- No new catalog ingestion features.

## 15. Final verdict

Observed:

- The system already contains strong pieces worth preserving: versioned imported operation pricing, facade quote aggregation with project snapshots, material observation history with Chrome capture, generic evidence records, deterministic revision snapshots, and project profile-rate justifications.

What should definitely be preserved:

- Versioned `price_list_versions` and imported price rows.
- `project_position_price_quotes` for facade sourcing.
- `SnapshotService` and report-snapshot publication model.
- Generic evidence records/assets and deterministic auto-link behavior.
- Profile-rate snapshot and lock semantics.

What should definitely be redesigned:

- Direct use of mutable material numeric fields as applied estimate prices.
- The split between legacy revision evidence and newer estimate evidence as competing proof contours.
- The absence of a first-class applied-price abstraction.
- Automatic operation price selection without a formal tariff-rule model.

Whether the current architecture can be extended safely:

- Inferred: only for incremental auditability improvements. It cannot safely absorb universal source-backed pricing by adding more special cases to the current mix of numeric fields, snapshots, and side tables.

The single most dangerous false assumption in the current design:

- The most dangerous false assumption is that storing a current numeric price on the canonical domain object is good enough even after the system has already introduced versioned sources, evidence capture, and proof-oriented reporting. That assumption is exactly what prevents convergence into one traceable estimate architecture.

## Appendix A - Table-to-domain mapping

| Table / entity | Business meaning |
| --- | --- |
| `materials` | canonical material catalog entry |
| `material_price_histories` | observed material price events |
| `material_prices` | imported material price rows |
| `operations` | canonical operation catalog |
| `operation_prices` | imported operation tariff rows |
| `price_lists` | supplier price list definition |
| `price_list_versions` | exact imported price source snapshot |
| `project_price_list_versions` | project-level record of used imported versions |
| `project_positions` | estimate lines for physical items |
| `project_position_price_quotes` | facade quote evidence and aggregation input |
| `project_manual_operations` | user-added operation assignments |
| `project_labor_works` | labor estimate lines |
| `project_profile_rates` | applied project labor-rate snapshots |
| `global_normohour_sources` | upstream labor-rate source rows |
| `project_revisions` | immutable estimate snapshots |
| `revision_runs` / `revision_run_items` | legacy proof collection workflow |
| `estimate_evidence_runs` / `estimate_evidence_items` | newer generic proof collection workflow |
| `evidence_records` / `generic_evidence_assets` | generic supporting proof record and files |

## Appendix B - File responsibility map

| File | Responsibility |
| --- | --- |
| `server/app/Services/Smeta/SmetaCalculator.php` | live cost calculation engine |
| `server/app/Service/ReportService.php` | report DTO assembly and price-source reporting |
| `server/app/Services/PriceImport/PriceImportSessionService.php` | import session orchestration |
| `server/app/Services/PriceImport/PriceImportExecutorV2.php` | imported price persistence |
| `server/app/Services/PriceImport/OperationPriceResolver.php` | runtime operation price choice |
| `server/app/Http/Controllers/Api/ProjectPositionController.php` | project-position persistence and facade price provenance |
| `server/app/Http/Controllers/Api/ProjectsOperationsController.php` | automatic plus manual operations assembly |
| `server/app/Services/NormohourRateService.php` | labor-rate calculation from sources |
| `server/app/Services/ProjectProfileRateResolver.php` | project effective labor-rate resolution |
| `server/app/Services/ChromeExtractService.php` | material extraction and observation creation |
| `server/app/Services/GenericChromeCaptureService.php` | generic evidence capture and auto-link |
| `server/app/Services/EvidenceRunItemCollector.php` | newer evidence item generation |
| `server/app/Http/Controllers/Api/RevisionRunController.php` | legacy revision proof workflow |
| `server/app/Http/Controllers/Api/EvidenceRunController.php` | newer evidence workflow |
| `server/app/Services/SnapshotService.php` | revision snapshot creation from report DTO |
| `client/src/views/ProjectEditorView.vue` | main estimate workspace |
| `client/src/components/PriceImportDialog.vue` | import workflow UI |
| `client/src/components/ProfileRatesSection.vue` | labor-rate justification UI |
| `client/src/components/evidence/EvidenceRunPanel.vue` | evidence workflow UI |
| `client/src/views/PriceListVersionShow.vue` | imported source audit UI |

## Appendix C - Open questions / ambiguities

Observed uncertainty:

- `ReportService::buildProjectMeta()` expects normohour source fields such as `name`, `rate`, and `sort_order`, but the exact current alignment with `ProjectNormohourSource` was not fully validated against all live schema variants.
- `project_profile_rates` exists in the schema dump and is used by code, but its original creation migration was not found in the standard migration set reviewed for this report.

Missing / not found:

- A definitive single file declaring the supported priority order for all operation pricing fallbacks across every controller and report path.
- A definitive proof that legacy revision evidence is fully retired or intentionally meant to coexist permanently with newer estimate evidence runs.