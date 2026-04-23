<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8" />
  <title>Обоснование цен</title>

  <style>
    @page {
      size: A4;
      margin: 5mm 10mm 10mm 25mm; /* top right bottom left */
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

    * {
      box-sizing: border-box;
    }

    a {
      color: inherit;
      text-decoration: underline;
    }

    .container {
      width: 100%;
      padding-top: 3mm; /* дополнительный гарантированный воздух сверху */
    }

    .muted { color: #606060; }
    .bold { font-weight: 700; }
    .mono { font-family: "DejaVu Sans Mono","Courier New",monospace; }

    .header {
      margin: 0 0 4.2mm 0;
      padding: 0 0 3mm 0;
      border-bottom: 1px solid #ddd;
      page-break-after: avoid;
      page-break-inside: avoid;
    }

    .header-title {
      margin: 0 0 2.2mm 0;
      font-size: 15px;
      line-height: 1.1;
      font-weight: 700;
      text-align: center;
      color: #111;
    }

    .header-meta {
      width: 100%;
      border-collapse: collapse;
      table-layout: fixed;
      font-size: 8.4pt;
      color: #444;
    }

    .header-meta td {
      padding: 0;
      vertical-align: top;
    }

    .header-meta .left   { text-align: left; }
    .header-meta .center { text-align: center; }
    .header-meta .right  { text-align: right; }

    .section-title {
      font-size: 11pt;
      font-weight: 800;
      margin: 0 0 2.6mm 0;
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

    .item {
      margin: 0 0 3mm 0;
      border: 1px solid #d7d7d7;
      border-left: 3px solid #9a9a9a;
      background: #fff;
      page-break-inside: avoid;
      break-inside: avoid;
    }

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
      width: 16%;
      font-weight: 700;
      color: #333;
      padding-right: 2mm;
      white-space: nowrap;
    }

    .meta-value {
      width: 84%;
      color: #111;
      word-break: break-word;
      overflow-wrap: anywhere;
    }

    .compact-source {
      font-size: 7.6pt;
      line-height: 1.12;
    }

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
      min-height: 28mm;
      padding: 10mm 0;
      text-align: center;
      color: #7a7a7a;
      font-size: 8pt;
      background: #f8f8f8;
    }

    .empty {
      border: 1px dashed #cfcfcf;
      padding: 5mm;
      text-align: center;
      color: #666;
      font-size: 9pt;
      margin-top: 6mm;
    }

    .evidence-summary {
      border: 1px solid #d7d7d7;
      background: #f9f9f9;
      padding: 2.5mm 3mm;
      margin: 0 0 4mm 0;
      page-break-inside: avoid;
    }

    .evidence-summary-title {
      font-size: 8.6pt;
      font-weight: 700;
      margin: 0 0 1.5mm 0;
      color: #333;
    }

    .evidence-summary-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 7.9pt;
    }

    .evidence-summary-table td {
      padding: 0.4mm 2mm 0.4mm 0;
      vertical-align: top;
      line-height: 1.2;
    }

    .source-badge {
      display: inline-block;
      padding: 0.3mm 1.5mm;
      border: 1px solid #cfcfcf;
      background: #f0f0f0;
      font-size: 7pt;
      line-height: 1.1;
      color: #555;
      white-space: nowrap;
    }

    .type-badge {
      display: inline-block;
      padding: 0.3mm 1.5mm;
      border: 1px solid #c5d5c5;
      background: #eef5ee;
      font-size: 7pt;
      line-height: 1.1;
      color: #446644;
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

    .snapshot-summary {
      border: 1px solid #dcdcdc;
      background: #fafafa;
      padding: 2mm 2.4mm;
      font-size: 7.8pt;
      line-height: 1.18;
    }
    .snapshot-summary div { margin: 0 0 0.8mm 0; }
    .snapshot-summary div:last-child { margin-bottom: 0; }
  </style>
</head>
<body>
  <div class="container">
    <div class="section-title">Материалы и ценовые подтверждения</div>
    <div class="section-note">
      Источники и скриншоты, подтверждающие стоимость материалов, включенных в ревизию сметы.
    </div>

    @if(isset($evidenceSummary) && is_array($evidenceSummary))
      @php
        $sourceLabels = [
          'auto' => 'Автоматически',
          'manual' => 'Вручную',
          'chrome_ext' => 'Chrome расширение',
          'internal' => 'Внутренний',
        ];
      @endphp
      <div class="evidence-summary">
        <div class="evidence-summary-title">Покрытие обоснованиями</div>
        <table class="evidence-summary-table">
          <tr>
            <td class="bold">Позиций с обоснованием:</td>
            <td>{{ $evidenceSummary['with_evidence'] ?? 0 }} / {{ $evidenceSummary['total_items'] ?? 0 }} ({{ $evidenceSummary['coverage_pct'] ?? 0 }}%)</td>
          </tr>
          @if(!empty($evidenceSummary['by_capture_source']))
            <tr>
              <td class="bold">По источнику:</td>
              <td>
                @foreach($evidenceSummary['by_capture_source'] as $src => $cnt)
                  {{ $sourceLabels[$src] ?? $src }}: {{ $cnt }}@if(!$loop->last), @endif
                @endforeach
              </td>
            </tr>
          @endif
        </table>
      </div>
    @endif

    @forelse($rows as $row)
      <div class="item">
        <div class="item-head">
          <div class="item-title">
            {{ $row['name'] ?? ('Позиция #' . ($row['project_position_id'] ?? $row['project_fitting_id'] ?? '—')) }}
            @if(!empty($row['capture_source']))
              @php
                $capLabels = ['auto' => 'Авто', 'manual' => 'Вручную', 'chrome_ext' => 'Chrome', 'internal' => 'Внутр.'];
              @endphp
              <span class="source-badge">{{ $capLabels[$row['capture_source']] ?? $row['capture_source'] }}</span>
            @endif
          </div>
        </div>

        <div class="item-body">
          <table class="meta-table">
            @if(!empty($row['article']) || !empty($row['unit']))
              <tr>
                <td class="meta-label">Артикул</td>
                <td class="meta-value">
                  {{ $row['article'] ?? '—' }}@if(!empty($row['unit'])), {{ $row['unit'] }}@endif
                </td>
              </tr>
            @endif

            @if(!empty($row['cost_driver_type']))
              @php
                $driverLabels = [
                  'plate' => 'Плита', 'edge' => 'Кромка', 'facade' => 'Фасад',
                  'fitting' => 'Фурнитура', 'operation' => 'Операция',
                  'labor_work' => 'Работа', 'expense' => 'Расход',
                ];
              @endphp
              <tr>
                <td class="meta-label">Тип</td>
                <td class="meta-value">
                  <span class="type-badge">{{ $driverLabels[$row['cost_driver_type']] ?? $row['cost_driver_type'] }}</span>
                </td>
              </tr>
            @endif

            @if(!empty($row['source_url']))
              <tr>
                <td class="meta-label">Источник</td>
                <td class="meta-value compact-source">
                  <a href="{{ $row['source_url'] }}">{{ $row['source_domain'] ?? $row['source_url'] }}</a>
                </td>
              </tr>
            @endif

            @if(!empty($row['price_per_unit']) && !empty($row['currency']))
              <tr>
                <td class="meta-label">Цена</td>
                <td class="meta-value">
                  <span class="price-badge">{{ $row['price_per_unit'] }} {{ $row['currency'] }}</span>
                </td>
              </tr>
            @endif

            @if(!empty($row['observed_at']))
              <tr>
                <td class="meta-label">Дата</td>
                <td class="meta-value">{{ date('d.m.Y H:i', strtotime($row['observed_at'])) }}</td>
              </tr>
            @endif

            @if($row['true_score'] !== null)
              <tr>
                <td class="meta-label">Оценка</td>
                <td class="meta-value">
                  <span class="score-indicator">{{ $row['true_score'] }} / 100</span>
                </td>
              </tr>
            @endif

            @if(!empty($row['cost_driver_type']) && $row['cost_driver_type'] === 'labor_work')
              @if(isset($row['labor_work_hours']))
                <tr>
                  <td class="meta-label">Трудозатраты</td>
                  <td class="meta-value">
                    {{ $row['labor_work_hours'] }} н/ч
                    @if(!empty($row['price_per_unit']) && isset($row['labor_work_total_cost']))
                      &times; {{ $row['price_per_unit'] }} ₽/н/ч = <span class="bold">{{ $row['labor_work_total_cost'] }} ₽</span>
                    @endif
                  </td>
                </tr>
              @endif
              @if(!empty($row['labor_work_basis']))
                <tr>
                  <td class="meta-label">Основание</td>
                  <td class="meta-value">{{ $row['labor_work_basis'] }}</td>
                </tr>
              @endif
              @if(!empty($row['labor_work_note']))
                <tr>
                  <td class="meta-label">Примечание</td>
                  <td class="meta-value">{{ $row['labor_work_note'] }}</td>
                </tr>
              @endif
              @if(!empty($row['labor_work_steps']))
                <tr>
                  <td class="meta-label" style="vertical-align:top;">Подоперации</td>
                  <td class="meta-value">
                    <table style="width:100%;border-collapse:collapse;font-size:7.8pt;">
                      <thead>
                        <tr style="border-bottom:1px solid #ddd;">
                          <th style="text-align:left;padding:0.5mm 1mm;font-weight:600;">Наименование</th>
                          <th style="text-align:right;padding:0.5mm 1mm;font-weight:600;white-space:nowrap;">н/ч</th>
                        </tr>
                      </thead>
                      <tbody>
                        @foreach($row['labor_work_steps'] as $step)
                          <tr>
                            <td style="padding:0.5mm 1mm;">{{ $step['title'] ?? '—' }}</td>
                            <td style="text-align:right;padding:0.5mm 1mm;white-space:nowrap;">{{ $step['hours'] ?? 0 }}</td>
                          </tr>
                        @endforeach
                      </tbody>
                    </table>
                  </td>
                </tr>
              @endif
            @endif

            @if(!empty($row['cost_driver_type']) && $row['cost_driver_type'] === 'expense' && !empty($row['expense_document_path']))
              <tr>
                <td class="meta-label">Документ</td>
                <td class="meta-value">
                  @if(str_starts_with($row['expense_document_mime'] ?? '', 'image/') && file_exists(storage_path('app/public/' . $row['expense_document_path'])))
                    <img src="{{ storage_path('app/public/' . $row['expense_document_path']) }}" alt="document" style="max-width:100%;max-height:120mm;" />
                  @else
                    Приложен ({{ basename($row['expense_document_path']) }})
                  @endif
                </td>
              </tr>
            @endif

            @if(($row['reference_type'] ?? null) === 'snapshot_summary' && ($row['cost_driver_type'] ?? null) === 'facade')
              @php
                $presentation = $row['facade_snapshot_presentation'] ?? [];
                $identity = $presentation['facade_identity'] ?? [];
                $pricing = $presentation['pricing_summary'] ?? [];
                $position = $presentation['position_summary'] ?? [];
                $sources = $presentation['sources'] ?? [];
              @endphp
              <tr>
                <td class="meta-label">Контракт</td>
                <td class="meta-value">Snapshot summary фасадной позиции</td>
              </tr>
              @if(!empty($row['finished_product_specification_id']))
                <tr>
                  <td class="meta-label">Спецификация</td>
                  <td class="meta-value">
                    {{ $identity['display_name'] ?? $row['specification_name'] ?? $row['name'] ?? 'Фасад' }}
                    <span class="muted">#{{ $row['finished_product_specification_id'] }}</span>
                  </td>
                </tr>
              @endif
              @if(!empty($identity['characteristics_text']))
                <tr>
                  <td class="meta-label">Параметры</td>
                  <td class="meta-value">{{ $identity['characteristics_text'] }}</td>
                </tr>
              @endif
              <tr>
                <td class="meta-label">Основание цены</td>
                <td class="meta-value">
                  {{ $presentation['compact_summary_text'] ?? '—' }}
                </td>
              </tr>
              @if(!empty($position))
                <tr>
                  <td class="meta-label">Позиция</td>
                  <td class="meta-value">
                    {{ $position['detail_name'] ?? 'Фасад' }},
                    {{ $position['quantity'] ?? '—' }} шт.,
                    {{ $position['width_mm'] ?? '—' }}×{{ $position['height_mm'] ?? '—' }} мм,
                    {{ $position['area_m2_display'] ?? '—' }} м²,
                    {{ $position['total_cost_display'] ?? '—' }}
                  </td>
                </tr>
              @endif
              @if(!empty($sources))
                <tr>
                  <td class="meta-label">Источники</td>
                  <td class="meta-value">
                    Зафиксировано {{ count($sources) }} источников на момент capture.
                  </td>
                </tr>
              @endif
            @endif
          </table>

          @if(($row['reference_type'] ?? null) === 'snapshot_summary' && ($row['cost_driver_type'] ?? null) === 'facade')
            @php
              $presentation = $row['facade_snapshot_presentation'] ?? [];
              $pricing = $presentation['pricing_summary'] ?? [];
              $sources = $presentation['sources'] ?? [];
            @endphp
            <div class="snapshot-summary">
              <div><strong>Snapshot-derived pricing basis</strong></div>
              <div>Цена за м² принята из immutable pricing snapshot, сохранённого вместе с позицией/ревизией.</div>
              @if(!empty($pricing['captured_at_display']) || !empty($pricing['computed_at_display']))
                <div>
                  Дата фиксации:
                  {{ $pricing['captured_at_display'] ?? $pricing['computed_at_display'] }}
                </div>
              @endif
              <div>{{ $presentation['basis_note'] ?? $row['basis_note'] ?? 'Источник построен по summary-level snapshot contract без legacy screenshot/price history полей.' }}</div>
              @if(!empty($sources))
                <div style="margin-top:1mm;"><strong>Зафиксированные source-level данные:</strong></div>
                @foreach($sources as $source)
                  <div>
                    {{ $source['supplier_name'] ?? '—' }}:
                    {{ $source['normalized_price_per_m2_display'] ?? '—' }}
                    @if(!empty($source['source_kind_label'])) · {{ $source['source_kind_label'] }} @endif
                    @if(!empty($source['effective_date_display'])) · {{ $source['effective_date_display'] }} @endif
                    @if(!empty($source['evidence_assets_count'])) · вложений: {{ $source['evidence_assets_count'] }} @endif
                  </div>
                  @if(!empty($source['evidence_assets']))
                    @foreach($source['evidence_assets'] as $asset)
                      <div class="muted" style="margin-left:3mm;">
                        {{ $asset['asset_type_label'] ?? $asset['asset_type'] ?? 'asset' }}:
                        {{ $asset['display_label'] ?? '—' }}
                        @if(!empty($asset['mime_type'])) ({{ $asset['mime_type'] }}) @endif
                      </div>
                    @endforeach
                  @endif
                @endforeach
              @endif
            </div>
          @else
            <div class="shot-wrap">
              @if(!empty($row['screenshot_path']) && file_exists(storage_path('app/public/' . $row['screenshot_path'])))
                <img src="{{ storage_path('app/public/' . $row['screenshot_path']) }}" alt="screenshot" />
              @else
                <div class="shot-empty">Скриншот отсутствует</div>
              @endif
            </div>
          @endif
        </div>
      </div>
    @empty
      <div class="empty">
        Нет данных обоснования цен в snapshot ревизии.
      </div>
    @endforelse
  </div>
</body>
</html>
