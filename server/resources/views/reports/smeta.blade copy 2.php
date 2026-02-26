<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Смета {{ $report['project']['number'] ?? 'Без номера' }}</title>

  <style>
    /* =========================
       DOMPDF • A4 • STABLE PAGE BREAKS
       ========================= */

    @page {
      size: A4;
      margin: 15mm 10mm 18mm 25mm; /* top right bottom left */
    }

    body {
      font-family: "DejaVu Sans", sans-serif;
      font-size: 10.4pt;
      line-height: 1.35;
      color: #111;
      background: #fff;
    }

    /* Helpers */
    .muted { color:#606060; }
    .mono { font-family:"DejaVu Sans Mono","Courier New",monospace; }
    .nowrap { white-space: nowrap; }
    .text-right { text-align:right; }
    .text-center { text-align:center; }
    .bold { font-weight:700; }
    .small { font-size:9pt; }
    .hr { height:1px; background:#d7d7d7; margin:4mm 0; }

    .container { width:100%; }

    /* Page control */
    .page-break { page-break-after: always; }
    .keep-with-next { page-break-after: avoid; }
    .no-break { page-break-inside: avoid; }

    /* ---------------- Header ---------------- */
    .header {
      margin: 0 0 5mm 0;
      padding: 0 0 3.5mm 0;
      border-bottom: 1px solid #ddd;
      page-break-after: avoid;
      page-break-inside: avoid;
    }

    .header-table {
      width: 100%;
      border-collapse: collapse;
      table-layout: fixed;
    }
    .header-table td { vertical-align: top; }
    .qr-block {
      width: 140px;
      text-align: center;
    }
    .qr-label {
      font-size: 9px;
      color: #555;
      margin-top: 4px;
      line-height: 1.3;
    }
    .title-block {
      text-align: center;
    }
    .title-block .title {
      font-size: 16px;
      font-weight: bold;
    }
    .title-block .subtitle {
      font-size: 11px;
      color: #666;
      margin-top: 3px;
    }
    .meta-block {
      width: 220px;
      text-align: right;
      font-size: 9px;
      color: #444;
      line-height: 1.35;
    }

    .info-row {
      display: table;
      width: 100%;
      table-layout: fixed;
      padding: 1.1mm 0;
      border-bottom: 1px dotted #e1e1e1;
    }
    .info-row:last-child { border-bottom: none; }

    .info-label, .info-value { display: table-cell; vertical-align: top; }
    .info-label { width: 25%; font-weight: 700; color:#222; }
    .info-value { width: 75%; text-align: left; color:#111; }

    /* ------------- Section titles ------------- */
    .section-title {
      font-size: 11pt;
      font-weight: 800;
      margin: 4mm 0 2mm 0;
      padding-left: 3.2mm;
      border-left: 2mm solid #4a4a4a;
      page-break-after: avoid;
    }

    .section-subtitle {
      font-size: 10pt;
      font-weight: 600;
      color: #333;
      margin: 3mm 0 1.8mm 0;
      padding-left: 2mm;
      border-left: 1.5mm solid #999;
      page-break-after: avoid;
    }

    /* ---------------- Tables ---------------- */
    table {
      width: 100%;
      border-collapse: collapse;
      margin: 0 0 3.0mm 0;
      font-size: 9.2pt;
      page-break-inside: auto; /* IMPORTANT: allow splitting */
    }

    thead { display: table-header-group; }
    tfoot { display: table-footer-group; }

    th, td {
      border: 1px solid #d7d7d7;
      padding: 1.6mm 2.0mm;
      vertical-align: top;
    }

    th {
      background: #f0f0f0;
      border-color: #c9c9c9;
      font-weight: 800;
      color: #000;
      text-align: left;
      word-break: normal;
      overflow-wrap: normal;
    }

    td { word-break: break-word; overflow-wrap: anywhere; }

    tbody tr:nth-child(even) { background: #fbfbfb; }
    tfoot tr { background:#efefef; font-weight:800; }
    tfoot td {
      border-top: 1.2px solid #9a9a9a;
      border-bottom: 1.2px solid #9a9a9a;
      padding-top: 2mm;
      padding-bottom: 2mm;
    }

    /* Allow DOMPDF to break table by rows; keep single row intact */
    tr { page-break-inside: avoid; page-break-after: auto; }

    /* ---------------- Summary card ---------------- */
    .summary-wrap {
      border: 1px solid #d7d7d7;
      border-left: 4px solid #111;
      background: #fff;
      padding: 3.5mm 4mm;
      margin: 0 0 5mm 0;
      page-break-inside: avoid;
    }

    .summary-title {
      font-size: 10.5pt;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: .4px;
      margin-bottom: 3mm;
    }

    .summary-table {
      width: 100%;
      border-collapse: collapse;
      margin: 0;
      font-size: 9.6pt;
    }

    .summary-table td {
      border: none;
      border-bottom: 1px solid #e6e6e6;
      padding: 1.8mm 0;
      background: transparent;
    }
    .summary-table tr:last-child td { border-bottom: none; }

    .summary-table .label { color:#222; }
    .summary-table .value {
      text-align: right;
      font-weight: 800;
      font-family: "DejaVu Sans Mono","Courier New",monospace;
      white-space: nowrap;
    }

    .summary-grand {
      margin-top: 3.5mm;
      padding-top: 3.5mm;
      border-top: 2px solid #111;
      display: table;
      width: 100%;
      table-layout: fixed;
    }

    .summary-grand .grand-label,
    .summary-grand .grand-value {
      display: table-cell;
      vertical-align: baseline;
    }

    .summary-grand .grand-label { font-size: 11pt; font-weight: 800; }
    .summary-grand .grand-value {
      font-size: 12.5pt;
      font-weight: 900;
      text-align: right;
      font-family: "DejaVu Sans Mono","Courier New",monospace;
      white-space: nowrap;
    }

    /* ---------------- Cards / detail blocks ---------------- */
    .card {
      border: 1px solid #d7d7d7;
      border-left: 3px solid #9a9a9a;
      background: #fff;
      padding: 3mm 4mm;
      margin: 3mm 0 4mm 0;
      /* IMPORTANT: DO NOT force no-break globally. Let DOMPDF split if needed. */
      page-break-inside: auto;
    }

    .card-title {
      font-weight: 800;
      font-size: 9pt;
      text-transform: uppercase;
      letter-spacing: .35px;
      margin-bottom: 2.5mm;
      page-break-after: avoid;
    }

    .card-body {
      font-size: 8.6pt;
      line-height: 1.18;
      white-space: pre-wrap;
      word-wrap: break-word;
    }

    .detail-block {
      font-size: 9.2pt;
      line-height: 1.25;
      background: #f6f6f6;
      padding: 2.4mm 3mm;
      border: 1px solid #e1e1e1;
      border-left: 3px solid #9a9a9a;
      margin-top: 2mm;
      page-break-inside: auto;
    }
    .detail-block div { margin: 0.6mm 0; }
    .detail-block strong { font-weight: 800; }

    /* ---------------- Totals ---------------- */
    .totals-section {
      margin-top: 6mm;
      border: 1px solid #d7d7d7;
      border-left: 4px solid #111;
      background: #fff;
      padding: 5mm 5mm;
      page-break-inside: avoid; /* keep totals intact if possible */
    }

    .totals-head {
      display: table;
      width: 100%;
      table-layout: fixed;
      margin-bottom: 2.5mm;
    }

    .totals-head .title,
    .totals-head .meta {
      display: table-cell;
      vertical-align: baseline;
    }

    .totals-head .title {
      font-size: 10.5pt;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: .4px;
    }

    .totals-head .meta {
      text-align: right;
      color: #606060;
      font-size: 9pt;
    }

    .totals-grid {
      display: table;
      width: 100%;
      table-layout: fixed;
      margin-top: 3.5mm;
    }

    .totals-grid .col {
      display: table-cell;
      vertical-align: top;
      width: 50%;
    }
    .totals-grid .col:first-child { padding-right: 6mm; }
    .totals-grid .col:last-child { padding-left: 6mm; }

    .total-row {
      display: table;
      width: 100%;
      table-layout: fixed;
      padding: 2.1mm 0;
      border-bottom: 1px solid #e6e6e6;
      font-size: 9.6pt;
    }

    .total-row span {
      display: table-cell;
      vertical-align: top;
    }

    .total-row span:last-child {
      text-align: right;
      font-weight: 800;
      font-family: "DejaVu Sans Mono","Courier New",monospace;
      white-space: nowrap;
    }

    .total-final {
      margin-top: 4mm;
      padding-top: 4mm;
      border-top: 2px solid #111;
      display: table;
      width: 100%;
      table-layout: fixed;
    }

    .total-final .label,
    .total-final .value {
      display: table-cell;
      vertical-align: baseline;
    }

    .total-final .label { font-size: 11pt; font-weight: 900; }
    .total-final .value {
      text-align: right;
      font-size: 13.5pt;
      font-weight: 900;
      font-family: "DejaVu Sans Mono","Courier New",monospace;
      white-space: nowrap;
    }

    /* Small tables */
    .positions-table { font-size: 9pt; }
    .positions-table th { font-size: 9pt; }
    .positions-table td { font-size: 9pt; padding: 1.3mm 1.8mm; }

    /* Signature */
    .signatures {
      margin-top: 8mm;
      display: table;
      width: 100%;
      table-layout: fixed;
      font-size: 9.8pt;
      page-break-inside: avoid;
    }
    .signatures .col { display: table-cell; vertical-align: top; width: 50%; }
    .signatures .col:first-child { padding-right: 10mm; }
    .signatures .col:last-child { padding-left: 10mm; }

    .sign-line {
      margin-top: 8mm;
      border-top: 1px solid #000;
      padding-top: 2mm;
      font-weight: 700;
    }

   /* =========================
   REPORT HEADER (premium / dompdf-friendly)
   ========================= */

.topbar-table {
  width: 100%;
  border-collapse: collapse;
  table-layout: auto; 
  margin-bottom: 4mm;
}

.topbar-table td {
  vertical-align: top;
  padding: 3mm;
}

/* 1) QR-код: минимальная ширина под изображение */
.topbar-qr {
  width: 1%; /* Трюк: минимально возможная ширина */
  white-space: nowrap;
  padding-right: 4mm;
}

.qr-img {
  display: block;
  width: 65px;
  height: 65px;
}

/* 2) Пояснение: занимает основное пространство */
.topbar-explain {
  width: auto;
  padding-right: 4mm;
}

.topbar-text {
  font-size: 8pt;
  line-height: 1;
  color: #444;
  text-align: left;
}

/* 3) Справка: фиксированная ширина справа */
.topbar-meta {
  width: 40mm;
  white-space: nowrap;
}

.meta-lines {
  border-left: 2px solid #111;
  padding-left: 3.5mm;
  font-size: 8pt;
  line-height: 1.1;
}

.meta-lines div {
  margin-bottom: 1.5mm;
}

.k { 
  color: #666; 
  font-size: 8pt;
  display: inline-block;
  min-width: 50px;
}

.mono-small { 
  font-family: monospace; 
  font-size: 8pt; 
}

a { color: inherit; text-decoration: underline; }
  </style>
</head>

<body>
  <div class="container">

  <table class="topbar-table">
  <tr>
    <td class="topbar-qr">
      <img src="{{ $qrSvg }}" class="qr-img" />
    </td>

    <td class="topbar-explain">
      <div class="topbar-text">
        QR-код служит средством защиты информации и подтверждения легитимности данных. 
        Юридическая значимость данных подтверждается только при наличии корректного QR-кода. 
        Проверка подлинности осуществляется на официальном сайте системы.
      </div>
    </td>

    <td class="topbar-meta">
      <div class="meta-lines">
        <div><span class="k">Дата: <b>{{ $revisionDate ?? '20.01.2026' }}</b></span></div>
        <div><span class="k">Hash:{{ $snapshotHashShort ?? '09c0dff2...769bc59d' }}</span></div>
        <div><span class="k">Версия: 1</span></div>
      </div>
    </td>
  </tr>
</table>


  <!-- TITLE AREA -->
  <div class="title-area">
    <div class="left">
      <div class="appendix">
        <span class="muted">Приложение № 1</span><br />
        к экспертному заключению
      </div>
    </div>

    <div class="center">
      <h1>Расчёт стоимости материалов и работ</h1>
    </div>

    <div class="right" style="text-align:right;">
      <div class="appendix">
        <span class="muted">Дата расчёта:</span><br />
        <span class="bold nowrap">{{ $revisionDate ?? date('d.m.Y') }}</span>
      </div>
    </div>
  </div>

  <!-- REQUISITES -->
  <div class="header-info">
    <div class="grid">
      <div class="col">
        <div class="info-row">
          <span class="info-label">Проект (дело):</span>
          <span class="info-value">{{ $report['project']['number'] ?? '—' }}</span>
        </div>
        <div class="info-row">
          <span class="info-label">Объект:</span>
          <span class="info-value">{{ $report['project']['address'] ?? '—' }}</span>
        </div>
      </div>

      <div class="col">
        <div class="info-row">
          <span class="info-label">Эксперт:</span>
          <span class="info-value">{{ $report['project']['expert_name'] ?? '—' }}</span>
        </div>
      </div>
    </div>
  </div>

</div>


    <!-- === 1A. СВОДНАЯ ТАБЛИЦА ИТОГОВ === -->
    <div class="summary-wrap">
      <div class="summary-title">Сводные итоги</div>

      <table class="summary-table">
        <tbody>
          <tr>
            <td class="label">Материалы (плиты + кромки)</td>
            <td class="value">{{ number_format($report['totals']['materials_cost'] ?? 0, 2, ',', ' ') }}</td>
          </tr>
          <tr>
            <td class="label">Операции</td>
            <td class="value">{{ number_format($report['totals']['operations_cost'] ?? 0, 2, ',', ' ') }}</td>
          </tr>
          <tr>
            <td class="label">Фурнитура/комплектующие</td>
            <td class="value">{{ number_format($report['totals']['fittings_cost'] ?? 0, 2, ',', ' ') }}</td>
          </tr>
          <tr>
            <td class="label">Нормируемые работы</td>
            <td class="value">{{ number_format($report['totals']['labor_works_cost'] ?? 0, 2, ',', ' ') }}</td>
          </tr>
          <tr>
            <td class="label">Накладные расходы</td>
            <td class="value">{{ number_format($report['totals']['expenses_cost'] ?? 0, 2, ',', ' ') }}</td>
          </tr>
        </tbody>
      </table>

      <div class="summary-grand">
        <div class="grand-label">ИТОГО</div>
        <div class="grand-value">{{ number_format($report['totals']['grand_total'] ?? 0, 2, ',', ' ') }} руб.</div>
      </div>
    </div>

    <!-- === 2. ПОЗИЦИИ === -->
    @php
      function translateEdgeScheme($code) {
        $schemes = [
          'O' => 'Вкруг',
          '=' => 'Параллельно длине',
          '||' => 'Параллельно ширине',
          'L' => 'Г-образно',
          'П' => 'П-образно',
          'none' => '',
        ];
        return $schemes[$code] ?? ($code && $code !== '—' ? $code : '');
      }
    @endphp

    @if(!empty($report['positions']))
      <div class="section-title">Перечень деталей, принятых к расчёту</div>
      <table class="positions-table">
        <thead>
          <tr>
            <th style="width: 35%;">Материал</th>
            <th class="text-center" style="width: 12%;">Размеры (мм)</th>
            <th class="text-right" style="width: 8%;">Кол-во</th>
            <th class="text-right" style="width: 15%;">Площадь (м²)</th>
            <th style="width: 30%;">Кромка</th>
          </tr>
        </thead>
        <tbody>
          @foreach($report['positions'] as $pos)
            @php
              // Skip facade positions — they have their own dedicated section
              if (($pos['kind'] ?? 'panel') === 'facade') continue;

              $width = $pos['width'] ?? 0;
              $length = $pos['length'] ?? 0;
              $qty = $pos['quantity'] ?? 0;
              $area = ($width / 1000) * ($length / 1000) * $qty;

              $detailName = $pos['custom_name'] ?? $pos['detail_type']['name'] ?? 'Деталь (без типа)';

              $materialName = $pos['material']['name']
                ?? $pos['material_name']
                ?? '(требуется указать материал)';

              $edgeScheme = translateEdgeScheme($pos['edge_scheme'] ?? 'none');
            @endphp
            <tr>
              <td style="max-width: 200px;">
                {{ $materialName }}
                <em class="muted" style="font-size: 9pt;">({{ $detailName }})</em>
              </td>
              <td class="text-center">{{ $width > 0 && $length > 0 ? $width . '×' . $length : '—' }}</td>
              <td class="text-right">{{ $qty }}</td>
              <td class="text-right mono">{{ number_format($area, 2) }}</td>
              <td>{{ $edgeScheme }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @endif

    <!-- === 3. ПЛИТЫ === -->
    @if(!empty($report['plates']))
      <div class="section-title">Расчёт плитных материалов</div>
      <table>
        <thead>
          <tr>
            <th style="width: 30%;">Материал</th>
            <th class="text-right" style="width: 12%;">Площадь м²</th>
            <th class="text-right" style="width: 8%;">Отходы</th>
            <th class="text-right" style="width: 12%;">С отходами</th>
            <th class="text-right" style="width: 8%;">Листов</th>
            <th class="text-right" style="width: 14%;">Цена/лист, руб.</th>
            <th class="text-right" style="width: 16%;">Итого, руб.</th>
          </tr>
        </thead>
        <tbody>
          @foreach($report['plates'] as $plate)
            @php
              $wasteCoeff = ($plate['waste_coeff'] ?? 1);
              $waste = round((($wasteCoeff - 1) * 100));
              $plateName = $plate['name'] ?? 'Материал не указан';
            @endphp
            <tr>
              <td style="max-width: 200px;">{{ $plateName }}</td>
              <td class="text-right mono">{{ number_format($plate['area_details'] ?? 0, 2) }}</td>
              <td class="text-right mono">{{ $waste }}%</td>
              <td class="text-right mono">{{ number_format($plate['area_with_waste'] ?? 0, 2) }}</td>
              <td class="text-right bold mono">{{ $plate['sheets_count'] ?? 0 }}</td>
              <td class="text-right mono nowrap">{{ number_format($plate['price_per_sheet'] ?? 0, 2, ',', ' ') }}</td>
              <td class="text-right bold mono nowrap">{{ number_format($plate['total_cost'] ?? 0, 2, ',', ' ') }}</td>
            </tr>
          @endforeach
        </tbody>
        <tfoot>
          <tr>
            <td colspan="6" style="text-align: right; font-weight: bold; padding-right: 4mm;">Итого по разделу:</td>
            <td class="text-right bold mono">
              @php
                $platesTotal = 0;
                foreach ($report['plates'] as $plate) { $platesTotal += ($plate['total_cost'] ?? 0); }
              @endphp
              {{ number_format($platesTotal, 2, ',', ' ') }}
            </td>
          </tr>
        </tfoot>
      </table>
    @endif

    <!-- === 4. КРОМКИ === -->
    @if(!empty($report['edges']))
      <div class="section-title">Расчёт кромочного материала</div>
      <table>
        <thead>
          <tr>
            <th style="width: 30%;">Материал</th>
            <th class="text-right" style="width: 12%;">Длина, м.п.</th>
            <th class="text-right" style="width: 8%;">Отходы</th>
            <th class="text-right" style="width: 12%;">С отходами</th>
            <th class="text-right" style="width: 14%;">Цена/м, руб.</th>
            <th class="text-right" style="width: 16%;">Итого, руб.</th>
          </tr>
        </thead>
        <tbody>
          @foreach($report['edges'] as $edge)
            @php
              $wasteCoeff = ($edge['waste_coeff'] ?? 1);
              $waste = round((($wasteCoeff - 1) * 100));
              $edgeName = $edge['name'] ?? 'Материал не указан';
            @endphp
            <tr>
              <td style="max-width: 200px;">{{ $edgeName }}</td>
              <td class="text-right mono">{{ number_format($edge['length_linear'] ?? 0, 2) }}</td>
              <td class="text-right mono">{{ $waste }}%</td>
              <td class="text-right mono">{{ number_format($edge['length_with_waste'] ?? 0, 2) }}</td>
              <td class="text-right mono nowrap">{{ number_format($edge['price_per_unit'] ?? 0, 2, ',', ' ') }}</td>
              <td class="text-right bold mono nowrap">{{ number_format($edge['total_cost'] ?? 0, 2, ',', ' ') }}</td>
            </tr>
          @endforeach
        </tbody>
        <tfoot>
          <tr>
            <td colspan="5" style="text-align: right; font-weight: bold; padding-right: 4mm;">Итого по разделу:</td>
            <td class="text-right bold mono">
              @php
                $edgesTotal = 0;
                foreach ($report['edges'] as $edge) { $edgesTotal += ($edge['total_cost'] ?? 0); }
              @endphp
              {{ number_format($edgesTotal, 2, ',', ' ') }}
            </td>
          </tr>
        </tfoot>
      </table>
    @endif

    <!-- === 4.1 ФАСАДНЫЕ МАТЕРИАЛЫ === -->
    @if(!empty($report['facades']))
      <div class="section-title">Фасадные материалы</div>
      <table>
        <thead>
          <tr>
            <th style="width: 22%;">Наименование</th>
            <th style="width: 10%;">Основа</th>
            <th class="text-right" style="width: 8%;">Толщ., мм</th>
            <th style="width: 12%;">Вид покрытия</th>
            <th class="text-right" style="width: 10%;">Площадь, м²</th>
            <th class="text-right" style="width: 12%;">Цена/м², руб.</th>
            <th style="width: 12%;">Метод</th>
            <th class="text-right" style="width: 14%;">Итого, руб.</th>
          </tr>
        </thead>
        <tbody>
          @foreach($report['facades'] as $facade)
            @php
              // Determine price method label from first position detail
              $methodLabel = 'один источник';
              $methodRange = '';
              $hasAggregated = false;
              foreach (($facade['position_details'] ?? []) as $pd) {
                  $pm = $pd['price_method'] ?? 'single';
                  $n = $pd['price_sources_count'] ?? 0;
                  // Only show aggregated label if method is not single AND there are actual sources
                  if ($pm !== 'single' && $n > 0) {
                      $hasAggregated = true;
                      $methodLabel = match($pm) {
                          'mean' => "среднее (n={$n})",
                          'median' => "медиана (n={$n})",
                          'trimmed_mean' => "усеч. средн. (n={$n})",
                          default => $pm,
                      };
                      if (!empty($pd['price_min']) && !empty($pd['price_max'])) {
                          $methodRange = number_format($pd['price_min'], 2, ',', ' ') . ' – ' . number_format($pd['price_max'], 2, ',', ' ');
                      }
                      break; // Use first aggregated position's info for group label
                  }
              }
            @endphp
            <tr>
              <td style="max-width: 200px;">{{ $facade['name'] ?? 'Фасад' }}</td>
              <td>{{ $facade['base_material_label'] ?? '—' }}</td>
              <td class="text-right mono">{{ $facade['thickness_mm'] ?? '—' }}</td>
              <td>{{ $facade['finish_name'] ?? '—' }}</td>
              <td class="text-right mono">{{ number_format($facade['area_total'] ?? 0, 2) }}</td>
              <td class="text-right mono nowrap">{{ number_format($facade['price_per_m2'] ?? 0, 2, ',', ' ') }}</td>
              <td style="font-size: 8.6pt;">
                {{ $methodLabel }}
                @if($methodRange)
                  <br><span style="color: #808080;">{{ $methodRange }}</span>
                @endif
              </td>
              <td class="text-right bold mono nowrap">{{ number_format($facade['total_cost'] ?? 0, 2, ',', ' ') }}</td>
            </tr>
          @endforeach
        </tbody>
        <tfoot>
          <tr>
            <td colspan="7" style="text-align: right; font-weight: bold; padding-right: 4mm;">Итого по разделу:</td>
            <td class="text-right bold mono">
              @php
                $facadesTotal = 0;
                foreach ($report['facades'] as $f) { $facadesTotal += ($f['total_cost'] ?? 0); }
              @endphp
              {{ number_format($facadesTotal, 2, ',', ' ') }}
            </td>
          </tr>
        </tfoot>
      </table>

      {{-- Per-facade position detail list --}}
      @php
        $allFacadePositions = [];
        foreach ($report['facades'] as $facade) {
            foreach (($facade['position_details'] ?? []) as $pd) {
                $pd['facade_name'] = $facade['name'] ?? 'Фасад';
                $pd['base_material_label'] = $facade['base_material_label'] ?? '—';
                $pd['thickness_mm'] = $facade['thickness_mm'] ?? '—';
                $pd['finish_name'] = $facade['finish_name'] ?? '—';
                $allFacadePositions[] = $pd;
            }
        }
      @endphp
      @if(!empty($allFacadePositions))
        <div style="margin-top: 3mm; font-size: 10pt; font-weight: 600;">Перечень фасадных деталей</div>
        <table>
          <thead>
            <tr>
              <th style="width: 30%;">Название</th>
              <th style="width: 15%;">Материал</th>
              <th class="text-center" style="width: 15%;">Размеры (Ш×В), мм</th>
              <th class="text-right" style="width: 8%;">Кол-во</th>
              <th class="text-right" style="width: 12%;">Площадь, м²</th>
              <th class="text-right" style="width: 20%;">Итого, руб.</th>
            </tr>
          </thead>
          <tbody>
            @foreach($allFacadePositions as $fp)
              <tr>
                <td>{{ $fp['detail_type'] ?? 'Фасад' }}</td>
                <td style="font-size: 8.6pt;">{{ $fp['facade_name'] }}</td>
                <td class="text-center">{{ ($fp['width'] ?? 0) }}×{{ ($fp['length'] ?? 0) }}</td>
                <td class="text-right">{{ $fp['quantity'] ?? 1 }}</td>
                <td class="text-right mono">{{ number_format($fp['area_m2'] ?? 0, 4) }}</td>
                <td class="text-right mono nowrap">{{ number_format($fp['total_cost'] ?? 0, 2, ',', ' ') }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      @endif

      {{-- Per-position facade quote evidence --}}
      @php
        $aggregatedPositions = [];
        foreach ($report['facades'] as $facade) {
            foreach (($facade['position_details'] ?? []) as $pd) {
                if (($pd['price_method'] ?? 'single') !== 'single' && !empty($pd['quotes'])) {
                    $pd['facade_name'] = $facade['name'] ?? 'Фасад';
                    $aggregatedPositions[] = $pd;
                }
            }
        }
      @endphp
      @if(!empty($aggregatedPositions))
        <div style="margin-top: 4mm; font-size: 10pt; font-weight: 600;">Котировки фасадов по позициям</div>
        <div style="font-size: 8.6pt; color: #606060; margin-bottom: 2mm;">
          Детализация ценовых источников для позиций с агрегированной ценой.
        </div>
        @foreach($aggregatedPositions as $aggPos)
          <div style="font-size: 9pt; font-weight: 500; margin-top: 2mm; margin-bottom: 1mm;">
            {{ $aggPos['facade_name'] }} — {{ $aggPos['detail_type'] }} ({{ $aggPos['width'] }}×{{ $aggPos['length'] }} мм, кол-во {{ $aggPos['quantity'] }})
            &nbsp;·&nbsp;
            @php
              $mLabel = match($aggPos['price_method']) {
                  'mean' => 'Среднее',
                  'median' => 'Медиана',
                  'trimmed_mean' => 'Усеч. среднее',
                  default => $aggPos['price_method'],
              };
            @endphp
            {{ $mLabel }} из {{ $aggPos['price_sources_count'] ?? count($aggPos['quotes']) }} источников
            → {{ number_format($aggPos['price_per_m2'], 2, ',', ' ') }} ₽/м²
            @if(!empty($aggPos['price_min']) && !empty($aggPos['price_max']))
              ({{ number_format($aggPos['price_min'], 2, ',', ' ') }} – {{ number_format($aggPos['price_max'], 2, ',', ' ') }})
            @endif
          </div>
          <table style="font-size: 8.6pt;">
            <thead>
              <tr>
                <th style="width: 20%;">Поставщик</th>
                <th style="width: 18%;">Прайс-лист</th>
                <th class="text-right" style="width: 12%;">Цена/м²</th>
                <th style="width: 12%;">Тип</th>
                <th style="width: 16%;">Файл / URL</th>
                <th style="width: 10%;">Дата</th>
                <th style="width: 12%;">SHA-256</th>
              </tr>
            </thead>
            <tbody>
              @foreach($aggPos['quotes'] as $q)
                <tr>
                  <td>{{ $q['supplier_name'] ?? '—' }}</td>
                  <td>{{ $q['price_list_name'] ?? '—' }} (v{{ $q['version_number'] ?? '?' }})</td>
                  <td class="text-right mono">{{ number_format($q['price_per_m2'] ?? 0, 2, ',', ' ') }}</td>
                  <td>
                    @php
                      $stLabel = match($q['source_type'] ?? '') {
                          'file' => 'Файл',
                          'url' => 'URL',
                          'manual' => 'Ручной',
                          default => '—',
                      };
                    @endphp
                    {{ $stLabel }}
                  </td>
                  <td style="max-width: 120px; word-break: break-all; font-size: 8pt;">
                    @if(!empty($q['source_url']))
                      <a href="{{ $q['source_url'] }}">{{ \Illuminate\Support\Str::limit($q['source_url'], 40) }}</a>
                    @elseif(!empty($q['original_filename']))
                      @if(!empty($q['price_list_version_id']) && ($q['source_type'] ?? '') === 'file' && !empty($documentToken))
                        <a href="{{ url('/public/price-file/' . $q['price_list_version_id'] . '/' . $documentToken) }}">{{ $q['original_filename'] }}</a>
                      @else
                        {{ $q['original_filename'] }}
                      @endif
                    @else
                      —
                    @endif
                  </td>
                  <td style="font-size: 8pt;">{{ $q['effective_date'] ?? ($q['captured_at'] ?? '—') }}</td>
                  <td class="mono" style="font-size: 7pt; word-break: break-all;">
                    @if(!empty($q['sha256']))
                      {{ substr($q['sha256'], 0, 16) }}…
                    @else
                      —
                    @endif
                  </td>
                </tr>
                @if(!empty($q['supplier_article']) || !empty($q['supplier_category']))
                <tr>
                  <td colspan="7" style="font-size: 7.5pt; color: #808080; padding-top: 0; padding-bottom: 2px;">
                    @if(!empty($q['supplier_article']))
                      Артикул: {{ $q['supplier_article'] }}
                    @endif
                    @if(!empty($q['supplier_category']))
                      @if(!empty($q['supplier_article'])) · @endif
                      Категория: {{ $q['supplier_category'] }}
                    @endif
                    @if(!empty($q['supplier_description']))
                      @if(!empty($q['supplier_article']) || !empty($q['supplier_category'])) · @endif
                      {{ \Illuminate\Support\Str::limit($q['supplier_description'], 80) }}
                    @endif
                  </td>
                </tr>
                @endif
              @endforeach
            </tbody>
          </table>
          {{-- Mismatch flags warning for extended mode quotes --}}
          @php
            $mismatchQuotes = array_filter($aggPos['quotes'], fn($q) => !empty($q['mismatch_flags']));
          @endphp
          @if(!empty($mismatchQuotes))
            @php
              $mismatchLabels = [
                  'facade_thickness_mm' => 'толщина',
                  'facade_cover_type' => 'тип покрытия',
                  'facade_class' => 'класс фасада',
                  'facade_base_type' => 'основа',
                  'facade_covering' => 'покрытие',
                  'facade_collection' => 'коллекция',
              ];
              $allFlags = [];
              foreach ($mismatchQuotes as $mq) {
                  foreach ($mq['mismatch_flags'] as $flag) {
                      $allFlags[$flag] = $mismatchLabels[$flag] ?? $flag;
                  }
              }
            @endphp
            <div style="margin-top: 2mm; padding: 2mm 3mm; background: #fff3cd; border: 1px solid #ffc107; border-radius: 2px; font-size: 8pt; color: #856404;">
              <strong>⚠ Внимание:</strong>
              при расчёте использованы котировки с несовпадающими признаками:
              <em>{{ implode(', ', array_values($allFlags)) }}</em>.
              Расчёт выполнен в расширенном (extended) режиме сопоставления.
            </div>
          @endif
        @endforeach
      @endif
    @endif

    <!-- === 4.5 НОРМИРУЕМЫЕ РАБОТЫ === -->
    @if(!empty($report['labor_works']))
      <div class="section-title">Нормируемые работы</div>
      <table>
        <thead>
          <tr>
            <th style="width: 6%;">№</th>
            <th style="width: 33%;">Наименование</th>
            <th style="width: 18%;">Основание</th>
            <th class="text-right" style="width: 10%;">Норма, ч</th>
            <th class="text-right" style="width: 16%;">Нормо-час, руб./ч</th>
            <th class="text-right" style="width: 17%;">Сумма, руб.</th>
          </tr>
        </thead>
        <tbody>
          @php $laborWorkIndex = 1; @endphp
          @foreach($report['labor_works'] as $work)
            @php
              $rate = $work['rate_per_hour'] ?? 0;
              $amount = ($work['cost'] ?? 0);
            @endphp
            <tr>
              <td class="text-center">{{ $laborWorkIndex }}</td>
              <td style="max-width: 220px;">{{ $work['title'] ?? '—' }}</td>
              <td style="max-width: 140px;">{{ $work['basis'] ?? '—' }}</td>
              <td class="text-right mono">{{ number_format($work['hours'] ?? 0, 2, ',', ' ') }}</td>
              <td class="text-right mono">{{ number_format($rate, 2, ',', ' ') }}</td>
              <td class="text-right bold mono">{{ number_format($amount, 2, ',', ' ') }}</td>
            </tr>
            @php $laborWorkIndex++; @endphp
          @endforeach
        </tbody>
        <tfoot>
          <tr>
            <td colspan="3" style="text-align: right; font-weight: bold; padding-right: 4mm;">Всего часов:</td>
            <td class="text-right bold mono">
              @php
                $laborHoursTotal = 0;
                foreach ($report['labor_works'] as $work) { $laborHoursTotal += ($work['hours'] ?? 0); }
              @endphp
              {{ number_format($laborHoursTotal, 2, ',', ' ') }}
            </td>
            <td colspan="2"></td>
          </tr>
          <tr>
            <td colspan="5" style="text-align: right; font-weight: bold; padding-right: 4mm;">Итого по разделу:</td>
            <td class="text-right bold mono">
              @php
                $laborWorksTotal = 0;
                foreach ($report['labor_works'] as $work) { $laborWorksTotal += ($work['cost'] ?? 0); }
              @endphp
              {{ number_format($laborWorksTotal, 2, ',', ' ') }}
            </td>
          </tr>
        </tfoot>
      </table>
    @endif

    <!-- === 4.6 ДЕТАЛИЗАЦИЯ РАБОТ === -->
    @if(!empty($report['labor_works']))
      @foreach($report['labor_works'] as $work)
        @if(!empty($work['steps']))
          <div class="section-subtitle">Детализация: {{ $work['title'] }}</div>
          <table>
            <thead>
              <tr>
                <th style="width: 6%;">№</th>
                <th style="width: 42%;">Подоперация</th>
                <th style="width: 28%;">Объём</th>
                <th class="text-right" style="width: 24%;">Время, ч</th>
              </tr>
            </thead>
            <tbody>
              @php $stepIndex = 1; @endphp
              @foreach($work['steps'] as $step)
                <tr>
                  <td class="text-center">{{ $stepIndex }}</td>
                  <td style="max-width: 280px;">{{ $step['title'] ?? '—' }}</td>
                  <td style="max-width: 180px;">{{ $step['input_data'] ?? '—' }}</td>
                  <td class="text-right mono">{{ number_format($step['hours'] ?? 0, 2, ',', ' ') }}</td>
                </tr>
                @php $stepIndex++; @endphp
              @endforeach
            </tbody>
            <tfoot>
              <tr>
                <td colspan="3" style="text-align: right; font-weight: bold; padding-right: 4mm;">Итого по детализации:</td>
                <td class="text-right bold mono">
                  @php
                    $stepsTotal = 0;
                    foreach ($work['steps'] as $step) { $stepsTotal += ($step['hours'] ?? 0); }
                  @endphp
                  {{ number_format($stepsTotal, 2, ',', ' ') }}
                </td>
              </tr>
            </tfoot>
          </table>
        @endif
      @endforeach
    @endif

    <!-- === 5. ОПЕРАЦИИ === -->
    @if(!empty($report['operations']))
      <div class="section-title">Расчёт стоимости работ</div>
      <table>
        <thead>
          <tr>
            <th style="width: 35%;">Наименование</th>
            <th class="text-right" style="width: 12%;">Кол-во</th>
            <th class="text-center" style="width: 8%;">Ед.</th>
            <th class="text-right" style="width: 14%;">Цена/ед, руб.</th>
            <th class="text-right" style="width: 16%;">Сумма, руб.</th>
          </tr>
        </thead>
        <tbody>
          @foreach($report['operations'] as $op)
            @php
              $opName = str_replace('не добавлено', 'отсутствует (не заявлено в исходных данных)', $op['name'] ?? 'Операция не указана');
              $opUnit = str_replace('не добавлено', 'отсутствует (не заявлено в исходных данных)', $op['unit'] ?? 'ед.');
            @endphp
            <tr>
              <td style="max-width: 220px;">{{ $opName }}</td>
              <td class="text-right bold mono">{{ $op['quantity'] ?? 0 }}</td>
              <td class="text-center">{{ $opUnit }}</td>
              <td class="text-right mono nowrap">{{ number_format($op['cost_per_unit'] ?? 0, 2, ',', ' ') }}</td>
              <td class="text-right bold mono nowrap">{{ number_format($op['total_cost'] ?? 0, 2, ',', ' ') }}</td>
            </tr>
          @endforeach
        </tbody>
        <tfoot>
          <tr>
            <td colspan="4" style="text-align: right; font-weight: bold; padding-right: 4mm;">Итого по разделу:</td>
            <td class="text-right bold mono">
              @php
                $operationsTotal = 0;
                foreach ($report['operations'] as $op) { $operationsTotal += ($op['total_cost'] ?? 0); }
              @endphp
              {{ number_format($operationsTotal, 2, ',', ' ') }}
            </td>
          </tr>
        </tfoot>
      </table>
    @endif

    <!-- === 6. ФУРНИТУРА === -->
    @if(!empty($report['fittings']))
      <div class="section-title">Фурнитура и комплектующие</div>
      <table>
        <thead>
          <tr>
            <th style="width: 32%;">Наименование</th>
            <th style="width: 14%;">Артикул</th>
            <th class="text-right" style="width: 10%;">Кол-во</th>
            <th class="text-center" style="width: 8%;">Ед.</th>
            <th class="text-right" style="width: 12%;">Цена, руб.</th>
            <th class="text-right" style="width: 16%;">Сумма, руб.</th>
          </tr>
        </thead>
        <tbody>
          @foreach($report['fittings'] as $fitting)
            @php
              $fittingName = str_replace('не добавлено', 'отсутствует (не заявлено в исходных данных)', $fitting['name'] ?? 'Фурнитура не указана');
              $fittingArticle = str_replace('не добавлено', '—', $fitting['article'] ?? '—');
              $fittingUnit = str_replace('не добавлено', 'шт.', $fitting['unit'] ?? 'шт.');
            @endphp
            <tr>
              <td style="max-width: 180px;">{{ $fittingName }}</td>
              <td class="mono small">{{ $fittingArticle }}</td>
              <td class="text-right mono">{{ number_format($fitting['quantity'] ?? 0, 1) }}</td>
              <td class="text-center">{{ $fittingUnit }}</td>
              <td class="text-right mono nowrap">{{ number_format($fitting['unit_price'] ?? 0, 2, ',', ' ') }}</td>
              <td class="text-right bold mono nowrap">{{ number_format($fitting['total_cost'] ?? 0, 2, ',', ' ') }}</td>
            </tr>
          @endforeach
        </tbody>
        <tfoot>
          <tr>
            <td colspan="5" style="text-align: right; font-weight: bold; padding-right: 4mm;">Итого по разделу:</td>
            <td class="text-right bold mono">
              @php
                $fittingsTotal = 0;
                foreach ($report['fittings'] as $fitting) { $fittingsTotal += ($fitting['total_cost'] ?? 0); }
              @endphp
              {{ number_format($fittingsTotal, 2, ',', ' ') }}
            </td>
          </tr>
        </tfoot>
      </table>
    @endif

    <!-- === 7. НАКЛАДНЫЕ РАСХОДЫ === -->
    @if(!empty($report['expenses']))
      <div class="section-title">Накладные расходы</div>
      <table>
        <thead>
          <tr>
            <th style="width: 45%;">Описание</th>
            <th style="width: 40%;">Примечание</th>
            <th class="text-right" style="width: 15%;">Сумма, руб.</th>
          </tr>
        </thead>
        <tbody>
          @foreach($report['expenses'] as $expense)
            @php
              $expenseType = str_replace('не добавлено', 'отсутствует (не заявлено в исходных данных)', $expense['type'] ?? 'Расход не указан');
              $expenseDesc = str_replace('не добавлено', '—', $expense['description'] ?? '—');
            @endphp
            <tr>
              <td style="max-width: 250px;">{{ $expenseType }}</td>
              <td style="max-width: 250px; font-size: 9pt; color: #606060;">{{ $expenseDesc }}</td>
              <td class="text-right bold mono nowrap">{{ number_format($expense['cost'] ?? 0, 2, ',', ' ') }}</td>
            </tr>
          @endforeach
        </tbody>
        <tfoot>
          <tr>
            <td colspan="2" style="text-align: right; font-weight: bold; padding-right: 4mm;">Итого по разделу:</td>
            <td class="text-right bold mono">
              @php
                $expensesTotal = 0;
                foreach ($report['expenses'] as $expense) { $expensesTotal += ($expense['cost'] ?? 0); }
              @endphp
              {{ number_format($expensesTotal, 2, ',', ' ') }}
            </td>
          </tr>
        </tfoot>
      </table>
    @endif

    <!-- === 8. ИТОГИ === -->
    <div class="totals-section">
      <div class="totals-head">
        <div class="title">Итоговая стоимость</div>
        <div class="meta">Валюта: руб.</div>
      </div>

      <div class="totals-grid">
        <div class="col">
          <div class="total-row">
            <span>Материалы (плиты + кромки + фасады):</span>
            <span>{{ number_format($report['totals']['materials_cost'] ?? 0, 2, ',', ' ') }}</span>
          </div>
          <div class="total-row">
            <span>Операции и работы:</span>
            <span>{{ number_format($report['totals']['operations_cost'] ?? 0, 2, ',', ' ') }}</span>
          </div>
          <div class="total-row">
            <span>Нормируемые работы:</span>
            <span>{{ number_format($report['totals']['labor_works_cost'] ?? 0, 2, ',', ' ') }}</span>
          </div>
        </div>

        <div class="col">
          <div class="total-row">
            <span>Фурнитура и комплектующие:</span>
            <span>{{ number_format($report['totals']['fittings_cost'] ?? 0, 2, ',', ' ') }}</span>
          </div>
          <div class="total-row">
            <span>Накладные расходы:</span>
            <span>{{ number_format($report['totals']['expenses_cost'] ?? 0, 2, ',', ' ') }}</span>
          </div>
        </div>
      </div>

      <div class="total-final">
        <div class="label">ИТОГО:</div>
        <div class="value">{{ number_format($report['totals']['grand_total'] ?? 0, 2, ',', ' ') }} руб.</div>
      </div>

      @if(!empty($report['totals']['grand_total']))
        <div style="margin-top: 4mm; font-size: 10pt; line-height: 1.6; color: #222;">
          <strong>Прописью:</strong> {{ ucfirst(\App\Helpers\NumberToWords::convert($report['totals']['grand_total'])) }}.
        </div>
      @endif
    </div>

    <!-- === PAGE BREAK BEFORE JUSTIFICATIONS (stabilize layout) === -->
    @if(!empty($report['profile_rate_justifications']) && is_array($report['profile_rate_justifications']))
      <div class="page-break"></div>

      @foreach($report['profile_rate_justifications'] as $justification)

        <!-- Split one huge block into multiple smaller blocks to avoid “holes” -->
        <div class="section-title">Обоснование стоимости нормо-часа по нормируемым работам</div>

        <div class="card no-break">
          <div class="card-title">Профиль</div>
          <div style="font-size: 9.8pt; line-height: 1.55;">
            <div><strong>{{ strtoupper($justification['profile_name'] ?? '—') }}</strong></div>
            @if(!empty($justification['region']))
              <div style="margin-top: 1mm;">Регион: <strong>{{ $justification['region'] }}</strong></div>
            @endif
            @if(!empty($justification['date']))
              <div style="margin-top: 1mm;">Дата фиксации ставки: <strong>{{ $justification['date'] }}</strong></div>
            @endif
          </div>
        </div>

        @if(!empty($justification['sources_stats']))
          <div class="card no-break">
            <div class="card-title">Статистика и итоговая ставка</div>
            <div style="font-size: 9.2pt; line-height: 1.5;">
              <div>Использовано источников: <strong>{{ $justification['sources_count_used'] ?? count($justification['sources_stats']) }}</strong></div>
              <div>Метод агрегации: <strong>{{ ucfirst($justification['calculation_method'] ?? '—') }}</strong></div>
              <div style="margin-top: 1.5mm; font-size: 10pt;">Итоговая ставка: <strong>{{ number_format($justification['rate'] ?? 0, 2, ',', ' ') }} руб./ч</strong></div>
            </div>
          </div>

          @if(strtolower($justification['calculation_method'] ?? '') === 'медиана')
            <div class="card">
              <div class="card-title">Методика</div>
              <div style="font-size: 9pt; line-height: 1.35; background: #f6f6f6; border: 1px solid #e1e1e1; padding: 2.5mm 3mm;">
                <strong>Метод медианы:</strong> Итоговая ставка определяется как центральное значение упорядоченного ряда ставок. Метод обеспечивает устойчивость результата к экстремальным значениям (выбросам) и отражает типичное рыночное значение.
              </div>

              <div style="margin-top: 2mm; font-size: 9pt; line-height: 1.45; background: #f9f9f9; border: 1px solid #e1e1e1; padding: 2.5mm 3mm;">
                <strong>Расчёт:</strong>
                @php
                  $rates = array_column($justification['sources_stats'], 'rate');
                  $rates = array_map(fn($r) => (float)$r, $rates);
                  sort($rates);
                  $count = count($rates);
                  $ratesStr = implode(', ', array_map(function($r) { return number_format($r, 2, ',', ' '); }, $rates));
                @endphp

                <div style="margin-top: 1mm;">Отсортированные ставки: <strong>{{ $ratesStr }} руб./ч</strong></div>

                @if($count > 0)
                  @if($count % 2 == 0)
                    @php
                      $mid1 = $rates[$count / 2 - 1];
                      $mid2 = $rates[$count / 2];
                      $median = ($mid1 + $mid2) / 2;
                    @endphp
                    <div style="margin-top: 1mm;">
                      Медиана: <strong>({{ number_format($mid1, 2, ',', ' ') }} + {{ number_format($mid2, 2, ',', ' ') }}) / 2 = {{ number_format($median, 2, ',', ' ') }} руб./ч</strong>
                    </div>
                  @else
                    @php $median = $rates[(int)floor($count / 2)]; @endphp
                    <div style="margin-top: 1mm;">Медиана: <strong>{{ number_format($median, 2, ',', ' ') }} руб./ч</strong></div>
                  @endif
                @endif

                <div style="margin-top: 1mm; font-size: 10pt;">
                  <strong>Принятая ставка: {{ number_format($justification['rate'] ?? 0, 2, ',', ' ') }} руб./ч</strong>
                </div>
              </div>
            </div>
          @endif

          <div class="card">
            <div class="card-title">Источники данных</div>

            <table style="width: 100%; margin-top: 1mm; font-size: 9pt;">
              <thead>
                <tr>
                  <th style="width: 6%; text-align: center;">№</th>
                  <th style="width: 34%;">Источник</th>
                  <th style="width: 20%;">Профиль</th>
                  <th style="width: 20%; text-align: right;">Ставка, руб./ч</th>
                  <th style="width: 20%;">Дата</th>
                </tr>
              </thead>
              <tbody>
                @php $sourceIndex = 1; @endphp
                @foreach($justification['sources_stats'] as $source)
                  <tr>
                    <td class="text-center">{{ $sourceIndex }}</td>
                    <td>{{ $source['name'] ?? '—' }}</td>
                    <td>{{ $justification['profile_name'] ?? '—' }}</td>
                    <td class="text-right mono">{{ number_format((float)($source['rate'] ?? 0), 2, ',', ' ') }}</td>
                    <td style="font-size: 8.6pt;">
                      @if(!empty($source['date']))
                        {{ \Carbon\Carbon::parse($source['date'])->format('d.m.Y') }}
                      @else
                        —
                      @endif
                    </td>
                  </tr>
                  @php $sourceIndex++; @endphp
                @endforeach
              </tbody>
            </table>

            @if(!empty($justification['source_links']))
              <div style="margin-top: 2mm; font-size: 8.6pt; color: #666;">
                <strong>Ссылки на источники:</strong>
                @php $linkIndex = 1; @endphp
                @foreach($justification['source_links'] as $link)
                  <div>{{ $linkIndex }}) <a href="{{ $link }}" style="text-decoration: underline; word-break: break-all;">{{ $link }}</a></div>
                  @php $linkIndex++; @endphp
                @endforeach
              </div>
            @endif
          </div>
        @endif

        @if(!empty($justification['works']))
          <div class="card">
            <div class="card-title">Работы, рассчитанные по данной ставке</div>

            <table style="width: 100%; margin-top: 1mm; font-size: 9pt;">
              <thead>
                <tr>
                  <th style="width: 55%;">Наименование работы</th>
                  <th style="width: 15%; text-align: right;">Часы</th>
                  <th style="width: 15%; text-align: right;">Ставка, руб./ч</th>
                  <th style="width: 15%; text-align: right;">Сумма, руб.</th>
                </tr>
              </thead>
              <tbody>
                @foreach($justification['works'] as $work)
                  <tr>
                    <td style="max-width: 200px;">{{ $work['title'] ?? '—' }}</td>
                    <td class="text-right mono">{{ number_format((float)($work['hours'] ?? 0), 2, ',', ' ') }}</td>
                    <td class="text-right mono">{{ number_format((float)($work['rate'] ?? 0), 2, ',', ' ') }}</td>
                    <td class="text-right mono bold">{{ number_format((float)($work['cost'] ?? 0), 2, ',', ' ') }}</td>
                  </tr>
                @endforeach
                <tr style="background: #efefef;">
                  <td colspan="3" class="text-right" style="font-weight: 800;">Итого:</td>
                  <td class="text-right mono bold">{{ number_format((float)($justification['total_cost'] ?? 0), 2, ',', ' ') }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        @endif

      @endforeach
    @endif

    <!-- === ИСТОЧНИКИ ЦЕНОВЫХ ДАННЫХ === -->
    @if(!empty($report['price_sources']))
      <div class="page-break"></div>
      <div class="section-title">Источники ценовых данных</div>
      <div style="font-size: 9pt; color: #606060; margin-bottom: 3mm;">
        Перечень прайс-листов, использованных при расчёте стоимости материалов и фасадов.
      </div>
      <table>
        <thead>
          <tr>
            <th style="width: 5%;">№</th>
            <th style="width: 18%;">Поставщик</th>
            <th style="width: 20%;">Прайс-лист</th>
            <th style="width: 12%;">Тип источника</th>
            <th style="width: 17%;">Файл / URL</th>
            <th style="width: 14%;">Дата прайса</th>
            <th style="width: 14%;">SHA-256</th>
          </tr>
        </thead>
        <tbody>
          @php $srcIdx = 1; @endphp
          @foreach($report['price_sources'] as $src)
            <tr>
              <td class="text-center">{{ $srcIdx }}</td>
              <td style="max-width: 140px;">{{ $src['supplier_name'] ?? '—' }}</td>
              <td style="max-width: 160px;">{{ $src['price_list_name'] ?? '—' }} (v{{ $src['version_number'] ?? '?' }})</td>
              <td>{{ $src['source_type_label'] ?? '—' }}</td>
              <td style="max-width: 140px; font-size: 8.6pt; word-break: break-all;">
                @if(!empty($src['source_url']))
                  <a href="{{ $src['source_url'] }}" style="text-decoration: underline;">{{ \Illuminate\Support\Str::limit($src['source_url'], 50) }}</a>
                @elseif(!empty($src['original_filename']))
                  @if(!empty($src['price_list_version_id']) && ($src['source_type'] ?? '') === 'file' && !empty($documentToken))
                    <a href="{{ url('/public/price-file/' . $src['price_list_version_id'] . '/' . $documentToken) }}" style="text-decoration: underline;">{{ $src['original_filename'] }}</a>
                  @else
                    {{ $src['original_filename'] }}
                  @endif
                @else
                  —
                @endif
              </td>
              <td style="font-size: 8.6pt;">
                @if(!empty($src['effective_date']))
                  {{ $src['effective_date'] }}
                @elseif(!empty($src['captured_at']))
                  {{ $src['captured_at'] }}
                @else
                  —
                @endif
              </td>
              <td class="mono" style="font-size: 7.5pt; word-break: break-all;">
                @if(!empty($src['sha256']))
                  {{ substr($src['sha256'], 0, 16) }}…
                @else
                  —
                @endif
              </td>
            </tr>
            @php $srcIdx++; @endphp
          @endforeach
        </tbody>
      </table>
    @endif

    <!-- === СПРАВОЧНЫЕ БЛОКИ === -->
    @if(!empty($report['project']['text_blocks']) && is_array($report['project']['text_blocks']))
      <div class="page-break"></div>
      <div class="section-title">Справочные сведения</div>

      @foreach($report['project']['text_blocks'] as $block)
        @if($block && (is_array($block) ? (!empty($block['title']) || !empty($block['text'])) && (($block['enabled'] ?? true) !== false) : trim($block) !== ''))
          <div class="card">
            @if(is_array($block) && isset($block['title']))
              @if(!empty($block['title']))
                <div class="card-title">{{ $block['title'] }}</div>
              @endif
              @if(!empty($block['text']))
                <div class="card-body">{{ trim($block['text']) }}</div>
              @endif
            @else
              <div class="card-body">{{ trim($block) }}</div>
            @endif
          </div>
        @endif
      @endforeach
    @endif

    <!-- === ОПИСАНИЯ КОЭФФИЦИЕНТОВ ОТХОДОВ === -->
    @if(!empty($report['project']['waste_plate_description']) && ($report['project']['show_waste_plate_description'] ?? false) === true)
      <div class="card">
        @if(!empty($report['project']['waste_plate_description']['title']))
          <div class="card-title">{{ $report['project']['waste_plate_description']['title'] }}</div>
        @endif
        @if(!empty($report['project']['waste_plate_description']['text']))
          <div class="card-body">{{ trim($report['project']['waste_plate_description']['text']) }}</div>
        @endif
      </div>
    @endif

    @if(!empty($report['project']['waste_edge_description']) && ($report['project']['show_waste_edge_description'] ?? false) === true)
      <div class="card">
        @if(!empty($report['project']['waste_edge_description']['title']))
          <div class="card-title">{{ $report['project']['waste_edge_description']['title'] }}</div>
        @endif
        @if(!empty($report['project']['waste_edge_description']['text']))
          <div class="card-body">{{ trim($report['project']['waste_edge_description']['text']) }}</div>
        @endif
      </div>
    @endif

    @if(!empty($report['project']['waste_operations_description']) && ($report['project']['show_waste_operations_description'] ?? false) === true)
      <div class="card">
        @if(!empty($report['project']['waste_operations_description']['title']))
          <div class="card-title">{{ $report['project']['waste_operations_description']['title'] }}</div>
        @endif
        @if(!empty($report['project']['waste_operations_description']['text']))
          <div class="card-body">{{ trim($report['project']['waste_operations_description']['text']) }}</div>
        @endif
      </div>
    @endif

    <!-- === ПОДПИСЬ === -->
    <div class="signatures">
      <div class="col">
        <div class="muted">Подпись:</div>
        <div class="sign-line">{{ $report['project']['expert_name'] ?? '—' }}</div>
      </div>
      <div class="col">
        <div class="muted">Дата:</div>
        <div class="sign-line">{{ date('d.m.Y') }}</div>
      </div>
    </div>

  </div>
</body>
</html>
