@extends('price-indices.public.layout')

@section('content')
    <div class="eyebrow">Официальная статистика</div>
    <h1>Индексы цен Росстата</h1>
    <div class="lead">
        <p>Общий каталог объединяет две статистические семьи: индексы цен производителей и индексы потребительских цен. Для опубликованных рядов доступны месячная динамика, график и расчёт изменения за выбранный период.@if ($latestDataYear !== null) Данные представлены по {{ $latestDataYear }} год включительно.@endif</p>
    </div>

    @unless ($isSearch)
        <section class="grid" aria-label="Статистические семьи">
            <a class="panel card" href="{{ $urls->producerPrices() }}"><span class="code">ИЦП</span><h2>Индексы цен производителей</h2><div>Цены производителей по продукции и товарным группам</div></a>
            <a class="panel card" href="{{ $urls->consumerPrices() }}"><span class="code">ИПЦ</span><h2>Индексы потребительских цен</h2><div>Инфляция и динамика потребительских цен с 1991 года</div></a>
        </section>
    @endunless

    <form class="panel search-form" method="get" action="{{ $urls->catalog() }}" role="search">
        <div class="field">
            <label for="public-index-search">Код, название или вид индекса</label>
            <div class="search-controls">
                <input id="public-index-search" name="q" type="search" value="{{ $searchQuery }}" maxlength="120" placeholder="Например, ИПЦ, инфляция или 31.02.10.140" aria-describedby="public-index-search-help">
                <button class="button" type="submit">Найти</button>
            </div>
            <small id="public-index-search-help">Поиск по индексам цен производителей, потребительским ценам и официальному ОКПД2.</small>
        </div>
    </form>

    @if ($isSearch)
        <p class="search-summary">
            @if ($pages->total() > 0)
                Найдено результатов: {{ $pages->total() }}@if ($searchQuery !== '') по запросу «{{ $searchQuery }}»@endif.
            @endif
            <a href="{{ $urls->catalog() }}">Все индексы</a>
        </p>
    @endif

    @if ($pages->isNotEmpty())
    <div class="grid">
        @if ($isCombinedSearch)
            @foreach ($pages as $result)
                @if ($result->isStatisticalSeries())
                    <a class="panel card" href="{{ $urls->detail($result->statisticalSlug, $result->familyCode) }}">
                        @php($classifierLabel = $result->classifierLabel ?? $formatter->classifierLabel((string) $result->localClassifierCode, $result->providerCodeKind))
                        <span class="code">{{ $result->familyLabel }}</span>
                        @if ($result->familyCode === App\Domain\PriceIndices\Application\Services\PublicIndexFamilyRegistry::PRODUCER_PRICES)
                            <div>{{ $classifierLabel ? $classifierLabel.' ' : '' }}{{ $result->code }}</div>
                        @endif
                        <h2>{{ $result->name }}</h2>
                        @if ($result->hasRosstatData)
                            <div>Есть опубликованные данные Росстата</div>
                            <div>Открыть данные</div>
                        @else
                            <div>{{ $result->familyLabel }}</div>
                        @endif
                        @if ($result->periodFrom && $result->periodTo)
                            <div>{{ $formatter->periodRange($result->periodFrom, $result->periodTo) }}</div>
                        @endif
                        <div class="metrics">
                            <div class="metric"><span class="metric__label">Изменение</span><span class="metric__value">{{ $formatter->percent($result->changePercent) }}</span></div>
                            <div class="metric"><span class="metric__label">Коэффициент</span><span class="metric__value">{{ $formatter->coefficient($result->coefficient) }}</span></div>
                        </div>
                    </a>
                @else
                    <article class="panel card">
                        <span class="code">ОКПД2 {{ $result->code }}</span>
                        <h2>{{ $result->name }}</h2>
                        <div>Отдельный опубликованный ряд Росстата не найден</div>
                    </article>
                @endif
            @endforeach
        @else
            @foreach ($pages as $page)
                @php($family = $families->forDataset((string) $page->getAttribute('public_dataset_code')))
                <a class="panel card" href="{{ $urls->detail($page->slug, $family->code) }}">
                    @php($providerCodeKind = $page->classifierItem->metadata_json['provider_code_kind'] ?? null)
                    @php($classifierLabel = $formatter->classifierLabel($page->classifierItem->classifier_code, is_string($providerCodeKind) ? $providerCodeKind : null))
                    <span class="code">{{ $family->searchLabel }}</span>
                    @if ($family->code === App\Domain\PriceIndices\Application\Services\PublicIndexFamilyRegistry::PRODUCER_PRICES)
                        <div>{{ $classifierLabel ? $classifierLabel.' ' : '' }}{{ $page->classifierItem->item_code }}</div>
                    @endif
                    <h2>{{ $page->classifierItem->name }}</h2>
                    <div>{{ $family->publicLabel }}</div>
                    <div>{{ $formatter->periodRange($page->period_from, $page->period_to) }}</div>
                    <div class="metrics">
                        <div class="metric"><span class="metric__label">Изменение</span><span class="metric__value">{{ $formatter->percent($page->change_percent) }}</span></div>
                        <div class="metric"><span class="metric__label">Коэффициент</span><span class="metric__value">{{ $formatter->coefficient($page->coefficient) }}</span></div>
                    </div>
                </a>
            @endforeach
        @endif
    </div>
    @elseif ($isSearch)
        <section class="panel empty-state">
            <h2>Ничего не найдено</h2>
            <p>По запросу «{{ $searchQuery }}» ничего не найдено в опубликованных данных Росстата и ОКПД2.</p>
            <ul>
                <li>Проверьте код продукции.</li>
                <li>Попробуйте более короткое название.</li>
                <li><a href="{{ $urls->catalog() }}">Перейдите ко всем индексам</a>.</li>
            </ul>
        </section>
    @endif

    @if ($pages->hasPages())
        <nav class="pagination" aria-label="Страницы каталога">
            @if ($pages->onFirstPage()) <span aria-disabled="true">←</span> @else <a href="{{ $isSearch ? $urls->catalogSearch($searchQuery, $pages->currentPage() - 1) : $urls->catalog($pages->currentPage() - 1) }}" rel="prev">←</a> @endif
            @foreach ($pages->getUrlRange(max(1, $pages->currentPage() - 2), min($pages->lastPage(), $pages->currentPage() + 2)) as $pageNumber => $ignored)
                @if ($pageNumber === $pages->currentPage()) <span class="current" aria-current="page">{{ $pageNumber }}</span> @else <a href="{{ $isSearch ? $urls->catalogSearch($searchQuery, $pageNumber) : $urls->catalog($pageNumber) }}">{{ $pageNumber }}</a> @endif
            @endforeach
            @if ($pages->hasMorePages()) <a href="{{ $isSearch ? $urls->catalogSearch($searchQuery, $pages->currentPage() + 1) : $urls->catalog($pages->currentPage() + 1) }}" rel="next">→</a> @else <span aria-disabled="true">→</span> @endif
        </nav>
    @endif
@endsection
