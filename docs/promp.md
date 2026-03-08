Важно:

1. работать в существующей кодовой базе



2\. не изобретать заново архитектуру



3\. выдать план → изменения → миграции → эндпойнты → тесты → файл-список

4. Используй информацию в файле для понимания структуры "C:\\xampp\\htdocs\\smeta-expert-server\\docs\\system-architecture.md"



ТЗ: Скриншоты-обоснования цен, актуализация при ревизии, публичные шаблоны парсинга (MVP, strict gate)

0\. Цель



Обеспечить обоснование цен в виде скриншотов “первого экрана” карточек товаров с ссылкой на источник.



Обеспечить актуализацию цен и скриншотов только в момент формирования ревизии/публикации/печати/verify (не при обычном редактировании сметы).



Обеспечить переиспользование уже собранных данных и скриншотов между пользователями (кэш по URL+цена+регион).



Сделать шаблоны парсинга общедоступными, чтобы большинство материалов добавлялись без Chrome-плагина.



Включить строгий режим ревизии: ревизия создаётся только если по всем позициям, требующим обоснования, есть доказательства (авто или ручные).



1\. Термины и сущности



Материал — сущность каталога (название и прочие поля).



Источник цены (Source URL) — URL карточки товара (в нормализованном виде).



Снимок цены (Price Snapshot) — запись результата актуализации:

price, name, observed\_at, source\_type, source\_url, screenshot\_path, true\_score, is\_verified, region\_id.



Ревизия отчёта (Revision) — неизменяемый слепок сметы/отчёта на момент публикации/печати.



Сессия ревизии (Revision Run) — процесс актуализации и сбора доказательств, который может завершиться успехом или потребовать ручных действий.



2\. Нормализация URL

2.1. Требование



Система должна хранить:



raw\_url — как ввёл пользователь/плагин



normalized\_url — для дедупликации/кэша скриншотов и цен



2.2. Правила нормализации (MVP)



удалять только tracking-параметры: utm\_\*, gclid, yclid, fbclid и аналогичные;



удалять #fragment;



не удалять весь query глобально (допускается удаление query только по доменным правилам);



нормализация должна применяться единообразно везде: добавление материала по URL, ревизия, ручной ввод, parser callback.



3\. Сервис захвата скриншота (Parser)

3.1. Проверка текущей реализации



Нужно проверить, есть ли в /parser/ модуль:



headless browser (Playwright/Puppeteer/Selenium и т.д.)



возможность сделать screenshot заданного URL



Результат:



“есть” → используем и описываем интеграцию



“нет” → реализуем новый модуль screenshot



3.2. Требования к захвату “первого экрана”



viewport: 1920×1080



deviceScaleFactor: 1



fullPage: false



скриншот фиксируется после:



domcontentloaded



network idle (или таймаут)



дополнительной задержки 300–800мс (рандом)



3.3. Мимикрия (MVP без proxy pool)



Обязательные меры:



рандомный User-Agent из white-list (desktop Chrome);



включенные JS, изображения;



рандомный jitter задержек;



rate limit по домену + backoff на ошибки.



3.4. Обработка блокировок



Сервис должен классифицировать результат:



SUCCESS



BLOCKED (403/429/капча/Cloudflare challenge)



TIMEOUT



PARSING\_ERROR



NO\_TEMPLATE (если логика шаблонов на стороне parser)



Возвращать статусы в основную систему для UI (ручной путь).



4\. Хранение скриншотов и привязка к цене

4.1. Где хранить



Файловое хранилище (локально/S3) в отдельной директории:

/storage/screenshots/{domain}/{yyyy}/{mm}/{hash}.png



hash формировать стабильно, минимум:

sha256(normalized\_url + price + currency + region\_id)



4.2. Привязка



Скриншот привязывается к снимку цены (Price Snapshot), а не к материалу вообще.



4.3. Дедупликация (переиспользование)



Если есть уже Price Snapshot для того же:



normalized\_url



price



region\_id (если используете)



и заполнен screenshot\_path

то скриншот переиспользуется, повторный парсинг не выполняется.



5\. Триггер актуализации: только Ревизия/Публикация/Печать/Verify

5.1. Когда происходит актуализация



Только при событиях:



генерация “Ревизии”



публикация отчёта на verify



“Сформировать в печать” (если отдельный процесс)



Обычное редактирование сметы парсер не трогает.



5.2. Алгоритм актуализации на ревизии



Для каждой позиции сметы, которая требует обоснования:



определить normalized\_url источника



получить актуальные name/price через:



шаблон домена (если найден)



иначе fallback (см. п.8.4)



сравнить с последним снимком цены по normalized\_url:



если price совпал и screenshot\_path есть → переиспользовать



если price изменился или скрина нет → создать новый snapshot и новый скрин



сохранить Price Snapshot с observed\_at, source\_url, screenshot\_path, true\_score



5.3. Иммутабельность



После создания ревизии данные ревизии (позиции, цены, ссылки, скрины) не должны меняться при изменениях в каталоге/материалах.



6\. PDF: Отчет расчетов + файл “Обоснование цен”

6.1. Требование



При успешной ревизии генерируются:



основной PDF (сметный отчёт/расчёты)



отдельный PDF “Обоснование цен”:



список позиций (в порядке отчёта)



для каждой позиции:



скриншот



source\_url (кликабельная ссылка)



observed\_at



price



(опционально) article, unit



6.2. Источник данных PDF “Обоснование”



Только snapshot\_json ревизии (immutability).



7\. Сбой актуализации: ручной режим + true\_score=0% + строгий gate

7.1. Если цена/скрин не смогли актуализироваться



Система должна:



показать список проблемных позиций (BLOCKED/TIMEOUT/PARSE\_ERROR/NO\_TEMPLATE)



предложить:



retry



ручной ввод цены



ручное добавление скрина (paste/upload)



7.2. True score



Вводится поле true\_score в снимках цены.



Нормы:



source\_type=manual → true\_score=0



source\_type=web → true\_score=100



source\_type=chrome\_ext → true\_score=80 (зафиксировать)



source\_type=price\_list → true\_score=80



7.3. Строгая политика ревизии (зафиксировано)



Ревизия блокируется, пока не решены все позиции:



авто (успешно) или



ручное закрытие (цена + скрин) с true\_score=0



Публикация “с пометкой” запрещена.



8\. Шаблоны парсинга: публичные и приватные

8.1. Цель



Добавление материала по URL должно работать через публичные шаблоны без плагина в большинстве случаев.



8.2. Правила применения шаблонов



При добавлении материала по URL:



искать шаблон по домену/паттерну URL



приоритет:



приватный шаблон пользователя



публичный шаблон



извлечь name, price (опционально unit, article)



если невалидно → fallback (8.4)



8.3. Публикация шаблона



Добавить возможность:



“Сделать шаблон публичным”



“Сделать приватным”



статус шаблона минимум: active/disabled



8.4. Fallback



Если шаблона нет или извлечение некорректно:



предложить: “Собрать через Chrome plugin”



или “Заполнить вручную (name, price)”



9\. Обязательные поля



обязательные: name, price



опциональные: unit, article



Валидация:



name не пустой



price число > 0



10\. UI/UX сценарии (минимум)

10.1. Добавление материала по ссылке



успех → материал добавлен, источник сохранён



нет шаблона/ошибка → “Собрать через плагин” / “Ввести вручную”



10.2. Ревизия/Публикация



старт ревизии → прогресс по позициям



если есть ошибки → состояние NEEDS\_MANUAL, список позиций, ручное закрытие



после закрытия всех → кнопка Retry



после успеха → Finalize → ссылки на 2 PDF



10.3. Карточка позиции/материала



история снимков цен (snapshots)



скриншоты по каждой цене



ссылки на источники



11\. Реализация: изменения БД

11.1. material\_price\_histories



Миграция:



ADD COLUMN true\_score SMALLINT UNSIGNED NOT NULL DEFAULT 100



11.2. Таблицы сессии ревизии (strict gate)



Добавить таблицы:



revision\_runs



id



project\_id



initiator\_user\_id



status ENUM: PENDING, IN\_PROGRESS, NEEDS\_MANUAL, READY, FAILED



total\_items, ok\_items, failed\_items



created\_at, updated\_at



revision\_run\_items



id



revision\_run\_id



project\_position\_id



material\_id



source\_url (normalized)



status ENUM: OK, BLOCKED, TIMEOUT, PARSE\_ERROR, NO\_TEMPLATE, NEEDS\_MANUAL



message TEXT NULL



price\_history\_id BIGINT NULL



created\_at, updated\_at



11.3. Флаг “требует обоснование”



Добавить:



project\_positions.requires\_price\_justification TINYINT(1) NOT NULL DEFAULT 1



12\. Реализация: Backend API (Laravel)

12.1. Запуск ревизии



POST /api/projects/{project}/revisions/run



создаёт revision\_runs



создаёт revision\_run\_items по позициям requires\_price\_justification=1



запускает jobs обновления



12.2. Статус ревизии



GET /api/projects/{project}/revisions/run/{runId}



агрегаты + items



12.3. Retry



POST /api/projects/{project}/revisions/run/{runId}/retry



пересчитывает только проблемные items



12.4. Manual close item



POST /api/revisions/run/{runId}/items/{itemId}/manual (multipart)



price\_per\_unit (req)



currency (req)



source\_url (opt)



region\_id (opt)



screenshot\_file (req)

Создаёт snapshot:



source\_type=manual, true\_score=0, observed\_at=now()



12.5. Финализация (создание ревизии)



POST /api/projects/{project}/revisions/run/{runId}/finalize



если есть non-OK items → 409 (список блокеров)



иначе:



создаёт project\_revision с snapshot\_json, куда включает:



позиции



price\_history\_id, source\_url, observed\_at, screenshot\_path, true\_score



возвращает ссылки на PDF



13\. Jobs/очереди (Laravel)

13.1. UpdateMaterialObservationForRevisionItem(revision\_run\_item\_id)



определяет source\_url



парсит name/price



делает скриншот (если нужен)



пишет material\_price\_histories



обновляет run\_item status



при ошибке → ставит соответствующий статус и помечает run как NEEDS\_MANUAL



13.2. RunRevisionUpdateJob(run\_id)



ставит IN\_PROGRESS



пачками диспатчит UpdateMaterialObservationForRevisionItem



завершает:



все OK → READY



иначе → NEEDS\_MANUAL



14\. Parser (Python + Playwright)

14.1. Режим screenshot\_by\_url



Добавить режим “screenshot\_by\_url” (или отдельный handler) который:



открывает URL



снимает screenshot первого экрана по спецификации (п.3.2)



возвращает статус: ok|blocked|timeout|error



сохраняет файл по правилам (п.4.1)



14.2. Новый internal endpoint



POST /api/internal/parser/screenshot

Вход:



url, region\_id, material\_id, revision\_run\_item\_id

Выход:



status, screenshot\_path, meta



15\. PDF: новый маршрут и шаблон

15.1. Маршрут



GET /api/projects/{project}/revisions/{number}/price-justification.pdf

Источник данных: snapshot\_json ревизии.



15.2. Шаблон



resources/views/reports/price\_justification.blade.php



список позиций и скрины



кликабельные ссылки на source\_url



16\. Критерии приёмки



Ревизия не создаётся, пока по всем requires\_price\_justification=1 позициям нет валидных доказательств.



Manual-обоснование создаёт snapshot с source\_type=manual, true\_score=0.



snapshot\_json ревизии содержит всё для неизменяемого восстановления: price\_history\_id, screenshot\_path, source\_url, observed\_at, true\_score.



Генерируется отдельный PDF “Обоснование цен” по ревизии, содержащий скрины на все требуемые позиции.



Публичные шаблоны реально используются при добавлении по URL; при ошибке предлагается плагин или ручной ввод.

