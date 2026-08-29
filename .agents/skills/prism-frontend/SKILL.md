---
name: prism-frontend
description: "Project-specific Vue 3, TypeScript, Vite and Vuetify engineering rules for Prism. Use for components, composables, stores, router, API layer, frontend state, error handling, public PriceIndices bundles, and frontend tests."
---

# Prism Frontend

Применяй этот Skill для технической реализации Vue 3/TypeScript/Vite в Prism. Visual/design/interaction decisions относятся к `prism-ui`.

## Canonical repository areas

Ориентируйся на текущий код в:

- `client/src/**`;
- `client/package.json`;
- `client/vite*.ts`;
- существующих frontend tests и public PriceIndices entry points.

## Анализ до frontend edit

Перед изменением:

1. Найди component/entry point и реальный execution path.
2. Найди API contract, существующие types и DTO mapping.
3. Проверь shared components, composables, stores, router и API layer.
4. Найди targeted tests и build/type-check coverage.
5. Определи loading/error/empty/disabled states и side effects.
6. Оцени, затрагиваются ли `prism-ui`, public rendering, SEO или backend contracts.

## Implementation rules

- Сохраняй strict typing и переиспользуй существующие types/contracts; не вводи `any` без обоснования.
- Держи component responsibility ограниченной; выноси повторяемую логику в существующие composables/helpers.
- Используй существующий API layer вместо direct ad-hoc requests и явно управляй predictable side effects.
- Реализуй явные loading, error, empty и disabled states там, где их требует flow.
- Для stores/router сохраняй существующий lifecycle, navigation semantics и cleanup.
- Для public PriceIndices JS bundles учитывай соответствие Blade/bootstrap data и server-visible contracts.
- Не меняй global UI primitives или unrelated screens в локальной задаче.

## Responsibility boundaries

`prism-frontend` отвечает за техническую реализацию и данные. `prism-ui` отвечает за visual/design/interaction rules; при изменении публичного server-rendered слоя добавляй `prism-public-rendering`, а для SEO semantics — `prism-public-seo`.

Не:

- смешивать data fetching, rendering и business logic без необходимости;
- дублировать backend contract вручную в нескольких местах;
- создавать local utility copies вместо существующих shared helpers;
- проводить unrelated global UI refactor вместе с feature fix.

## Cross-skill routing и validation

- При visual behavior changes добавляй `prism-ui`.
- Для public server rendering добавляй `prism-public-rendering`; для SEO semantics — `prism-public-seo`.
- Для каждой frontend-задачи используй `prism-testing` и выбирай type-check, unit/build или public checks по фактическому diff.
- Для существенного UI/public scope, если Browser реально доступен, добавляй `prism-visual-acceptance`.

Не активируй все Skills механически и не называй frontend validation визуальной проверкой без использования Browser.

