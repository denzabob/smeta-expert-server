<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    <meta name="description" content="{{ $description }}">
    <meta name="robots" content="index,follow">
    <link rel="canonical" href="{{ $canonical }}">
    <meta property="og:type" content="{{ $ogType ?? 'website' }}">
    <meta property="og:locale" content="ru_RU">
    <meta property="og:site_name" content="ПРИЗМА Индексы">
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:url" content="{{ $canonical }}">
    <style>
        :root { color-scheme: light; --ink:#17222f; --muted:#5f6b78; --primary:#3156a3; --primary-soft:#e9efff; --surface:#fff; --surface-alt:#f4f6fa; --outline:#d7dde6; --positive:#196c45; --shadow:0 8px 28px rgba(23,34,47,.08); }
        * { box-sizing:border-box; }
        body { margin:0; background:var(--surface-alt); color:var(--ink); font:16px/1.55 system-ui,-apple-system,"Segoe UI",sans-serif; }
        a { color:var(--primary); text-underline-offset:3px; }
        .shell { width:min(1160px,calc(100% - 32px)); margin:0 auto; }
        .topbar { background:var(--surface); border-bottom:1px solid var(--outline); }
        .topbar__inner { min-height:64px; display:flex; align-items:center; justify-content:space-between; gap:24px; }
        .brand { color:var(--ink); font-size:18px; font-weight:750; letter-spacing:.03em; text-decoration:none; }
        .brand span { color:var(--primary); }
        main { padding:40px 0 64px; }
        h1,h2 { line-height:1.2; letter-spacing:-.02em; }
        h1 { margin:0 0 16px; font-size:clamp(30px,4vw,48px); }
        h2 { margin:0 0 16px; font-size:24px; }
        .lead { max-width:800px; margin:0; color:var(--muted); font-size:18px; }
        .eyebrow,.code { color:var(--primary); font-weight:700; letter-spacing:.04em; }
        .panel { background:var(--surface); border:1px solid var(--outline); border-radius:20px; box-shadow:var(--shadow); }
        .crumbs { margin-bottom:24px; color:var(--muted); font-size:14px; }
        .crumbs a { color:inherit; }
        .grid { display:grid; gap:16px; grid-template-columns:repeat(2,minmax(0,1fr)); margin-top:32px; }
        .card { display:block; padding:24px; color:inherit; text-decoration:none; transition:border-color .15s,transform .15s; }
        .card:hover { border-color:#91a6d7; transform:translateY(-1px); }
        .card:focus-visible,.button:focus-visible,a:focus-visible { outline:3px solid #8faaf0; outline-offset:3px; }
        .card h2 { margin:8px 0 16px; font-size:20px; }
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
        footer { padding:28px 0; border-top:1px solid var(--outline); color:var(--muted); background:var(--surface); font-size:14px; }
        @media (max-width:760px) { .grid,.detail-grid,.facts { grid-template-columns:1fr; } main { padding-top:28px; } .section,.card { padding:20px; } .metrics { grid-template-columns:1fr 1fr; } }
        @media (max-width:420px) { .shell { width:min(100% - 20px,1160px); } .metrics { grid-template-columns:1fr; } }
    </style>
</head>
<body>
<header class="topbar"><div class="shell topbar__inner"><a class="brand" href="{{ $urls->catalog() }}">ПРИЗМА <span>Индексы</span></a><span>Данные Росстата</span></div></header>
<main><div class="shell">@yield('content')</div></main>
<footer><div class="shell">Публичный справочник официальных индексов. Для расчёта стоимости используйте калькулятор ПРИЗМА.</div></footer>
</body>
</html>
