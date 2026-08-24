@extends('price-indices.public.layout', ['ogType' => 'article'])

@section('content')
    <nav class="crumbs" aria-label="Хлебные крошки"><a href="{{ $urls->catalog() }}">Главная</a> → <a href="{{ $urls->producerPrices() }}">Индексы цен производителей</a> → <a href="{{ $urls->producerPriceProducts() }}">По товарам и товарным группам</a> → <span aria-current="page">{{ $page->classifierItem->name }}</span></nav>
    <div class="code">{{ $page->classifierItem->item_code }}</div>
    <h1>{{ $heading }}</h1>
    <p class="eyebrow">{{ $indicatorType }}</p>
    <div class="lead">
        <p>По данным Росстата, для товарной группы «{{ $page->classifierItem->name }}» доступны индексы цен производителей с {{ $formatter->periodGenitive($page->period_from) }} по {{ $formatter->period($page->period_to) }}. Накопленный коэффициент изменения цен за этот период составляет {{ $coefficient }}, что соответствует изменению на {{ $change }}.</p>
        <p>В таблице приведены официальные месячные индексы цен товара к предыдущему месяцу. Данные позволяют проследить изменение цен по периодам и использовать их для расчёта изменения стоимости.</p>
    </div>

    <div class="detail-grid">
        <div>
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
                <section class="panel section" aria-labelledby="public-calculator-title">
                    <h2 id="public-calculator-title">Рассчитать изменение за период</h2>
                    <p class="method">Расчёт применяет официальные месячные значения этого ряда из той же публикации, что и страница. Начальный месяц служит базой; используются факторы после него и по конечный месяц включительно.</p>
                    <form id="public-index-calculator" class="calculator-form" method="post" action="{{ $calculationEndpoint }}" data-public-index-calculator>
                        <div class="field">
                            <label for="calculation-start-period">Начальный период</label>
                            <select id="calculation-start-period" name="start_period" required>
                                @foreach ($observations as $observation)
                                    <option value="{{ $observation->period_start->format('Y-m') }}">{{ $formatter->period($observation->period_start, true) }}</option>
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
                        <h3>Результат расчёта</h3>
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
                    <noscript><p class="form-error">Для показа результата прямо на этой странице требуется JavaScript. Все статистические значения, периоды и источник выше и ниже доступны без JavaScript.</p></noscript>
                </section>
            @endif

            <section class="panel section">
                <h2>Помесячные индексы</h2>
                <div class="table-wrap"><table><thead><tr><th>Период</th><th>Индекс, % к предыдущему месяцу</th></tr></thead><tbody>
                @foreach ($observations as $observation)
                    <tr><td>{{ $formatter->period($observation->period_start, true) }}</td><td>{{ $formatter->indexValue($observation->value) }}</td></tr>
                @endforeach
                </tbody></table></div>
            </section>

            <section class="panel section method">
                <h2>Как рассчитан коэффициент</h2>
                <p>Коэффициент отражает последовательное применение официальных месячных индексов после базового месяца и до конца выбранного диапазона. Агрегат заранее зафиксирован в публичном snapshot; при открытии страницы он не пересчитывается.</p>
            </section>

            @if ($relatedPages->isNotEmpty())
                <section class="panel section">
                    <h2>Связанные индексы</h2>
                    <ul class="related-list">
                        @foreach ($relatedPages as $relatedPage)
                            <li><a href="{{ $urls->detail($relatedPage->slug) }}">{{ $relatedPage->classifierItem->item_code }} — {{ $relatedPage->classifierItem->name }}</a></li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </div>

        <aside>
            <section class="panel section cta">
                <h2>Профессиональные инструменты</h2>
                <p>Войдите в ПРИЗМУ, чтобы продолжить работу с индексом в профессиональном калькуляторе.</p>
                <a class="button" href="{{ $calculatorUrl }}" data-metrika-goal="public_index_calculator_click" data-item-code="{{ $page->classifierItem->item_code }}">Войти в ПРИЗМУ</a>
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
                    <li><span>Snapshot обновлён</span>{{ $page->generated_at->format('d.m.Y H:i') }}</li>
                </ul>
                @php($sourceUrl = $page->sourceFile->source?->source_page_url ?: $page->sourceFile->source_url)
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
    @endpush
@endif
