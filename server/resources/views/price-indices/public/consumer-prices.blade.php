@extends('price-indices.public.layout')

@section('content')
    <nav class="crumbs" aria-label="Хлебные крошки"><a href="{{ $urls->catalog() }}">Индексы</a> → <span aria-current="page">Индексы потребительских цен</span></nav>
    <div class="eyebrow">Официальные данные Росстата</div>
    <h1>Индекс потребительских цен (ИПЦ) Росстата</h1>
    <div class="lead">
        <p>Индекс потребительских цен показывает, как меняется стоимость товаров и услуг, которые приобретают потребители. Значение 100 % означает, что по сравнению с предыдущим месяцем цены в среднем не изменились; значение выше или ниже 100 % показывает рост или снижение.</p>
        <p>Изменение за произвольный период рассчитывается последовательным перемножением официальных месячных индексов после начального месяца и по конечный месяц включительно. Доступна история по Российской Федерации с 1991 года, интерактивный график и расчёт суммы.</p>
    </div>

    @if ($summary['examples']->isNotEmpty())
        <section class="grid" aria-label="Опубликованные индексы потребительских цен">
            @foreach ($summary['examples'] as $page)
                <a class="panel card cpi-card" href="{{ $urls->detail($page->slug, $family->code) }}">
                    <span class="code">{{ $family->shortLabel }} · Росстат</span>
                    <h2>@if ($page->classifierItem->item_code === 'all_items_and_services')Индекс потребительских цен — товары и услуги@else{{ $page->classifierItem->name }}@endif</h2>
                    <div>{{ $formatter->periodRange($page->period_from, $page->period_to) }}</div>
                    <div class="metrics">
                        <div class="metric metric--period"><span class="metric__label">Последний период</span><span class="metric__value">{{ $formatter->period($page->period_to, true) }}</span></div>
                        <div class="metric"><span class="metric__label">Индекс к предыдущему месяцу</span><span class="metric__value">{{ $formatter->indexValue($page->getAttribute('latest_value')) }} %</span></div>
                        <div class="metric"><span class="metric__label">Изменение за месяц</span><span class="metric__value">{{ $formatter->monthlyChangeFromIndex($page->getAttribute('latest_value')) }}</span></div>
                    </div>
                    <span class="card__link-hint" aria-hidden="true">Открыть ряд <span>→</span></span>
                </a>
            @endforeach
        </section>
    @else
        <section class="panel empty-state"><h2>Публикация готовится</h2><p>Опубликованные ряды ИПЦ пока недоступны.</p></section>
    @endif

    @if ($summary['source_url'])
        <p><a href="{{ $summary['source_url'] }}" rel="nofollow noopener">Источник и методологические материалы Росстата</a></p>
    @endif
@endsection
