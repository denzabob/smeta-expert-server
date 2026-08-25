@php($metrikaId = filter_var(config('price_indices.yandex_metrika_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null)
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    <meta name="description" content="{{ $description }}">
    <meta name="robots" content="{{ $robots ?? 'index,follow' }}">
    <link rel="canonical" href="{{ $canonical }}">
    <meta property="og:type" content="{{ $ogType ?? 'website' }}">
    <meta property="og:locale" content="ru_RU">
    <meta property="og:site_name" content="ПРИЗМА Индексы">
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:url" content="{{ $canonical }}">
    @if (! empty($structuredData))
        <script type="application/ld+json">{!! json_encode($structuredData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) !!}</script>
    @endif
    @if ($metrikaId !== null)
        <!-- Yandex.Metrika counter -->
        <script type="text/javascript">
        (function(m,e,t,r,i,k,a){
            m[i]=m[i]||function(){(m[i].a=m[i].a||[]).push(arguments)};
            m[i].l=1*new Date();
            for (var j = 0; j < document.scripts.length; j++) {
                if (document.scripts[j].src === r) { return; }
            }
            k=e.createElement(t),a=e.getElementsByTagName(t)[0],
            k.async=1,k.src=r,a.parentNode.insertBefore(k,a)
        })(window, document,'script','https://mc.yandex.ru/metrika/tag.js?id={{ $metrikaId }}', 'ym');

        ym({{ $metrikaId }}, 'init', {
            ssr:true,
            trackHash:true,
            clickmap:true,
            ecommerce:"dataLayer",
            referrer: document.referrer,
            url: location.href,
            accurateTrackBounce:true,
            trackLinks:true
        });

        document.addEventListener('click', function(event) {
            var link = event.target.closest('[data-metrika-goal]');
            if (!link || typeof ym !== 'function') { return; }
            ym({{ $metrikaId }}, 'reachGoal', link.dataset.metrikaGoal, {
                item_code: link.dataset.itemCode
            });
        });
        </script>
        <!-- /Yandex.Metrika counter -->
    @endif
    <style>
        :root { color-scheme: light; --ink:#17222f; --muted:#5f6b78; --primary:#3156a3; --primary-soft:#e9efff; --surface:#fff; --surface-alt:#f4f6fa; --outline:#d7dde6; --positive:#196c45; --shadow:0 8px 28px rgba(23,34,47,.08); }
        * { box-sizing:border-box; }
        body { margin:0; background:var(--surface-alt); color:var(--ink); font:16px/1.55 system-ui,-apple-system,"Segoe UI",sans-serif; }
        a { color:var(--primary); text-underline-offset:3px; }
        .shell { width:min(1160px,calc(100% - 32px)); margin:0 auto; }
        .skip-link { position:absolute; left:16px; top:-80px; z-index:10; padding:10px 14px; border-radius:10px; background:var(--ink); color:#fff; }
        .skip-link:focus { top:12px; }
        .topbar { background:var(--surface); border-bottom:1px solid var(--outline); }
        .topbar__inner { min-height:64px; display:flex; align-items:center; justify-content:space-between; gap:24px; }
        .brand { color:var(--ink); font-size:18px; font-weight:750; letter-spacing:.03em; text-decoration:none; }
        .brand span { color:var(--primary); }
        main { padding:40px 0 64px; }
        h1,h2 { line-height:1.2; letter-spacing:-.02em; }
        h1 { margin:0 0 16px; font-size:clamp(30px,4vw,48px); }
        h2 { margin:0 0 16px; font-size:24px; }
        .lead { max-width:800px; margin:0; color:var(--muted); font-size:18px; }
        .actions { display:flex; flex-wrap:wrap; gap:12px; margin-top:24px; }
        .actions + .panel { margin-top:32px; }
        .eyebrow,.code { color:var(--primary); font-weight:700; letter-spacing:.04em; }
        .panel { background:var(--surface); border:1px solid var(--outline); border-radius:20px; box-shadow:var(--shadow); }
        .crumbs { margin-bottom:24px; color:var(--muted); font-size:14px; }
        .crumbs a { color:inherit; }
        .grid { display:grid; gap:16px; grid-template-columns:repeat(2,minmax(0,1fr)); margin-top:32px; }
        .card { display:block; padding:24px; color:inherit; text-decoration:none; transition:border-color .15s,transform .15s; }
        .card:hover { border-color:#91a6d7; transform:translateY(-1px); }
        .card:focus-visible,.button:focus-visible,a:focus-visible { outline:3px solid #8faaf0; outline-offset:3px; }
        .card h2 { margin:8px 0 16px; font-size:20px; }
        .search-form { margin:28px 0 12px; padding:20px; }
        .search-controls { display:grid; grid-template-columns:minmax(0,1fr) auto; gap:12px; align-items:stretch; }
        .search-controls .button { min-height:44px; margin-top:0; padding:9px 20px; border-radius:10px; white-space:nowrap; }
        .field { display:flex; flex-direction:column; gap:7px; }
        .field label { font-weight:700; }
        .field small,.search-summary,.form-help { color:var(--muted); }
        input,select { width:100%; min-height:44px; padding:9px 12px; border:1px solid var(--outline); border-radius:10px; background:var(--surface); color:var(--ink); font:inherit; }
        input:focus-visible,select:focus-visible,button:focus-visible { outline:3px solid #8faaf0; outline-offset:2px; }
        button.button { border:0; cursor:pointer; }
        button.button:disabled { cursor:wait; opacity:.65; }
        .empty-state { margin-top:28px; padding:28px; }
        .metrics { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px; margin-top:18px; }
        .metric { padding:16px; background:var(--surface-alt); border-radius:14px; }
        .metric__label { display:block; color:var(--muted); font-size:13px; }
        .metric__value { display:block; margin-top:4px; font-size:19px; font-weight:750; font-variant-numeric:tabular-nums; }
        .detail-grid { display:grid; grid-template-columns:minmax(0,1.6fr) minmax(280px,.8fr); gap:24px; margin-top:28px; align-items:start; }
        .section { padding:28px; margin-bottom:24px; }
        .facts { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:16px; }
        .fact { padding:18px; border-radius:14px; background:var(--surface-alt); }
        .fact dt { color:var(--muted); font-size:13px; }
        .fact dd { margin:4px 0 0; font-size:21px; font-weight:760; font-variant-numeric:tabular-nums; }
        .cta { background:var(--primary-soft); border-color:#c3d2fa; }
        .button { display:inline-flex; min-height:44px; align-items:center; justify-content:center; margin-top:10px; padding:10px 18px; border-radius:999px; background:var(--primary); color:#fff; font-weight:700; text-decoration:none; }
        .table-wrap { overflow-x:auto; }
        table { width:100%; border-collapse:collapse; font-variant-numeric:tabular-nums; }
        th,td { padding:11px 12px; border-bottom:1px solid var(--outline); text-align:left; }
        th { color:var(--muted); font-size:13px; font-weight:650; }
        td:last-child,th:last-child { text-align:right; }
        .meta-list { margin:0; padding:0; list-style:none; }
        .meta-list li { padding:9px 0; border-bottom:1px solid var(--outline); overflow-wrap:anywhere; }
        .meta-list span { display:block; color:var(--muted); font-size:13px; }
        .pagination { display:flex; flex-wrap:wrap; gap:8px; margin-top:28px; }
        .pagination a,.pagination span { min-width:40px; padding:8px 11px; border:1px solid var(--outline); border-radius:10px; background:var(--surface); text-align:center; text-decoration:none; }
        .pagination .current { border-color:var(--primary); background:var(--primary); color:#fff; }
        .method { color:var(--muted); }
        .summary-grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:16px; margin:28px 0; }
        .summary-grid .metric { border:1px solid var(--outline); background:var(--surface); }
        .related-list { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:10px 24px; margin:0; padding-left:20px; }
        .chart-heading { display:flex; align-items:flex-start; justify-content:space-between; gap:20px; }
        .chart-heading h2 { margin-bottom:8px; }
        .chart-heading .method { max-width:620px; margin:0; }
        .chart-mode-control { display:inline-flex; flex:0 0 auto; padding:3px; border:1px solid var(--outline); border-radius:12px; background:var(--surface-alt); }
        .chart-mode-button { min-height:40px; padding:7px 12px; border:0; border-radius:9px; background:transparent; color:var(--muted); font:inherit; font-size:14px; font-weight:700; cursor:pointer; }
        .chart-mode-button--active { background:var(--surface); color:var(--primary); box-shadow:0 1px 4px rgba(25,39,71,.12); }
        .price-chart { width:100%; min-width:0; min-height:300px; margin-top:18px; overflow:hidden; }
        .chart-status { min-height:20px; margin:4px 0 0; font-size:14px; }
        .price-chart-tooltip { display:grid; gap:5px; min-width:170px; padding:10px 12px; color:var(--ink); font-size:13px; }
        .price-chart-tooltip strong { margin-bottom:2px; }
        .calculator-block { margin-top:22px; padding-top:22px; border-top:1px solid var(--outline); }
        .calculator-block h3 { margin:0 0 8px; font-size:19px; }
        .classifier-context__header { display:flex; align-items:flex-start; justify-content:space-between; gap:24px; }
        .classifier-label { display:inline-block; margin-bottom:6px; color:var(--primary); font-size:13px; font-weight:800; letter-spacing:.08em; }
        .classifier-version { display:flex; flex-wrap:wrap; justify-content:flex-end; gap:10px 20px; margin:0; }
        .classifier-version div { min-width:112px; }
        .classifier-version dt { color:var(--muted); font-size:12px; }
        .classifier-version dd { margin:2px 0 0; font-weight:700; font-variant-numeric:tabular-nums; }
        .classifier-lineage { display:grid; gap:2px; margin:20px 0 0; padding:0 0 0 14px; border-left:2px solid var(--outline); list-style:none; }
        .classifier-position { border-radius:10px; }
        .classifier-position__content { display:grid; grid-template-columns:minmax(86px,auto) minmax(0,1fr); gap:12px; padding:7px 10px; color:inherit; text-decoration:none; }
        .classifier-position--current { background:var(--primary-soft); color:var(--ink); font-weight:700; }
        .classifier-code { color:var(--primary); font-weight:750; font-variant-numeric:tabular-nums; }
        .classifier-children { margin-top:24px; padding-top:22px; border-top:1px solid var(--outline); }
        .classifier-children h3 { margin:0 0 12px; font-size:18px; }
        .classifier-children__list { display:grid; gap:0; margin:0; padding:0; list-style:none; }
        .classifier-children__list li { display:flex; align-items:flex-start; justify-content:space-between; gap:16px; padding:9px 0; border-bottom:1px solid var(--outline); }
        .classifier-data-marker { flex:0 0 auto; padding:3px 8px; border-radius:999px; background:var(--primary-soft); color:var(--primary); font-size:12px; font-weight:700; }
        .classifier-more { margin:16px 0 0; }
        .calculator-form { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:16px; }
        .calculator-form .amount-field,.calculator-form .form-actions { grid-column:1 / -1; }
        .form-actions { display:flex; flex-wrap:wrap; align-items:center; gap:14px; }
        .form-error { margin-top:16px; padding:14px; border:1px solid #bd3d3d; border-radius:12px; background:#fff3f3; }
        .calculation-result { margin-top:22px; padding-top:22px; border-top:1px solid var(--outline); }
        .calculation-result:focus { outline:0; }
        .result-line { margin:7px 0; font-size:18px; }
        .result-line strong { font-variant-numeric:tabular-nums; }
        .chain-details { margin-top:20px; }
        .chain-details summary { cursor:pointer; font-weight:700; }
        .provenance { margin-top:18px; color:var(--muted); font-size:14px; overflow-wrap:anywhere; }
        [hidden] { display:none !important; }
        footer { padding:28px 0; border-top:1px solid var(--outline); color:var(--muted); background:var(--surface); font-size:14px; }
        @media (max-width:760px) { .grid,.detail-grid,.facts,.summary-grid,.related-list,.calculator-form,.search-controls { grid-template-columns:1fr; } .search-controls .button { width:100%; } .calculator-form .amount-field,.calculator-form .form-actions { grid-column:auto; } .chart-heading { display:block; } .chart-mode-control { display:grid; grid-template-columns:1fr 1fr; width:100%; margin-top:16px; } .classifier-context__header { display:block; } .classifier-version { justify-content:flex-start; margin-top:14px; } .classifier-position__content { grid-template-columns:1fr; gap:2px; } .classifier-children__list li { display:block; } .classifier-data-marker { display:inline-block; margin-top:5px; } main { padding-top:28px; } .section,.card { padding:20px; } .metrics { grid-template-columns:1fr 1fr; } }
        @media (max-width:420px) { .shell { width:min(100% - 20px,1160px); } .metrics { grid-template-columns:1fr; } .chart-mode-control { grid-template-columns:1fr; } .chart-mode-button { width:100%; } }
    </style>
</head>
<body>
<a class="skip-link" href="#main-content">К основному содержанию</a>
@if ($metrikaId !== null)
<noscript>
<div>
<img src="https://mc.yandex.ru/watch/{{ $metrikaId }}"
     style="position:absolute; left:-9999px;"
     alt="">
</div>
</noscript>
@endif
<header class="topbar"><div class="shell topbar__inner"><a class="brand" href="{{ $urls->catalog() }}">ПРИЗМА <span>Индексы</span></a><span>Данные Росстата</span></div></header>
<main id="main-content"><div class="shell">@yield('content')</div></main>
<footer><div class="shell">Публичный справочник официальных индексов. Для расчёта стоимости используйте калькулятор ПРИЗМА.</div></footer>
@stack('scripts')
</body>
</html>
