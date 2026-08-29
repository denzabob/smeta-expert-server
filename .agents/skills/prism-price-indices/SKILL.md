---
name: prism-price-indices
description: "Use for Prism PriceIndices work: CPI/PPI, OKPD2, Rosstat statistical series and datasets, source files, provenance, imports, observations, classifiers, activation, public series, index calculations, and PriceIndices admin/API contracts."
---

# Prism PriceIndices

Применяй этот Skill для задач домена PriceIndices. Сначала ориентируйся на текущий код, тесты и schema/contracts repository.

## Основные области

- `server/app/Domain/PriceIndices/**`
- `server/tests/Feature/PriceIndices/**`
- `client/src/modules/price-indices/**`
- `client/src/public-price-indices/**`
- связанные routes, controllers и migrations

Для architecture/history context используй `server/app/Domain/PriceIndices/README.md`, `PRICE_INDICES_MVP_2_ARCHITECTURE_AUDIT.md`, `PRICE_INDICES_OKPD2_ARCHITECTURE_AUDIT.md` и `docs/PRICE_INDICES_*`, если они существуют. Исторический audit не является источником истины сам по себе.

При конфликте приоритет имеют current code, current tests, current schema и current contracts; затем исторические документы.

## Инварианты

- Сохраняй provenance и traceability source/import lifecycle.
- Явно различай staged, active, published и недоступные dataset/series semantics, если это следует из текущего контракта.
- Сохраняй deterministic calculations и numeric/decimal integrity; не заменяй decimal values плавающей арифметикой без доказанной совместимости.
- Не смешивай public и admin boundaries и не ослабляй публичные access/visibility rules.
- Проверяй backward compatibility, idempotency и trusted-classifier lifecycle там, где они уже являются частью контракта.
- Не изобретай новую PriceIndices architecture, если задачу решает локальное изменение существующего flow.

## Рабочий порядок

1. Найди реальные entry points, execution path, persistence и тесты.
2. Проверь lifecycle источника, импорта, observation, classifier, publication и calculation согласно current code.
3. Сверь API/public/admin contracts и legacy behavior.
4. Раздели cross-layer работу на bounded block и запусти targeted tests из `prism-testing`.

Не считай audit-документ или название класса доказательством текущего поведения без проверки реализации.
