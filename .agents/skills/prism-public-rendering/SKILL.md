---
name: prism-public-rendering
description: "Project-specific Prism public rendering rules for Blade, server-rendered HTML, public PriceIndices views, JS bootstrap, progressive enhancement, public bundles, JSON bootstrap, escaping, XSS safety, and crawler-visible fallback."
---

# Prism Public Rendering

Применяй этот Skill для технической реализации публичных server-rendered страниц Prism/PriceIndices. Не смешивай его с `prism-public-seo`: SEO semantics и indexability маршрутизируй отдельно.

## Canonical areas and analysis

Ищи реальные entry points и contracts в:

- `server/resources/views/price-indices/public/**`;
- `client/src/public-price-indices/**`;
- `server/routes/web.php`;
- public controllers/services и rendering tests.

Перед изменением проверь route/controller, Blade data contract, frontend bootstrap, public SEO requirements и targeted tests. Раздели server-visible HTML и client enhancement до начала реализации.

## Server-visible content

Определи, какой meaningful content должен существовать до выполнения JavaScript: title/heading, explanatory text, links, fallback data и not-found/error response, если это входит в текущий contract. Для индексируемых страниц JS-rendered content не заменяет crawler-visible HTML.

## Client enhancement

JS может добавить интерактивность, calculator/chart behavior и richer states после загрузки, но не должен разрушать доступный server-rendered fallback. Сохраняй согласованность Blade/bootstrap data и frontend types.

## Rendering safety rules

- Используй safe escaping и корректное JSON encoding для embedded/bootstrap data; не интерполируй пользовательские значения в inline script небезопасным способом.
- Не дублируй initialization при повторной загрузке или hydration-like enhancement.
- Используй deterministic asset references и проверь, что public bundle действительно подключён текущим entry point.
- Обрабатывай отсутствие JS graceful, когда это допускается текущим public contract.
- Не ослабляй anonymous public access, route visibility или not-found semantics.
- Для SEO metadata, canonical, sitemap и indexability добавляй `prism-public-seo`, не дублируй его правила здесь.

## Progressive enhancement workflow

1. Опиши expected anonymous HTTP/Blade response без JavaScript.
2. Проверь bootstrap payload и escaping на границе backend → HTML → JS.
3. Добавь client enhancement без потери fallback и без duplicate initialization.
4. Сверь public SEO contract, frontend expectations и rendering tests.

## Cross-skill routing и validation

- Для SEO semantics используй `prism-public-seo`.
- Для Vue/TypeScript implementation используй `prism-frontend`.
- Для visual behavior при доступном Browser используй `prism-ui` и `prism-visual-acceptance` по фактическому scope.
- Для автоматизированных checks используй `prism-testing`; разделяй HTTP/source/crawler checks и browser acceptance.

Не активируй все Skills механически и не называй client rendering доказательством crawler-visible HTML.

