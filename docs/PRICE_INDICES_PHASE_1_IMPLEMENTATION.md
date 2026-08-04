# ПРИЗМА Индексы — итог первого этапа

Дата фиксации: 4 августа 2026 года.

## 1. Назначение этапа

Первый этап создаёт изолированный каркас приложения «ПРИЗМА Индексы»: backend-границу,
capability endpoint, пользовательские и административные маршруты, отдельную навигацию и
честные страницы-заглушки.

На этом этапе не реализованы статистическая база данных, импорт XLSX, маппинг, расчёт
индексов, отчёты и отдельный биллинг модуля.

## 2. Архитектура

Предметные границы:

- backend: `server/app/Domain/PriceIndices`;
- frontend: `client/src/modules/price-indices`;
- API: `/api/indices`;
- пользовательский интерфейс: `/app/indices`;
- административный интерфейс: `/admin/indices`.

Разрешённые общие зависимости: `User`, Sanctum, `AppShell`, `AdminLayout`, профиль,
поддержка, уведомления, тема, Axios и Pinia.

Price Indices не должен зависеть от `Project`, `ProjectPosition`, `ProjectRevision`,
`RevisionPublication`, `SmetaCalculator`, `ReportService`, а также от справочников
материалов, работ и цен. Поиск этих зависимостей в предметных backend/frontend-каталогах
первого этапа совпадений не обнаружил.

## 3. Feature flags и доступ

Значения по умолчанию:

```dotenv
PRICE_INDICES_ENABLED=false
PRICE_INDICES_ADMIN_ONLY=true
```

- Модуль выключен по умолчанию.
- Первый этап допускает только точные, регистрозависимые роли `admin` и `superadmin`.
- `User::isAdmin()` для Price Indices не используется.
- Legacy-пользователь `id=1` без точной административной роли доступа не получает.
- `PRICE_INDICES_ADMIN_ONLY=false` возвращается capability-контрактом, но не открывает
  модуль обычному пользователю.

## 4. Backend

Реализованы:

- middleware `EnsurePriceIndicesAccess`;
- alias middleware `price_indices.access`;
- invokable controller `PriceIndicesCapabilitiesController`;
- отдельный route-файл `server/routes/price_indices.php`;
- endpoint `GET /api/indices/capabilities`;
- feature-тест `PriceIndicesCapabilitiesTest`.

Маршрут использует `auth:sanctum` и `price_indices.access`. При выключенном модуле
middleware возвращает 404, при неподходящей роли — 403.

Успешный JSON-контракт:

```json
{
  "data": {
    "application": "price_indices",
    "enabled": true,
    "access": true,
    "admin_only": true,
    "stage": "skeleton"
  }
}
```

Статистические модели, таблицы и миграции отсутствуют. Capability endpoint не выполняет
SQL-запросов, что отдельно проверяется тестом.

## 5. Пользовательские маршруты

| Маршрут | Назначение первого этапа |
|---|---|
| `/app/indices` | Обзор приложения |
| `/app/indices/new` | Заглушка нового расчёта |
| `/app/indices/calculations` | Заглушка списка расчётов |
| `/app/indices/indicators` | Заглушка показателей |
| `/app/indices/sources` | Заглушка источников |

Все страницы используют существующий `AppShell`, lazy imports и guard
`requiresPriceIndices`.

## 6. Пользовательское меню

Отдельное sidebar-меню Price Indices содержит:

**Работа**

- Обзор;
- Новый расчёт;
- Мои расчёты.

**Данные**

- Показатели;
- Источники.

Меню приложения «Сметы» на маршрутах Price Indices не показывается. Универсальный sidebar
получает конфигурацию через prop и не импортирует предметное меню напрямую.

## 7. Переключатель приложений

Порядок приложений для подходящего администратора с успешным capability:

1. Сметы.
2. Индексы.
3. Админ панель.
4. Парсер.

«Индексы» появляются только после статуса capability `available`. Видимость Админ-панели
и Парсера сохраняет прежнюю legacy-проверку, включая `id=1`. Активное приложение
определяется текущим route. Последнее приложение записывается в
`localStorage['prisma.lastApplication']`, но localStorage не участвует в авторизации и не
может обойти router guard.

## 8. Административные маршруты

Добавлены дочерние маршруты существующего `AdminLayout`:

- `/admin/indices/sources` — Источники данных;
- `/admin/indices/imports` — Импорты XLSX;
- `/admin/indices/mappings` — Шаблоны маппинга;
- `/admin/indices/logs` — Журнал импорта.

Все маршруты используют lazy imports и meta `requiresAuth`, `requiresAdmin`,
`requiresPriceIndices`. Группа «Индексы» располагается между группами «Система» и
«Поддержка» и показывается только при capability `available`.

Страницы являются заглушками. Backend CRUD, загрузка файлов, импортные сессии, журнал в
БД и конструктор маппинга отсутствуют.

## 9. Capability store

Pinia-store поддерживает состояния:

- `idle`;
- `loading`;
- `available`;
- `forbidden`;
- `disabled`;
- `error`.

Store дедуплицирует активный Promise, кеширует терминальное состояние, разделяет кеш по
идентификатору пользователя и роли, поддерживает явный `refresh()` и работает fail-closed.
HTTP 403 преобразуется в `forbidden`, HTTP 404 — в `disabled`, остальные ошибки — в
`error`.

## 10. Router guard

Порядок проверок:

1. authentication;
2. существующая admin-проверка, если установлен `requiresAdmin`;
3. Price Indices capability.

При недоступном capability пользовательские маршруты перенаправляются на `/projects`,
административные — на `/admin`. Обычный пользователь на admin route сначала блокируется
существующим admin guard. Redirect loop не создаётся.

## 11. Автоматизированные проверки

Проверки выполнены 4 августа 2026 года.

### Backend

- `php artisan route:list --path=api/indices`: зарегистрирован один capability route.
- `php artisan route:list --path=api/projects`: сохранены 95 существующих project routes.
- `php artisan test --filter=PriceIndices`: 13 тестов, 20 assertions, все прошли за 1.03 с.
- `php -l` для config, routes, controller, middleware и feature-теста: синтаксических
  ошибок нет.

PHPUnit показал одно внешнее предупреждение: metadata в doc-comment метода
`BlockH12VerificationStatusTransitionTest::test_terminal_state_cannot_transition()`
устареет в PHPUnit 12. Оно не относится к Price Indices.

### Frontend

- `npm run test:unit`: 10 test files, 196 тестов, все прошли за 862 мс.
- `npm run build-only -- --logLevel error`: завершено успешно.
- Vite сформировал 9 lazy chunks: пять пользовательских и четыре административных
  страницы Price Indices.
- `npm run type-check`: завершился с exit code 1 из-за ранее существовавших ошибок вне
  первого этапа.

Внешние TypeScript diagnostics остаются в:

- `AdminLlmSettings.vue`;
- `AdminUsersTab.vue`;
- `FacadeEditDialog.vue`;
- `OperationRuleEditor.vue`;
- `AccountSecuritySection.vue`;
- `UserSecurityPanel.vue`;
- `DetailTypesView.vue`;
- `ParserHistory.vue`;
- `PricingLaborView.vue`;
- `PricingOperationsView.vue`;
- `ProjectEditorView.vue`.

Новых диагностик нет в `client/src/modules/price-indices`, `AppShell.vue`, `AppMenu.vue`,
`AppSidebarNew.vue`, `AdminLayout.vue` и `router/index.ts`.

## 12. Ручная проверка

Browser automation не выполнил открытие страницы из-за ошибки инструмента:
`codex/sandbox-state-meta: missing field sandboxPolicy`. До ошибки временно запущенные
Laravel и Vite отвечали на `127.0.0.1:8000` и `127.0.0.1:5173`; capability endpoint для
гостя вернул штатный 401. Фактический UI smoke не заявляется как выполненный.

| Сценарий | Результат | Примечание |
|---|---|---|
| Desktop expanded | Не проверен | Browser runtime недоступен |
| Desktop rail | Не проверен | Проверить иконки, tooltip, popup и сохранение rail mode |
| Mobile | Не проверен | Проверить drawer, popup и отсутствие смешения меню |
| Светлая тема | Не проверен | Проверить карточки, hover, focus и active state |
| Тёмная тема | Не проверен | Проверить контраст и отсутствие несогласованных цветов |
| Admin, модуль включён | Не проверен | Capability и права покрыты automated tests |
| Admin, модуль выключен | Не проверен | 404 и redirects покрыты automated tests |
| Обычный пользователь | Не проверен | 403 и admin guard покрыты automated tests |
| Legacy `id=1` | Не проверен | Запрет Price Indices покрыт backend/frontend tests |
| AppMenu | Не проверен | Порядок и visibility покрыты unit-тестами |
| Пользовательский sidebar | Не проверен | Конфигурация и маршруты покрыты unit-тестами |
| Admin navigation | Не проверен | Порядок, visibility и active helper покрыты unit-тестами |
| Профиль | Не проверен | Существующий компонент не изменялся первым этапом |
| Поддержка | Не проверен | Существующий компонент не изменялся первым этапом |
| Уведомления | Не проверен | Существующий компонент не изменялся первым этапом |
| Переход обратно в Сметы | Не проверен | Route resolution покрыт unit-тестом |

Ручной smoke должен дополнительно проверить отсутствие горизонтальной прокрутки,
длинные подписи, клавиатурный focus, закрытие mobile drawer после переключения приложения
и доступность профиля/поддержки.

## 13. Регрессия существующих функций

Автоматизированно подтверждены регистрация `/api/projects`, прежние правила видимости
Admin/Parser в AppMenu, пользовательская навигация Price Indices и полный существующий
frontend unit-набор. Parser route/layout, биллинг и бизнес-код проектов не изменялись.

Навигационные сценарии `/projects`, создание и открытие проекта, редактор, `/catalog`,
`/products`, `/pricing`, `/settings`, `/settings/billing`, профиль, уведомления, поддержка,
тема, admin dashboard/users/logs/billing/chat и Parser вручную не проверены из-за
недоступности browser runtime.

## 14. Известные ограничения

- Нет статистических таблиц и persistence-моделей.
- Нет XLSX import и хранения исходных версий.
- Нет маппинга.
- Нет расчётов индексов.
- Нет PDF/DOCX.
- Нет пользовательского биллинга Price Indices.
- Общий type-check содержит прежние внешние ошибки.
- Browser runtime недоступен для фактического UI smoke.
- Последнее приложение сохраняется только в localStorage.
- Контекст Price Indices пока не передаётся в поддержку.

## 15. Локальное включение

Использовать локальные значения без реальных секретов:

```dotenv
PRICE_INDICES_ENABLED=true
PRICE_INDICES_ADMIN_ONLY=true
```

Если Laravel config был закеширован:

```bash
php artisan config:clear
```

После включения доступ всё равно требует аутентифицированного пользователя с точной ролью
`admin` или `superadmin`.

## 16. Отключение

```dotenv
PRICE_INDICES_ENABLED=false
```

После очистки config cache capability endpoint возвращает 404 для подходящего
администратора, приложение и admin-группа скрываются, прямые пользовательские и
административные маршруты блокируются.

## 17. Следующий этап

Следующий этап должен начинаться с проектирования внутренней модели статистических
данных: классификаторов, территорий, источников, версий исходных XLSX, наблюдений и
импортных сессий.

Конструктор маппинга нельзя реализовывать до утверждения этой модели и правил её
целостности, версионирования и отката.
