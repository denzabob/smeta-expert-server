@extends('price-indices.public.layout')

@section('content')
    <div class="eyebrow">Официальная статистика</div>
    <h1>Индексы цен производителей Росстата по товарам</h1>
    <div class="lead">
        <p>ПРИЗМА Индексы содержит официальные индексы цен производителей Росстата по товарам и товарным группам. Здесь можно посмотреть индекс изменения цен, динамику по месяцам и накопленный коэффициент за весь доступный период.@if ($latestDataYear !== null) Актуальные данные представлены по {{ $latestDataYear }} год включительно.@endif</p>
        <p>Для каждой товарной позиции доступны месячные индексы цен, период наблюдений и рассчитанный коэффициент изменения стоимости. Индекс цен товара можно применить к собственному периоду и исходной стоимости с помощью индивидуального расчёта в сервисе ПРИЗМА.</p>
    </div>

    <form class="panel search-form" method="get" action="{{ $urls->catalog() }}" role="search">
        <div class="field">
            <label for="public-index-search">Код или название продукции</label>
            <input id="public-index-search" name="q" type="search" value="{{ $searchQuery }}" maxlength="120" placeholder="Например, 31.02.10.140 или кухонная мебель">
            <small>Поиск выполняется только среди опубликованных индексов.</small>
        </div>
        <button class="button" type="submit">Найти</button>
    </form>

    @if ($isSearch)
        <p class="search-summary">
            @if ($pages->total() > 0)
                Найдено опубликованных рядов: {{ $pages->total() }}@if ($searchQuery !== '') по запросу «{{ $searchQuery }}»@endif.
            @endif
            <a href="{{ $urls->catalog() }}">Все индексы</a>
        </p>
    @endif

    @if ($pages->isNotEmpty())
    <div class="grid">
        @foreach ($pages as $page)
            <a class="panel card" href="{{ $urls->detail($page->slug) }}">
                @php($providerCodeKind = $page->classifierItem->metadata_json['provider_code_kind'] ?? null)
                @php($classifierLabel = $formatter->classifierLabel($page->classifierItem->classifier_code, is_string($providerCodeKind) ? $providerCodeKind : null))
                <span class="code">{{ $classifierLabel ? $classifierLabel.' ' : '' }}{{ $page->classifierItem->item_code }}</span>
                <h2>{{ $page->classifierItem->name }}</h2>
                <div>Индекс цен производителей</div>
                <div>{{ $formatter->periodRange($page->period_from, $page->period_to) }}</div>
                <div class="metrics">
                    <div class="metric"><span class="metric__label">Изменение</span><span class="metric__value">{{ $formatter->percent($page->change_percent) }}</span></div>
                    <div class="metric"><span class="metric__label">Коэффициент</span><span class="metric__value">{{ $formatter->coefficient($page->coefficient) }}</span></div>
                </div>
            </a>
        @endforeach
    </div>
    @elseif ($isSearch)
        <section class="panel empty-state">
            <h2>Ничего не найдено</h2>
            <p>По запросу «{{ $searchQuery }}» ничего не найдено среди опубликованных индексов Росстата.</p>
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
