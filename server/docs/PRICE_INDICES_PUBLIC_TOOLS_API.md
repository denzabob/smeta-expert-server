# Публичный API инструментов ПРИЗМЫ

API доступен только на https://indices.prismcore.ru по namespace
/api/public/v1/. Он анонимный, read-only и предназначен для публичных
инструментов prismcore.ru. Browser не должен обращаться к нему напрямую:
landing использует same-origin BFF.

## Rate limit

- Поиск рядов и ОКПД2: 60 запросов в минуту на IP.
- Расчёт: 30 запросов в минуту на IP.

## Поиск статистического ряда

GET /api/public/v1/index-series/search?q=кухонная%20мебель&limit=10

Параметры:

- q — от 2 до 120 символов.
- family — необязательно: producer_prices или consumer_prices.
- limit — необязательно, от 1 до 20, по умолчанию 10.

Ответ содержит только опубликованные, indexable и пригодные для месячного
расчёта ряды PPI/CPI:

~~~json
{
  "items": [
    {
      "slug": "31-02-10-140",
      "family": "producer_prices",
      "family_label": "Индексы цен производителей",
      "title": "Наборы кухонной мебели",
      "code": "31.02.10.140",
      "unit": "%",
      "min_period": "2025-01",
      "max_period": "2025-12",
      "detail_url": "https://indices.prismcore.ru/31-02-10-140"
    }
  ]
}
~~~

## Расчёт изменения

POST /api/public/v1/index-series/calculate

~~~json
{
  "family": "producer_prices",
  "slug": "31-02-10-140",
  "start_period": "2025-01",
  "end_period": "2025-12",
  "amount": "100000.00"
}
~~~

amount nullable. Период рассчитывается существующим domain service со
семантикой (start,end]; максимальная длина — 120 месяцев. Денежная арифметика
выполняется backend decimal-safe.

Успешный ответ:

~~~json
{
  "series": {
    "slug": "31-02-10-140",
    "family": "producer_prices",
    "family_label": "Индексы цен производителей",
    "title": "Наборы кухонной мебели",
    "code": "31.02.10.140",
    "detail_url": "https://indices.prismcore.ru/31-02-10-140"
  },
  "period": {
    "start": "2025-01",
    "end": "2025-12",
    "months": 11
  },
  "result": {
    "factor": "1.634146829442",
    "change_percent": "63.41",
    "amount": "100000.00",
    "result_amount": "163414.63",
    "delta_amount": "63414.63"
  },
  "source": {
    "publisher": "Росстат"
  }
}
~~~

## Поиск ОКПД2

GET /api/public/v1/okpd2/search?q=кухонная%20мебель&limit=20

Поиск выполняется только по ACTIVE canonical official snapshot. При отсутствии
active snapshot API отвечает 503 с кодом CLASSIFIER_UNAVAILABLE; fallback на
старую версию не выполняется.

~~~json
{
  "classifier": {
    "name": "ОКПД2"
  },
  "items": [
    {
      "code": "31.02.10.140",
      "title": "Наборы кухонной мебели",
      "level": 5,
      "path": [
        {
          "code": "31",
          "title": "Мебель"
        },
        {
          "code": "31.02.10.140",
          "title": "Наборы кухонной мебели"
        }
      ],
      "price_index": {
        "available": true,
        "title": "Наборы кухонной мебели",
        "url": "https://indices.prismcore.ru/31-02-10-140"
      }
    }
  ]
}
~~~

## Ошибки

Все ошибки имеют стабильный machine-readable contract:

~~~json
{
  "error": {
    "code": "PERIOD_NOT_AVAILABLE",
    "message": "Для выбранного периода недостаточно опубликованных данных."
  }
}
~~~

Основные коды: VALIDATION_ERROR, SERIES_NOT_FOUND, PERIOD_NOT_AVAILABLE,
PERIOD_TOO_LONG, CLASSIFIER_UNAVAILABLE, RATE_LIMITED, SERVICE_UNAVAILABLE.
