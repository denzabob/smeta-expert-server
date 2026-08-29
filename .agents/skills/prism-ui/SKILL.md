---
name: prism-ui
description: "Use for Prism Vue 3 and Vuetify UI work: visual design, responsive layouts, public landing and PriceIndices calculator/chart/table interfaces, components, design-system spacing, accessibility, and hover/focus/error/disabled/loading states."
---

# Prism UI

Применяй этот Skill к Vue 3/Vuetify и связанным UI-задачам. Сначала определи, относится ли изменение к shared foundation/design-system или к конкретному feature screen.

## Общие правила

- Используй semantic/global design-system primitives и shared layout wrappers.
- Не создавай page-local mini design system.
- Не хардкодь цвета, radii, shadows или spacing, если существует reusable token/component.
- Сохраняй desktop/mobile behavior и проверяй hover, focus, error, disabled и loading states.
- Оценивай accessibility, keyboard flow и predictable empty/error/loading states.
- Не меняй global tokens без оценки blast radius.

## Два режима интерфейса

Operational UI для estimates, admin, evidence, settings и data-heavy screens требует density, scan speed, predictable controls и enterprise usability. Не переноси marketing spacing в эти экраны автоматически.

Public/marketing/SEO UI для public PriceIndices, landing и discoverability pages может использовать больше whitespace, narrative sections и conversion hierarchy, но должен оставаться в общем visual language PrismCore.

Перед изменением shared primitive перечисли затронутые косвенно экраны и регрессии. Для foundation-изменений планируй build и ручной smoke-check desktop/mobile, hover, focus, error, disabled, loading и light/dark theme, если тема применима.

## Cross-skill routing

- Техническую Vue/TypeScript implementation маршрутизируй в `prism-frontend`.
- Public server rendering маршрутизируй в `prism-public-rendering`.
- Browser verification выполняй через `prism-visual-acceptance`, если Browser реально доступен.
- SEO semantics и indexability маршрутизируй в `prism-public-seo`.
