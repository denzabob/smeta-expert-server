@extends('price-indices.public.layout', ['ogType' => 'article'])

@section('content')
    <nav class="crumbs"><a href="{{ $urls->catalog() }}">Индексы</a> → {{ $page->classifierItem->name }}</nav>
    <div class="code">{{ $page->classifierItem->item_code }}</div>
    <h1>{{ $formatter->heading($page->classifierItem->name) }}</h1>
    <p class="lead">Официальный помесячный ряд {{ $page->dataset->provider_name ?: 'Росстата' }} с рассчитанным для полного периода коэффициентом.</p>

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
        </div>

        <aside>
            <section class="panel section cta">
                <h2>Рассчитать стоимость</h2>
                <p>Выберите собственные начальный и конечный месяцы и примените официальный коэффициент к вашей стоимости.</p>
                <a class="button" href="{{ $calculatorUrl }}" data-metrika-goal="public_index_calculator_click" data-item-code="{{ $page->classifierItem->item_code }}">Рассчитать стоимость</a>
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
