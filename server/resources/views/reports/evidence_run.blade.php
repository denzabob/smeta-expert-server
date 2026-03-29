<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8" />
  <title>Отчёт по доказательствам</title>

  <style>
    @page {
      size: A4;
      margin: 5mm 10mm 10mm 25mm;
    }

    body {
      font-family: "DejaVu Sans", sans-serif;
      font-size: 8.8pt;
      line-height: 1.24;
      color: #111;
      background: #fff;
      margin: 0;
      padding: 0;
    }

    * { box-sizing: border-box; }
    a { color: inherit; text-decoration: underline; }
    .muted { color: #606060; }
    .bold  { font-weight: 700; }
    .mono  { font-family: "DejaVu Sans Mono","Courier New",monospace; }

    .container {
      width: 100%;
      padding-top: 3mm;
    }

    /* ── Cover / header ── */
    .cover {
      margin: 0 0 5mm 0;
      padding: 0 0 4mm 0;
      border-bottom: 2px solid #444;
      page-break-after: avoid;
      page-break-inside: avoid;
    }

    .cover-title {
      margin: 0 0 3mm 0;
      font-size: 16px;
      line-height: 1.15;
      font-weight: 700;
      text-align: center;
      color: #111;
    }

    .cover-subtitle {
      margin: 0 0 2mm 0;
      font-size: 10pt;
      text-align: center;
      color: #555;
    }

    .cover-meta {
      width: 100%;
      border-collapse: collapse;
      table-layout: fixed;
      font-size: 8.4pt;
      color: #444;
      margin-top: 2mm;
    }

    .cover-meta td {
      padding: 0.5mm 0;
      vertical-align: top;
    }

    .cover-meta .label { font-weight: 700; width: 25%; }
    .cover-meta .value { width: 75%; }

    /* ── Section headings ── */
    .section-title {
      font-size: 11pt;
      font-weight: 800;
      margin: 5mm 0 2.6mm 0;
      padding-left: 3mm;
      border-left: 2mm solid #4a4a4a;
      page-break-after: avoid;
    }

    .section-note {
      font-size: 8.2pt;
      color: #666;
      line-height: 1.2;
      margin: 0 0 3mm 0;
      page-break-after: avoid;
    }

    /* ── Summary box ── */
    .summary-box {
      border: 1px solid #d7d7d7;
      background: #f9f9f9;
      padding: 2.5mm 3mm;
      margin: 0 0 4mm 0;
      page-break-inside: avoid;
    }

    .summary-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 8.2pt;
    }

    .summary-table td {
      padding: 0.5mm 2mm 0.5mm 0;
      vertical-align: top;
      line-height: 1.2;
    }

    .summary-table .lbl { font-weight: 700; color: #333; width: 30%; }

    /* ── Item cards ── */
    .item {
      margin: 0 0 3mm 0;
      border: 1px solid #d7d7d7;
      border-left: 3px solid #9a9a9a;
      background: #fff;
      page-break-inside: avoid;
      break-inside: avoid;
    }

    .item-resolved { border-left-color: #4caf50; }
    .item-skipped  { border-left-color: #ff9800; }
    .item-failed   { border-left-color: #f44336; }

    .item-head {
      padding: 2.2mm 3mm 1.8mm 3mm;
      background: #fafafa;
      border-bottom: 1px solid #e4e4e4;
    }

    .item-title {
      margin: 0;
      font-size: 8.9pt;
      line-height: 1.15;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: 0.2px;
      color: #111;
      word-break: break-word;
    }

    .item-body {
      padding: 2.2mm 3mm 2.5mm 3mm;
    }

    .meta-table {
      width: 100%;
      border-collapse: collapse;
      table-layout: fixed;
      margin: 0 0 1.8mm 0;
      font-size: 7.9pt;
    }

    .meta-table td {
      border: none;
      padding: 0 0 0.8mm 0;
      vertical-align: top;
      line-height: 1.14;
    }

    .meta-label {
      width: 18%;
      font-weight: 700;
      color: #333;
      padding-right: 2mm;
      white-space: nowrap;
    }

    .meta-value {
      width: 82%;
      color: #111;
      word-break: break-word;
      overflow-wrap: anywhere;
    }

    /* ── Badges ── */
    .badge {
      display: inline-block;
      padding: 0.3mm 1.5mm;
      border: 1px solid #cfcfcf;
      background: #f0f0f0;
      font-size: 7pt;
      line-height: 1.1;
      white-space: nowrap;
    }

    .badge-ok       { border-color: #a5d6a7; background: #e8f5e9; color: #2e7d32; }
    .badge-warning  { border-color: #ffe0b2; background: #fff8e1; color: #e65100; }
    .badge-error    { border-color: #ef9a9a; background: #ffebee; color: #c62828; }
    .badge-type     { border-color: #c5d5c5; background: #eef5ee; color: #446644; }

    .price-badge {
      display: inline-block;
      padding: 0.7mm 2mm;
      border: 1px solid #cfcfcf;
      background: #f5f5f5;
      font-weight: 700;
      font-size: 7.8pt;
      line-height: 1.1;
      white-space: nowrap;
    }

    .score-indicator {
      display: inline-block;
      padding: 0.3mm 1.5mm;
      border: 1px solid #cfcfcf;
      background: #f5f5f5;
      font-size: 7pt;
      line-height: 1.1;
      white-space: nowrap;
    }

    .compact-source {
      font-size: 7.6pt;
      line-height: 1.12;
    }

    /* ── Screenshot / asset ── */
    .shot-wrap {
      border: 1px solid #dcdcdc;
      background: #fcfcfc;
      padding: 1.2mm;
      text-align: center;
    }

    .shot-wrap img {
      display: block;
      max-width: 100%;
      max-height: 88mm;
      width: auto;
      height: auto;
      margin: 0 auto;
    }

    .shot-empty {
      min-height: 12mm;
      padding: 4mm 0;
      text-align: center;
      color: #7a7a7a;
      font-size: 8pt;
      background: #f8f8f8;
    }

    .asset-doc {
      padding: 2mm 3mm;
      border: 1px solid #e0e0e0;
      background: #fafafa;
      font-size: 7.8pt;
      margin-top: 1mm;
    }

    /* ── Exceptions table ── */
    .exc-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 7.9pt;
      margin: 0 0 3mm 0;
    }

    .exc-table th, .exc-table td {
      border: 1px solid #ddd;
      padding: 1mm 2mm;
      text-align: left;
      vertical-align: top;
    }

    .exc-table th {
      background: #f5f5f5;
      font-weight: 700;
      font-size: 7.6pt;
    }

    /* ── Appendix table ── */
    .app-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 7.2pt;
      margin: 0 0 3mm 0;
    }

    .app-table th, .app-table td {
      border: 1px solid #ddd;
      padding: 0.7mm 1.5mm;
      text-align: left;
      vertical-align: top;
      word-break: break-all;
    }

    .app-table th {
      background: #f5f5f5;
      font-weight: 700;
    }

    .empty {
      border: 1px dashed #cfcfcf;
      padding: 5mm;
      text-align: center;
      color: #666;
      font-size: 9pt;
      margin-top: 4mm;
    }
  </style>
</head>
<body>
  <div class="container">

    {{-- ═══════════════════════════════════════════ A. COVER ═══════════════════════════════════════════ --}}
    <div class="cover">
      <div class="cover-title">Отчёт по доказательствам ценообразования</div>
      <div class="cover-subtitle">
        Проект {{ $cover['project_number'] ?? '—' }}
        @if(!empty($cover['project_name']))
          — {{ $cover['project_name'] }}
        @endif
      </div>
      <table class="cover-meta">
        <tr>
          <td class="label">ID прогона:</td>
          <td class="value mono">{{ $cover['run_uuid'] ?? '—' }}</td>
        </tr>
        <tr>
          <td class="label">Финализирован:</td>
          <td class="value">{{ $cover['finalized_at'] ?? '—' }}</td>
        </tr>
        <tr>
          <td class="label">Сформирован:</td>
          <td class="value">{{ $cover['generated_at'] ?? '—' }}</td>
        </tr>
        <tr>
          <td class="label">Позиций:</td>
          <td class="value">{{ $cover['total_items'] ?? 0 }}</td>
        </tr>
        <tr>
          <td class="label">Версия:</td>
          <td class="value mono">{{ $cover['version'] ?? '—' }}</td>
        </tr>
      </table>
    </div>

    {{-- ═══════════════════════════════════════════ B. SUMMARY ═══════════════════════════════════════════ --}}
    <div class="section-title">Сводка покрытия</div>
    <div class="section-note">Статистика по статусам, компонентам и методам сбора доказательств.</div>

    <div class="summary-box">
      <table class="summary-table">
        <tr>
          <td class="lbl">Всего позиций:</td>
          <td>{{ $summary['total_items'] ?? 0 }}</td>
        </tr>
        <tr>
          <td class="lbl">Подтверждено:</td>
          <td><span class="badge badge-ok">{{ $summary['resolved'] ?? 0 }}</span></td>
        </tr>
        <tr>
          <td class="lbl">Пропущено:</td>
          <td><span class="badge badge-warning">{{ $summary['skipped'] ?? 0 }}</span></td>
        </tr>
        <tr>
          <td class="lbl">Ошибки:</td>
          <td><span class="badge badge-error">{{ $summary['failed'] ?? 0 }}</span></td>
        </tr>

        @if(!empty($summary['by_component']))
          @php
            $componentLabels = [
              'plate' => 'Плита', 'edge' => 'Кромка', 'facade' => 'Фасад',
              'fitting' => 'Фурнитура', 'operation' => 'Операция',
              'labor_work' => 'Работа', 'expense' => 'Расход',
            ];
          @endphp
          <tr>
            <td class="lbl">По компонентам:</td>
            <td>
              @foreach($summary['by_component'] as $comp => $cnt)
                <span class="badge badge-type">{{ $componentLabels[$comp] ?? $comp }}: {{ $cnt }}</span>{{ !$loop->last ? ' ' : '' }}
              @endforeach
            </td>
          </tr>
        @endif

        @if(!empty($summary['by_capture_method']))
          @php
            $captureLabels = [
              'chrome_extension' => 'Chrome расш.',
              'manual' => 'Вручную',
              'auto' => 'Автоматически',
              'api' => 'API',
            ];
          @endphp
          <tr>
            <td class="lbl">Метод сбора:</td>
            <td>
              @foreach($summary['by_capture_method'] as $method => $cnt)
                {{ $captureLabels[$method] ?? $method }}: {{ $cnt }}@if(!$loop->last), @endif
              @endforeach
            </td>
          </tr>
        @endif

        @if(!empty($summary['by_source_type']))
          @php
            $sourceLabels = [
              'chrome_capture' => 'Chrome захват',
              'supplier_site' => 'Сайт поставщика',
              'price_list' => 'Прайс-лист',
              'manual_entry' => 'Ручной ввод',
            ];
          @endphp
          <tr>
            <td class="lbl">Тип источника:</td>
            <td>
              @foreach($summary['by_source_type'] as $src => $cnt)
                {{ $sourceLabels[$src] ?? $src }}: {{ $cnt }}@if(!$loop->last), @endif
              @endforeach
            </td>
          </tr>
        @endif
      </table>
    </div>

    {{-- ═══════════════════════════════════════════ C. DETAILED ITEMS ═══════════════════════════════════════════ --}}
    <div class="section-title">Детальные записи</div>
    <div class="section-note">Подробная информация по каждой позиции доказательств.</div>

    @forelse($items as $item)
      @php
        $statusClass = match($item['status'] ?? '') {
          'resolved' => 'item-resolved',
          'skipped'  => 'item-skipped',
          'failed'   => 'item-failed',
          default    => '',
        };
        $statusLabels = [
          'resolved' => 'Подтверждено',
          'skipped'  => 'Пропущено',
          'failed'   => 'Ошибка',
          'pending'  => 'Ожидание',
        ];
        $resolutionLabels = [
          'chrome' => 'Chrome расш.',
          'manual' => 'Вручную',
          'auto'   => 'Автоматически',
          'skipped' => 'Пропущено',
        ];
      @endphp
      <div class="item {{ $statusClass }}">
        <div class="item-head">
          <div class="item-title">
            {{ $item['label'] ?? 'Позиция' }}
            @if(!empty($item['status']))
              <span class="badge {{ match($item['status']) { 'resolved' => 'badge-ok', 'skipped' => 'badge-warning', 'failed' => 'badge-error', default => '' } }}">
                {{ $statusLabels[$item['status']] ?? $item['status'] }}
              </span>
            @endif
            @if(!empty($item['capture_method']))
              <span class="badge">{{ $captureLabels[$item['capture_method']] ?? $item['capture_method'] }}</span>
            @endif
          </div>
        </div>

        <div class="item-body">
          <table class="meta-table">
            @if(!empty($item['cost_component']))
              <tr>
                <td class="meta-label">Компонент</td>
                <td class="meta-value">
                  <span class="badge badge-type">{{ $componentLabels[$item['cost_component']] ?? $item['cost_component'] }}</span>
                </td>
              </tr>
            @endif

            @if(!empty($item['resolution_type']))
              <tr>
                <td class="meta-label">Способ</td>
                <td class="meta-value">{{ $resolutionLabels[$item['resolution_type']] ?? $item['resolution_type'] }}</td>
              </tr>
            @endif

            @if(!empty($item['extracted_name']))
              <tr>
                <td class="meta-label">Наименование</td>
                <td class="meta-value">{{ $item['extracted_name'] }}</td>
              </tr>
            @endif

            @if(!empty($item['extracted_article']))
              <tr>
                <td class="meta-label">Артикул</td>
                <td class="meta-value">{{ $item['extracted_article'] }}</td>
              </tr>
            @endif

            @if(!empty($item['source_url']))
              <tr>
                <td class="meta-label">Источник</td>
                <td class="meta-value compact-source">
                  <a href="{{ $item['source_url'] }}">{{ $item['source_domain'] ?? $item['source_url'] }}</a>
                </td>
              </tr>
            @endif

            @if($item['effective_value'] !== null && $item['effective_value'] !== '')
              <tr>
                <td class="meta-label">Значение</td>
                <td class="meta-value">
                  <span class="price-badge">{{ $item['effective_value'] }} {{ $item['currency'] ?? '' }}</span>
                </td>
              </tr>
            @endif

            @if($item['observed_price'] !== null && $item['observed_price'] !== '' && $item['observed_price'] != $item['effective_value'])
              <tr>
                <td class="meta-label">Наблюд. цена</td>
                <td class="meta-value">
                  <span class="price-badge">{{ $item['observed_price'] }} {{ $item['currency'] ?? '' }}</span>
                </td>
              </tr>
            @endif

            @if(!empty($item['observed_at']))
              <tr>
                <td class="meta-label">Дата наблюд.</td>
                <td class="meta-value">{{ date('d.m.Y H:i', strtotime($item['observed_at'])) }}</td>
              </tr>
            @endif

            @if(!empty($item['verification_status']))
              @php
                $verLabels = ['pending' => 'Ожидает', 'verified' => 'Проверено', 'rejected' => 'Отклонено'];
              @endphp
              <tr>
                <td class="meta-label">Верификация</td>
                <td class="meta-value">{{ $verLabels[$item['verification_status']] ?? $item['verification_status'] }}</td>
              </tr>
            @endif

            @if($item['trust_score'] !== null)
              <tr>
                <td class="meta-label">Оценка</td>
                <td class="meta-value">
                  <span class="score-indicator">{{ $item['trust_score'] }} / 100</span>
                </td>
              </tr>
            @endif

            @if(!empty($item['source_type']))
              <tr>
                <td class="meta-label">Тип источника</td>
                <td class="meta-value">{{ $sourceLabels[$item['source_type']] ?? $item['source_type'] }}</td>
              </tr>
            @endif
          </table>

          {{-- Image asset preview --}}
          @if(!empty($item['image_asset']))
            @php
              $imgPath = $item['image_asset']['file_path'] ?? null;
              $imgExists = $imgPath && file_exists(storage_path('app/public/' . $imgPath));
            @endphp
            <div class="shot-wrap">
              @if($imgExists)
                <img src="{{ storage_path('app/public/' . $imgPath) }}" alt="screenshot" />
              @else
                <div class="shot-empty">Изображение недоступно ({{ $imgPath ?? 'путь не указан' }})</div>
              @endif
            </div>
          @endif

          {{-- Non-image document labels --}}
          @if(!empty($item['non_image_assets']))
            @foreach($item['non_image_assets'] as $docAsset)
              <div class="asset-doc">
                Приложение: {{ $docAsset['asset_type'] ?? 'документ' }}
                <span class="muted">({{ $docAsset['mime_type'] ?? '—' }})</span>
                @if(!empty($docAsset['sha256']))
                  <br /><span class="mono muted" style="font-size:6.5pt;">SHA256: {{ substr($docAsset['sha256'], 0, 16) }}…</span>
                @endif
              </div>
            @endforeach
          @endif
        </div>
      </div>
    @empty
      <div class="empty">Нет позиций доказательств в данном прогоне.</div>
    @endforelse

    {{-- ═══════════════════════════════════════════ D. EXCEPTIONS ═══════════════════════════════════════════ --}}
    @if(!empty($exceptions))
      <div class="section-title">Исключения</div>
      <div class="section-note">Пропущенные и ошибочные позиции.</div>

      <table class="exc-table">
        <thead>
          <tr>
            <th style="width:30%;">Позиция</th>
            <th style="width:15%;">Компонент</th>
            <th style="width:12%;">Статус</th>
            <th style="width:43%;">Диагностика</th>
          </tr>
        </thead>
        <tbody>
          @foreach($exceptions as $exc)
            <tr>
              <td>{{ $exc['label'] ?? '—' }} <br /><span class="mono muted" style="font-size:6.5pt;">{{ $exc['uuid'] ?? '' }}</span></td>
              <td><span class="badge badge-type">{{ $componentLabels[$exc['cost_component']] ?? ($exc['cost_component'] ?? '—') }}</span></td>
              <td>
                <span class="badge {{ ($exc['status'] ?? '') === 'skipped' ? 'badge-warning' : 'badge-error' }}">
                  {{ $statusLabels[$exc['status']] ?? ($exc['status'] ?? '—') }}
                </span>
              </td>
              <td>
                @if(!empty($exc['diagnostics']))
                  @if(is_array($exc['diagnostics']))
                    @foreach($exc['diagnostics'] as $key => $val)
                      <span class="bold">{{ $key }}:</span> {{ is_string($val) ? $val : json_encode($val, JSON_UNESCAPED_UNICODE) }}<br />
                    @endforeach
                  @else
                    {{ $exc['diagnostics'] }}
                  @endif
                @else
                  <span class="muted">—</span>
                @endif
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @endif

    {{-- ═══════════════════════════════════════════ E. TECHNICAL APPENDIX ═══════════════════════════════════════════ --}}
    @if(!empty($appendix))
      <div class="section-title">Техническое приложение</div>
      <div class="section-note">Метаданные записей доказательств для аудита и верификации.</div>

      <table class="app-table">
        <thead>
          <tr>
            <th>ID</th>
            <th>UUID</th>
            <th>Компонент</th>
            <th>Источник</th>
            <th>Метод</th>
            <th>Верификация</th>
            <th>Доверие</th>
            <th>Создан</th>
            <th>Автор</th>
            <th>Вложения</th>
          </tr>
        </thead>
        <tbody>
          @foreach($appendix as $rec)
            <tr>
              <td>{{ $rec['record_id'] ?? '—' }}</td>
              <td class="mono">{{ !empty($rec['record_uuid']) ? substr($rec['record_uuid'], 0, 8) . '…' : '—' }}</td>
              <td><span class="badge badge-type">{{ $componentLabels[$rec['cost_component']] ?? ($rec['cost_component'] ?? '—') }}</span></td>
              <td>{{ $sourceLabels[$rec['source_type']] ?? ($rec['source_type'] ?? '—') }}</td>
              <td>{{ $captureLabels[$rec['capture_method']] ?? ($rec['capture_method'] ?? '—') }}</td>
              <td>{{ $verLabels[$rec['verification_status']] ?? ($rec['verification_status'] ?? '—') }}</td>
              <td>{{ $rec['trust_score'] ?? '—' }}</td>
              <td>{{ !empty($rec['created_at']) ? date('d.m.Y', strtotime($rec['created_at'])) : '—' }}</td>
              <td>{{ $rec['created_by'] ?? '—' }}</td>
              <td>
                @if(!empty($rec['assets']))
                  @foreach($rec['assets'] as $a)
                    {{ $a['asset_type'] ?? '?' }}
                    @if(!empty($a['sha256']))
                      <span class="mono" style="font-size:6pt;">{{ substr($a['sha256'], 0, 8) }}…</span>
                    @endif
                    @if(!$loop->last)<br />@endif
                  @endforeach
                @else
                  <span class="muted">—</span>
                @endif
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @endif

  </div>
</body>
</html>
