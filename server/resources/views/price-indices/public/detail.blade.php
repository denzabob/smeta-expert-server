@extends('price-indices.public.layout', ['ogType' => 'article'])

@section('content')
    @if ($family->code === App\Domain\PriceIndices\Application\Services\PublicIndexFamilyRegistry::CONSUMER_PRICES)
        <nav class="crumbs" aria-label="Хлебные крошки"><a href="{{ $urls->catalog() }}">Индексы</a> → <a href="{{ $urls->consumerPrices() }}">Индексы потребительских цен</a> → <span aria-current="page">{{ $page->classifierItem->name }}</span></nav>
    @else
        <nav class="crumbs" aria-label="Хлебные крошки"><a href="{{ $urls->catalog() }}">Главная</a> → <a href="{{ $urls->producerPrices() }}">Индексы цен производителей</a> → <a href="{{ $urls->producerPriceProducts() }}">По товарам и товарным группам</a> → <span aria-current="page">{{ $page->classifierItem->name }}</span></nav>
        <div class="code">{{ $page->classifierItem->item_code }}</div>
    @endif
    <h1>{{ $heading }}</h1>
    <p class="eyebrow">{{ $indicatorType }}</p>
    <div class="lead">
        @if ($family->code === App\Domain\PriceIndices\Application\Services\PublicIndexFamilyRegistry::CONSUMER_PRICES)
            <p>По данным Росстата, для категории «{{ $page->classifierItem->name }}» доступны месячные индексы потребительских цен по Российской Федерации с {{ $formatter->periodGenitive($page->period_from) }} по {{ $formatter->period($page->period_to) }}. Накопленный коэффициент за весь период составляет {{ $coefficient }}, изменение — {{ $change }}.</p>
            <p>Каждое значение показывает изменение к предыдущему месяцу: 100 % означает отсутствие изменения. По умолчанию график показывает последние пять лет, а калькулятор независимо рассчитывает произвольный диапазон длиной не более {{ $chartData->limits['calculator_max_range_months'] }} месяцев.</p>
        @else
            <p>По данным Росстата, для товарной группы «{{ $page->classifierItem->name }}» доступны индексы цен производителей с {{ $formatter->periodGenitive($page->period_from) }} по {{ $formatter->period($page->period_to) }}. Накопленный коэффициент изменения цен за этот период составляет {{ $coefficient }}, что соответствует изменению на {{ $change }}.</p>
            <p>В таблице приведены официальные месячные индексы цен товара к предыдущему месяцу. Данные позволяют проследить изменение цен по периодам и использовать их для расчёта изменения стоимости.</p>
        @endif
    </div>

    <div class="detail-grid">
        <div>
            @if ($family->code === App\Domain\PriceIndices\Application\Services\PublicIndexFamilyRegistry::CONSUMER_PRICES)
                <section class="panel section latest-index" aria-labelledby="latest-index-title">
                    <h2 id="latest-index-title">Последнее официальное значение</h2>
                    <dl class="facts latest-index__facts">
                        <div class="fact"><dt>Последний период</dt><dd>{{ $latestObservation ? $formatter->period($latestObservation->period_start, true) : '—' }}</dd></div>
                        <div class="fact latest-index__official"><dt>Индекс к предыдущему месяцу</dt><dd>{{ $formatter->indexValue($latestObservation?->value) }} %</dd></div>
                        <div class="fact"><dt>Изменение цен за месяц</dt><dd>{{ $formatter->monthlyChangeFromIndex($latestObservation?->value) }}</dd></div>
                    </dl>
                    <p class="method latest-index__note">Изменение за месяц — поясняющее отображение, рассчитанное как официальный индекс минус 100. Это не годовая инфляция и не отдельный показатель Росстата.</p>
                </section>
            @endif

            <section class="panel section">
                <h2>Показатели за период</h2>
                <dl class="facts">
                    <div class="fact"><dt>Период</dt><dd>{{ $formatter->periodRange($page->period_from, $page->period_to) }}</dd></div>
                    <div class="fact"><dt>Коэффициент</dt><dd>{{ $formatter->coefficient($page->coefficient) }}</dd></div>
                    <div class="fact"><dt>Изменение</dt><dd>{{ $formatter->percent($page->change_percent) }}</dd></div>
                    <div class="fact"><dt>Использовано месячных индексов</dt><dd>{{ $page->factors_count }}</dd></div>
                    <div class="fact"><dt>Минимальный индекс</dt><dd>{{ $formatter->indexValue($page->min_index_value) }} · {{ $formatter->period($page->min_index_period) }}</dd></div>
                    <div class="fact"><dt>Максимальный индекс</dt><dd>{{ $formatter->indexValue($page->max_index_value) }} · {{ $formatter->period($page->max_index_period) }}</dd></div>
                </dl>
            </section>

            @if ($calculatorSupported)
                <section class="panel section chart-section" aria-labelledby="public-chart-title">
                    <div class="chart-heading">
                        <div>
                            <h2 id="public-chart-title">Динамика индекса цен</h2>
                            <p id="public-chart-description" class="method">График показывает официальные месячные значения из той же публикации, что и таблица. 100 % — уровень без изменения к предыдущему периоду.</p>
                        </div>
                        <div class="chart-mode-control" role="group" aria-label="Режим графика">
                            <button class="chart-mode-button chart-mode-button--active" type="button" data-chart-mode="monthly" aria-pressed="true">Индекс за месяц</button>
                            <button class="chart-mode-button" type="button" data-chart-mode="cumulative" aria-pressed="false">Накопленное изменение</button>
                        </div>
                    </div>
                    @if ($family->code === App\Domain\PriceIndices\Application\Services\PublicIndexFamilyRegistry::CONSUMER_PRICES)
                        <div class="chart-range-control" role="group" aria-label="Период графика">
                            <button class="chart-range-button" type="button" data-chart-range="1y" aria-pressed="false">1 год</button>
                            <button class="chart-range-button" type="button" data-chart-range="3y" aria-pressed="false">3 года</button>
                            <button class="chart-range-button chart-range-button--active" type="button" data-chart-range="5y" aria-pressed="true">5 лет</button>
                            <button class="chart-range-button" type="button" data-chart-range="10y" aria-pressed="false">10 лет</button>
                            <button class="chart-range-button" type="button" data-chart-range="all" aria-pressed="false">Вся история</button>
                        </div>
                        <p class="chart-range-note method" data-chart-range-note>Накопленное изменение считается с начала выбранного диапазона. Диапазон графика не изменяет периоды калькулятора.</p>
                    @endif
                    <div class="price-chart" data-public-index-chart role="img" aria-label="Динамика месячного индекса цен" aria-describedby="public-chart-description"></div>
                    <p class="chart-status method" data-chart-status aria-live="polite">График загружается. Все значения доступны в таблице ниже.</p>
                    <script id="public-price-index-chart-data" type="application/json">{!! Illuminate\Support\Js::encode($chartData) !!}</script>
                    <noscript><p class="form-error">Интерактивный график требует JavaScript. Все статистические значения, периоды и источник доступны в таблице и тексте страницы.</p></noscript>

                    <div class="calculator-block" aria-labelledby="public-calculator-title">
                        <h3 id="public-calculator-title">Рассчитать изменение за период</h3>
                        <p class="method">Начальный месяц служит базой; сервер применяет факторы после него и по конечный месяц включительно.</p>
                        @php
                            $defaultStartIndex = max(0, $observations->count() - ($chartData->limits['calculator_max_range_months'] + 1));
                        @endphp
                        <form id="public-index-calculator" class="calculator-form" method="post" action="{{ $calculationEndpoint }}" data-public-index-calculator data-max-range-months="{{ $chartData->limits['calculator_max_range_months'] }}">
                            <div class="field">
                                <label for="calculation-start-period">Начальный период</label>
                                <select id="calculation-start-period" name="start_period" required>
                                    @foreach ($observations as $observation)
                                        <option value="{{ $observation->period_start->format('Y-m') }}" @selected($loop->index === $defaultStartIndex)>{{ $formatter->period($observation->period_start, true) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="field">
                                <label for="calculation-end-period">Конечный период</label>
                                <select id="calculation-end-period" name="end_period" required>
                                    @foreach ($observations as $observation)
                                        <option value="{{ $observation->period_start->format('Y-m') }}" @selected($loop->last)>{{ $formatter->period($observation->period_start, true) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="field amount-field">
                                <label for="calculation-amount">Сумма, ₽ <span class="method">(необязательно)</span></label>
                                <input id="calculation-amount" name="amount" type="text" inputmode="decimal" maxlength="18" autocomplete="off" pattern="[0-9]+([.,][0-9]{1,2})?" aria-describedby="calculation-amount-help">
                                <small id="calculation-amount-help">До двух знаков после запятой. Расчёт выполняется на сервере без округления в браузере.</small>
                            </div>
                            <div class="form-actions">
                                <button class="button" type="submit">Рассчитать</button>
                                <span class="form-help">Результат описывает математику выбранного статистического ряда и не является рекомендацией по выбору индекса.</span>
                            </div>
                        </form>
                        <div id="calculation-error" class="form-error" role="alert" hidden></div>
                        <div id="calculation-result" class="calculation-result" role="status" aria-live="polite" aria-atomic="true" tabindex="-1" hidden>
                            <h3>За выбранный период</h3>
                            <p class="result-line">Период: <strong data-result-period></strong></p>
                            <p class="result-line">Коэффициент: <strong data-result-coefficient></strong></p>
                            <p class="result-line">Изменение: <strong data-result-change></strong></p>
                            <p class="result-line" data-result-amount-row hidden>Сумма: <strong data-result-amount></strong></p>
                            <details class="chain-details">
                                <summary>Как рассчитано</summary>
                                <div class="table-wrap"><table><thead><tr><th>Период</th><th>Месячный индекс</th><th>Фактор</th><th>Накопленный коэффициент</th></tr></thead><tbody data-result-chain></tbody></table></div>
                            </details>
                            <p class="provenance" data-result-provenance></p>
                        </div>
                    </div>
                </section>
            @endif

            @if ($family->code === App\Domain\PriceIndices\Application\Services\PublicIndexFamilyRegistry::CONSUMER_PRICES)
                @php
                    $recentObservations = $observations->take(-24)->reverse()->values();
                    $historyByYear = $observations->reverse()->groupBy(fn ($observation) => $observation->period_start->format('Y'));
                    $firstObservation = $observations->first();
                @endphp
                <section class="panel section">
                    <h2>Последние 24 месяца</h2>
                    <p class="method">Официальные месячные индексы текущего опубликованного ряда — от новых данных к более старым.</p>
                    <div class="table-wrap"><table><thead><tr><th>Период</th><th>Индекс, % к предыдущему месяцу</th></tr></thead><tbody data-recent-observations>
                    @foreach ($recentObservations as $observation)
                        <tr><td>{{ $formatter->period($observation->period_start, true) }}</td><td>{{ $formatter->indexValue($observation->value) }}</td></tr>
                    @endforeach
                    </tbody></table></div>

                    <details class="history-details">
                        <summary>Показать всю историю с {{ $firstObservation ? $formatter->periodGenitive($firstObservation->period_start).' года' : 'первого доступного периода' }} — {{ $observations->count() }} месяцев</summary>
                        <div class="table-wrap history-table"><table data-full-history><thead><tr><th>Период</th><th>Индекс, % к предыдущему месяцу</th></tr></thead>
                        @foreach ($historyByYear as $year => $yearObservations)
                            <tbody aria-label="{{ $year }} год">
                                <tr class="history-year"><th colspan="2" scope="rowgroup">{{ $year }}</th></tr>
                                @foreach ($yearObservations as $observation)
                                    <tr><td>{{ $formatter->period($observation->period_start, true) }}</td><td>{{ $formatter->indexValue($observation->value) }}</td></tr>
                                @endforeach
                            </tbody>
                        @endforeach
                        </table></div>
                    </details>
                </section>
            @else
                <section class="panel section">
                    <h2>Помесячные индексы</h2>
                    <div class="table-wrap"><table><thead><tr><th>Период</th><th>Индекс, % к предыдущему месяцу</th></tr></thead><tbody>
                    @foreach ($observations as $observation)
                        <tr><td>{{ $formatter->period($observation->period_start, true) }}</td><td>{{ $formatter->indexValue($observation->value) }}</td></tr>
                    @endforeach
                    </tbody></table></div>
                </section>
            @endif

            <section class="panel section method">
                <h2>Как рассчитан коэффициент</h2>
                <p>Коэффициент отражает последовательное применение официальных месячных индексов после базового месяца и до конца выбранного диапазона. Агрегат заранее зафиксирован в опубликованных данных; при открытии страницы он не пересчитывается.</p>
            </section>

            @if ($classifierContext !== null)
                <section class="panel section classifier-context" aria-labelledby="official-classifier-title">
                    <div class="classifier-context__header">
                        <div>
                            <span class="classifier-label">ОКПД2</span>
                            <h2 id="official-classifier-title">Официальная классификация</h2>
                        </div>
                        <dl class="classifier-version">
                            <div><dt>Версия</dt><dd>{{ $classifierContext->versionLabel }}</dd></div>
                            <div><dt>Действует с</dt><dd>{{ $classifierContext->effectiveFrom->format('d.m.Y') }}</dd></div>
                        </dl>
                    </div>

                    <ol class="classifier-lineage" aria-label="Иерархия ОКПД2">
                        @foreach ($classifierContext->lineage as $position)
                            <li @class(['classifier-position', 'classifier-position--current' => $position->isCurrent]) @if ($position->isCurrent) aria-current="true" @endif>
                                @if (! $position->isCurrent && $position->statisticalSlug !== null)
                                    <a class="classifier-position__content" href="{{ $urls->detail($position->statisticalSlug) }}"><span class="classifier-code">{{ $position->code }}</span><span>{{ $position->name }}</span></a>
                                @else
                                    <span class="classifier-position__content"><span class="classifier-code">{{ $position->code }}</span><span>{{ $position->name }}</span></span>
                                @endif
                            </li>
                        @endforeach
                    </ol>

                    @if ($classifierContext->children !== [])
                        <div class="classifier-children">
                            <h3>Дочерние позиции</h3>
                            <ul class="classifier-children__list">
                                @foreach ($classifierContext->children as $child)
                                    <li>
                                        <div>
                                            @if ($child->statisticalSlug !== null)
                                                <a href="{{ $urls->detail($child->statisticalSlug) }}"><span class="classifier-code">{{ $child->code }}</span> — {{ $child->name }}</a>
                                            @else
                                                <span><span class="classifier-code">{{ $child->code }}</span> — {{ $child->name }}</span>
                                            @endif
                                        </div>
                                        @if ($child->hasRosstatData)
                                            <span class="classifier-data-marker">Есть данные Росстата</span>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                            @if ($classifierContext->hasMoreChildren)
                                <p class="classifier-more"><a href="{{ $urls->catalogSearch($classifierContext->current->code) }}">Найти все позиции этого раздела</a></p>
                            @endif
                        </div>
                    @endif
                </section>
            @endif

            @if ($relatedPages->isNotEmpty())
                <section class="panel section">
                    <h2>{{ $family->code === App\Domain\PriceIndices\Application\Services\PublicIndexFamilyRegistry::CONSUMER_PRICES ? 'Другие индексы потребительских цен' : 'Связанные индексы' }}</h2>
                    <ul class="related-list">
                        @foreach ($relatedPages as $relatedPage)
                            <li><a href="{{ $urls->detail($relatedPage->slug, $family->code) }}">@if ($family->code === App\Domain\PriceIndices\Application\Services\PublicIndexFamilyRegistry::PRODUCER_PRICES){{ $relatedPage->classifierItem->item_code }} — @endif{{ $relatedPage->classifierItem->name }}</a></li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </div>

        <aside>
            <section class="panel section cta">
                <h2>Профессиональные инструменты</h2>
                <p>Войдите в ПРИЗМУ, чтобы продолжить работу с индексом в профессиональном калькуляторе.</p>
                <a class="button" href="{{ $calculatorUrl }}" data-metrika-goal="public_index_calculator_click" data-item-code="{{ $family->code === App\Domain\PriceIndices\Application\Services\PublicIndexFamilyRegistry::CONSUMER_PRICES ? $page->slug : $page->classifierItem->item_code }}">Войти в ПРИЗМУ</a>
            </section>

            <section class="panel section">
                <h2>Источник и версия</h2>
                <ul class="meta-list">
                    <li><span>Поставщик данных</span>{{ $page->dataset->provider_name ?: 'Росстат' }}</li>
                    <li><span>Набор данных</span>{{ $page->dataset->name }}</li>
                    <li><span>Исходный файл</span>{{ $page->sourceFile->original_filename }}</li>
                    <li><span>SHA-256</span>{{ $page->sourceFile->sha256 }}</li>
                    <li><span>Импорт</span>{{ $formatter->shortPublicId($page->import->public_id) }} · {{ $page->import->importer_code }} v{{ $page->import->importer_version }}</li>
                    <li><span>Опубликовано</span>{{ $page->source_published_at ? $page->source_published_at->format('d.m.Y H:i') : '—' }}</li>
                    <li><span>Данные обновлены</span>{{ $page->generated_at->format('d.m.Y H:i') }}</li>
                </ul>
                @if ($sourceNotes->isNotEmpty())
                    <div class="method">
                        <h3>Примечания источника</h3>
                        @foreach ($sourceNotes as $note)
                            <p>{{ $note['text'] }}</p>
                        @endforeach
                    </div>
                @endif
                @php
                    $sourceUrl = $page->sourceFile->source?->source_page_url ?: $page->sourceFile->source_url;
                @endphp
                @if ($sourceUrl && filter_var($sourceUrl, FILTER_VALIDATE_URL))
                    <p><a href="{{ $sourceUrl }}" rel="nofollow noopener">Страница источника</a></p>
                @endif
            </section>
        </aside>
    </div>
@endsection

@if ($calculatorSupported)
    @push('scripts')
        <script defer src="/price-indices-public-calculator.js"></script>
        <script defer src="/price-indices-public-chart.js"></script>
    @endpush
@endif
