# Prism project contract for Codex

## Repository

Prism — production SaaS for estimate calculation, expert pricing, furniture evidence, verification, reports and related business workflows.

Main stack: Laravel/PHP, Vue 3, Vuetify, MariaDB/MySQL, PDF generation, import/export flows, browser-extension integration, revision/evidence flows and PriceIndices.

## Core discipline

- Inspect the real implementation and contracts before editing.
- Separate observed facts, inferences and missing/not-found concepts.
- Keep diffs minimal, localised and backward-compatible.
- Do not silently change public contracts or legacy flows.
- Do not expand scope or introduce unrelated refactors.
- Do not claim checks, tests, builds or manual verification that were not actually run.
- Treat pricing, evidence, revisions, PDFs, imports/exports, extension contracts, public verification and PriceIndices as high-risk areas.

## Large tasks

For cross-layer or high-risk work, use `large-change-scope`; when useful, start with the read-only `architect` and `explorer` agents. Split work into bounded blocks, each with one goal, exact scope, out-of-scope items, compatibility expectations, acceptance criteria and targeted validation. Implement one approved block at a time.

Small local tasks should not automatically start the whole pipeline.

## Native Prism Skills

Use the most specific Skill available:

- `prism-backend` — Laravel/PHP backend execution paths, contracts, services, validation and compatibility.
- `prism-database` — MariaDB/MySQL schema, persistence safety, backfills, constraints and rollback planning.
- `prism-frontend` — Vue 3/TypeScript/Vite implementation and frontend state/API mechanics.
- `prism-public-rendering` — Blade, server-rendered public HTML, bootstrap data and progressive enhancement.
- `prism-price-indices` — PriceIndices, CPI/PPI, OKPD2, Rosstat data, provenance, imports, datasets, classifiers, calculations and admin/public contracts.
- `prism-public-seo` — public PriceIndices pages, SSR/crawler HTML, metadata, canonical/robots, sitemap, structured data and legacy public URLs.
- `prism-ui` — Vue/Vuetify UI, responsive behavior, public landing/calculator/chart/table work, accessibility and visual states.
- `prism-testing` — select and report the minimum validation matrix for the actual diff.
- `prism-visual-acceptance` — Browser-based visual acceptance for substantial UI/public changes when Browser is available.
- `prism-git-workflow` — safe multi-workstation Git synchronization and Git-based deployment workflow.
- `large-change-scope` — analysis-first planning and bounded cross-layer delivery.

External/general Skills are optional and conditional: use `material-3`, `laravel-expert`, `database-architect`, `performance-optimizer`, `code-reviewer` or `backend-architect` only when that Skill is actually available in the current Codex environment.

## Skill combinations

- Backend: `prism-backend` + `prism-testing`.
- Database: `prism-backend` + `prism-database` + `prism-testing`.
- Vue: `prism-frontend` + `prism-ui` when visual behavior changes + `prism-testing`.
- Public PriceIndices/landing: `prism-public-rendering` + `prism-public-seo` + `prism-frontend` + `prism-ui` when visual scope exists + `prism-testing` + `prism-visual-acceptance` when Browser validation is justified.
- Cross-layer: `large-change-scope` + relevant domain/engineering Skills; use read-only architecture/exploration support when useful.

Do not activate every Skill mechanically.

## Agent routing and write concurrency

Do not delegate merely because an agent exists. Delegate when it reduces uncertainty, protects the main context, enables independent read-heavy work or provides an independent review.

- `architect` — read-only architectural analysis of complex or cross-layer work; returns one recommended next block.
- `explorer` — fast targeted read-only repository exploration; returns facts, paths, symbols, execution paths, tests and hidden dependencies.
- `implementer` — the only write-capable agent for one bounded implementation block; keeps the diff minimal and runs targeted checks.
- `reviewer` — independent read-only review of the completed block, prioritising correctness, regressions, compatibility, security, scope and test gaps.

Parallelise independent read-only investigations where useful. Do not parallelise writes to the same files. Use at most one write-capable implementer per bounded block.

## Validation and completion

Use `prism-testing` to choose targeted checks. Report separate sections for `Run`, `Not run` and `Manual checks`.

Every implementation report must state files changed, behavior changed, checks run, checks not run, known risks/limitations and manual verification steps. For infrastructure-only changes, also verify that application code, migrations, Docker/deploy configuration and data were not changed.
