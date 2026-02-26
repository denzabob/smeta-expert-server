# Универсальный модуль прайсов (Price Import Module)

## Описание

Модуль для импорта прайс-листов операций и материалов с:
- Поддержкой поставщиков и версионирования прайсов
- Fuzzy matching (триграммы + Левенштейн) для автоматического сопоставления
- Коэффициентами пересчета единиц измерения
- Алиасами для запоминания соответствий
- Bulk actions для массовых операций

## Структура миграций

```
2026_02_02_000001_create_suppliers_table.php        - Поставщики
2026_02_02_000002_create_price_lists_table.php      - Прайс-листы (operations|materials)
2026_02_02_000003_create_price_list_versions_table.php - Версии прайсов (immutable)
2026_02_02_000004_create_price_import_sessions_table.php - Сессии импорта с state machine
2026_02_02_000005_create_supplier_product_aliases_table.php - Алиасы (память соответствий)
2026_02_02_000006_create_operation_prices_table.php - Цены операций по версиям
2026_02_02_000007_create_material_prices_table.php  - Цены материалов по версиям
2026_02_02_000008_add_search_name_to_operations_materials.php - Нормализованное имя для поиска
2026_02_02_000009_create_exchange_rates_table.php   - Курсы валют
```

## Запуск миграций

### Через Docker:
```bash
docker-compose exec app php artisan migrate
```

### Локально (если база доступна напрямую):
```bash
cd server
php artisan migrate
```

## State Machine сессии импорта

```
created → parsing_failed (ошибка парсинга)
        → mapping_required → resolution_required → execution_running → completed
                                                                    → execution_failed
```

## API Endpoints

### Поставщики
- `GET /api/suppliers` - список поставщиков
- `POST /api/suppliers` - создать поставщика
- `GET /api/suppliers/{id}` - получить поставщика
- `PUT /api/suppliers/{id}` - обновить
- `DELETE /api/suppliers/{id}` - удалить

### Прайс-листы
- `GET /api/suppliers/{id}/price-lists` - список прайсов поставщика
- `POST /api/suppliers/{id}/price-lists` - создать прайс
- `GET /api/suppliers/{id}/price-lists/{priceListId}/versions` - версии прайса

### Импорт
- `POST /api/price-imports/upload` - загрузить файл (Excel/CSV)
- `POST /api/price-imports/paste` - вставить из буфера
- `POST /api/price-imports/{session}/mapping` - отправить маппинг колонок
- `GET /api/price-imports/{session}/resolution` - получить очередь сопоставления
- `POST /api/price-imports/{session}/bulk-action` - массовое действие
- `POST /api/price-imports/{session}/execute` - выполнить импорт

## Формула пересчета цены

```
Price_internal = Price_supplier / conversion_factor
```

Примеры:
- 1 упаковка (100 шт) = 500₽ → 1 шт = 5₽ (factor = 100)
- 1 лист 2800x2070 = 1000₽ → 1 м² = 172.62₽ (factor = площадь листа)

## Vue компоненты

- `PriceImportDialog.vue` - основной диалог импорта с wizard-ом
  - Шаг 1: Загрузка файла / вставка из буфера
  - Шаг 2: Маппинг колонок
  - Шаг 3: Сопоставление (Resolution) с bulk actions
  - Шаг 4: Результаты импорта

## Модели

- `Supplier` - поставщик
- `PriceList` - прайс-лист
- `PriceListVersion` - версия прайса
- `PriceImportSession` - сессия импорта
- `SupplierProductAlias` - алиас продукта
- `OperationPrice` - цена операции
- `MaterialPrice` - цена материала
- `ExchangeRate` - курс валюты

## Сервисы

- `PriceFileParser` - парсинг Excel/CSV/HTML
- `TextNormalizer` - нормализация текста, триграммы
- `CandidateMatchingService` - fuzzy matching
- `PriceImportExecutor` - выполнение импорта
- `PriceImportSessionService` - управление сессией
