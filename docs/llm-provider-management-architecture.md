# Управление LLM провайдерами в контуре сметного приложения

## 1. Назначение документа
Этот документ описывает полную архитектуру подсистемы LLM в приложении:
- управление провайдерами и их метаданными;
- хранение и приоритет API-ключей/настроек;
- выбор primary (провайдера по умолчанию);
- failover на fallback-провайдеры при недоступности;
- circuit breaker для временного исключения нестабильных провайдеров;
- использование LLM в сценариях декомпозиции работ для смет;
- наблюдаемость и админские операции.

---

## 2. Архитектурная карта

### 2.1 Основные слои
1. UI админки LLM
- Экран: client/src/views/AdminPanelView.vue
- Позволяет:
  - выбрать mode (auto/manual);
  - выбрать primary_provider;
  - настроить fallback_providers;
  - сохранить ключи/model/base_url провайдера;
  - выполнить тест провайдеров;
  - сбросить circuit breaker.

2. Админ API
- Контроллер: server/app/Http/Controllers/Api/AdminLLMController.php
- Маршруты: server/routes/api.php
  - GET /api/admin/llm-providers
  - GET /api/admin/llm-settings
  - PUT /api/admin/llm-settings
  - POST /api/admin/llm-test
  - POST /api/admin/llm-reset-circuit
  - GET /api/admin/llm-prompts
  - PUT /api/admin/llm-prompts
  - POST /api/admin/llm-prompts/reset
  - POST /api/admin/llm-prompts/preview

3. Реестр провайдеров
- Файл: server/app/Services/LLM/ProviderRegistry.php
- Централизует:
  - список поддерживаемых провайдеров;
  - display-метаданные для UI;
  - default base_url/model;
  - сопоставление имени провайдера к классу.

4. Хранилище настроек
- Файл: server/app/Services/LLM/LLMSettingsRepository.php
- Хранит и читает:
  - llm.mode
  - llm.primary_provider
  - llm.fallback_providers
  - llm.providers (включая api_key/base_url/model/др.)
- Источник: таблица app_settings.
- Ключи api_key в БД шифруются через Laravel Crypt.

5. Роутер выполнения запросов
- Файл: server/app/Services/LLM/LLMRouter.php
- Функции:
  - строит цепочку попыток primary -> fallback;
  - проверяет circuit breaker перед каждым провайдером;
  - выполняет failover по типам ошибок;
  - пишет расширенные логи в ai_logs.

6. Circuit Breaker
- Файл: server/app/Services/LLM/CircuitBreaker.php
- Правила:
  - после 3 ошибок подряд провайдер уходит в down на 120 секунд;
  - состояние хранится в cache/redis (ключ llm:health:{provider});
  - после успешного ответа fail_count сбрасывается.

7. Провайдеры (адаптеры API)
- Интерфейс: server/app/Services/LLM/Contracts/LLMProviderInterface.php
- Реализации:
  - OpenRouter: server/app/Services/LLM/Providers/OpenRouterProvider.php
  - DeepSeek: server/app/Services/LLM/Providers/DeepSeekProvider.php
  - Mistral: server/app/Services/LLM/Providers/MistralProvider.php
  - RouterAI: server/app/Services/LLM/Providers/RouterAiProvider.php

8. Бизнес-контур смет (декомпозиция работ)
- Входная точка API: POST /api/work/decompose
- Контроллер: server/app/Http/Controllers/Api/WorkDecomposeController.php
- Сервис: server/app/Services/AI/DecompositionService.php
- Построение промптов: server/app/Services/LLM/Prompts/DecompositionPromptBuilder.php

---

## 3. Поддерживаемые провайдеры и их дефолты
Источник: ProviderRegistry.

На текущий момент:
1. openrouter
- default_base_url: https://openrouter.ai/api/v1
- default_model: google/gemini-2.0-flash-001

2. deepseek
- default_base_url: https://api.deepseek.com/v1
- default_model: deepseek-chat

3. mistral
- default_base_url: https://api.mistral.ai/v1
- default_model: mistral-small-latest

4. routerai
- default_base_url: https://routerai.ru/api/v1
- default_model: openai/gpt-4o

---

## 4. Управление ключами и настройками

### 4.1 Где физически хранятся настройки
1. Основное хранилище: таблица app_settings
- миграция: server/database/migrations/2026_01_28_000001_create_app_settings_table.php

2. Fallback-источник: .env + config/services.php
- конфиг: server/config/services.php
- env-переменные, например:
  - MISTRAL_API_KEY
  - MISTRAL_MODEL
  - MISTRAL_BASE_URL
  - ROUTERAI_API_KEY
  - ROUTERAI_MODEL
  - ROUTERAI_BASE_URL

### 4.2 Приоритет источников (критично)
Алгоритм LLMSettingsRepository::getProviderSettings(provider):
1. Читается llm.providers[provider] из app_settings.
2. Если api_key пустой, применяется fallback в env/config.

Итог:
- настройки, сохраненные через админку, приоритетнее env;
- env используется как резерв, если в БД ключ не задан.

### 4.3 Шифрование ключей
При сохранении llm.providers:
- api_key шифруется через Crypt::encryptString;
- при чтении расшифровывается;
- в админке ключ отображается маской, не в открытом виде.

### 4.4 Кеширование
- настройки LLM кешируются на 5 минут (ключ llm:settings).
- после изменений выполняется Cache::forget(llm:settings).
- при изменении env на сервере рекомендуется php artisan config:clear.

---

## 5. Выбор default провайдера и fallback

### 5.1 Параметры управления
1. llm.mode
- auto: включен failover
- manual: только primary, без failover

2. llm.primary_provider
- провайдер по умолчанию

3. llm.fallback_providers
- массив провайдеров, которые используются после primary в auto-режиме

### 5.2 Где настраивается
1. Через UI админки (рекомендуемый путь)
- вкладка Настройки LLM провайдеров

2. Через API
- PUT /api/admin/llm-settings

3. Через дефолт в миграции app_settings
- primary по умолчанию: openrouter
- fallback по умолчанию: [deepseek]
- mode по умолчанию: auto

### 5.3 Важно по конфигурации
Не рекомендуется ставить в fallback тот же провайдер, что и primary (например primary=mistral и fallback=[mistral]). Это не дает реального резервирования.

---

## 6. Механизм failover и отказоустойчивости

### 6.1 Цепочка выполнения
LLMRouter формирует список попыток:
1. [primary]
2. + fallback_providers (если mode=auto)

Для каждого провайдера:
1. Проверка circuit breaker (isAvailable).
2. Проверка, что провайдер сконфигурирован.
3. Попытка запроса generateDecomposition.
4. При успехе:
- recordSuccess в circuit breaker;
- запись успешного ai_log;
- возврат результата.

### 6.2 Типы ошибок и поведение
Источник: LLMProviderException и LLMRouter.

1. Ошибки auth/config
- failover запрещен (isFailoverAllowed=false).
- в manual режиме запрос падает сразу.
- в auto режиме логируются как критичные.

2. Ошибки timeout/network/http_429/http_5xx/unknown
- failover разрешен;
- recordFailure в circuit breaker;
- переход к следующему провайдеру (в auto).

3. invalid_json
- отдельный сценарий: допускается ограниченный retry/failover
- лимит MAX_JSON_RETRY_ATTEMPTS = 1.

### 6.3 Circuit Breaker
Правила:
1. Порог: 3 ошибки подряд.
2. Down-период: 120 секунд.
3. После успешного ответа счетчик ошибок сбрасывается.
4. Админка может вручную сбросить состояние:
- POST /api/admin/llm-reset-circuit

---

## 7. Как LLM встроен в сметный контур

### 7.1 Endpoint бизнес-функции
POST /api/work/decompose
- контроллер: WorkDecomposeController::decompose
- валидатор запроса: DecomposeWorkRequest

### 7.2 Многоуровневая логика DecompositionService
DecompositionService использует 3-tier стратегию:
1. Tier 1: Exact match по пресетам
- таблица work_presets
- поиск по context_hash + normalized_title
- статусы active: candidate/verified

2. Tier 2: Fuzzy (зарезервирован)
- в текущем MVP не реализован

3. Tier 3: AI generation через LLMRouter
- строится промпт в DecompositionPromptBuilder;
- выполняется запрос к провайдеру с failover.

### 7.3 Поведение при недоступности всех LLM
Если LLMRouter выбрасывает LLMUnavailableException:
- DecompositionService не ломает сценарий;
- возвращает локальный fallback_local (базовые этапы работ);
- в meta прикладывает failover_chain и причину.

Итог: сметный сценарий остается работоспособным даже при падении внешних LLM.

### 7.4 Контур самообучения через feedback
1. Пользователь/фронт отправляет feedback по финальным этапам.
2. FeedbackService нормализует контекст и шаги.
3. WorkPresetRepository:
- создает draft-пресеты;
- повышает usage_count;
- переводит draft -> candidate при пороге usage_count >= 10.

Это снижает нагрузку на LLM со временем и повышает стабильность выдачи.

---

## 8. Наблюдаемость и аналитика

### 8.1 Что логируется
Таблица ai_logs:
- provider_name
- model_name
- prompt_tokens/completion_tokens
- cost_usd
- latency_ms
- is_successful
- fallback_used
- failover_chain
- error_type
- http_status
- user_id

Миграции:
- create_ai_logs
- add_llm_fields_to_ai_logs
- add_user_id_to_ai_logs

### 8.2 Админская статистика
Контроллер: AdminLLMStatsController
- /api/admin/llm-stats
- /api/admin/llm-stats/users
- /api/admin/llm-stats/providers
- /api/admin/llm-stats/activity

Позволяет видеть:
- успешность провайдеров;
- латентность;
- долю fallback;
- типы ошибок;
- активность пользователей.

---

## 9. Операционные сценарии

### 9.1 Подключить нового провайдера
1. Добавить класс провайдера (реализация LLMProviderInterface).
2. Зарегистрировать провайдер в ProviderRegistry:
- metadata
- class mapping
3. Добавить секцию в config/services.php и env-переменные.
4. Подключить биндинг в LLMServiceProvider (если нужен direct binding).
5. Проверить в админке (llm-test).

### 9.2 Переключить default провайдер на проде
1. В админке задать primary_provider.
2. Настроить fallback_providers (другие провайдеры).
3. Сохранить ключи в админке или через env.
4. Проверить /api/admin/llm-test.
5. Убедиться, что circuit breaker у primary = healthy.

### 9.3 Диагностика: провайдер "не активен"
Проверить в порядке:
1. Есть ли api_key в app_settings llm.providers[provider].
2. Если нет, есть ли provider key в env (services.{provider}.key).
3. Корректен ли primary/fallback набор.
4. Не в состоянии ли provider=down в circuit breaker.
5. Не забыли ли очистить config cache после env изменений.

---

## 10. Ограничения и риски
1. Доступ к админ LLM API сейчас ограничен условием user_id = 1.
- это рабочая схема, но в будущем лучше перейти на role/permission model.

2. Fallback в manual режиме отключен намеренно.
- это полезно для диагностики primary, но снижает отказоустойчивость.

3. Некорректная конфигурация fallback (дублирование primary) не блокируется жестко.
- рекомендуется добавить валидацию уникальности цепочки.

4. Исторические ключи в app_settings имеют приоритет над env.
- при расследованиях важно помнить о приоритете БД.

---

## 11. Краткая последовательность выполнения запроса декомпозиции
1. Клиент вызывает POST /api/work/decompose.
2. WorkDecomposeController передает данные в DecompositionService.
3. DecompositionService:
- normalizer;
- поиск exact preset;
- если нет пресета: promptBuilder -> llmRouter.
4. LLMRouter:
- строит цепочку primary/fallback;
- проверяет circuit breaker;
- вызывает provider;
- при ошибках делает failover (в auto);
- пишет ai_logs.
5. При успехе возвращается AI-ответ.
6. При полной недоступности возвращается fallback_local.

---

## 12. Файлы-источники (основные)
- server/app/Services/LLM/ProviderRegistry.php
- server/app/Services/LLM/LLMSettingsRepository.php
- server/app/Services/LLM/LLMRouter.php
- server/app/Services/LLM/CircuitBreaker.php
- server/app/Services/LLM/Providers/OpenRouterProvider.php
- server/app/Services/LLM/Providers/DeepSeekProvider.php
- server/app/Services/LLM/Providers/MistralProvider.php
- server/app/Services/LLM/Providers/RouterAiProvider.php
- server/app/Services/LLM/Prompts/DecompositionPromptBuilder.php
- server/app/Services/AI/DecompositionService.php
- server/app/Services/AI/FeedbackService.php
- server/app/Services/AI/WorkPresetRepository.php
- server/app/Http/Controllers/Api/AdminLLMController.php
- server/app/Http/Controllers/Api/AdminLLMStatsController.php
- server/app/Http/Controllers/Api/WorkDecomposeController.php
- server/app/Models/AiLog.php
- server/app/Models/WorkPreset.php
- server/config/services.php
- server/routes/api.php
- server/database/migrations/2026_01_28_000001_create_app_settings_table.php
- server/database/migrations/2026_01_27_000002_create_ai_logs_table.php
- server/database/migrations/2026_01_28_000002_add_llm_fields_to_ai_logs_table.php
- server/database/migrations/2026_01_28_000003_add_user_id_to_ai_logs_table.php
- server/database/migrations/2026_01_27_000001_create_work_presets_table.php
