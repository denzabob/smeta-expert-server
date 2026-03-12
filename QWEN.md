# Smeta Expert Server — Контекст Проекта

## 📋 Обзор Проекта

**Smeta Expert Server** — это полнофункциональная система для расчёта смет, управления строительными проектами и автоматизированного парсинга цен на материалы от поставщиков.

### Основные Компоненты

| Компонент | Технологии | Назначение |
|-----------|------------|------------|
| **Backend API** | Laravel 12, PHP 8.2, Sanctum | Бизнес-логика, ORM, авторизация, PDF-генерация |
| **Frontend SPA** | Vue 3, TypeScript, Vuetify 3, Pinia | Пользовательский интерфейс |
| **Parser** | Python 3, Playwright | Автоматический парсинг сайтов поставщиков |
| **Chrome Extension** | JavaScript, Service Worker | Ручной захват данных со страниц поставщиков |
| **Database** | MariaDB 10.6 | Хранение данных |
| **Web Server** | Nginx (Alpine) | Reverse proxy, статика |

### Архитектурная Схема

```
┌─────────────────────────────────────────────────────────────────┐
│                    Источники Данных                              │
│  ┌──────────────┐  ┌─────────────┐  ┌─────────────────────┐    │
│  │   Поставщики │  │   Прайсы    │  │  Chrome Extension   │    │
│  └──────┬───────┘  └──────┬──────┘  └──────────┬──────────┘    │
│         │                 │                     │               │
└─────────┼─────────────────┼─────────────────────┼───────────────┘
          │                 │                     │
          ▼                 ▼                     ▼
┌─────────────────────────────────────────────────────────────────┐
│                     Parser (Python)                              │
│  • collect_urls.py  • queue_worker_async.py  • core.py         │
│  • suppliers/*.py   • configs/*.json                           │
└────────────────────────────┬────────────────────────────────────┘
                             │
                             ▼
┌─────────────────────────────────────────────────────────────────┐
│                  Laravel API (server/)                           │
│  • Materials  • Projects  • Operations  • Revisions            │
│  • PDF Generation (DomPDF)  • Queue Workers                     │
│  • Sanctum Auth  • Event Observers                              │
└────────────────────────────┬────────────────────────────────────┘
                             │
              ┌──────────────┴──────────────┐
              ▼                             ▼
┌──────────────────────┐        ┌──────────────────────┐
│   Frontend (client/) │        │   MariaDB Database   │
│   Vue 3 + Vuetify    │        │   + Redis Cache      │
└──────────────────────┘        └──────────────────────┘
```

---

## 🏗️ Структура Проекта

```
smeta-expert-server/
├── server/                 # Laravel 12 Backend
│   ├── app/
│   │   ├── Http/Controllers/Api/   # API контроллеры
│   │   ├── Models/                 # Eloquent модели
│   │   ├── Services/               # Бизнес-сервисы
│   │   ├── Observers/              # Модельные обсерверы
│   │   ├── DTOs/                   # Data Transfer Objects
│   │   └── Middleware/             # HTTP middleware
│   ├── database/migrations/        # Миграции БД
│   ├── routes/api.php              # API маршруты
│   ├── tests/                      # PHPUnit тесты
│   └── composer.json
│
├── client/                 # Vue 3 Frontend
│   ├── src/
│   │   ├── views/          # Страницы приложения
│   │   ├── components/     # Vue компоненты
│   │   ├── stores/         # Pinia stores
│   │   ├── router/         # Маршрутизация
│   │   └── api/            # API клиенты
│   ├── package.json
│   └── vite.config.ts
│
├── parser/                 # Python Parser
│   ├── suppliers/          # Адаптеры поставщиков
│   ├── configs/            # JSON конфигурации
│   ├── core.py             # Основная логика
│   ├── queue_worker_async.py
│   └── requirements.txt
│
├── docker/                 # Docker конфигурации
│   ├── app/Dockerfile      # PHP/Laravel контейнер
│   ├── spa/Dockerfile      # Frontend контейнер
│   └── nginx/              # Nginx конфиги
│
├── docs/                   # Документация
├── chrome-extension/       # Chrome расширение
└── deploy-app              # Скрипт деплоя на VPS
```

---

## 🔧 Сборка и Запуск

### Локальная Разработка (Docker)

```bash
# Запуск всех сервисов
docker compose up -d

# Просмотр логов
docker compose logs -f app

# Остановка
docker compose down
```

### Сервисы

| Сервис | Порт | Описание |
|--------|------|----------|
| `web` (Nginx) | `127.0.0.1:8000` | Reverse proxy |
| `app` (Laravel) | — | PHP приложение |
| `db` (MariaDB) | — | База данных |
| `worker` | — | Queue worker |
| `spa` | `127.0.0.1:8011` | Frontend (prod) |
| `phpmyadmin` | `8080` | Админка БД |

### Команды Разработчика

```bash
# Backend (в контейнере app)
docker compose exec app php artisan migrate
docker compose exec app php artisan test
docker compose exec app php artisan optimize:clear
docker compose exec app php artisan config:cache
docker compose exec app php artisan queue:work

# Frontend (локально)
cd client
npm install
npm run dev          # Development сервер
npm run build        # Production сборка

# Parser (локально)
cd parser
pip install -r requirements.txt
python -m parser.main --help
```

### Production Деплой (VPS)

```bash
# На VPS: быстрое обновление
./deploy-app

# Ручная последовательность
git pull --ff-only
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build
docker compose exec -T app php artisan migrate --force
docker compose exec -T app php artisan optimize:clear
```

**Важно:** VPS должен оставаться чистым Git working tree. Запрещены ручные правки в `/opt/smeta-expert-server`.

---

## 📡 API Endpoints

### Аутентификация

| Метод | Endpoint | Описание |
|-------|----------|----------|
| POST | `/api/login` | Вход (Sanctum token) |
| POST | `/api/chrome/auth/token` | Токен для расширения |
| GET | `/api/chrome/me` | Информация о пользователе |

### Материалы

| Метод | Endpoint | Описание |
|-------|----------|----------|
| GET | `/api/materials` | Список материалов |
| POST | `/api/materials` | Создать материал |
| GET | `/api/materials/catalog` | Каталог (новый flow) |
| POST | `/api/materials/catalog` | Создать в каталоге |
| POST | `/api/materials/parse-by-url` | Парсинг по URL |
| GET | `/api/materials/{id}/history` | История цены |

### Проекты

| Метод | Endpoint | Описание |
|-------|----------|----------|
| GET/POST | `/api/projects` | Список/создание проектов |
| GET/PUT/DELETE | `/api/projects/{id}` | Управление проектом |
| GET/POST | `/api/projects/{id}/labor-works` | Работы проекта |
| PATCH | `/api/projects/{id}/labor-works/reorder` | Переупорядочивание |
| GET/POST | `/api/projects/{id}/labor-works/{workId}/steps` | Шаги работ |
| POST | `/api/work/decompose` | AI декомпозиция работ |

### Parser

| Метод | Endpoint | Описание |
|-------|----------|----------|
| POST | `/api/parsing/sessions` | Создать сессию парсинга |
| GET | `/api/parsing/sessions/{id}/logs` | Логи сессии |
| POST | `/api/internal/parser/callback` | Callback от парсера |
| GET | `/api/parsing/suppliers/health` | Статус поставщиков |

### Смета и PDF

| Метод | Endpoint | Описание |
|-------|----------|----------|
| GET | `/api/smeta/report/{projectId}` | Отчёт сметы |
| GET | `/api/smeta/pdf/{project}` | Генерация PDF |
| GET | `/api/projects/{id}/revisions/{num}/pdf` | PDF ревизии |

---

## 🗄️ Модель Данных

### Ключевые Таблицы

**materials** — каталог материалов
- `price_per_unit`, `article`, `type`, `unit`, `source_url`
- `origin` (user/parser/chrome_ext)
- `trust_score`, `version`

**material_price_histories** — история наблюдений цены
- `version`, `price_per_unit`, `valid_from`, `valid_to`
- `source_type`, `screenshot_path`, `region_id`

**projects** — проекты смет
- Коэффициенты отходов, регионы, текстовые блоки
- Нормо-час ставки, обоснования

**project_labor_works** — работы в проекте
- `hours`, `hours_source` (manual/from_steps)
- `rate_per_hour`, `cost_total`
- `position_profile_id`

**project_labor_work_steps** — шаги работ
- Декомпозиция работ на подзадачи
- `hours`, `sort_order`

**parsing_sessions** — сессии парсинга
- `status`, `progress`, `supplier`
- `total_urls`, `processed_urls`

**supplier_urls** — очередь URL парсера
- `status` (pending/processing/completed/failed)
- `claimed_at`, `locked_by`

---

## 🔍 Event Observers

Система использует Laravel Observers для автоматических расчётов:

### ProjectLaborWorkObserver
- **created**: Инициализация `hours_source`
- **updating**: Валидация режима часов
- **updated**: Пересчёт `cost_total` при изменении hours/rate

### ProjectLaborWorkStepObserver
- **created**: Пересчёт часов родительской работы
- **updated**: Синхронизация часов родителя
- **deleted**: Переключение режима (from_steps → manual)

**Сервис:** `LaborWorkHoursCalculator`
- `recalculateHours()` — сумма шагов или manual значение
- `recalculateCost()` — `hours × rate_per_hour`

---

## 🧪 Тестирование

```bash
# Backend тесты
cd server
php artisan test

# Конкретный тест
php artisan test tests/Feature/LaborWorkRateBinderTest.php

# Frontend линтинг
cd client
npm run lint
npm run type-check
```

---

## 📝 Конвенции Разработки

### Backend (Laravel)

- **PSR-12** coding standard
- **PSR-4** autoloading
- DI через конструктор контроллеров
- Сервисы в `app/Services/`
- DTO в `app/DTOs/`
- Миграции с обратимыми `down()`

### Frontend (Vue 3)

- **TypeScript** строго
- **Composition API** (`<script setup>`)
- **Pinia** для state management
- **Vuetify 3** компоненты
- ESLint + Prettier

### Parser (Python)

- Python 3.10+
- Асинхронные воркеры (`asyncio`)
- Playwright для browser automation
- JSON конфигурации поставщиков
- Callback-и в Laravel API

### Git Flow

```
local → git push → VPS git pull --ff-only → deploy
```

**На VPS разрешено:**
- `git pull --ff-only`
- `docker compose up -d --build`
- `./deploy-app`
- Миграции БД

**На VPS запрещено:**
- Ручные правки исходного кода
- Изменение tracked файлов
- Ад-hoc правки Nginx конфигов

---

## 🚨 Потенциальные Проблемы

### Критичные

1. **Observer deletion safety** — `ProjectLaborWorkStepObserver::deleted()` должен читать `project_labor_work_id` ДО удаления отношения
2. **Mode switching** — При удалении последнего шага работа переключается с `from_steps` на `manual`
3. **Cost recalculation** — Может вызываться несколько раз при обновлении

### Производительность

- Дублирование `recalculateCost()` при обновлении шагов
- Округление `SUM(hours)` vs individual rounding
- Rate binding только при наличии `position_profile_id`

---

## 📚 Дополнительная Документация

| Файл | Описание |
|------|----------|
| `README_DEPLOY.md` | Политика деплоя, VPS правила |
| `docs/system-architecture.md` | Полная архитектура системы |
| `EVENT_OBSERVERS_SUMMARY.json` | Детали Event Observers |
| `INDEX.txt` | Навигация по документации labor rates |

---

## 🔑 Ключевые Сервисы

### Backend Services

- **LaborWorkRateBinder** — Привязка ставок к работам
- **LaborWorkHoursCalculator** — Расчёт часов и стоимости
- **MaterialDeduplicationService** — Дедупликация материалов
- **ChromeExtractService** — Обработка данных из расширения
- **ReportService** — Генерация отчётов сметы
- **MaterialParseService** — Создание материалов с наблюдениями

### Parser Modules

- **core.py** — Основная логика парсинга
- **queue_worker_async.py** — Асинхронная обработка очереди
- **collect_urls.py** — Сбор URL поставщиков
- **suppliers/*.py** — Адаптеры конкретных поставщиков

---

## 🌐 Маршрутизация

```
/              → SPA (Vue 3)
/api/*         → Laravel API
/sanctum/*     → Sanctum authentication
/v/*           → Legacy compatibility (prismcore.ru)
```

**Production:**
- SPA контейнер: `127.0.0.1:8011`
- API контейнер: `127.0.0.1:8000`
- Host Nginx проксирует `/` → SPA, `/api/*` → Backend

---

**Версия:** 1.0  
**Дата:** Март 2026  
**Статус:** ✅ Production Ready
