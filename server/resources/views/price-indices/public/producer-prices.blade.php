@extends('price-indices.public.layout')

@section('content')
    <nav class="crumbs" aria-label="Хлебные крошки"><a href="{{ $urls->catalog() }}">Главная</a> → <span aria-current="page">Индексы цен производителей</span></nav>
    <div class="eyebrow">Официальная статистика цен</div>
    <h1>Индексы цен производителей Росстата</h1>
    <div class="lead">
        <p>Индекс цен производителей показывает изменение цен на продукцию при её реализации производителями. Публичный раздел ПРИЗМА содержит опубликованные статистические ряды Росстата по продукции, товарам и товарным группам.</p>
    </div>

    <section class="summary-grid" aria-label="Сводка опубликованных данных">
        <div class="metric"><span class="metric__label">Опубликованных рядов</span><span class="metric__value">{{ $summary['series_count'] }}</span></div>
        <div class="metric"><span class="metric__label">Доступный период</span><span class="metric__value">@if ($summary['period_from'] && $summary['period_to']){{ $formatter->periodRange($summary['period_from'], $summary['period_to']) }}@else—@endif</span></div>
        <div class="metric"><span class="metric__label">Публикация данных</span><span class="metric__value">{{ $summary['source_published_at']?->format('d.m.Y') ?? '—' }}</span></div>
    </section>

    <div class="actions">
        <a class="button" href="{{ $urls->producerPriceProducts() }}">Индексы по товарам</a>
        <a class="button" href="{{ $urls->catalog() }}">Открыть полный каталог</a>
    </div>

    @if ($summary['examples']->isNotEmpty())
        <section class="panel section">
            <h2>Примеры опубликованных рядов</h2>
            <ul class="related-list">
                @foreach ($summary['examples'] as $example)
                    <li><a href="{{ $urls->detail($example->slug) }}">{{ $example->classifierItem->item_code }} — {{ $example->classifierItem->name }}</a></li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($summary['source_url'])
        <p><a href="{{ $summary['source_url'] }}" rel="nofollow noopener">Источник и методологические материалы Росстата</a></p>
    @endif
@endsection
