# UI Refactor Plan

## 1. Goal Summary

Подготовить безопасный порядок приведения frontend к единому строгому enterprise UI без массового переписывания страниц и без риска сломать бизнес-логику.

На первом этапе рефакторинг должен идти через стандартизацию wrappers, tokens и повторяемых UI-паттернов. После этого страницы переводятся блоками, начиная с низкорисковых реестров и заканчивая редактором сметы, pricing/evidence и admin.

## 2. Current Architecture Involved

### Observed

- Stack: Vue 3, Vuetify 3, Pinia, Vue Router, Vite.
- Global Vuetify theme/defaults: `client/src/plugins/theme.ts`.
- Global stylesheet: `client/src/styles/design-system.scss`.
- Main app bootstrap imports design system from `client/src/main.ts`.
- Shared layout components:
  - `client/src/components/layout/PageContainer.vue`
  - `client/src/components/layout/PageHeader.vue`
  - `client/src/components/layout/SectionCard.vue`
  - `client/src/components/layout/TableToolbar.vue`
  - `client/src/components/layout/EmptyState.vue`
  - `client/src/components/layout/ButtonGroup.vue`
- Shells/layouts:
  - `client/src/layouts/AppShell.vue`
  - `client/src/layouts/AdminLayout.vue`
  - `client/src/layouts/ParserLayout.vue`
  - `client/src/components/workspace/*`
  - `client/src/components/settings/shell/*`
- High-density business screens include:
  - `client/src/views/ProjectEditorView.vue`
  - pricing screens
  - evidence components
  - import screens
  - admin screens
  - material/catalog screens

### Inferred

- The project is in a partial migration: global DS exists, but local screen styles still define many competing patterns.
- The safest path is additive: create missing shared primitives, migrate one screen type at a time, and avoid touching API/data contracts.

## 3. Affected Files / Directories For Future Work

### Foundation

- `client/src/plugins/theme.ts`
- `client/src/styles/design-system.scss`
- `client/src/components/layout/*`
- future `client/src/components/ui/*` or `client/src/components/design-system/*`

### Shells

- `client/src/layouts/AppShell.vue`
- `client/src/layouts/AdminLayout.vue`
- `client/src/layouts/ParserLayout.vue`
- `client/src/layouts/shell/*`
- `client/src/components/workspace/*`
- `client/src/components/settings/shell/*`

### Page Groups

- Project list/editor: `client/src/views/ProjectsView.vue`, `client/src/views/ProjectEditorView.vue`
- Pricing: `client/src/views/Pricing*.vue`, `client/src/components/pricing/*`
- Finished products: `client/src/views/ProductsView.vue`, `client/src/views/FinishedProductPricingView.vue`, `client/src/components/products/*`
- Evidence: `client/src/components/evidence/*`
- Materials/catalog: `client/src/views/MaterialsView.vue`, `client/src/views/MaterialsCatalogView.vue`, `client/src/components/catalog/*`
- Suppliers/price lists/imports: `client/src/views/SuppliersIndex.vue`, `SupplierShow.vue`, `PriceList*.vue`, `PriceImportsView.vue`, `client/src/components/imports/*`
- Settings/security: `client/src/views/UserSettingsView.vue`, `client/src/components/settings/*`, `client/src/components/security/*`
- Admin: `client/src/views/admin/*`, `client/src/components/admin/*`, `client/src/components/notifications/*`
- Parser: `client/src/views/Parser*.vue`, `client/src/layouts/ParserLayout.vue`
- Ideas/support: `client/src/views/ideas/*`, `client/src/components/ideas/*`, `client/src/components/support/*`

## 4. Page Type Groups

### A. Standard Registry Pages

Examples:
- Projects
- Materials
- Products
- Suppliers
- Price list versions
- Operations
- Price imports

Common pattern:
- `PageContainer`
- `PageHeader`
- filters/search toolbar
- `SectionCard`
- `v-data-table`
- row actions
- empty/no-results state

Priority: high, because these pages define the everyday SaaS rhythm and are lower risk than editor/pricing internals.

### B. Dense Workspace / Editor

Examples:
- `ProjectEditorView`
- workspace sidebar/header/health bar
- position table
- bulk actions
- position drawer/dialog

Common pattern:
- custom workspace shell
- sticky toolbar
- dense data table
- side navigation
- dialog/drawer forms
- health/error/status bars

Priority: later. This is high-risk because UI structure is tied to calculation and editing workflows.

### C. Pricing / Evidence Workflows

Examples:
- Pricing operations
- Pricing labor
- Finished product pricing
- Evidence drawers/dialogs
- Evidence run panels

Common pattern:
- table + right detail panel
- status chips
- calculation summary
- evidence attachments
- source lifecycle actions

Priority: medium-high, but after shared detail panel/status primitives exist.

### D. Settings / Admin Sectioned Screens

Examples:
- User settings
- Admin system
- Admin rules/problems/ideas
- Security/account panels

Common pattern:
- section navigation
- forms
- sticky footer/save state
- tabs
- data-heavy admin tables

Priority: medium. Good candidate after form and section-nav primitives.

### E. Parser Module

Examples:
- Parser dashboard/history/settings/supplier config
- `ParserLayout`

Common pattern:
- separate shell
- English labels still present
- dashboard/status/admin-like screens

Priority: medium-low unless parser is actively used by end users.

### F. Ideas / Support / Notification UI

Examples:
- Ideas pages
- support chat
- notifications panel

Common pattern:
- feed/list cards
- comments
- attachments
- chat/message surfaces

Priority: low-medium. These need consistency, but should not block operational UI.

### G. Auth / Onboarding

Examples:
- Login/reset/admin login/auth components

Common pattern:
- separate visual tone
- auth card/hero
- security dialogs

Priority: low for enterprise workspace standard, unless brand consistency is required immediately.

## 5. Top UI Problems

### Observed

- Several shell systems coexist: main app, admin, parser, workspace editor.
- Shared layout components exist but are not universal.
- Empty states are duplicated: shared `EmptyState` plus many local `.empty-state`, `.operations-empty-state`, `.fp-pricing-empty-state`, etc.
- Right-side/detail panels are duplicated: admin inspector, pricing operation drawer, price imports drawer, account drawer, project position drawer.
- Status display is inconsistent: `v-chip`, local badges, local status labels/colors.
- Tables are styled globally, but many screens add local table wrappers and cell styles.
- Forms mix Vuetify defaults, local stacks, `v-row`, custom card sections and inline styles.
- Local scoped styles contain many hardcoded dimensions, padding, border-radius and raw colors.
- Parser/Admin still contain English UI labels in user-visible places.
- Some screens use technical microcopy such as `strict`, `pricing`, `source`, `LLM`, `History & Analytics`, `System Status`.

### Inferred

- The biggest inconsistency is not lack of theme, but lack of standardized composition components.
- A pure global CSS pass would have high blast radius and could break dense screens visually.

## 6. What To Standardize First

1. Page composition:
   - `PageContainer`
   - `PageHeader`
   - `SectionCard`
   - standard content stack classes

2. Data table shell:
   - search/filter/action toolbar
   - empty/loading/error states
   - row actions
   - status chips

3. Detail panel/drawer:
   - right-side panel anatomy
   - mobile behavior
   - header/body/actions
   - meta rows and summary blocks

4. Forms:
   - form sections
   - field rows
   - sticky action footer
   - validation/error presentation

5. Status and microcopy:
   - shared status map
   - common labels
   - remove unnecessary English/technical labels from user-facing screens

## 7. Reusable Components / Wrappers To Create Or Improve

### Improve Existing

- `PageContainer`: add documented density/max-width variants.
- `PageHeader`: add optional back action, breadcrumbs/meta slot, compact variant.
- `SectionCard`: clarify when to use header/actions and when to use plain section.
- `TableToolbar`: add standard props or named slots for search, filters, bulk actions, right actions.
- `EmptyState`: add size/density variant and optional inline/table mode.
- `ButtonGroup`: document primary/secondary/destructive ordering.

### Create New

- `AppDataTableShell`
  - owns toolbar + table + empty/loading/error slots.
  - keeps table composition consistent without changing business data logic.

- `AppRowActions`
  - icon actions with tooltip, aria-label, loading/disabled.
  - replaces local row action groups.

- `StatusChip`
  - receives domain/status and maps to label/color/variant.
  - can be extended per domain through local maps.

- `AppDetailDrawer`
  - desktop right drawer, mobile fullscreen/bottom behavior.
  - consistent header/body/actions.

- `AppDetailMetaGrid`
  - standard label/value blocks for drawer summaries.

- `AppFormSection`
  - title, hint, fields slot, optional aside.
  - replaces local `.position-form-section`, `.applicability-card`, `.usd-waste-row` where appropriate.

- `AppActionFooter`
  - sticky save/cancel/dirty state for settings and long forms.

- `AppStateBlock`
  - standardized loading/empty/error/info block for non-table content.

- `AppTabs`
  - wrapper over `v-tabs` for route-bound tabs and mobile scroll behavior.

## 8. Safe Refactoring Order

### Block 1. Documentation And Inventory

Goal:
- Keep `UI_STANDARD_DRAFT.md` and `UI_REFACTOR_PLAN.md` as the working baseline.

In scope:
- Docs only.

Out of scope:
- No Vue/CSS behavior changes.

Acceptance:
- Documents describe current patterns, risks and migration order.

Validation:
- Read-only/source review.

Status:
- This block is what this task implements.

### Block 2. Foundation Component Contracts

Goal:
- Define exact props/slots for missing shared primitives before migration.

In scope:
- `client/src/components/layout/*`
- new `client/src/components/ui/*` or `client/src/components/design-system/*`
- optional Story/showcase update in `DesignSystemShowcase.vue`

Out of scope:
- No page migrations except a tiny showcase/pilot if needed.

Acceptance:
- Components exist and can be used without changing business logic.

Validation:
- `npm run type-check`
- targeted visual smoke on showcase/pilot screen.

### Block 3. Low-Risk Registry Pilot

Goal:
- Convert one already close-to-standard registry page to the final pattern.

Recommended pilot:
- `ProjectsView.vue`.

Why:
- It already uses `PageContainer`, `PageHeader`, `SectionCard`, table and empty states.
- `SuppliersIndex.vue` is now legacy/internal and should not be used as a primary user-facing pilot.

Out of scope:
- No API changes.
- No calculation/pricing/evidence logic.

Acceptance:
- Same data, same actions, unified toolbar/table/empty/status/action patterns.

Validation:
- build/type-check.
- manual desktop/mobile smoke.
- create/open/delete flow if available in local env.

### Block 4. Registry Batch

Goal:
- Apply the proven registry pattern to similar screens.

Candidates:
- Materials
- Products
- Suppliers
- Price list versions
- Price imports list
- Operations list where safe

Out of scope:
- Do not refactor complex drawers yet unless covered by `AppDetailDrawer`.

Acceptance:
- Same functionality, unified page/header/toolbar/table/empty states.

### Block 5. StatusChip And Microcopy Pass

Goal:
- Normalize repeated statuses and remove accidental technical language.

In scope:
- status chip maps for projects, operations, evidence, pricing sources, parser states.
- user-visible labels.

Out of scope:
- No backend enum changes.
- No API contract renames.

Acceptance:
- UI labels are consistent while payload values remain unchanged.

### Block 6. Detail Drawer Standardization

Goal:
- Introduce `AppDetailDrawer` and migrate one right-side panel.

Recommended pilot:
- `PriceImportsView` drawer or `PricingOperationsView` drawer.

Out of scope:
- No pricing calculation change.
- No source/evidence API behavior change.

Acceptance:
- Same actions and data, unified drawer anatomy.

### Block 7. Forms And Settings

Goal:
- Standardize sectioned forms and sticky action footers.

Candidates:
- `UserSettingsView`
- `ProjectDefaultsView`
- account/security panels
- operation/source dialogs

Out of scope:
- No persistence contract changes.

Acceptance:
- Same payload, clearer form sections, consistent validation/loading/dirty state.

### Block 8. Pricing / Evidence UI

Goal:
- Unify high-risk business workflows after primitives are stable.

Candidates:
- `FinishedProductPricingModule`
- `PricingOperationsView`
- `PricingLaborView`
- `EvidenceDrawer`
- evidence manager dialogs

Out of scope:
- No recalculation logic changes.
- No evidence lifecycle changes.

Acceptance:
- Same business behavior, improved consistency of source cards, tables, status, detail panels.

### Block 9. Project Editor Workspace

Goal:
- Bring the editor to the shared workspace/table/drawer standard carefully.

In scope:
- workspace header/sidebar patterns
- dense position table shell
- bulk toolbar
- position form sections

Out of scope:
- No calculator changes.
- No import/export/PDF/evidence revision behavior changes.

Acceptance:
- Editor remains dense and fast; keyboard/mouse workflows still work.

### Block 10. Shell Unification

Goal:
- Align `AppShell`, `AdminLayout`, `ParserLayout` and workspace shell principles.

Out of scope:
- No route restructuring unless explicitly approved.

Acceptance:
- Shells use shared nav/topbar tokens and behavior where practical.

## 9. Backward Compatibility Concerns

- UI wrappers must preserve slots/events and not change API payloads.
- Status chip labels must not rename backend enum values.
- Table migrations must preserve sorting, filtering, row click, selection and action loading states.
- Drawer migrations must preserve lifecycle side effects: fetch on open, refresh after save, dirty state, selected row.
- Settings/forms must preserve serialization and validation behavior.
- Project editor changes have the highest risk because visual changes can affect dense editing workflows.

## 10. Targeted Validation Plan

For every implementation block:

- Run targeted type/build checks where feasible:
  - `cd client && npm run type-check`
  - `cd client && npm run build-only` or full `npm run build` when scope is broader.
- Manual desktop smoke:
  - page loads
  - search/filter
  - table row hover/actions
  - empty state if easy to trigger
  - dialog/drawer open/close
  - save/delete loading and disabled states
- Manual mobile smoke:
  - page header/actions wrap correctly
  - toolbar filters wrap/scroll correctly
  - drawer/dialog behavior is usable
  - no overlapping text/buttons
- Theme smoke:
  - dark and light if changed area uses tokens/surfaces.

## 11. Recommended Next Block

Next recommended block: Foundation Component Contracts.

Do not start with `ProjectEditorView` or pricing/evidence internals. First create or finalize:

- `AppDataTableShell`
- `StatusChip`
- `AppRowActions`
- `AppDetailDrawer`
- `AppFormSection`
- documented variants for existing `PageHeader`, `SectionCard`, `EmptyState`, `TableToolbar`

After that, use `ProjectsView.vue` as the low-risk registry pilot.

## 11.1 Navigation Decisions

- `/suppliers` is no longer a canonical user-facing section.
- Suppliers are created and edited from the finished-products pricing source flow.
- Supplier routes remain as a legacy/internal compatibility layer for deep links, price-list versions and audit flows.
- Do not include `SuppliersIndex.vue` as a primary target in future UI migration batches unless supplier IA is explicitly reopened.

## 12. Screens To Inspect Deeper Before Any Refactor

- `ProjectsView.vue`: best registry pilot.
- `SuppliersIndex.vue`: legacy/internal compatibility screen, not a primary user-facing migration target.
- `MaterialsView.vue` and `MaterialsCatalogView.vue`: data-heavy catalog patterns.
- `PricingOperationsView.vue`: right drawer + pricing summary + source lifecycle.
- `FinishedProductPricingModule.vue`: cards + profile form + sources table + evidence actions.
- `EvidenceDrawer.vue`: dialog/detail/list pattern and evidence status language.
- `UserSettingsView.vue` + `components/settings/shell/*`: form section navigation and sticky save pattern.
- `AdminLayout.vue` + `views/admin/AdminSystemView.vue`: admin shell, inspector and tabs.
- `ParserLayout.vue` + parser views: separate shell and English microcopy.
- `ProjectEditorView.vue` + `components/workspace/*`: high-risk workspace/editor patterns.

## 13. Design-System Base Components

These components should become the base of the UI system:

- `PageContainer`
- `PageHeader`
- `SectionCard`
- `TableToolbar`
- `EmptyState`
- `ButtonGroup`
- `AppDataTableShell`
- `StatusChip`
- `AppRowActions`
- `AppDetailDrawer`
- `AppDetailMetaGrid`
- `AppFormSection`
- `AppActionFooter`
- `AppStateBlock`
- `AppTabs`
