<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Смета {{ $report['project']['number'] ?? 'Без номера' }}</title>

  <style>
   /* =========================
       DOMPDF • PREMIUM STRICT A4
       ========================= */

@page {
  size: A4;
  margin: 15mm 10mm 20mm 22mm; /* top right bottom left */
}

html, body { margin: 0; padding: 0; }
* { box-sizing: border-box; }

body {
  font-family: "DejaVu Sans", sans-serif;
  font-size: 10.6pt;
  line-height: 1.35;
  color: #111;
  background: #fff;
  padding: 10mm 5mm 10mm 25mm;  /* top right bottom left */
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

/* ---------------- Header ---------------- */
.header {
  margin: 0 0 5mm 0;
  padding: 0 0 3.5mm 0;
  border-bottom: 2px solid #111;
  page-break-after: avoid;
}

/* Dompdf-safe two columns */
.header-top {
  display: table;
  width: 100%;
  table-layout: fixed;
  margin-bottom: 2.5mm;
}
.header-top .col { display: table-cell; vertical-align: top; }
.header-top .col.right { text-align: right; width: 40%; }

.appendix-ref {
  font-size: 9pt;
  color: #606060;
  line-height: 1.35;
}

.header h1 {
  font-size: 15pt;
  font-weight: 800;
  text-align: center;
  text-transform: uppercase;
  letter-spacing: .4px;
  margin: 1.5mm 0 0.8mm 0;
}

.header h2 {
  font-size: 10pt;
  font-weight: 400;
  text-align: center;
  color: #222;
  margin: 0 0 2.5mm 0;
}

/* Header info: 2 columns via table layout */
.header-grid {
  display: table;
  width: 100%;
  table-layout: fixed;
  margin-top: 1mm;
  font-size: 9.2pt;
  line-height: 1.25;
}
.header-grid .col { display: table-cell; vertical-align: top; width: 50%; }
.header-grid .col:first-child { padding-right: 6mm; }
.header-grid .col:last-child { padding-left: 6mm; }

.info-row {
  display: table;
  width: 100%;
  table-layout: fixed;
  padding: 1.1mm 0;
  border-bottom: 1px dotted #e1e1e1;
}
.info-row:last-child { border-bottom: none; }

.info-label, .info-value { display: table-cell; vertical-align: top; }
.info-label { width: 45%; font-weight: 700; color:#222; }
.info-value { width: 55%; text-align: left; color:#111; }

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
  margin: 0 0 3.5mm 0;
  font-size: 9.2pt;
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
}

tbody tr:nth-child(even) { background: #fbfbfb; }

tfoot tr { background:#efefef; font-weight:800; }
tfoot td {
  border-top: 1.2px solid #9a9a9a;
  border-bottom: 1.2px solid #9a9a9a;
  padding-top: 2mm;
  padding-bottom: 2mm;
}

/* Важно для PDF */
tr, td, th { page-break-inside: avoid; }

th { word-break: normal; overflow-wrap: normal; }
td { word-break: break-word; overflow-wrap: anywhere; }


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
  page-break-inside: avoid;
}

.card-title {
  font-weight: 800;
  font-size: 9pt;
  text-transform: uppercase;
  letter-spacing: .35px;
  margin-bottom: 2.5mm;
}

.card-body {
  font-size: 8.4pt;
  line-height: 1.15;
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
  page-break-inside: avoid;
}
.detail-block div { margin: 0.6mm 0; }
.detail-block strong { font-weight: 800; }

/* ---------------- Totals ---------------- */
.totals-section {
  margin-top: 7mm;
  border: 1px solid #d7d7d7;
  border-left: 4px solid #111;
  background: #fff;
  padding: 5mm 5mm;
  page-break-inside: avoid;
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

/* 2 columns */
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
  padding: 2.3mm 0;
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
  margin-top: 4.5mm;
  padding-top: 4.5mm;
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
  margin-top: 10mm;
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

.page-break { page-break-after: always; margin-top: 10mm; }

a { color: inherit; text-decoration: underline; }
  </style>
</head>

<body>
  <div class="container">

    <!-- === 1. ШАПКА КАК ПРИЛОЖЕНИЕ К ЭКСПЕРТИЗЕ === -->
    <div class="header">
      <div class="header-top">
        <div class="col">
          <div class="appendix-ref">
            Приложение № 1<br />
            к экспертному заключению
          </div>
        </div>
        <div class="col right">
          <div class="appendix-ref">
            <span class="muted small">Дата расчёта:</span><br />
            <span class="bold">{{ date('d.m.Y') }}</span>
          </div>
        </div>
      </div>

      <h1>Расчёт стоимости материалов и работ</h1>
      
      <div class="header-grid">
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
          <div class="info-row">
            <span class="info-label">Дата/время:</span>
            <span class="info-value">{{ date('d.m.Y H:i') }}</span>
          </div>
        </div>
      </div>
    </div>

    <!-- === 1A. СВОДНАЯ ТАБЛИЦА ИТОГОВ (PREMIUM) === -->
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
            <td class="label">Фурнитура</td>
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
              <td style="max-width: 200px; word-wrap: break-word;">
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

    <!-- === 3. ПЛИТНЫЕ МАТЕРИАЛЫ === -->
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
            <th class="text-right" style="width: 14%;">Цена/лист в руб.</th>
            <th class="text-right" style="width: 16%;">Итого в руб.</th>
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
              <td style="max-width: 200px; word-wrap: break-word;">{{ $plateName }}</td>
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
                foreach ($report['plates'] as $plate) {
                  $platesTotal += ($plate['total_cost'] ?? 0);
                }
              @endphp
              {{ number_format($platesTotal, 2, ',', ' ') }}
            </td>
          </tr>
        </tfoot>
      </table>
    @endif

    <!-- === 4. КРОМОЧНЫЕ МАТЕРИАЛЫ === -->
    @if(!empty($report['edges']))
      <div class="section-title">Расчёт кромочного материала</div>
      <table>
        <thead>
          <tr>
            <th style="width: 30%;">Материал</th>
            <th class="text-right" style="width: 12%;">Длина м.п.</th>
            <th class="text-right" style="width: 8%;">Отходы</th>
            <th class="text-right" style="width: 12%;">С отходами</th>
            <th class="text-right" style="width: 14%;">Цена/м в руб.</th>
            <th class="text-right" style="width: 16%;">Итого в руб.</th>
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
              <td style="max-width: 200px; word-wrap: break-word;">{{ $edgeName }}</td>
              <td class="text-right mono">{{ number_format($edge['length_linear'] ?? 0, 2) }} м.п.</td>
              <td class="text-right mono">{{ $waste }}%</td>
              <td class="text-right mono">{{ number_format($edge['length_with_waste'] ?? 0, 2) }} м.п.</td>
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
                foreach ($report['edges'] as $edge) {
                  $edgesTotal += ($edge['total_cost'] ?? 0);
                }
              @endphp
              {{ number_format($edgesTotal, 2, ',', ' ') }}
            </td>
          </tr>
        </tfoot>
      </table>
    @endif

    <!-- === 4.5 МОНТАЖНО-СБОРОЧНЫЕ РАБОТЫ === -->
    @if(!empty($report['labor_works']))
      <div class="section-title">Нормируемые работы</div>
      <table>
        <thead>
          <tr>
            <th style="width: 6%;">№</th>
            <th style="width: 33%;">Наименование</th>
            <th style="width: 18%;">Основание</th>
            <th class="text-right" style="width: 10%;">Норма, ч</th>
            <th class="text-right" style="width: 16%;">Нормо-час, ₽/ч</th>
            <th class="text-right" style="width: 17%;">Сумма, ₽</th>
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
              <td style="max-width: 220px; word-wrap: break-word;">{{ $work['title'] ?? '—' }}</td>
              <td style="max-width: 140px; word-wrap: break-word;">{{ $work['basis'] ?? '—' }}</td>
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
                foreach ($report['labor_works'] as $work) {
                  $laborHoursTotal += ($work['hours'] ?? 0);
                }
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
                foreach ($report['labor_works'] as $work) {
                  $laborWorksTotal += ($work['cost'] ?? 0);
                }
              @endphp
              {{ number_format($laborWorksTotal, 2, ',', ' ') }}
            </td>
          </tr>
        </tfoot>
      </table>
    @endif

    <!-- === 4.6 ДЕТАЛИЗАЦИЯ НОРМИРУЕМЫХ РАБОТ === -->
    @if(!empty($report['labor_works']))
      @foreach($report['labor_works'] as $work)
        @if(!empty($work['steps']))
          <div class="section-subtitle" style="margin-top: 6mm; margin-bottom: 2mm;">
            Детализация: {{ $work['title'] }}
          </div>
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
                  <td style="max-width: 280px; word-wrap: break-word;">{{ $step['title'] ?? '—' }}</td>
                  <td style="max-width: 180px; word-wrap: break-word;">{{ $step['input_data'] ?? '—' }}</td>
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
                    foreach ($work['steps'] as $step) {
                      $stepsTotal += ($step['hours'] ?? 0);
                    }
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
            <th class="text-right" style="width: 14%;">Цена/ед в руб.</th>
            <th class="text-right" style="width: 16%;">Сумма в руб.</th>
          </tr>
        </thead>
        <tbody>
          @foreach($report['operations'] as $op)
            @php
              $opName = str_replace('не добавлено', 'отсутствует (не заявлено в исходных данных)', $op['name'] ?? 'Операция не указана');
              $opUnit = str_replace('не добавлено', 'отсутствует (не заявлено в исходных данных)', $op['unit'] ?? 'ед.');
            @endphp
            <tr>
              <td style="max-width: 220px; word-wrap: break-word;">{{ $opName }}</td>
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
                foreach ($report['operations'] as $op) {
                  $operationsTotal += ($op['total_cost'] ?? 0);
                }
              @endphp
              {{ number_format($operationsTotal, 2, ',', ' ') }}
            </td>
          </tr>
        </tfoot>
      </table>
    @endif

    <!-- === 3.5 ОБОСНОВАНИЕ ПЛИТ === -->
    @if(!empty($report['plates']))
      <div class="section-title">Детализация плитных материалов</div>
      @foreach($report['plates'] as $plate)
        @php $plateName = $plate['name'] ?? 'Материал не указан'; @endphp

        <div class="card">
          <div class="card-title">{{ $plateName }}</div>

          @if(!empty($plate['position_details']))
            <table style="margin-bottom: 2mm;">
              <thead>
                <tr>
                  <th style="width: 35%;">Название детали</th>
                  <th class="text-right" style="width: 12%;">Кол-во</th>
                  <th style="width: 20%;">Размеры мм</th>
                  <th class="text-right" style="width: 15%;">Площадь м²</th>
                  <th class="text-right" style="width: 18%;">Сумма площадей</th>
                </tr>
              </thead>
              <tbody>
                @foreach($plate['position_details'] as $detail)
                  @php
                    $detailArea = $detail['area'] ?? 0;
                    $qty = $detail['quantity'] ?? 1;
                    $detailTotalArea = $detailArea * $qty;

                    $detailName = $detail['detail_type'] ?? 'Деталь (без наименования)';
                    $detailName = str_replace('не добавлено', 'отсутствует (не заявлено в исходных данных)', $detailName);
                  @endphp
                  <tr>
                    <td>{{ $detailName }}</td>
                    <td class="text-right mono">{{ $qty }}</td>
                    <td class="mono">{{ intval($detail['width'] ?? 0) }}×{{ intval($detail['length'] ?? 0) }}</td>
                    <td class="text-right mono">{{ number_format($detailArea, 4) }}</td>
                    <td class="text-right mono">{{ number_format($detailTotalArea, 4) }}</td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          @endif

          @php
            $wasteCoeff = ($plate['waste_coeff'] ?? 1);
            $wastePct = round((($wasteCoeff - 1) * 100));
          @endphp

          <div class="detail-block">
            <div><strong>Расчёт:</strong></div>
            <div>Суммарная площадь деталей: <span class="mono">{{ number_format($plate['area_details'] ?? 0, 4) }}</span> м²</div>
            <div>Применён коэффициент отходов: <span class="mono">{{ $wastePct }}%</span> (<span class="mono">{{ number_format($wasteCoeff, 2) }}</span>)</div>
            <div>
              Площадь с учётом отходов:
              <span class="mono">{{ number_format($plate['area_details'] ?? 0, 4) }}</span> ×
              <span class="mono">{{ number_format($wasteCoeff, 2) }}</span> =
              <span class="mono">{{ number_format($plate['area_with_waste'] ?? 0, 4) }}</span> м²
            </div>

            @if($report['project']['use_area_calc_mode'] ?? false)
              <div style="margin-top: 1mm; padding-top: 1mm; border-top: 1px solid #ddd;">
                <strong>Режим: По площади</strong>
                <div>Цена за м²: <span class="mono">{{ number_format($plate['price_per_m2'] ?? 0, 2, ',', ' ') }}</span></div>
                <div>
                  Итого:
                  <span class="mono">{{ number_format($plate['area_with_waste'] ?? 0, 4) }}</span> ×
                  <span class="mono">{{ number_format($plate['price_per_m2'] ?? 0, 2, ',', ' ') }}</span> =
                  <strong class="mono">{{ number_format($plate['total_cost'] ?? 0, 2, ',', ' ') }}</strong>
                </div>
              </div>
            @else
              <div style="margin-top: 1mm; padding-top: 1mm; border-top: 1px solid #ddd;">
                <strong>Режим: По листам</strong>
                <div>Площадь листа: <span class="mono">{{ number_format($plate['sheet_area'] ?? 0, 4) }}</span> м²</div>
                <div>
                  Кол-во листов:
                  ⌈<span class="mono">{{ number_format($plate['area_with_waste'] ?? 0, 4) }}</span> /
                  <span class="mono">{{ number_format($plate['sheet_area'] ?? 0, 4) }}</span>⌉ =
                  <span class="bold mono">{{ $plate['sheets_count'] ?? 0 }}</span>
                </div>
                <div>Цена за лист: <span class="mono">{{ number_format($plate['price_per_sheet'] ?? 0, 2, ',', ' ') }}</span></div>
                <div>
                  Итого:
                  <span class="mono">{{ $plate['sheets_count'] ?? 0 }}</span> ×
                  <span class="mono">{{ number_format($plate['price_per_sheet'] ?? 0, 2, ',', ' ') }}</span> =
                  <strong class="mono">{{ number_format($plate['total_cost'] ?? 0, 2, ',', ' ') }}</strong>
                </div>
              </div>
            @endif
          </div>
        </div>
      @endforeach
    @endif

    <!-- === 4.5 ОБОСНОВАНИЕ КРОМОК === -->
    @if(!empty($report['edges']))
      <div class="section-title">Детализация кромочного материала</div>
      @foreach($report['edges'] as $edge)
        @php $edgeName = $edge['name'] ?? 'Материал не указан'; @endphp

        <div class="card">
          <div class="card-title">{{ $edgeName }}</div>

          @if(!empty($edge['position_details']))
            <table style="margin-bottom: 2mm;">
              <thead>
                <tr>
                  <th style="width: 22%;">Название детали</th>
                  <th style="width: 12%;">Размеры мм</th>
                  <th class="text-center" style="width: 11%; white-space: nowrap;">Схема</th>
                  <th class="text-right" style="width: 9%;">Кол-во</th>
                  <th class="text-right" style="width: 13%;">Периметр м</th>
                  <th class="text-right" style="width: 13%;">Длина м</th>
                  <th class="text-right" style="width: 20%;">Итого м</th>
                </tr>
              </thead>
              <tbody>
                @foreach($edge['position_details'] as $detail)
                  @php
                    $lengthTotal = $detail['length_total'] ?? 0;
                    $detailName = $detail['detail_type'] ?? 'Деталь (без наименования)';
                    $detailName = str_replace('не добавлено', 'отсутствует (не заявлено в исходных данных)', $detailName);

                    $schemeMap = ['O' => 'Вкруг', '=' => 'Параллельно длине', '||' => 'Параллельно ширине', 'L' => 'Г-образно', 'П' => 'П-образно'];
                    $scheme = $schemeMap[$detail['scheme'] ?? ''] ?? ($detail['scheme'] ?? '');
                  @endphp
                  <tr>
                    <td>{{ $detailName }}</td>
                    <td class="mono">{{ (int)($detail['width'] ?? 0) }}×{{ (int)($detail['length'] ?? 0) }}</td>
                    <td class="text-center nowrap">{{ $scheme }}</td>
                    <td class="text-right mono">{{ $detail['quantity'] ?? 1 }}</td>
                    <td class="text-right mono">{{ number_format($detail['perimeter'] ?? 0, 3) }}</td>
                    <td class="text-right mono">{{ number_format(($detail['perimeter'] ?? 0) * ($detail['quantity'] ?? 1), 3) }}</td>
                    <td class="text-right bold mono">{{ number_format($lengthTotal, 3) }}</td>
                  </tr>
                @endforeach
                <tr style="background-color: #efefef; font-weight: bold; border-top: 1.2px solid #9a9a9a;">
                  <td colspan="6" class="text-right" style="padding-right: 2.4mm;">Итого длина кромки:</td>
                  <td class="text-right mono">{{ number_format($edge['length_linear'] ?? 0, 2) }} м.п.</td>
                </tr>
              </tbody>
            </table>
          @endif

          @php
            $wasteCoeff = ($edge['waste_coeff'] ?? 1);
            $wastePct = round((($wasteCoeff - 1) * 100));
          @endphp

          <div class="detail-block">
            <div><strong>Расчёт:</strong></div>
            <div>Суммарная длина кромки: <span class="mono">{{ number_format($edge['length_linear'] ?? 0, 3) }}</span> м</div>
            <div>Применён коэффициент отходов: <span class="mono">{{ $wastePct }}%</span> (<span class="mono">{{ number_format($wasteCoeff, 2) }}</span>)</div>
            <div>
              Длина с учётом отходов:
              <span class="mono">{{ number_format($edge['length_linear'] ?? 0, 3) }}</span> ×
              <span class="mono">{{ number_format($wasteCoeff, 2) }}</span> =
              <span class="mono">{{ number_format($edge['length_with_waste'] ?? 0, 3) }}</span> м
            </div>
            <div>Цена за метр: <span class="mono">{{ number_format($edge['price_per_unit'] ?? 0, 2, ',', ' ') }}</span> руб./м</div>
            <div>
              Итого:
              <span class="mono">{{ number_format($edge['length_with_waste'] ?? 0, 3) }}</span> ×
              <span class="mono">{{ number_format($edge['price_per_unit'] ?? 0, 2, ',', ' ') }}</span> =
              <strong class="mono">{{ number_format($edge['total_cost'] ?? 0, 2, ',', ' ') }}</strong>
            </div>
          </div>
        </div>
      @endforeach
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
            <th class="text-right" style="width: 12%;">Цена</th>
            <th class="text-right" style="width: 16%;">Сумма в руб.</th>
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
              <td style="max-width: 180px; word-wrap: break-word;">{{ $fittingName }}</td>
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
                foreach ($report['fittings'] as $fitting) {
                  $fittingsTotal += ($fitting['total_cost'] ?? 0);
                }
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
            <th class="text-right" style="width: 15%;">Сумма</th>
          </tr>
        </thead>
        <tbody>
          @foreach($report['expenses'] as $expense)
            @php
              $expenseType = str_replace('не добавлено', 'отсутствует (не заявлено в исходных данных)', $expense['type'] ?? 'Расход не указан');
              $expenseDesc = str_replace('не добавлено', '—', $expense['description'] ?? '—');
            @endphp
            <tr>
              <td style="max-width: 250px; word-wrap: break-word;">{{ $expenseType }}</td>
              <td style="max-width: 250px; word-wrap: break-word; font-size: 9pt; color: #606060;">{{ $expenseDesc }}</td>
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
                foreach ($report['expenses'] as $expense) {
                  $expensesTotal += ($expense['cost'] ?? 0);
                }
              @endphp
              {{ number_format($expensesTotal, 2, ',', ' ') }}
            </td>
          </tr>
        </tfoot>
      </table>
    @endif

    <!-- === 8. ИТОГИ (PREMIUM) === -->
    <div class="totals-section">
      <div class="totals-head">
        <div class="title">Итоговая стоимость</div>
        <div class="meta">Валюта: руб.</div>
      </div>

      <div class="totals-grid">
        <div class="col">
          <div class="total-row">
            <span>Материалы (плиты + кромки):</span>
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
    </div>

    <!-- === ОБОСНОВАНИЕ СТОИМОСТИ НОРМ-ЧАСА ПО НОРМИРУЕМЫМ РАБОТАМ === -->
    @if(!empty($report['profile_rate_justifications']) && is_array($report['profile_rate_justifications']))
      @foreach($report['profile_rate_justifications'] as $justification)
        <div class="card">
          <div class="card-title">Обоснование стоимости норм-часа по нормируемым работам</div>

          <div style="margin-top: 4mm; padding: 0;">
            <div style="margin-bottom: 2mm;">
              <strong>ПРОФИЛЬ: {{ strtoupper($justification['profile_name']) }}</strong>
            </div>

            <div style="margin-top: 3mm; margin-bottom: 3mm; font-size: 9.8pt; line-height: 1.55;">
              @if(!empty($justification['region']))
                <div>Регион: <strong>{{ $justification['region'] }}</strong></div>
              @endif
              @if(!empty($justification['date']))
                <div>Дата фиксации ставки: <strong>{{ $justification['date'] }}</strong></div>
              @endif

              @if(!empty($justification['sources_stats']))
                <div style="margin-top: 3mm; padding: 2.5mm 3mm; background: #fff; border-left: 2.5mm solid #adadad; border: 1px solid #e1e1e1;">
                  <strong>Статистика по ставкам, участвующим в расчёте:</strong>
                  <div style="margin-top: 1.8mm; margin-left: 3mm; font-size: 9pt;">
                    <div>Использовано источников: <strong>{{ $justification['sources_count_used'] }}</strong></div>
                    <div>Метод агрегации: <strong>{{ ucfirst($justification['calculation_method']) }}</strong></div>
                    <div style="margin-top: 1mm; font-size: 10pt;">Итоговая ставка: <strong>{{ number_format($justification['rate'], 2, ',', ' ') }} ₽/ч</strong></div>
                  </div>
                </div>
              @endif
            </div>

            @if(!empty($justification['sources_stats']))
              @if(strtolower($justification['calculation_method']) === 'медиана')
                <div style="margin-top: 3mm; padding: 2.5mm 3mm; font-size: 9pt; line-height: 1.1; color: #262626; background: #f6f6f6; border: 1px solid #e1e1e1;">
                  <strong>Методология:</strong> Для определения стоимости нормо-часа применён метод медианы, обеспечивающий устойчивость результата к статистическим выбросам и отражающий типичное рыночное значение ставки.
                </div>

                <div style="margin-top: 2mm; padding: 2.5mm 3mm; font-size: 9pt; line-height: 1.45; color: #262626; background: #f9f9f9; border: 1px solid #e1e1e1;">
                  <strong>Расчёт ставки:</strong>
                  @php
                    $rates = array_column($justification['sources_stats'], 'rate');
                    sort($rates);
                    $count = count($rates);
                    $ratesStr = implode(', ', array_map(function($r) { return number_format($r, 2, ',', ' '); }, $rates));
                  @endphp
                  <div style="margin-top: 1mm;">
                    Отсортированные ставки: <strong>{{ $ratesStr }} ₽/ч</strong>
                  </div>
                  @if($count % 2 == 0)
                    @php
                      $mid1 = $rates[$count / 2 - 1];
                      $mid2 = $rates[$count / 2];
                      $median = ($mid1 + $mid2) / 2;
                    @endphp
                    <div style="margin-top: 1mm;">
                      Медиана (среднее из двух центральных значений): <strong>({{ number_format($mid1, 2, ',', ' ') }} + {{ number_format($mid2, 2, ',', ' ') }}) / 2 = {{ number_format($median, 2, ',', ' ') }} ₽/ч</strong>
                    </div>
                  @else
                    @php
                      $median = $rates[floor($count / 2)];
                    @endphp
                    <div style="margin-top: 1mm;">
                      Медиана (центральное значение): <strong>{{ number_format($median, 2, ',', ' ') }} ₽/ч</strong>
                    </div>
                  @endif
                  <div style="margin-top: 1mm; font-size: 10pt;">
                    <strong>Итоговая ставка: {{ number_format($justification['rate'], 2, ',', ' ') }} ₽/ч</strong>
                  </div>
                </div>
              @else
                {{-- Для других методов расчёта (среднее арифметическое и т.д.) --}}
                <div style="margin-top: 3mm; padding: 2.5mm 3mm; font-size: 9pt; line-height: 1.45; color: #262626; background: #f9f9f9; border: 1px solid #e1e1e1;">
                  <strong>Расчёт ставки:</strong>
                  @php
                    $rates = array_column($justification['sources_stats'], 'rate');
                    $ratesStr = implode(', ', array_map(function($r) { return number_format($r, 2, ',', ' '); }, $rates));
                  @endphp
                  <div style="margin-top: 1mm;">
                    Ставки: <strong>{{ $ratesStr }} ₽/ч</strong>
                  </div>
                  @if(strtolower($justification['calculation_method']) === 'среднее')
                    @php
                      $sum = array_sum($rates);
                      $count = count($rates);
                      $average = $sum / $count;
                    @endphp
                    <div style="margin-top: 1mm;">
                      Среднее арифметическое: <strong>({{ $ratesStr }}) / {{ $count }} = {{ number_format($average, 2, ',', ' ') }} ₽/ч</strong>
                    </div>
                  @endif
                  <div style="margin-top: 1mm; font-size: 10pt;">
                    <strong>Итоговая ставка: {{ number_format($justification['rate'], 2, ',', ' ') }} ₽/ч</strong>
                  </div>
                </div>
              @endif

              <div style="margin-top: 3mm;">
                <strong>Источники данных:</strong>
              </div>

              <table style="width: 100%; margin-top: 1mm; font-size: 9pt;">
                <thead>
                  <tr>
                    <th style="width: 6%; text-align: center;">№</th>
                    <th style="width: 34%;">Источник</th>
                    <th style="width: 20%;">Профиль</th>
                    <th style="width: 20%; text-align: right;">Ставка, ₽/ч</th>
                    <th style="width: 20%;">Дата</th>
                  </tr>
                </thead>
                <tbody>
                  @php $sourceIndex = 1; @endphp
                  @foreach($justification['sources_stats'] as $source)
                    <tr>
                      <td class="text-center">{{ $sourceIndex }}</td>
                      <td>{{ $source['name'] ?? '—' }}</td>
                      <td>{{ $justification['profile_name'] }}</td>
                      <td class="text-right mono">{{ number_format($source['rate'], 2, ',', ' ') }}</td>
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
            @endif

            @if(!empty($justification['works']))
              <div style="margin-top: 3mm; font-size: 9.8pt;">
                <strong>Работы, рассчитанные по данной ставке:</strong>
              </div>

              <table style="width: 100%; margin-top: 1mm; font-size: 9pt;">
                <thead>
                  <tr>
                    <th style="width: 55%;">Наименование работы</th>
                    <th style="width: 15%; text-align: right;">Часы</th>
                    <th style="width: 15%; text-align: right;">Ставка, ₽/ч</th>
                    <th style="width: 15%; text-align: right;">Сумма, ₽</th>
                  </tr>
                </thead>
                <tbody>
                  @foreach($justification['works'] as $work)
                    <tr>
                      <td style="max-width: 200px; word-wrap: break-word;">{{ $work['title'] }}</td>
                      <td class="text-right mono">{{ number_format($work['hours'], 2, ',', ' ') }}</td>
                      <td class="text-right mono">{{ number_format($work['rate'] ?? 0, 2, ',', ' ') }}</td>
                      <td class="text-right mono bold">{{ number_format($work['cost'] ?? 0, 2, ',', ' ') }}</td>
                    </tr>
                  @endforeach
                  <tr style="background: #efefef;">
                    <td colspan="3" class="text-right" style="font-weight: 800;">Итого:</td>
                    <td class="text-right mono bold">{{ number_format($justification['total_cost'], 2, ',', ' ') }}</td>
                  </tr>
                </tbody>
              </table>
            @endif
          </div>
        </div>
      @endforeach
    @endif

    <!-- === СПРАВОЧНЫЕ БЛОКИ И ПОДПИСИ === -->
    @if(!empty($report['project']['text_blocks']) && is_array($report['project']['text_blocks']))
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
        <div class="muted">Дата и время:</div>
        <div class="sign-line">{{ date('d.m.Y H:i') }}</div>
      </div>
    </div>

  </div>
</body>
</html>