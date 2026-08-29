---
name: prism-visual-acceptance
description: "Project-specific Browser-based visual acceptance methodology for Prism UI, responsive changes, public PriceIndices pages, calculators, charts, navigation, component states, and visual regressions."
---

# Prism Visual Acceptance

Применяй этот Skill для manual/agent visual acceptance UI и public changes. Browser/Browser plugin предоставляет capability, а этот Skill задаёт методику проверки.

## Browser capability

Используй доступный `Browser` Skill/Plugin для открытия, навигации, проверки console/network и screenshots. Availability не гарантируй: сначала проверь, доступен ли Browser в текущем environment.

Для существенных frontend/UI/public изменений Browser acceptance обязательна, если capability доступна. Не выполняй state-changing browser actions в рамках этой проверки.

## Acceptance flow

Проверь:

1. page opens и основной flow достигает ожидаемого состояния;
2. console errors и failed network requests;
3. desktop и mobile viewport;
4. horizontal overflow, clipping и очевидные layout shifts;
5. loading, empty, error и disabled states;
6. keyboard focus, hover и очевидные accessibility regressions;
7. anonymous public access, если страница публичная.

Для PriceIndices проверяй только затронутые scope: overview, search, detail, calculator, chart, navigation, 404/not-found и legacy URLs.

## Evidence and reporting

Делай screenshots только для релевантных состояний и сравнивай их с ожидаемым поведением, не создавая бессмысленный набор снимков.

Отчёт разделяй на:

- `Visually checked` — реально открытые страницы, viewport и состояния;
- `Not checked` — не проверенные состояния или scope;
- `Issues found` — фактические visual/functional regressions.

Не пиши `visual acceptance passed`, если Browser реально не запускался.

## Routing

- Техническую Vue/TypeScript реализацию маршрутизируй в `prism-frontend`.
- Visual/design/interaction rules маршрутизируй в `prism-ui`.
- Public server-rendered и crawler-visible checks дополняй `prism-public-rendering` и `prism-public-seo`.
- Automated checks выбирай через `prism-testing`; automated validation не равно visual acceptance.

## Preconditions

Перед Browser check проверь, что target environment и test data доступны, а проверяемая страница не требует state-changing действий для достижения acceptance state.

