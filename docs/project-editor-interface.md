# Интерфейс редактора сметы (`/projects/{id}/edit`)

## Общая структура

Файл — `client/src/views/ProjectEditorView.vue` (~8 400 строк). Реализован на Vue 3 + Vuetify 3. Никаких Pinia-сторов — всё состояние локально через `ref`/`computed`.

### Макет страницы

```
┌─────────────────────────────────────────┐
│  WorkspaceHeader (sticky)               │
│  [# проекта] [сумма] [PDF] [Ревизия]    │
├─────────────────────────────────────────┤
│  ProjectHealthBar (предупреждения)      │
├──────────┬──────────────────────────────┤
│Sidebar   │  workspace-module-area       │
│(иконки)  │  (активный модуль)           │
│          │                              │
│ Позиции  │                              │
│ Матер.   │                              │
│ Операции │                              │
│ Фурнитура│                              │
│ Работы   │                              │
│ Расходы  │                              │
│ Ревизии  │                              │
│ Настройки│                              │
└──────────┴──────────────────────────────┘
```

---

## Модули (вкладки сайдбара)

| Модуль | Бейдж | Условие предупреждения |
|--------|-------|------------------------|
| **Позиции** | кол-во позиций | позиции без материала / цены |
| **Материалы** | кол-во плит + кромок | плиты без цены или листа |
| **Операции** | кол-во операций | — |
| **Фурнитура** | кол-во позиций | — |
| **Работы** | кол-во работ | нет ставок нормо-часов |
| **Расходы** | кол-во расходов | — |
| **Ревизии** | кол-во ревизий | сессия в NEEDS_MANUAL / FAILED |
| **Настройки** | — | — |

---

## Модуль «Позиции»

Самый сложный модуль.

**Тулбар:**
- Кнопка «Добавить позицию» → `positionDialog`
- Кнопка «Импорт Excel» → `ImportPositionsDialog`
- Переключатель «Панели / Фасады»
- Пресеты колонок: **Основные / Материалы / Размеры / Кромки / Фасады / Итоги** (сохраняются в `localStorage`)

**Режим выделения (bulk mode):**
- При выборе строк тулбар переключается на действия над выделенными:
  - `replace_material` — заменить материал у всех
  - `replace_edge` — заменить кромку
  - `set_edge_scheme` — задать схему кромления
  - `clear_field` — очистить поле
  - `replace_facade_material` — заменить фасадный материал
- Подтверждение через `confirmBulkDialog`
- Режим применения: **Strict** (только точное совпадение) / **Partial**

**Таблица позиций** (`v-data-table`, без пагинации):
- При наведении появляется `RowHoverActions` с быстрыми действиями и меню
- Клик по строке → открывает **правый drawer** `positionDrawer` (ширина 420 px) для инлайн-редактирования

**Правый drawer позиции:**
- Два режима: панель vs фасад (разные наборы полей)
- Калькулятор выражений для ввода ширины/длины (можно писать `2400+60`)
- Автосохранение каждого поля через `updatePositionField()`

---

## Модуль «Ревизии»

**Верхний блок — активная сессия ревизии:**
- Чип со статусом + прогресс-бар (при `IN_PROGRESS`)
- Счётчики: OK / Проблемных
- Кнопки: Обновить / Повторить / **Финализировать**
- Таблица позиций сессии с кнопкой «Ручное закрытие» для проблемных

**Нижний блок — история ревизий:**
- Таблица с кол-вом позиций, датой, хешем снапшота
- Действия: просмотр JSON, скачать PDF сметы, скачать PDF обоснования цен, публикация/отзыв

---

## Диалоги

| Диалог | Назначение |
|--------|-----------|
| **positionDialog** | Добавить / редактировать позицию (тип: панель или фасад) |
| **fittingDialog** | Добавить / редактировать фурнитуру с автопоиском по каталогу |
| **expenseDialog** | Добавить / редактировать расход |
| **operationDialog** | Добавить / редактировать ручную операцию |
| **laborWorkDialog** | Добавить / редактировать работу (норматив, часы, профиль) |
| **stepsDialog** (1000px) | Детализация шагов работы: split-pane список + форма + **AI-ассистент** для авторазбивки |
| **manualCloseDialog** | Ручное закрытие позиции ревизии: цена + screenshot (drag-drop / Ctrl+V) |
| **revisionDialog** | Просмотр снапшота ревизии (статус, хеш, развёртываемый JSON) |
| **confirmBulkDialog** | Подтверждение bulk-операции над позициями |
| **normohourSourceDialog** | Добавить / редактировать источник нормо-часов |
| **deleteDialog** | Подтверждение удаления шага работы |

**Ещё два overlay-drawer'а:**
- `ProjectSettingsDrawer` — настройки проекта (коэффициенты, текстовые блоки)
- `positionDrawer` — инлайн-редактирование выбранной позиции

---

## Заголовок (WorkspaceHeader)

- Номер и название проекта
- Итоговая сумма (`projectTotalSum` = материалы + операции + фасады + фурнитура + работы + расходы)
- Бейдж последней ревизии
- Кнопки: **Создать PDF**, **Ревизия (strict)**, **Обновить**, **Настройки**

---

## Полоса предупреждений (ProjectHealthBar)

Вычисляемый массив `healthIssues` с severity и действием-ссылкой. При клике — навигация к нужному модулю. Dismissible.

---

## Дочерние компоненты

| Компонент | Файл | Роль |
|-----------|------|------|
| `WorkspaceHeader` | `@/components/workspace/WorkspaceHeader.vue` | Верхняя панель: заголовок, сумма, действия |
| `WorkspaceSidebar` | `@/components/workspace/WorkspaceSidebar.vue` | Левый сайдбар с модулями |
| `ProjectHealthBar` | `@/components/workspace/ProjectHealthBar.vue` | Полоса предупреждений |
| `ProjectSettingsDrawer` | `@/components/ProjectSettingsDrawer.vue` | Правый drawer настроек проекта |
| `ImportPositionsDialog` | `@/components/ImportPositionsDialog.vue` | Диалог импорта из Excel |
| `RowHoverActions` | `@/components/RowHoverActions.vue` | Быстрые действия при наведении на строку |
| `ProfileRatesSection` | `@/components/ProfileRatesSection.vue` | Блок ставок профилей (в модуле Работы) |

---

## API-эндпоинты

### Проект
| Метод | Эндпоинт | Назначение |
|-------|----------|-----------|
| GET | `/api/projects/:id` | Загрузить проект |
| PUT | `/api/projects/:id` | Сохранить проект (автосохранение, debounce 800 мс) |

### Позиции
| Метод | Эндпоинт | Назначение |
|-------|----------|-----------|
| GET | `/api/projects/:id/positions` | Загрузить позиции |
| POST | `/api/projects/:id/positions` | Создать позицию |
| PUT | `/api/project-positions/:id` | Обновить позицию / отдельное поле |
| DELETE | `/api/project-positions/:id` | Удалить позицию |
| POST | `/api/projects/:id/positions/bulk` | Массовое обновление |

### Справочники
| GET `/api/detail-types` | GET `/api/operations` | GET `/api/units` | GET `/api/regions` | GET `/api/position-profiles` | GET `/api/materials` | GET `/api/materials/catalog` | GET `/api/facade-materials` |

### Работы и ставки
| Метод | Эндпоинт | Назначение |
|-------|----------|-----------|
| POST | `/api/projects/:id/labor-works/recalculate` | Пересчёт работ (preview) |
| POST | `/api/projects/:id/profile-rates/recalculate-and-fix` | Пересчёт ставок |
| POST | `/api/projects/:id/profile-rates/lock` | Заблокировать ставки |
| POST | `/api/projects/:id/profile-rates/unlock` | Разблокировать ставки |

### Ревизии
| Метод | Эндпоинт | Назначение |
|-------|----------|-----------|
| GET | `/api/projects/:id/revisions/latest` | Последняя ревизия |
| GET | `/api/projects/:id/revisions` | История ревизий |
| GET | `/api/projects/:id/revisions/:num` | Снапшот ревизии |
| POST | `/api/projects/:id/revisions/:num/publish` | Опубликовать |
| GET | `/api/projects/:id/revisions/:num/pdf` | PDF сметы (blob) |
| GET | `/api/projects/:id/revisions/:num/price-justification.pdf` | PDF обоснования (blob) |

### Сессия ревизии (strict)
| Метод | Эндпоинт | Назначение |
|-------|----------|-----------|
| POST | `/api/projects/:id/revisions/run` | Запустить сессию |
| GET | `/api/projects/:id/revisions/run/:runId` | Опросить состояние / позиции |
| POST | `/api/projects/:id/revisions/run/:runId/retry` | Повторить |
| POST | `/api/projects/:id/revisions/run/:runId/finalize` | Финализировать |
| POST | `/api/revisions/run/:runId/items/:itemId/manual` | Ручное закрытие позиции |

---

## Автосохранение

Любое изменение полей проекта → debounce 800 мс → `PUT /api/projects/:id`. Перед критическими операциями вызывается `flushAutoSave()` для немедленного сброса без ожидания.

---

## Загрузка страницы (порядок)

1. Параллельно: справочники (типы деталей, материалы, операции, единицы, регионы, профили)
2. Данные проекта + позиции + фурнитура + расходы + нормо-часы + работы
3. Последняя ревизия + история ревизий
4. Проверка `localStorage` на активную сессию ревизии → восстановление и автополлинг (каждые **2,5 с**)
5. `loadingReady = true` → оверлей-скелетон сменяется UI

---

## Жизненный цикл сессии ревизии (strict)

```
Кнопка «Ревизия (strict)»
        │
        ▼
POST /revisions/run         → создаёт RevisionRun + RevisionRunItem для каждой позиции
        │
        ▼
Автополлинг каждые 2.5 с    → GET /revisions/run/:id (обновляет счётчики и статусы)
        │
   ┌────┴────────────────┐
NEEDS_MANUAL          READY
   │                     │
Пользователь         auto-finalize()
открывает                │
manualCloseDialog        ▼
(price + screenshot)  POST /finalize
                         │
                         ▼
                  ProjectRevision (snapshot_json)
                  + ссылки на PDF
```

### Сохранение сессии между перезагрузками

ID активного `RevisionRun` сохраняется в `localStorage` под ключом `project:{id}:activeRevisionRunId` и восстанавливается при монтировании компонента.
