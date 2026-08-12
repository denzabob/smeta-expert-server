@extends('price-indices.public.layout')

@section('content')
    <div class="eyebrow">Официальная статистика</div>
    <h1>Индексы цен производителей по товарам</h1>
    <p class="lead">Помесячные индексы, изменение и коэффициент за весь доступный период. Страницы сформированы из опубликованных статистических данных.</p>

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
