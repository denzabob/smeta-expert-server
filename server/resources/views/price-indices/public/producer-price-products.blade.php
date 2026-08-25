@extends('price-indices.public.layout')

@section('content')
    <nav class="crumbs" aria-label="Хлебные крошки"><a href="{{ $urls->catalog() }}">Главная</a> → <a href="{{ $urls->producerPrices() }}">Индексы цен производителей</a> → <span aria-current="page">По товарам и товарным группам</span></nav>
    <div class="eyebrow">Товарные статистические ряды</div>
    <h1>Индексы цен производителей по товарам и товарным группам</h1>
    <div class="lead">
        <p>В набор входят опубликованные индексы цен производителей по отдельным видам продукции и товарным группам. Каждая ссылка ведёт на существующую canonical-страницу ряда с фактическим периодом, месячными значениями и источником.</p>
    </div>

    <section class="summary-grid" aria-label="Сводка набора">
        <div class="metric"><span class="metric__label">Доступных рядов</span><span class="metric__value">{{ $summary['series_count'] }}</span></div>
        <div class="metric"><span class="metric__label">Диапазон данных</span><span class="metric__value">@if ($summary['period_from'] && $summary['period_to']){{ $formatter->periodRange($summary['period_from'], $summary['period_to']) }}@else—@endif</span></div>
        <div class="metric"><span class="metric__label">Публикация данных</span><span class="metric__value">{{ $summary['source_published_at']?->format('d.m.Y') ?? '—' }}</span></div>
    </section>

    @if ($summary['examples']->isNotEmpty())
        <section class="panel section">
            <h2>Представительные опубликованные ряды</h2>
            <ul class="related-list">
                @foreach ($summary['examples'] as $example)
                    <li><a href="{{ $urls->detail($example->slug) }}">{{ $example->classifierItem->item_code }} — {{ $example->classifierItem->name }}</a></li>
                @endforeach
            </ul>
        </section>
    @endif

    <div class="actions">
        <a class="button" href="{{ $urls->catalog() }}">Все индексы</a>
    </div>

    @if ($summary['source_url'])
        <p><a href="{{ $summary['source_url'] }}" rel="nofollow noopener">Источник и методологические материалы Росстата</a></p>
    @endif
@endsection
