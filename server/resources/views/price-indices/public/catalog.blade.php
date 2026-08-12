@extends('price-indices.public.layout')

@section('content')
    <div class="eyebrow">Официальная статистика</div>
    <h1>Индексы цен производителей Росстата по товарам</h1>
    <div class="lead">
        <p>ПРИЗМА Индексы содержит официальные индексы цен производителей Росстата по товарам и товарным группам. Здесь можно посмотреть индекс изменения цен, динамику по месяцам и накопленный коэффициент за весь доступный период.@if ($latestDataYear !== null) Актуальные данные представлены по {{ $latestDataYear }} год включительно.@endif</p>
        <p>Для каждой товарной позиции доступны месячные индексы цен, период наблюдений и рассчитанный коэффициент изменения стоимости. Индекс цен товара можно применить к собственному периоду и исходной стоимости с помощью индивидуального расчёта в сервисе ПРИЗМА.</p>
    </div>

    <div class="grid">
        @foreach ($pages as $page)
            <a class="panel card" href="{{ $urls->detail($page->slug) }}">
                <span class="code">{{ $page->classifierItem->item_code }}</span>
                <h2>{{ $page->classifierItem->name }}</h2>
                <div>{{ $formatter->periodRange($page->period_from, $page->period_to) }}</div>
                <div class="metrics">
                    <div class="metric"><span class="metric__label">Изменение</span><span class="metric__value">{{ $formatter->percent($page->change_percent) }}</span></div>
                    <div class="metric"><span class="metric__label">Коэффициент</span><span class="metric__value">{{ $formatter->coefficient($page->coefficient) }}</span></div>
                </div>
            </a>
        @endforeach
    </div>

    @if ($pages->hasPages())
        <nav class="pagination" aria-label="Страницы каталога">
            @if ($pages->onFirstPage()) <span aria-disabled="true">←</span> @else <a href="{{ $urls->catalog($pages->currentPage() - 1) }}" rel="prev">←</a> @endif
            @foreach ($pages->getUrlRange(max(1, $pages->currentPage() - 2), min($pages->lastPage(), $pages->currentPage() + 2)) as $pageNumber => $ignored)
                @if ($pageNumber === $pages->currentPage()) <span class="current" aria-current="page">{{ $pageNumber }}</span> @else <a href="{{ $urls->catalog($pageNumber) }}">{{ $pageNumber }}</a> @endif
            @endforeach
            @if ($pages->hasMorePages()) <a href="{{ $urls->catalog($pages->currentPage() + 1) }}" rel="next">→</a> @else <span aria-disabled="true">→</span> @endif
        </nav>
    @endif
@endsection
