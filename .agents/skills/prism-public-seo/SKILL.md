---
name: prism-public-seo
description: "Use for Prism public PriceIndices pages and indices.prismcore.ru: landing, SSR, crawler-visible HTML, SEO metadata, sitemap, robots, canonical, structured data, indexability, public calculator/chart/search, and legacy public URLs."
---

# Prism public SEO

Применяй этот Skill к public PriceIndices и SEO-задачам. Всегда различай browser-rendered correctness и crawler-visible HTML correctness: визуальная работа после JavaScript не доказывает корректность HTML, который получает crawler.

## Проверяй отдельно

- HTTP status и redirects;
- canonical, robots и sitemap;
- server-rendered HTML/source;
- title, description и heading semantics;
- internal linking и structured data;
- anonymous accessibility;
- JS enhancement и browser behavior;
- legacy URL behavior.

Сначала найди текущий public entry point, SSR/snapshot/page builder, routes и тесты. Не заменяй server-rendered content клиентским HTML без явной проверки SEO-контракта.

## Карта валидации

Для изменений используй существующие PriceIndices public SEO tests, особенно тесты с именами/областями `PublicSeo*`, `PublicPriceIndices*`, `PublicSeries*`, `Sitemap*`, `Metrika*` и SSR/public snapshots. Уточняй фактические имена в repository перед запуском.

Для каждой SEO-функции фиксируй, что подтверждено HTTP/source-тестом, что подтверждено браузером и что осталось непроверенным вручную. Сохраняй anonymous access, canonical/redirect compatibility и общую visual language PrismCore.
