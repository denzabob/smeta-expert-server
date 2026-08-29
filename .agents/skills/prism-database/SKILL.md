---
name: prism-database
description: "Project-specific MariaDB/MySQL and Laravel persistence safety for Prism. Use for migrations, schema, indexes, constraints, foreign keys, unique rules, backfills, nullable semantics, data integrity, destructive database changes, PriceIndices persistence, and rollback planning."
---

# Prism Database

Применяй этот Skill для изменений persistence и базы данных Prism. Это repository-specific safety guide, а не учебник MariaDB/MySQL или Laravel.

## Обязательный анализ до schema changes

До изменения migration/schema/persistence:

1. Изучи текущую migration history: связанные таблицы, поля, индексы, constraints, порядок миграций и compatibility migrations.
2. Изучи соответствующие models, casts, relationships и persistence services/repositories.
3. Проверь существующие данные и compatibility assumptions: nullable/empty semantics, legacy rows, orphaned references, текущие значения и используемые identity fields. Для production не выполняй изменяющие запросы без явного scope.
4. Найди targeted database/feature tests и существующие проверки импорта, persistence и rollback.
5. Оцени backward compatibility для старого кода, deploy order, API/jobs/imports и уже сохранённых строк.
6. Определи rollback strategy: что откатывается безопасно, что требует forward-fix или restore, и как проверить результат.

Зафиксируй найденные факты, допущения и неизвестные до редактирования migration или persistence-кода.

## Безопасные schema patterns

- Предпочитай additive migrations, transitional schema и поэтапный rollout.
- Добавляй явные indexes, foreign keys, unique constraints и правила `ON DELETE`/`ON UPDATE`; проверь совместимость типов, collation/storage engine и уже существующих данных.
- Выполняй backfill отдельно от schema change, небольшими идемпотентными порциями и с контролем прогресса. Не совмещай долгий mass backfill с миграцией, если это создаёт lock/timeout risk.
- Сначала делай новое поле/связь совместимыми со старым кодом, затем backfill, затем включай stricter constraint или обязательное чтение после проверки данных.
- Явно различай `NULL`, отсутствие значения, пустую строку, ноль и default. Не меняй semantics существующего поля молча.
- Перед добавлением constraint проверь duplicate, orphaned и invalid rows. Не полагайся на то, что `down()` автоматически безопасен после преобразования данных.
- Сохраняй существующие compatibility fields и legacy paths, пока их удаление не входит в явный scope.

## PriceIndices persistence

Для PriceIndices persistence используй совместно:

```text
prism-database
+ prism-price-indices
+ prism-testing
```

Сохраняй provenance и traceability source/import lifecycle, staged/active/published semantics, idempotency и numeric/decimal integrity согласно текущему коду и тестам. Не меняй identity dataset/series, publication lifecycle или смысл наблюдений только ради удобства schema change.

## Запрещённые изменения без явного scope

Не выполняй без явно согласованного scope:

- `DROP` column или table;
- destructive rename column/table;
- mass data rewrite;
- изменение semantics существующего поля;
- изменение unique identity или natural key;
- удаление compatibility field.

Если требуется destructive change, сначала опиши affected data, migration/deploy order, обратимость, recovery/restore plan и targeted validation; при отсутствии безопасного rollback остановись и сообщи риск.

## Cross-skill routing

- Laravel/backend contract: добавь `prism-backend`; для Laravel-specific решений используй `laravel-expert` только если он реально доступен в текущем Codex environment.
- Database architecture, schema integrity или migration strategy: используй `database-architect` только если он реально доступен в текущем Codex environment.
- PriceIndices persistence: обязательно `prism-price-indices` и `prism-testing`.
- Schema, затрагивающая несколько слоёв (database, backend, API, frontend, public/PDF/import flows): сначала используй `large-change-scope` и работай bounded block.

Не активируй все Skills механически: выбирай только относящиеся к фактическому diff.

## Validation и отчёт

Выбирай минимальную достаточную проверку по фактическому diff через `prism-testing`. Как минимум проверь migration/schema contract, targeted tests и diff scope; для backfill/constraint отдельно проверь idempotency, existing-data compatibility и rollback path.

В отчёте разделяй:

- `Run` — точные реально выполненные команды и результат;
- `Not run` — непроведённые проверки;
- `Manual checks` — оставшиеся проверки данных, миграционного порядка, lock/performance и recovery.

Не называй миграцию безопасной или rollback проверенным, если это не было фактически проверено.






