# Архитектура: Доказательства и Ревизии

> Актуально на: апрель 2026  
> Охватывает: Backend (Laravel), Frontend (Vue 3), Chrome Extension (Manifest V3)

---

## Содержание

1. [Обзор системы](#1-обзор-системы)
2. [Два параллельных контура](#2-два-параллельных-контура)
3. [Схема базы данных](#3-схема-базы-данных)
4. [API эндпоинты](#4-api-эндпоинты)
5. [Chrome Extension](#5-chrome-extension)
6. [Потоки данных](#6-потоки-данных)
7. [Backend сервисы](#7-backend-сервисы)
8. [Frontend архитектура](#8-frontend-архитектура)
9. [Feature Flags](#9-feature-flags)
10. [Жизненный цикл статусов](#10-жизненный-цикл-статусов)
11. [Хранение файлов и скриншотов](#11-хранение-файлов-и-скриншотов)
12. [Аутентификация Chrome Extension](#12-аутентификация-chrome-extension)
13. [Сравнительная таблица контуров](#13-сравнительная-таблица-контуров)

---

## 1. Обзор системы

На странице `/projects/{id}/edit` реализованы два независимых механизма обоснования стоимости:

- **Ревизии (Revision)** — автоматический сбор цен с сайтов поставщиков через веб-парсер. При неудаче парсинга пользователь указывает цену вручную через Chrome Extension.
- **Доказательства (Evidence)** — новый гибкий механизм сбора ценовых обоснований через Chrome Extension. Поддерживает любые типы затрат через полиморфные связи.

Оба контура связаны с таблицами фотодоказательств, PDF-отчётами и снапшотом проекта.

```
Пользователь открывает страницу поставщика
          ↓
Chrome Extension (popup.js + lib/api.js)
          ↓
      ┌───────┐          ┌─────────────┐
      │ Ревизия│          │Доказательства│
      └───┬───┘          └──────┬──────┘
          ↓                     ↓
   RevisionRunItem        EstimateEvidenceItem
          ↓                     ↓
   EvidenceArtifact       EvidenceRecord
          ↓                     ↓
   EvidenceAsset          GenericEvidenceAsset
          ↓                     ↓
   MaterialPriceHistory   EvidenceLink (полиморф.)
          ↓                     ↓
          └──────┬──────────────┘
                 ↓
            PDF-отчёт
```

---

## 2. Два параллельных контура

### Ревизии (устаревший/legacy)

- **Запускается** через `POST /projects/{project}/revisions/run`
- **Автоматически** запрашивает парсер (через `RunRevisionUpdateJob`) для каждого URL-поставщика
- **При сбое** — переходит в состояние `NEEDS_MANUAL`, пользователь отправляет скриншот вручную через расширение
- **Статусы** — UPPERCASE: `PENDING`, `IN_PROGRESS`, `NEEDS_MANUAL`, `READY`, `FAILED`, `FINALIZED`
- **Контейнер** — `revision_runs` + `revision_run_items`
- **Артефакт** — `evidence_artifacts` + `evidence_assets`

### Доказательства (pipeline v2 / новый)

- **Запускается** через `POST /projects/{project}/evidence-runs`
- **Заполнение элементов** — `EvidenceRunItemCollector::populateRun()` создаёт `EstimateEvidenceItem` для каждого ценового компонента проекта
- **Пользователь** разрешает элементы вручную: через Chrome Extension, вводя `evidence_record_id`, или пропуская
- **Статусы** — lowercase: `pending`, `in_progress`, `ready`, `finalized`, `failed`
- **Контейнер** — `estimate_evidence_runs` + `estimate_evidence_items`
- **Артефакт** — `evidence_records` + `generic_evidence_assets` + `evidence_links`

> **Правило различия**: Если видите UPPERCASE статусы — это Ревизия. Lowercase — это Доказательства.

---

## 3. Схема базы данных

### Контур Ревизий

#### `revision_runs`
| Колонка | Тип | Описание |
|---------|-----|----------|
| id | bigint PK | — |
| project_id | FK(projects) | Проект |
| initiator_user_id | FK(users) | Кто запустил |
| status | ENUM | `PENDING`, `IN_PROGRESS`, `NEEDS_MANUAL`, `READY`, `FAILED`, `FINALIZED` |
| total_items | unsigned int | Всего элементов |
| ok_items | unsigned int | Успешно разрешённых |
| failed_items | unsigned int | Проваленных |
| started_at | timestamp | Начало |
| finished_at | timestamp | Конец |
| last_error | text | Текст ошибки |
| created_at / updated_at | timestamps | — |

#### `revision_run_items`
| Колонка | Тип | Описание |
|---------|-----|----------|
| id | bigint PK | — |
| revision_run_id | FK | Родительский прогон |
| project_position_id | FK | Позиция проекта |
| project_fitting_id | FK nullable | Фурнитура |
| material_id | FK nullable | Материал |
| source_url | text | URL поставщика |
| status | ENUM | `OK`, `BLOCKED`, `TIMEOUT`, `PARSE_ERROR`, `NO_TEMPLATE`, `NEEDS_MANUAL`, `OK_NO_PRICE` |
| state | string | `pending`, `running`, `failed`, `manual_required`, `manual_verified`, `auto_verified`, `finalized` |
| cost_driver_type | string | `plate`, `edge`, `facade`, `fitting`, `operation`, `labor_work`, `expense` |
| evidence_subject_type | string | Полиморфный тип |
| evidence_subject_id | bigint | Полиморфный ID |
| price_history_id | FK nullable | Ссылка на историю цен |
| diagnostics_json | JSON | Отладочная информация |
| attempt_count | int | Счётчик попыток |

#### `evidence_artifacts` (артефакты ревизии + авто-парсинга)
| Колонка | Тип | Описание |
|---------|-----|----------|
| id | bigint PK | — |
| uuid | UUID | Уникальный идентификатор |
| material_id | FK | Материал |
| revision_run_id | FK | Прогон ревизии |
| revision_run_item_id | FK | Элемент прогона |
| mode | ENUM | `auto` (парсинг) / `manual` (пользователь) |
| capture_source | string | `auto_scrape`, `manual_entry`, `chrome_extension`, `INTERNAL` |
| source_url_raw | text | Исходный URL |
| source_url_normalized | string | Нормализованный URL |
| source_domain | string | Домен поставщика |
| extracted_price | decimal(12,2) | Цена |
| currency | string | Валюта |
| extracted_name | string | Название товара |
| extracted_article | string | Артикул |
| screenshot_path | string | Путь к скриншоту |
| screenshot_sha256 | string | SHA256 файла скриншота |
| confidence_score | tinyint | Уверенность AI (0-100) |
| trust_score | tinyint | Доверие к источнику (0-100) |
| created_by | FK(users) | Автор |

#### `evidence_assets` (файлы артефактов ревизии)
| Колонка | Тип | Описание |
|---------|-----|----------|
| id | bigint PK | — |
| uuid | UUID | — |
| evidence_artifact_id | FK | Родительский артефакт |
| asset_type | string | `screenshot`, `document`, `receipt`, `price_list` |
| file_path | string | Путь в хранилище |
| original_filename | string | Имя файла |
| mime_type | string | MIME type |
| file_size | bigint | Размер в байтах |
| sha256 | string | Хэш файла |
| metadata_json | JSON | Доп. информация |

---

### Контур Доказательств (pipeline v2)

#### `estimate_evidence_runs` (сессия сбора доказательств)
| Колонка | Тип | Описание |
|---------|-----|----------|
| id | bigint PK | — |
| uuid | UUID | — |
| project_id | FK(projects) | Проект |
| initiated_by | FK(users) | Кто запустил |
| status | string | `pending`, `in_progress`, `ready`, `finalized`, `failed` |
| total_items | unsigned int | Всего элементов |
| completed_items | unsigned int | Завершённых |
| failed_items | unsigned int | Проваленных |
| metadata_json | JSON | Конфигурация прогона |
| snapshot_json | JSON | Снапшот проекта на момент старта |
| started_at | timestamp | — |
| finalized_at | timestamp | — |

#### `estimate_evidence_items` (ценовой компонент, требующий доказательства)
| Колонка | Тип | Описание |
|---------|-----|----------|
| id | bigint PK | — |
| uuid | UUID | — |
| evidence_run_id | FK | Родительский прогон |
| cost_component | string | `plate`, `edge`, `facade`, `fitting`, `operation`, `labor_work`, `expense` |
| label | string | Человекочитаемая метка |
| status | string | `pending`, `collecting`, `resolved`, `failed`, `skipped` |
| resolution_type | string | `auto`, `manual`, `chrome`, `skipped` |
| subject_type | string | Полиморфный тип ценового компонента |
| subject_id | bigint | Полиморфный ID |
| evidence_record_id | FK nullable | Привязанная запись доказательства |
| source_url | string | Откуда взято доказательство |
| effective_value | decimal(12,2) | Итоговое значение цены |
| currency | string | Валюта |
| diagnostics_json | JSON | Отладочная информация |

#### `evidence_records` (запись наблюдения за ценой)
| Колонка | Тип | Описание |
|---------|-----|----------|
| id | bigint PK | — |
| uuid | UUID | — |
| cost_component | string | `plate`, `edge`, `facade`, `fitting`, `operation`, `labor_work`, `expense` |
| source_type | string | `CHROME_CAPTURE`, `MANUAL_ENTRY`, ... |
| capture_method | string | `auto_scrape`, `manual_entry`, `chrome_extension`, `file_upload`, `api_import` |
| verification_status | string | `pending`, `auto_verified`, `manual_verified`, `rejected`, `stale` |
| source_url | string | URL страницы поставщика |
| source_domain | string | Домен |
| observed_price | decimal(12,2) | Наблюдаемая цена |
| currency | string | Валюта (по умолчанию `RUB`) |
| observed_at | timestamp | Время наблюдения |
| extracted_name | string | Название товара |
| extracted_article | string | Артикул |
| metadata_json | JSON | Контекст захвата (селекторы, режим, браузер) |
| confidence_score | smallint | Уверенность (0-100) |
| trust_score | smallint | Доверие (0-100, по умолчанию 60) |
| created_by | FK(users) | Автор |

#### `generic_evidence_assets` (файлы доказательств)
| Колонка | Тип | Описание |
|---------|-----|----------|
| id | bigint PK | — |
| uuid | UUID | — |
| evidence_record_id | FK | Родительская запись |
| asset_type | string | `screenshot`, `document`, ... |
| file_path | string | Путь в хранилище |
| original_filename | string | Имя файла |
| mime_type | string | MIME type |
| file_size | unsigned int | Размер в байтах |
| sha256 | string(64) | Хэш файла (для дедупликации) |
| metadata_json | JSON | Доп. информация |

#### `evidence_links` (полиморфная связь записи с субъектом)
| Колонка | Тип | Описание |
|---------|-----|----------|
| id | bigint PK | — |
| evidence_record_id | FK | Запись доказательства |
| linkable_type | string | Полиморфный тип (напр. `EstimateEvidenceItem`) |
| linkable_id | bigint | Полиморфный ID |
| relation_type | string | `primary`, `captured_for`, ... |
| Unique | — | (evidence_record_id, linkable_type, linkable_id) |

---

## 4. API эндпоинты

### Аутентификация Chrome Extension

```
POST   /api/chrome/auth/token                  Выдать токен по email/password
POST   /api/chrome/auth/token/session          Выдать токен из активной веб-сессии
GET    /api/chrome/auth/status                 Проверить наличие токена
POST   /api/chrome/auth/revoke                 Отозвать токен
```

### Ревизионные доказательства (Chrome Extension → Ревизия)

```
GET    /api/chrome/revision-items              Список открытых RevisionRunItem
POST   /api/chrome/revision-items/{id}/evidence  Отправить доказательство для элемента
                                                  (FormData: price, currency, source_url, screenshot_file)
```

### Доказательства (Chrome Extension → Evidence)

```
GET    /api/chrome/generic-items               Список открытых EstimateEvidenceItem
POST   /api/chrome/capture-observation         Захватить независимое наблюдение
POST   /api/chrome/generic-items/{id}/capture  Захватить доказательство для конкретного элемента
POST   /api/chrome/extract-with-evidence       Извлечь + прикрепить как доказательство
```

> Все четыре эндпоинта требуют `EVIDENCE_GENERIC_CHROME_ENABLED=true` в `.env`.  
> Возвращают `404` если флаг отключён.

### Управление прогонами Доказательств (Web App)

```
POST   /api/projects/{project}/evidence-runs                           Создать прогон
GET    /api/projects/{project}/evidence-runs                           Список прогонов
GET    /api/projects/{project}/evidence-runs/{runId}                   Детали прогона + элементы
POST   /api/projects/{project}/evidence-runs/{runId}/finalize          Финализировать прогон
POST   /api/projects/{project}/evidence-runs/{runId}/items/{id}/resolve  Разрешить элемент
POST   /api/projects/{project}/evidence-runs/{runId}/items/{id}/skip  Пропустить элемент
GET    /api/projects/{project}/evidence-runs/{runId}/pdf               Скачать PDF-отчёт
```

### Управление прогонами Ревизий (Web App)

```
POST   /api/projects/{project}/revisions/run                          Создать прогон ревизии
GET    /api/projects/{project}/revisions/run/{runId}                  Детали прогона
POST   /api/projects/{project}/revisions/run/{runId}/retry            Повторить
POST   /api/revisions/run/{runId}/items/{id}/manual                   Вручную задать цену
POST   /api/revisions/run/{runId}/items/{id}/attach-document          Прикрепить документ
POST   /api/projects/{project}/revisions/run/{runId}/finalize         Финализировать
```

---

## 5. Chrome Extension

### Архитектура расширения

```
chrome-extension/
├── manifest.json              Manifest V3
├── background/
│   └── background.js          Service Worker — маршрутизация сообщений
├── content/
│   └── content.js             Content Script — анализ DOM страницы
├── popup/
│   ├── popup.html             UI расширения
│   ├── popup.js               Основная логика UI
│   └── popup.css              Стили
└── lib/
    └── api.js                 PrizmAPI — клиент к бэкенду
```

### PrizmAPI — клиентская библиотека

```javascript
class PrizmAPI {
  // Конфигурация
  configure(baseUrl, token)
  isAuthenticated()
  getMe()

  // Шаблоны
  listTemplates(domain)
  findTemplate(url)
  saveTemplate(data)
  deleteTemplate(id)

  // Извлечение данных
  extract(url, extracted, templateId?, regionId?, dataSources?)
  validateFields(extracted, dataSources?, url?)

  // === Ревизия ===
  getRevisionItems()                         // GET /chrome/revision-items
  submitItemEvidence(itemId, formData)       // POST /chrome/revision-items/{id}/evidence

  // === Доказательства ===
  getGenericItems()                          // GET /chrome/generic-items
  captureObservation(formData)              // POST /chrome/capture-observation
  captureGenericItem(itemId, formData)      // POST /chrome/generic-items/{id}/capture

  // Авторизация
  login(email, password)
  issueToken(email, password)
}
```

> **Важно**: Запросы FormData (загрузка файлов/скриншотов) делаются **напрямую из popup.js**, минуя Service Worker. Это обязательно: `FormData` не сериализуется через `chrome.runtime.sendMessage`.

### Панель Ревизии (`revision-panel`)

В `popup.js`:

```javascript
// Загрузка элементов ревизии
async function loadRevisionItems()
  → GET /chrome/revision-items
  → рендерит список RevisionRunItem
  → показывает панель если есть элементы

// Форма отправки
function showRevisionForm(itemId)
  → отображает поля: цена, валюта
  → предзаполняет ценой из захваченных полей страницы

// Захват + отправка
async function handleRevisionSubmit()
  1. captureVisibleTab()  — скриншот
  2. prizmApi.submitItemEvidence(itemId, FormData{price, currency, screenshot_file})
  3. UI: статус скриншота ✓/✗
```

### Панель Доказательств (`generic-evidence-panel`)

```javascript
// Загрузка элементов
async function loadGenericItems()
  → GET /chrome/generic-items
  → рендерит список EstimateEvidenceItem
  → панель скрыта если нет элементов

// Форма для конкретного элемента
function showGenericForm(itemId)
  → выбор позиции из списка
  → поля: цена, валюта, скриншот-статус

// Захват привязанный к элементу
async function handleGenericCapture()
  1. captureVisibleTab()  — скриншот текущей вкладки
  2. prizmApi.captureGenericItem(itemId, FormData{price, currency, source_url, screenshot_file})
  3. Успех: элемент убирается из списка; панель скрывается если список пуст
```

---

## 6. Потоки данных

### Поток: Ревизия через Chrome Extension

```
1. Пользователь открывает страницу поставщика в браузере
2. Открывает popup расширения
3. loadRevisionItems() → GET /chrome/revision-items
   ← Список RevisionRunItem с status=NEEDS_MANUAL
4. Выбирает нужный элемент, вводит цену
5. Нажимает "Отправить доказательство"
6. Popup:
   a. chrome.tabs.captureVisibleTab() → JPEG Data URL
   b. Blob из Data URL
   c. FormData { price_per_unit, currency, source_url, screenshot_file }
   d. prizmApi.submitItemEvidence(itemId, formData)
      → POST /chrome/revision-items/{id}/evidence
7. RevisionRunController.manual():
   a. Сохраняет файл → storage/public/screenshots/manual/{Y/m}/{uuid}.jpg
   b. Создаёт EvidenceArtifact { mode='manual', capture_source='MANUAL', extracted_price, ... }
   c. Создаёт EvidenceAsset { file_path, sha256, mime_type }
   d. Создаёт MaterialPriceHistory { price, evidence_artifact_id }
   e. Обновляет RevisionRunItem: status='OK', state='manual_verified'
   f. Пересчитывает счётчики RevisionRun
   g. Если все элементы завершены → RevisionRun.status = 'READY'
8. Popup показывает: "Доказательство отправлено ✓"
```

### Поток: Независимое наблюдение (capture-observation)

```
1. Пользователь на странице поставщика
2. Открывает popup, нажимает "Захватить наблюдение"
3. Popup:
   a. chrome.tabs.captureVisibleTab() → JPEG
   b. FormData { cost_component, observed_price, currency, source_url,
                 extracted_name, extracted_article, screenshot_file,
                 capture_mode='viewport' }
   c. prizmApi.captureObservation(formData)
      → POST /chrome/capture-observation
4. GenericChromeController.captureObservation():
   a. abort(404) если !EvidenceFeatures::genericChromeEnabled()
   b. Валидация полей
   c. GenericChromeCaptureService.captureObservation(payload, userId, screenshot)
5. GenericChromeCaptureService:
   a. Нормализация URL
   b. Проверка дублей: тот же URL + cost_component + user + последние 60 сек
      → Если дубль: возвращает существующий EvidenceRecord
   c. Создаёт EvidenceRecord {
        uuid, cost_component, source_type='CHROME_CAPTURE',
        capture_method='chrome_extension', verification_status='pending',
        observed_price, currency, source_url, extracted_name, extracted_article,
        trust_score=60, metadata_json={capture_mode, selectors, browser_context}
      }
   d. Если есть скриншот:
      - Вычисляет SHA256
      - Проверяет на дубль в рамках записи
      - Сохраняет в storage/public/screenshots/chrome/generic/{Y/m}/{uuid}.jpg
      - Создаёт GenericEvidenceAsset { file_path, sha256, evidence_record_id }
   e. Возвращает { record_id, record_uuid, asset_id, duplicate: false }
6. Popup: "Наблюдение захвачено ✓"
```

### Поток: Захват привязанный к элементу (captureGenericItem)

```
1. Пользователь выбирает EstimateEvidenceItem из списка в popup
2. Popup:
   a. Захват скриншота
   b. prizmApi.captureGenericItem(itemId, formData)
      → POST /chrome/generic-items/{itemId}/capture
3. GenericChromeController.captureGenericItem():
   a. Загружает EstimateEvidenceItem
   b. Проверяет: элемент не в терминальном статусе
   c. Проверяет: run.initiated_by === auth()->id()
   d. Вызывает GenericChromeCaptureService.captureForItem(item, payload, userId, screenshot)
4. GenericChromeCaptureService.captureForItem():
   a. Вызывает captureObservation() → создаёт EvidenceRecord
   b. Создаёт EvidenceLink {
        evidence_record_id, linkable_type='EstimateEvidenceItem',
        linkable_id=item.id, relation_type='captured_for'
      }
   c. Обновляет EstimateEvidenceItem {
        status='resolved', resolution_type='chrome',
        evidence_record_id=record.id, effective_value, currency
      }
   d. refreshRunCounters(run):
      - Подсчитывает завершённые элементы
      - Если все терминальные → EstimateEvidenceRun.status = 'ready'
5. Popup: элемент исчезает из списка, показывает "✓"
```

### Поток: Создание и финализация прогона Доказательств (Web App)

```
1. Пользователь нажимает "Создать прогон" в EvidenceRunPanel.vue
2. useEvidenceRun.createRun() → POST /api/projects/{id}/evidence-runs
3. EvidenceRunController.store():
   a. Создаёт EstimateEvidenceRun { status='pending', snapshot_json }
   b. EvidenceRunItemCollector.populateRun():
      - Находит все ценовые компоненты проекта (плиты, кромки, фасады, фурнитуру, операции, работы, расходы)
      - Для каждого создаёт EstimateEvidenceItem { cost_component, label, status='pending', subject_type, subject_id }
   c. Возвращает run + items
4. Расширение видит новые элементы в следующем вызове GET /chrome/generic-items
5. Пользователь обходит страницы поставщиков, захватывает доказательства
6. После разрешения всех элементов: run.status автоматически → 'ready'
7. Пользователь нажимает "Финализировать" в EvidenceRunPanel.vue
8. useEvidenceRun.finalizeRun() → POST /api/projects/{id}/evidence-runs/{id}/finalize
9. EvidenceRunFinalizer.finalize():
   - Проверяет: все элементы в терминальном статусе (resolved/failed/skipped)
   - Обновляет: status='finalized', finalized_at=now()
10. PDF-отчёт доступен по GET /api/.../pdf
```

---

## 7. Backend сервисы

### `GenericChromeCaptureService`
**Файл**: `server/app/Services/GenericChromeCaptureService.php`

Основные методы:

```php
captureObservation(array $payload, int $userId, ?UploadedFile $screenshot): array
  // Нормализует URL, проверяет дубли, создаёт EvidenceRecord + GenericEvidenceAsset
  // Возвращает: [record, asset, duplicate]

captureForItem(EstimateEvidenceItem $item, array $payload, int $userId, ?UploadedFile $screenshot): array
  // Вызывает captureObservation(), создаёт EvidenceLink, обновляет item + run
  // Возвращает: [record, asset, duplicate, success]

findDuplicate(array $payload, int $userId): ?EvidenceRecord
  // same source_url + cost_component + user + within 60 seconds

storeScreenshot(EvidenceRecord $record, UploadedFile $file): GenericEvidenceAsset
  // SHA256-дедупликация внутри одной записи
  // Путь: storage/public/screenshots/chrome/generic/{Y/m}/{uuid}.ext

refreshRunCounters(EstimateEvidenceRun $run): void
  // Пересчитывает completed_items, failed_items
  // Если все терминальные → run.status = 'ready'
```

### `EvidenceRunItemCollector`
Автоматически создаёт `EstimateEvidenceItem` для всех ценовых компонентов проекта при старте прогона.

### `EvidenceRunFinalizer`
Проверяет что все элементы терминальные, затем устанавливает `status=finalized`.

### `EstimateEvidencePdfBuilder`
Генерирует PDF-отчёт финализированного прогона со всеми ссылками на доказательства.

### `RevisionRunController@manual`
Обрабатывает ручную отправку доказательств для ревизии:
- Сохраняет скриншот → `storage/public/screenshots/manual/{Y/m}/`
- Создаёт EvidenceArtifact + EvidenceAsset
- Создаёт MaterialPriceHistory
- Обновляет RevisionRunItem

---

## 8. Frontend архитектура

### Компоненты Доказательств

**`EvidenceRunPanel.vue`** — основной контейнер
- Список прогонов + выбор активного
- Кнопки: Создать прогон, Обновить, Финализировать, Скачать PDF
- Показывает `EvidenceItemsTable` и `EvidenceCoverageSummary`
- Подсказки по использованию Chrome Extension

**`EvidenceItemsTable.vue`** — таблица элементов
- Колонки: метка, статус, цена, валюта, linked record ID
- Действия: разрешить (ввод evidence_record_id), пропустить (с причиной)
- Стилизация строк: пропущенные (dim), проваленные (red)

**`EvidenceResolutionDialog.vue`** — диалог разрешения
- Режим Resolve: ввод evidence_record_id, resolution_type
- Режим Skip: причина пропуска

**`EvidenceCoverageSummary.vue`** — прогресс-индикатор
- Показывает: всего / resolved / skipped / failed / pending
- Цветные чипы

### Composable `useEvidenceRun.ts`

```typescript
// client/src/composables/useEvidenceRun.ts
useEvidenceRun(projectId: Ref<number>) {
  // Состояние
  runs: Ref<EvidenceRun[]>
  selectedRun: Ref<EvidenceRun & {items: EvidenceItem[]} | null>
  loading, actionLoading, error, pdfDownloading: Ref<boolean>

  // Вычисляемые
  runsCount, coverage, canFinalize, isFinalized
  selectedRunItems

  // Методы
  fetchRuns()           // Загружает список прогонов
  createRun()           // Создаёт новый прогон
  selectRun(runId)      // Загружает детали прогона
  resolveItem(itemId, evidenceRecordId)  // Разрешает элемент
  skipItem(itemId, reason?)             // Пропускает элемент
  finalizeRun()         // Финализирует прогон
  downloadPdf()         // Скачивает PDF-отчёт
}
```

### API-клиент `evidenceRun.ts`

```typescript
// client/src/api/evidenceRun.ts

type EvidenceRunStatus = 'pending' | 'in_progress' | 'ready' | 'finalized' | 'failed'
type EvidenceItemStatus = 'pending' | 'collecting' | 'resolved' | 'failed' | 'skipped'
type CostComponent = 'plate' | 'edge' | 'facade' | 'fitting' | 'operation' | 'labor_work' | 'expense'

// Методы:
list(projectId)
create(projectId)
show(projectId, runId)
resolveItem(projectId, runId, itemId, { evidence_record_id })
skipItem(projectId, runId, itemId, { reason? })
finalize(projectId, runId)
downloadPdf(projectId, runId)  // → Blob
```

---

## 9. Feature Flags

Файл: `server/app/Evidence/EvidenceFeatures.php`

| Флаг в `.env` | Значение конфига | Описание |
|--------------|-----------------|----------|
| `EVIDENCE_PIPELINE_V2=true` | `smeta.evidence.pipeline_v2` | Включает Evidence Runs (Доказательства) |
| `EVIDENCE_GENERIC_CHROME_ENABLED=true` | `smeta.evidence.generic_chrome_enabled` | Включает 4 Chrome-эндпоинта захвата |
| `EVIDENCE_FACADE_ENABLED=true` | `smeta.evidence.facade_enabled` | Фасады — отдельный контур |
| `EVIDENCE_OPERATIONS_ENABLED=true` | `smeta.evidence.operations_enabled` | Операции |
| `EVIDENCE_LABOR_WORK_ENABLED=true` | `smeta.evidence.labor_work_enabled` | Трудозатраты |
| `EVIDENCE_EXPENSES_ENABLED=true` | `smeta.evidence.expenses_enabled` | Расходы |
| `EVIDENCE_EXPENSES_DOCUMENT_ENABLED=true` | `smeta.evidence.expenses_document_enabled` | Документы расходов |
| `EVIDENCE_CHROME_REVISION_ENABLED=true` | `smeta.evidence.chrome_revision_enabled` | Ревизия через Chrome Extension |

**Порядок включения для базового сценария**:
1. `EVIDENCE_GENERIC_CHROME_ENABLED=true` — даёт возможность захвата через расширение
2. `EVIDENCE_PIPELINE_V2=true` — включает UI управления прогонами Доказательств

После изменения `.env`:
```bash
php artisan config:clear
php artisan config:cache
```

---

## 10. Жизненный цикл статусов

### Прогон Ревизии

```
PENDING
  → IN_PROGRESS (при старте RunRevisionUpdateJob)
    → NEEDS_MANUAL (если парсер не справился)
    → READY (все элементы завершены)
      → FINALIZED (пользователь финализировал)
    → FAILED (критическая ошибка)
```

### Элемент Ревизии

```
PENDING → running → OK | BLOCKED | TIMEOUT | PARSE_ERROR | NO_TEMPLATE | NEEDS_MANUAL
NEEDS_MANUAL → (manual submission) → OK (state: manual_verified)
OK → finalized (при финализации прогона)
```

### Прогон Доказательств

```
pending
  → in_progress (при первом захвате)
    → ready (все элементы терминальные — refreshRunCounters авто-переход)
      → finalized (пользователь нажал Финализировать)
    → failed (критическая ошибка)
```

### Элемент Доказательств

```
pending
  → collecting (при старте захвата)
    → resolved (evidence_record привязан)
    → failed
    → skipped (пользователь пропустил)
```

---

## 11. Хранение файлов и скриншотов

| Тип файла | Путь в хранилище | Дедупликация |
|-----------|-----------------|--------------|
| Ревизия (ручной скриншот) | `storage/public/screenshots/manual/{Y/m}/{uuid}.jpg` | Нет (всегда новый файл) |
| Доказательства (Chrome скриншот) | `storage/public/screenshots/chrome/generic/{Y/m}/{uuid}.jpg` | SHA256: проверка в рамках одной `EvidenceRecord` |
| Документы расходов | `storage/app/evidence-documents/` | SHA256 |

**Формат скриншота**: JPEG, quality=80, viewport capture (`chrome.tabs.captureVisibleTab`)

**Размеры viewport**: сохраняются в `evidence_artifacts.viewport_w / viewport_h`

---

## 12. Аутентификация Chrome Extension

```
1. Пользователь вводит email + password в popup расширения (Tab: Settings)
2. Popup вызывает: prizmApi.login(email, password)
   → POST /api/chrome/auth/token (публичный эндпоинт, без auth middleware)
3. Сервер:
   a. Находит пользователя по email
   b. Отзывает предыдущие токены с именем 'chrome-extension'
   c. Создаёт Sanctum токен с abilities=['chrome-ext']
   d. Возвращает { token, user }
4. Popup сохраняет токен в chrome.storage.local
5. Все последующие запросы: Authorization: Bearer {token}
6. Middleware laravel sanctum проверяет токен перед каждым запросом
7. При истечении: токен инвалидируется, расширение показывает форму входа
```

**Безопасность**:
- Токены хранятся в `chrome.storage.local` (не в `localStorage`)
- Доступ к элементам: `$item->run->initiated_by === auth()->id()`
- Финализация прогона: требует `update` policy на Project
- PDF: возвращает 404 если PDF ещё не сгенерирован

---

## 13. Сравнительная таблица контуров

| Аспект | Ревизии | Доказательства |
|--------|---------|----------------|
| **Формат статусов** | UPPERCASE | lowercase |
| **Контейнер прогона** | `revision_runs` | `estimate_evidence_runs` |
| **Элементы** | `revision_run_items` | `estimate_evidence_items` |
| **Артефакт** | `evidence_artifacts` → `evidence_assets` | `evidence_records` → `generic_evidence_assets` |
| **Связь** | RevisionRunItem → прямая FK | `evidence_links` (полиморфная) |
| **Автосбор** | ✅ Парсер (RunRevisionUpdateJob) | ❌ Только вручную через расширение |
| **Chrome эндпоинт** | `POST /chrome/revision-items/{id}/evidence` | `POST /chrome/generic-items/{id}/capture` |
| **Дедупликация** | Нет | По URL + cost_component + user + 60сек |
| **Типы затрат** | Все из проекта | Только включённые через feature flags |
| **История цен** | `material_price_histories` (с artifact_id) | Опционально через `evidence_record_id` |
| **Снапшот** | Нет | `snapshot_json` в `estimate_evidence_runs` |
| **Feature Flag** | `EVIDENCE_CHROME_REVISION_ENABLED` | `EVIDENCE_GENERIC_CHROME_ENABLED` + `EVIDENCE_PIPELINE_V2` |
| **Счётчики** | `total_items`, `ok_items`, `failed_items` | `total_items`, `completed_items`, `failed_items` |
| **Авто-переход в READY** | По статусам элементов (OK) | `refreshRunCounters()` → терминальные = resolved/failed/skipped |

---

## Связанные документы

- [chrome-extension-architecture.md](chrome-extension-architecture.md) — Детали архитектуры расширения
- [chrome-extension-and-material-types.md](chrome-extension-and-material-types.md) — Маппинг типов материалов
- [PDF_GENERATION_ARCHITECTURE.md](PDF_GENERATION_ARCHITECTURE.md) — Генерация отчётов
- [price-justification-mvp-implementation.md](price-justification-mvp-implementation.md) — MVP реализация обоснования цен
