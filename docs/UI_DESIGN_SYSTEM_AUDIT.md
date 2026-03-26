# UI Design System Audit & Roadmap
> Smeta Expert — Vue 3 + Vuetify 3 Admin/SaaS Product  
> Audited: March 2026

---

## 1. Executive Summary

The product has the bones of a good design system but accumulates three distinct visual sub-languages that were never reconciled. The result is a UI that is _functional but inconsistent_: screens built by different people at different times look like they belong to different products.

The biggest single credibility problem for a B2B audience is **inconsistent visual weight** — some screens are clean and flat, others carry heavy card nesting, legacy Vuetify elevation, and decorative colours that undercut the professional tone.

A targeted, phased cleanup of ~15 specific issues can bring the product to a consistent, enterprise-grade appearance without a full redesign.

---

## 2. Key Design Problems

### 2.1 Four Competing Style Systems (Critical)

The project currently has **four independent CSS files** that each define their own surface, spacing, and colour rules:

| File | What it defines | Conflict |
|---|---|---|
| `assets/base.css` | Vue scaffolding tokens (`--vt-c-*`, `--color-*`) | Never used by any actual components |
| `assets/main.css` | Body/layout resets | Duplicates Vuetify resets |
| `assets/soft-cards.css` | Card/surface classes | Uses hardcoded `rgba(0,0,0,0.08)` borders |
| `styles/design-system.scss` | The real system | Good coverage but leaks hardcoded Tailwind values |

`base.css` and `main.css` are effectively dead — no component references `--vt-c-*` or `--color-background`. They add confusion without adding value.

### 2.2 Hardcoded Colour Values Throughout (Critical)

Every colour in the system should come from a theme token. The current code has:

```scss
/* design-system.scss — all hardcoded, none theme-aware */
background: rgba(100, 116, 139, 0.5);   /* slate-500 — switch off */
background: rgba(56, 189, 248, 0.5);    /* sky-400 — switch on (semitransparent!) */
background: rgba(148, 163, 184, 0.12);  /* slate-400 — hover */
background: rgba(148, 163, 184, 0.09);  /* slate-400 — table row hover */
```

```css
/* IosToggle.vue — Apple-specific colour */
background-color: #34c759;  /* iOS system green */
```

These values are disconnected from the Vuetify theme and will break in dark mode.

### 2.3 Two Competing Toggle/Switch Components (Critical)

- `IosToggle.vue`: custom, iOS-sized (50×30 px), Apple green, visually large for a web table
- `v-switch` (Vuetify): semi-transparent active track, misaligned label baseline

Neither is cross-referenced or documented. Screens use whichever was passed down by the author.

### 2.4 Button Variant Chaos (High)

Buttons use all five Vuetify variants without a documented hierarchy:

| Screen | Primary action | Secondary | Destructive |
|---|---|---|---|
| `SuppliersIndex` | `color="primary"` flat | `variant="text"` | — |
| `AdminLlmSettings` | `variant="tonal"` | `variant="tonal"` | — |
| `ParserSettings` | `variant="elevated"` warning | `variant="elevated"` success | — |
| `AdminUsersTab` | `variant="tonal"` | `variant="text"` icon | — |
| `ProjectsView` | `variant="flat"` primary | — | `variant="text"` error icon |

`variant="elevated"` appears on buttons in `ParserSettings` only, reintroducing shadows that the design system explicitly zeros out.

### 2.5 Card Nesting / Double-Surface Problem (High)

`UserSettingsView` wraps `v-card variant="outlined"` _inside_ a `SectionCard` (which itself renders a `v-card`). This creates:

- Two visible card outlines
- Unintended visual depth
- Padding that compounds (`v-card-text` padding + inner card padding)

The same pattern appears in `AdminLlmSettings` and `WorkProfilesView`.

### 2.6 Parser Screens Completely Outside the System (High)

`ParserDashboard.vue` and `ParserSettings.vue`:
- Use raw `v-container fluid` instead of `PageContainer`
- Use `elevation="2"` cards instead of flat bordered cards
- Use English UI strings while the rest of the app is Russian
- Contain decorative animations (`activity-pulse`, `pulse-icon`) inconsistent with the enterprise tone
- Use `variant="elevated"` on buttons

### 2.7 Inconsistent Filter/Search Toolbar Patterns (Medium)

Each view implements its own above-table controls layout:

| View | Pattern |
|---|---|
| `SuppliersIndex` | `v-row dense` + `v-col` |
| `ProjectsView` | Custom `.pj-controls` div |
| `WorkProfilesView` | Custom `.filters-row` div with inline `max-width` |
| `MaterialsView` | `v-row dense` without density on inputs |
| `AdminUsersTab` | `v-card` + `v-card-text` with `d-flex flex-wrap` |

Five different patterns for the same UI function.

### 2.8 Tabs Visual Inconsistency (Medium)

The design system overrides `v-tabs` to render as a **pill group** (bordered container, tonal active state). `AdminPanelView` relies on Vuetify's default **underline style** and doesn't get the pill treatment because it doesn't use the overridden class properly. The result: two tab styles on different screens.

### 2.9 Missing Interaction States (Medium)

- No `focus-visible` ring explicitly defined for custom components (`IosToggle`, badge spans, link anchors)
- No `disabled` state documented or consistently styled
- No `error` state for form fields — Vuetify handles these but there's no global style to unify colour, border, and helper text appearance
- Empty states only exist in `ProjectsView`; `MaterialsView`, `SuppliersIndex`, and most admin tabs lack them

### 2.10 Spacing Mixed With Utility Classes (Low–Medium)

Design system tokens `--ds-space-*` co-exist with Vuetify utility classes (`ma-6`, `pa-3`, `mb-4`, `ga-3`) and hardcoded pixel values (`padding: 20px`, `max-width: 350px`, `style="max-width: 250px"`). This means component spacing is not predictable or extractable.

---

## 3. Recommended Design Direction

### Philosophy: Structured Flat Enterprise

> Clean, low-elevation surfaces with strong typographic hierarchy. Visual weight comes from borders and spacing, not shadows. Colour is functional, not decorative.

**Reference points**: Linear, Notion, Vercel dashboard, GitHub Issues — not Material Design defaults, not iOS consumer apps.

### Core Principles

1. **Single surface layer**: Content lives on `surface` over `background`. Only modals/drawers introduce a second layer.
2. **Borders as separators**: Subtle 1 px borders at `rgba(on-surface, 0.10)` — no shadows except modals (single, soft elevation).
3. **Colour is semantic**: Primary = action, Success = ok, Warning = caution, Error = danger. Never use colour for decoration.
4. **Consistent sizing**: All interactive controls are 36 px tall (compact density). Table rows are 44 px.
5. **Rhythm over individuality**: Every section, card, and control follows the same spacing scale. Creativity is in content, not layout.

---

## 4. Design-System Foundations

### 4.1 Spacing Scale

```scss
--ds-space-2:  2px;   // icon inner gap, chip padding
--ds-space-4:  4px;   // tight inline gap
--ds-space-8:  8px;   // button gap, form field gap
--ds-space-12: 12px;  // card padding (compact)
--ds-space-16: 16px;  // standard card padding, section gap
--ds-space-24: 24px;  // between sections inside a page
--ds-space-32: 32px;  // between major page sections
--ds-space-48: 48px;  // page vertical margins
```

**Rule**: Components use only these values. Never write `px` values inline.

### 4.2 Border-Radius Scale

```scss
--ds-radius-4:  4px;   // tags, inline badges
--ds-radius-8:  8px;   // buttons, chips, table
--ds-radius-12: 12px;  // cards, inputs, dialogs
--ds-radius-16: 16px;  // modals (large)
--ds-radius-full: 9999px; // pills, toggles
```

**Current problem**: Buttons use `--ds-radius-12` (12 px). For enterprise, reduce to `--ds-radius-8` (8 px). 12 px on a 32 px height button looks very round/consumer.

### 4.3 Typography Scale

```scss
// Page title
--ds-text-page-title:    1.375rem / 600 / -0.01em
// Section heading
--ds-text-section-title: 1rem     / 600 / 0
// Card heading
--ds-text-card-title:    0.9375rem / 600 / 0
// Body / table cell
--ds-text-body:          0.875rem  / 400 / 0
// Caption / helper
--ds-text-caption:       0.8125rem / 400 / 0.01em
// Label / chip text
--ds-text-label:         0.75rem   / 500 / 0.03em uppercase
```

**Current problem**: `saas-page-header__title` is `1.5rem`. Reduce to `1.375rem` — more contained, more enterprise.

### 4.4 Colour Tokens (Semantic Roles)

Replace all hardcoded hex/rgba values with theme-relative tokens:

```scss
// Surfaces
--ds-surface-page:       rgb(var(--v-theme-background))
--ds-surface-card:       rgb(var(--v-theme-surface))
--ds-surface-overlay:    rgb(var(--v-theme-surface))

// Borders
--ds-border-default:     rgba(var(--v-theme-on-surface), 0.10)
--ds-border-strong:      rgba(var(--v-theme-on-surface), 0.18)
--ds-border-focus:       rgb(var(--v-theme-primary))

// Text
--ds-text-primary:       rgba(var(--v-theme-on-surface), 1.0)
--ds-text-secondary:     rgba(var(--v-theme-on-surface), 0.64)
--ds-text-tertiary:      rgba(var(--v-theme-on-surface), 0.40)
--ds-text-disabled:      rgba(var(--v-theme-on-surface), 0.30)

// Interactive surfaces
--ds-hover-bg:           rgba(var(--v-theme-on-surface), 0.05)
--ds-active-bg:          rgba(var(--v-theme-primary), 0.10)
--ds-selected-bg:        rgba(var(--v-theme-primary), 0.08)

// Switch / toggle
--ds-switch-off-track:   rgba(var(--v-theme-on-surface), 0.25)
--ds-switch-on-track:    rgb(var(--v-theme-primary))
--ds-switch-thumb:       rgb(var(--v-theme-surface))
```

### 4.5 Elevation Scale

```scss
// No elevation: all cards, panels, toolbars
--ds-shadow-none: none;

// Modals and drawers only
--ds-shadow-modal: 0 8px 32px rgba(0, 0, 0, 0.12), 0 2px 8px rgba(0, 0, 0, 0.06);

// Floating menus / dropdowns
--ds-shadow-dropdown: 0 4px 16px rgba(0, 0, 0, 0.10);
```

**Rule**: `box-shadow: none` on everything except `.v-overlay__content` (dialogs), navigation drawers (right-side edge), and dropdown menus.

### 4.6 Component Sizing

```scss
--ds-control-height-sm:  28px;   // compact chips, secondary badges
--ds-control-height-md:  36px;   // all form inputs, buttons (compact density)
--ds-control-height-lg:  44px;   // table rows, primary CTA buttons
--ds-icon-size-sm:       16px;   // inline icons in text
--ds-icon-size-md:       18px;   // button icons, table action icons
--ds-icon-size-lg:       20px;   // navigation icons
--ds-icon-size-xl:       24px;   // empty-state icons (×2-3 as needed)
```

---

## 5. Component-by-Component Recommendations

### 5.1 Button

**Hierarchy (strict):**

| Level | Variant | Use |
|---|---|---|
| Primary | `color="primary" variant="flat"` | One per screen/section, main CTA |
| Secondary | `variant="outlined"` | Equal-weight alternatives |
| Ghost | `variant="text"` | Low-priority actions: cancel, refresh |
| Danger primary | `color="error" variant="flat"` | Destructive confirm actions only |
| Danger ghost | `color="error" variant="text"` | In-row delete icons |

**Never use** `variant="elevated"` — it reintroduces shadows.  
**Never use** `variant="tonal"` as a primary action — reserve for secondary in colour-differentiated contexts.

**Sizes:**
- Default: `32px` height (current `min-height: 32px` is correct)
- Table row actions: `28px` icon-only buttons
- Page primary CTA: `36px` (add `size="default"` or override `min-height: 36px`)

**Radius:** Change from `--ds-radius-12` to `--ds-radius-8` for buttons only:
```scss
.v-btn {
  border-radius: var(--ds-radius-8) !important;
}
```

**Icon consistency:**
- Always use `<v-icon size="18">mdi-*</v-icon>` as a child inside `v-btn` (not `icon=` prop) so the size is explicit
- Exception: icon-only `v-btn` with `icon` prop is fine, but set `width: 32px; height: 32px` globally

### 5.2 Input / Select / Textarea

**Standard configuration for all form controls:**
```html
<v-text-field
  variant="outlined"
  density="compact"
  hide-details="auto"
/>
```

**Do:**
- Always `variant="outlined"` — clear affordance in both light and dark
- Always `density="compact"` — 36 px height
- Use `hide-details="auto"` so error messages appear only when needed
- Use `prepend-inner-icon` not `prepend-icon` to keep the icon inside the border

**Don't:**
- Mix `variant="solo"` and `variant="outlined"` on the same form
- Use `density="comfortable"` on any control inside a card (only for prominent standalone forms)
- Apply inline `style="max-width: 250px"` — use layout classes or container constraints instead

**Missing in `MaterialsView`:** no `variant` prop specified on several `v-select` — results in Vuetify default which may differ from `outlined`.

### 5.3 Switch / Toggle

**Eliminate `IosToggle.vue`** from all product screens. It uses Apple-specific green and Apple sizing. Replace everywhere with Vuetify's `v-switch` using the corrected global styles below.

**Corrected switch styles (full replacement for current design-system.scss section):**

```scss
// Switch track
.v-switch .v-switch__track {
  border-radius: var(--ds-radius-full) !important;
  width: 36px;
  height: 20px;
  opacity: 1;
  background: var(--ds-switch-off-track);
  transition: background 0.18s ease;
}

// Active state uses the theme primary, not a hardcoded sky blue
.v-switch.v-selection-control--dirty .v-switch__track {
  background: var(--ds-switch-on-track) !important;
  opacity: 1;
}

// Thumb: smaller relative to track, no shadow
.v-switch .v-switch__thumb {
  width: 14px;
  height: 14px;
  background: var(--ds-switch-thumb);
  box-shadow: none;
}

// Label alignment: ensure switch and label are always flex-aligned
.v-switch .v-selection-control__wrapper {
  align-self: center;
}

.v-input--density-compact.v-switch {
  --v-input-control-height: 36px;
}
```

**Layout rule to prevent text overlap:**  
Always set `hide-details` on switches placed in-line with other controls. When a switch needs a description, place it in a dedicated `v-row` column:

```html
<!-- DO: switch in its own column with enough space -->
<v-col cols="12" sm="6">
  <v-switch
    v-model="value"
    label="Enable feature"
    color="primary"
    density="compact"
    hide-details
  />
</v-col>

<!-- DON'T: switch next to other controls in tight space -->
<div class="d-flex align-center ga-3">
  <v-switch label="Enable very long label text" />
  <v-text-field label="Input" />  <!-- label will overlap switch -->
</div>
```

### 5.4 Table

**Standard `v-data-table` config:**

```html
<v-data-table
  :headers="headers"
  :items="items"
  density="comfortable"
  :hover="true"
  :items-per-page="25"
/>
```

The global design system already handles table styling well. Additional rules:

- **Table toolbar** (controls above the table): standardise on `TableToolbar` pattern (see §6 Quick Wins)
- **Action column**: always rightmost, always `width: 80px` or `width: 120px`
- **Action buttons in rows**: always `variant="text"`, `size="small"`, icon-only, `28px` square
- **No expansion panels inside tables** — use a detail drawer instead
- **Empty state**: always provide a `#no-data` slot with icon + title + description + optional CTA

### 5.5 Badge / Chip / Status Indicator

**Use `v-chip` with `variant="tonal"` for all status badges:**
```html
<v-chip size="small" color="success" variant="tonal">Активен</v-chip>
<v-chip size="small" color="grey" variant="tonal">Неактивен</v-chip>
<v-chip size="small" color="warning" variant="tonal">В ожидании</v-chip>
```

**Custom `.pj-badge` spans in `ProjectsView`** should be replaced with `v-chip`. Custom CSS badges are harder to maintain, lack accessibility attributes, and break dark mode.

**Rule**: Never use `v-chip color="primary" variant="elevated"` (introduces shadow + strong colour — too heavy for status labels).

### 5.6 Tabs

Currently broken: design-system pill style vs. AdminPanelView underline style.

**Decision: use pill-group tabs everywhere** (already defined in design-system.scss). Apply to `AdminPanelView`:

```html
<!-- AdminPanelView.vue -->
<SectionCard>
  <template #header-actions>
    <v-tabs v-model="activeTab" color="primary">
      ...
    </v-tabs>
  </template>
  <v-window v-model="activeTab">...</v-window>
</SectionCard>
```

If tabs are too many for pill style (AdminPanelView has 10+ tabs), use a sidebar navigation within the page instead.

### 5.7 Modal / Dialog

```scss
// Global dialog rules
.v-overlay__content .v-card {
  border-radius: var(--ds-radius-16) !important;
  box-shadow: var(--ds-shadow-modal) !important;
  border: none !important;  // no border on modal, shadow provides edges
}
```

**Sizing convention:**
- Small confirm dialogs: `max-width: 400px`
- Form dialogs: `max-width: 560px`
- Large data dialogs: `max-width: 800px`
- Never `width: 100%` without a `max-width`

### 5.8 Form Layout

**Standard card-form pattern:**

```html
<SectionCard title="Section Name">
  <v-row dense>
    <v-col cols="12" md="6">
      <v-text-field variant="outlined" density="compact" hide-details="auto" label="..." />
    </v-col>
    <v-col cols="12" md="6">
      <v-select variant="outlined" density="compact" hide-details="auto" label="..." />
    </v-col>
    <v-col cols="12">
      <v-switch color="primary" density="compact" hide-details label="..." />
    </v-col>
  </v-row>

  <template #actions>
    <v-spacer />
    <v-btn variant="text">Отмена</v-btn>
    <v-btn color="primary" variant="flat">Сохранить</v-btn>
  </template>
</SectionCard>
```

**Do not** nest `v-card` inside `SectionCard`. Use `v-divider` to create sub-sections within one card.

### 5.9 Pagination

Use `v-data-table` built-in pagination. For custom pagination:

```scss
.v-pagination .v-btn {
  min-height: 32px !important;
  width: 32px !important;
  border-radius: var(--ds-radius-8) !important;
}
```

### 5.10 Empty State

Create a reusable `EmptyState.vue` component:

```html
<template>
  <div class="ds-empty-state">
    <v-icon :icon="icon" size="48" color="on-surface-variant" />
    <p class="ds-empty-state__title">{{ title }}</p>
    <p class="ds-empty-state__sub">{{ description }}</p>
    <slot name="actions" />
  </div>
</template>

<style scoped>
.ds-empty-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: var(--ds-space-48) var(--ds-space-24);
  gap: var(--ds-space-8);
  text-align: center;
}
.ds-empty-state__title {
  font-size: 0.9375rem;
  font-weight: 600;
  color: rgba(var(--v-theme-on-surface), 0.7);
  margin: 0;
}
.ds-empty-state__sub {
  font-size: 0.875rem;
  color: rgba(var(--v-theme-on-surface), 0.5);
  margin: 0;
}
</style>
```

---

## 6. Quick Wins (Highest Impact per Hour)

Listed by effort/impact ratio:

### QW-1: Unify Button Variants (30 min)
Replace all `variant="elevated"` on buttons with `variant="flat"` or `variant="outlined"`. Affects: `ParserSettings.vue`, `AdminLlmSettings.vue`.

```bash
# grep to find all instances
grep -r 'variant="elevated"' client/src
```

### QW-2: Fix Button Border Radius (5 min)
Change `--ds-radius-12` to `--ds-radius-8` for `.v-btn` only in `design-system.scss`. Immediately makes buttons look more enterprise and less consumer.

### QW-3: Fix Active Switch Colour (10 min)
In `design-system.scss`, replace the hardcoded `rgba(56, 189, 248, 0.5)` with `rgb(var(--v-theme-primary))`. Makes switches theme-aware and accessible (solid colour vs. semi-transparent).

```scss
/* BEFORE */
.v-switch.v-selection-control--dirty .v-switch__track {
  background: rgba(56, 189, 248, 0.5) !important;
}

/* AFTER */
.v-switch.v-selection-control--dirty .v-switch__track {
  background: rgb(var(--v-theme-primary)) !important;
  opacity: 0.85;
}
```

### QW-4: Kill `assets/base.css` Token Block (10 min)
Delete the `--vt-c-*` and `--color-*` variable definitions — they're never consumed and add confusion. Leave any reset rules.

### QW-5: Standardise Table Toolbar Pattern (45 min)
Create `TableToolbar.vue` (see §8 for implementation). Replace `.pj-controls`, `.filters-row`, and `v-row dense` toolbars in all views.

### QW-6: Remove Double-Card Nesting (45 min)
In `UserSettingsView`, `AdminLlmSettings`, `WorkProfilesView`: remove the inner `v-card variant="outlined"`. Replace with `v-divider` + spacing to separate sub-sections.

### QW-7: Fix Page Title Size (2 min)
Reduce `saas-page-header__title` from `1.5rem` to `1.375rem`. More contained, more structured.

### QW-8: Standardise Missing `variant="outlined" density="compact"` on inputs (30 min)
`MaterialsView` is missing `variant` on several `v-select` and `v-text-field` inputs. Grep and add.

### QW-9: Add `border-radius` to Page-Level Tabs (5 min)
`WorkProfilesView` `.page-tabs` tabs lack the pill-group border. Add the correct class or remove the custom class so design-system rules apply.

### QW-10: Replace `IosToggle.vue` usages with `v-switch` (1–2 hours)
Search for `IosToggle` across all views and replace. Document the single switch pattern in design system.

---

## 7. Refactor Roadmap

### Phase 1 — Stop the Bleeding (1–2 days)
*No new UI patterns. Fix inconsistencies in existing code.*

1. Apply QW-1 through QW-5 (button, switch, radius, dead CSS, toolbar)
2. Remove all `elevation="2"` and `variant="elevated"` from cards
3. Enforce `density="compact"` on all form controls not in a dialog
4. Add `variant="outlined"` to all `v-text-field`/`v-select` missing it

**Deliverable**: All screens use the same card style, button hierarchy, and form density.

### Phase 2 — Unify Structure (2–3 days)
*Replace ad-hoc patterns with shared components.*

1. Create `TableToolbar.vue` and replace all 5 custom toolbar patterns
2. Create `EmptyState.vue` and add to all tables
3. Remove double-card nesting from `UserSettingsView`, `AdminLlmSettings`, `WorkProfilesView`
4. Migrate `AdminPanelView` tabs to pill-group style (or sidebar nav)
5. Replace all `IosToggle` usages with styled `v-switch`
6. Move `soft-cards.css` classes into `design-system.scss` and delete the file

**Deliverable**: Structural consistency. All pages follow `PageContainer > PageHeader > SectionCard` pattern.

### Phase 3 — Parser Screens (1 day)
*Bring parser UI into the design system.*

1. Wrap `ParserDashboard.vue` and `ParserSettings.vue` in `PageContainer`
2. Replace all `v-container fluid` with design-system layout
3. Replace `elevation="2"` cards with bordered flat cards
4. Internationalise: translate English UI strings to Russian
5. Remove `activity-pulse` animation or replace with a subtle `v-badge` with dot indicator

**Deliverable**: Parser screens look like the rest of the product.

### Phase 4 — Token Completion (1 day)
*Remove all remaining hardcoded values.*

1. Replace all `rgba(148, 163, 184, *)` hover values with `var(--ds-hover-bg)`
2. Replace all `rgba(56, 189, 248, *)` primary-adjacent colours with `rgba(var(--v-theme-primary), *)`
3. Delete dead CSS from `assets/base.css`
4. Run a grep for remaining magic numbers in `design-system.scss` and `soft-cards.css`

**Deliverable**: All colours are theme-relative. Dark mode works without patching.

### Phase 5 — Interaction States (2 days)
*Add the polish that makes a UI feel finished.*

1. Add explicit `focus-visible` outlines to all interactive elements
2. Add `disabled` visual state to custom components (`IosToggle` replacement, empty-state CTA)
3. Add error state styling to forms (border turns error-red, icon appears)
4. Document and test all states in `DesignSystemShowcase.vue`

**Deliverable**: The UI behaves predictably under all interaction conditions.

---

## 8. Concrete Vue/CSS Implementation Suggestions

### 8.1 `TableToolbar.vue` — Standard Above-Table Controls

```vue
<!-- components/layout/TableToolbar.vue -->
<template>
  <div class="ds-table-toolbar">
    <div class="ds-table-toolbar__search">
      <slot name="search" />
    </div>
    <div class="ds-table-toolbar__filters">
      <slot name="filters" />
    </div>
    <div class="ds-table-toolbar__spacer" />
    <div class="ds-table-toolbar__actions">
      <slot name="actions" />
    </div>
  </div>
</template>

<style scoped>
.ds-table-toolbar {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: var(--ds-space-8);
  padding-bottom: var(--ds-space-16);
}
.ds-table-toolbar__search {
  flex: 1 1 240px;
  max-width: 320px;
}
.ds-table-toolbar__filters {
  display: flex;
  align-items: center;
  gap: var(--ds-space-8);
  flex-wrap: wrap;
}
.ds-table-toolbar__spacer {
  flex: 1 1 0;
}
.ds-table-toolbar__actions {
  display: flex;
  align-items: center;
  gap: var(--ds-space-8);
}
</style>
```

Usage:
```html
<TableToolbar>
  <template #search>
    <v-text-field v-model="search" placeholder="Поиск..." prepend-inner-icon="mdi-magnify"
      variant="outlined" density="compact" hide-details clearable />
  </template>
  <template #filters>
    <v-select v-model="statusFilter" :items="statusOptions" placeholder="Все статусы"
      variant="outlined" density="compact" hide-details clearable style="min-width:160px" />
  </template>
  <template #actions>
    <v-btn color="primary" variant="flat" prepend-icon="mdi-plus" @click="openCreate">
      Добавить
    </v-btn>
  </template>
</TableToolbar>
```

### 8.2 Corrected `design-system.scss` — Complete Switch Block

```scss
// ─── Switches ─────────────────────────────────────────────────────────────────

.v-switch {
  // Prevent label/control overlap: always align to center
  .v-selection-control {
    align-items: center;
  }
}

.v-switch .v-switch__track {
  width: 36px !important;
  min-width: 36px !important;
  height: 20px !important;
  border-radius: var(--ds-radius-full) !important;
  opacity: 1 !important;
  background: rgba(var(--v-theme-on-surface), 0.25) !important;
  transition: background-color 0.18s ease !important;
}

.v-switch.v-selection-control--dirty .v-switch__track {
  background: rgb(var(--v-theme-primary)) !important;
  opacity: 0.9 !important;
}

.v-switch .v-switch__thumb {
  width: 14px !important;
  height: 14px !important;
  background: rgb(var(--v-theme-surface)) !important;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.18) !important;
}

.v-switch.v-selection-control--dirty .v-switch__thumb {
  background: rgb(var(--v-theme-surface)) !important;
}

// Inline switch in a form row: consistent height
.v-input--density-compact.v-switch {
  --v-input-control-height: 36px;
}
```

### 8.3 Button Global Corrections

```scss
// ─── Buttons ──────────────────────────────────────────────────────────────────

.v-btn {
  // Enterprise feel: tighter radius
  border-radius: var(--ds-radius-8) !important;
  // Consistent height at compact density
  min-height: var(--ds-control-height-md);
  padding-inline: var(--ds-space-12) !important;
  letter-spacing: 0;
  text-transform: none;
  font-weight: 500;
}

// Icon-only buttons: square
.v-btn--icon {
  width: var(--ds-control-height-md) !important;
  height: var(--ds-control-height-md) !important;
  min-width: var(--ds-control-height-md) !important;
  padding: 0 !important;
}

// Table row action buttons: slightly smaller
.v-data-table td .v-btn--icon,
.v-table td .v-btn--icon {
  width: var(--ds-control-height-sm) !important;
  height: var(--ds-control-height-sm) !important;
}

// Absolute ban on elevated shadow
.v-btn--variant-elevated,
.v-btn--variant-flat {
  box-shadow: none !important;
}

// Outlined: single pixel, clear
.v-btn--variant-outlined {
  border-width: 1px !important;
}

// Icon child sizing
.v-btn .v-icon {
  font-size: var(--ds-icon-size-md) !important;
}
```

### 8.4 Replace `IosToggle.vue` — Migration Snippet

```vue
<!-- BEFORE: anywhere IosToggle is used -->
<IosToggle v-model="settings.is_active" :disabled="saving" />

<!-- AFTER: drop-in replacement -->
<v-switch
  v-model="settings.is_active"
  :disabled="saving"
  color="primary"
  density="compact"
  hide-details
  class="ds-switch"
/>
```

The `IosToggle.vue` file can then be deleted entirely.

### 8.5 Unified Card Rule — Eliminate Double-Nesting

```vue
<!-- BEFORE: UserSettingsView — double card -->
<SectionCard>
  <v-card variant="outlined" class="content-card">
    <v-card-text>
      <v-row dense>...</v-row>
    </v-card-text>
  </v-card>
</SectionCard>

<!-- AFTER: single card, use divider for sub-sections -->
<SectionCard title="Регион и режим расчёта" subtitle="Используются при создании новых проектов">
  <v-row dense>
    <v-col cols="12" md="6">
      <v-select ... />
    </v-col>
    <v-col cols="12" md="6">
      <v-switch ... />
    </v-col>
  </v-row>
</SectionCard>
```

### 8.6 Dead CSS Cleanup — What to Delete

```
client/src/assets/base.css       → delete the entire :root { --vt-c-* } block
client/src/assets/soft-cards.css → merge into design-system.scss, delete file
```

Merge into `design-system.scss`:
```scss
// From soft-cards.css — migrated & token-corrected
.soft-content-card {
  border-radius: var(--ds-radius-12);
  background: rgb(var(--v-theme-surface));
  border: 1px solid var(--ds-border-default);
}

.soft-data-card {
  overflow: hidden;
}

.soft-data-table {
  border-top: 1px solid var(--ds-border-default);
}

.soft-dialog-card {
  border-radius: var(--ds-radius-16);
}
```

### 8.7 PageContainer Style Fix

The `saas-page-container__inner` currently uses `gap: var(--ds-space-24)`. Good. The padding of 16px page-wide feels tight on larger screens. Proposed:

```scss
.saas-page-container {
  padding: var(--ds-space-24) var(--ds-space-16) !important;
}

@media (min-width: 1280px) {
  .saas-page-container {
    padding: var(--ds-space-32) var(--ds-space-24) !important;
  }
}
```

---

## Appendix: Audit Checklist

| Issue | File(s) | Severity | Phase |
|---|---|---|---|
| `variant="elevated"` on buttons | `ParserSettings.vue`, `AdminLlmSettings.vue` | Critical | 1 |
| Active switch colour hardcoded | `design-system.scss` | Critical | 1 |
| `IosToggle.vue` Apple green | `IosToggle.vue` + usages | Critical | 2 |
| Double card nesting | `UserSettingsView`, `AdminLlmSettings`, `WorkProfilesView` | High | 2 |
| 5 different toolbar patterns | All main views | High | 2 |
| Parser screens outside design system | `ParserDashboard`, `ParserSettings` | High | 3 |
| Hardcoded `rgba(148,163,184,*)` hover | `design-system.scss` | High | 4 |
| Missing `variant="outlined"` inputs | `MaterialsView` | Medium | 1 |
| Tab style inconsistency | `AdminPanelView` | Medium | 2 |
| `assets/base.css` dead tokens | `base.css` | Medium | 4 |
| Missing empty states | Most views except `ProjectsView` | Medium | 2 |
| Button radius too round for enterprise | `design-system.scss` `.v-btn` | Low-Medium | 1 |
| Page title `1.5rem` too large | `design-system.scss` | Low | 1 |
| Mixed utility class spacing | All views | Low | ongoing |
| No `focus-visible` ring on custom elements | `IosToggle.vue`, badges | Low | 5 |
