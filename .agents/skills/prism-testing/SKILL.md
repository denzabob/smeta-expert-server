---
name: prism-testing
description: "Use for Prism validation planning and execution: choose the minimum sufficient checks from the actual diff across Laravel/PHP, Vue/Vuetify, PriceIndices, public SEO, imports, PDFs, extension flows, and infrastructure-only changes."
---

# Prism testing

Выбирай минимально достаточную validation matrix по фактическому diff. Не запускай весь suite автоматически для маленького изменения и не называй работу проверенной без реального выполнения команды.

## Skill routing

- Backend diff: `prism-backend` + targeted backend checks.
- Database/persistence diff: `prism-backend` + `prism-database` + targeted migration/data checks.
- Frontend diff: `prism-frontend`; при visual behavior добавь `prism-ui`.
- Public rendering diff: `prism-public-rendering` + `prism-public-seo` по фактическому SEO scope.
- Существенный UI/public diff: добавь `prism-visual-acceptance`, если Browser доступен и browser validation оправдана.

```text
automated validation != visual acceptance
```

PHPUnit passed != Blade/SEO rendering verified; TypeScript build passed != UI visually correct; browser rendering passed != crawler HTML correct.

## Frontend commands

Из `client/package.json` доступны:

- `npm --prefix client run type-check`
- `npm --prefix client run test:unit`
- `npm --prefix client run test:public-price-indices`
- `npm --prefix client run build`
- `npm --prefix client run build:public-price-indices`

Запускай только команды, покрывающие изменённый слой. Для public SEO отдельно учитывай browser/source/HTTP smoke-checks, если тесты их не покрывают.

## Backend commands

Backend Laravel находится в `server`, а compose app service монтирует его как `/var/www/html`. Для targeted PHPUnit используй фактический путь/команду среды, например:

- `docker compose exec app php artisan test tests/Feature/PriceIndices`
- `docker compose exec app php artisan test --filter=PublicSeo`
- `docker compose exec app php artisan test --filter=PublicPriceIndices`
- `docker compose exec app php artisan test --filter=PriceIndices`

Если контейнер не используется и зависимости установлены локально, эквивалентом является `cd server` и `php artisan test ...`. Перед запуском уточни существующий test path/filter; для PriceIndices предпочитай `server/tests/Feature/PriceIndices/**`.

## Report format

Всегда разделяй:

### Run

Точные фактически выполненные команды и результат.

### Not run

Команды и проверки, которые не выполнялись, с причиной если она известна.

### Manual checks

Оставшиеся проверки интерфейса, HTTP/SSR/source, PDF, extension или окружения.

Для infrastructure-only diff дополнительно проверь syntax/config/discovery и `git diff` scope; не запускай application suite без связи с diff.
