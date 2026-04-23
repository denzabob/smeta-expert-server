<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8" />
  <title>{{ $cover['document_title'] }}</title>

  <style>
    @page { size: A4; margin: 14mm 12mm 14mm 24mm; }

    body {
      font-family: "DejaVu Sans", sans-serif;
      font-size: 8.8pt;
      line-height: 1.28;
      color: #111;
      background: #fff;
      margin: 0;
      padding: 0;
    }

    * { box-sizing: border-box; }
    a { color: inherit; text-decoration: underline; }

    /* ── Cover ─────────────────────────────────────────────────────── */
    .cover {
      margin: 0 0 5mm 0;
      padding: 0 0 4mm 0;
      border-bottom: 1pt solid #555;
      page-break-inside: avoid;
    }

    .cover-subtitle {
      font-size: 8.5pt;
      text-align: center;
      color: #666;
      margin: 0 0 1mm 0;
    }

    .cover-title {
      font-size: 12.5pt;
      font-weight: 700;
      text-align: center;
      margin: 0 0 3mm 0;
      line-height: 1.20;
    }

    .cover-intro {
      font-size: 8.3pt;
      color: #333;
      line-height: 1.30;
      text-align: justify;
      margin: 0 0 2mm 0;
      padding: 2mm 3mm;
      border-left: 1.5pt solid #999;
      background: #f8f8f8;
    }

    /* Inline summary sentence – replaces bulky summary box */
    .cover-summary {
      font-size: 8.3pt;
      color: #333;
      margin: 0 0 3mm 0;
      line-height: 1.30;
    }

    .cover-meta { width: 100%; border-collapse: collapse; font-size: 8.3pt; }
    .cover-meta td { padding: 0.5mm 0; vertical-align: top; }
    .cover-meta .lbl { font-weight: 700; width: 28%; padding-right: 2mm; }

    /* ── Section heading ────────────────────────────────────────────── */
    .section-heading {
      font-size: 9.8pt;
      font-weight: 700;
      margin: 5mm 0 2mm 0;
      padding: 1.2mm 2.5mm;
      background: #eeeeee;
      border-left: 2.5pt solid #555;
      page-break-after: avoid;
    }

    /* ── External entry card ─────────────────────────────────────────── */
    .entry-ext {
      margin: 0 0 2.5mm 0;
      border: 0.75pt solid #ccc;
      border-left: 2pt solid #888;
      background: #fff;
      page-break-inside: avoid;
      break-inside: avoid;
    }

    .entry-head {
      padding: 1.3mm 2.5mm 1mm 2.5mm;
      background: #f6f6f6;
      border-bottom: 0.75pt solid #ddd;
    }

    .entry-title { margin: 0; font-size: 8.8pt; font-weight: 700; line-height: 1.15; }
    .entry-kind  { font-size: 7.5pt; color: #666; margin: 0.3mm 0 0 0; }

    /* 2-column layout */
    .card-cols { width: 100%; border-collapse: collapse; }
    .col-meta  { width: 45%; vertical-align: top; padding: 1.8mm 1.5mm 1.8mm 2.5mm; }
    .col-img   { width: 55%; vertical-align: top; padding: 1.8mm 2.5mm 1.8mm 1.5mm; }

    /* Meta fields */
    .meta-tbl { width: 100%; border-collapse: collapse; font-size: 7.9pt; }
    .meta-tbl td { padding: 0.35mm 0; vertical-align: top; line-height: 1.18; }
    .ml { width: 40%; font-weight: 700; color: #444; padding-right: 1.5mm; }
    .mv { color: #111; word-break: break-word; overflow-wrap: anywhere; }

    .price-val { font-weight: 700; font-size: 9pt; }
    .recalc    { font-size: 7.3pt; color: #555; font-style: italic; }

    /* Screenshot */
    .shot-box { border: 0.75pt solid #ccc; background: #f5f5f5; text-align: center; padding: 1mm; }
    .shot-box img {
      display: block;
      max-width: 100%;
      max-height: 62mm;
      width: auto;
      height: auto;
      margin: 0 auto;
    }

    /* Full URL */
    .full-url-line {
      font-size: 7pt;
      color: #555;
      padding: 0.8mm 2.5mm;
      border-top: 0.5pt solid #ececec;
      word-break: break-all;
      overflow-wrap: anywhere;
    }

    /* Confirmation footer */
    .confirm-line {
      font-size: 7.8pt;
      color: #444;
      padding: 1mm 2.5mm;
      border-top: 0.75pt solid #e8e8e8;
    }

    /* No attachment / doc asset */
    .no-attach { font-size: 7.5pt; color: #888; font-style: italic; }
    .asset-doc { font-size: 7.8pt; padding: 1mm 2mm; border: 0.75pt solid #e0e0e0; background: #f8f8f8; }
    .snapshot-box { border: 0.75pt solid #d9d9d9; background: #fafafa; padding: 2mm; font-size: 7.7pt; line-height: 1.2; }
    .snapshot-box div { margin: 0 0 0.8mm 0; }
    .snapshot-box div:last-child { margin-bottom: 0; }

    /* ── Internal section ───────────────────────────────────────────── */
    .section-note {
      font-size: 8.2pt;
      color: #444;
      line-height: 1.30;
      margin: 0 0 2mm 0;
      padding: 2mm 3mm;
      border-left: 1.5pt solid #ccc;
      background: #fafafa;
      font-style: italic;
    }

    .rate-box {
      font-size: 8.5pt;
      font-weight: 700;
      margin: 0 0 2mm 0;
      padding: 1.5mm 3mm;
      border: 0.75pt solid #ccc;
      background: #f8f8f8;
    }

    .int-table { width: 100%; border-collapse: collapse; font-size: 8.3pt; margin-bottom: 3mm; }
    .int-table th {
      font-weight: 700;
      background: #f0f0f0;
      border: 1pt solid #ccc;
      padding: 1.2mm 2mm;
      text-align: left;
      font-size: 8pt;
    }
    .int-table td { border: 1pt solid #ddd; padding: 1.2mm 2mm; vertical-align: top; line-height: 1.20; }
    .int-table .col-name { width: 65%; }
    .int-table .col-val  { width: 35%; font-weight: 700; }

    /* ── Closing notes ──────────────────────────────────────────────── */
    .closing-notes {
      margin-top: 4mm;
      padding: 2.5mm 3mm;
      border: 0.75pt solid #ccc;
      background: #fafafa;
      font-size: 8.2pt;
      color: #333;
      line-height: 1.30;
    }
    .closing-notes p { margin: 0 0 1.5mm 0; }
    .closing-notes p:last-child { margin: 0; }

    /* ── Empty ──────────────────────────────────────────────────────── */
    .empty { border: 1pt dashed #ccc; padding: 5mm; text-align: center; color: #666; font-size: 8.8pt; }
  </style>
</head>
<body>
<div class="container">

  {{-- ══════════════════════════ COVER ══════════════════════════ --}}
  <div class="cover">
    <div class="cover-subtitle">{{ $cover['document_subtitle'] }}</div>
    <div class="cover-title">{{ $cover['document_title'] }}</div>
    <div class="cover-intro">{{ $cover['document_intro'] }}</div>
    <div class="cover-summary">
      В приложении отражено {{ $summary['total_items'] }} позиций,
      из них {{ $summary['external_confirmed'] }} подтверждены внешними источниками,
      {{ $summary['internal_calc'] }} приняты по внутреннему расчёту.
    </div>
    <table class="cover-meta">
      <tr>
        <td class="lbl">Проект (дело):</td>
        <td>
          {{ $cover['project_number'] }}
          @if(!empty($cover['project_name']))
            &nbsp;—&nbsp;{{ $cover['project_name'] }}
          @endif
        </td>
      </tr>
      @if(!empty($cover['object_address']))
        <tr>
          <td class="lbl">Объект:</td>
          <td>{{ $cover['object_address'] }}</td>
        </tr>
      @endif
      <tr>
        <td class="lbl">Дата составления:</td>
        <td>{{ $cover['date'] }}</td>
      </tr>
    </table>
  </div>

  {{-- ══════════════════════════ РАЗДЕЛЫ ══════════════════════════ --}}
  @forelse($sections as $section)

    <div class="section-heading">{{ $section['title'] }}</div>

    @if($section['section_type'] === 'labor')
      @if(!empty($section['internal_entries']))
        <div class="section-note">
          Для монтажно-демонтажных работ, включённых в смету, применена единая стоимость
          1 нормо-часа подрядных работ.
          Ставка подтверждена отдельным расчётом стоимости 1 часа подрядных работ,
          являющимся частью расчётной документации.
          Перечень работ соответствует расчётной части сметы.
        </div>
        @if(!empty($section['rate_display']))
          <div class="rate-box">
            Применяемая стоимость 1 нормо-часа: {{ $section['rate_display'] }}
          </div>
        @endif
        <table class="int-table">
          <thead>
            <tr>
              <th style="width:4%;text-align:center;">№</th>
              <th>Наименование работы</th>
            </tr>
          </thead>
          <tbody>
            @foreach($section['internal_entries'] as $i => $entry)
              <tr>
                <td style="text-align:center;">{{ $i + 1 }}</td>
                <td>{{ $entry['entry_title'] }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      @endif

      @if(!empty($section['external_entries']))
        <div class="section-note">
          Ниже приведены внешние источники, использованные для подтверждения стоимости
          труда по вакансиям и опубликованным предложениям работодателей.
        </div>

        @foreach($section['external_entries'] as $entry)
          <div class="entry-ext">

            <div class="entry-head">
              <div class="entry-title">{{ $entry['vacancy_title'] ?? $entry['entry_title'] }}</div>
              <div class="entry-kind">Внешнее подтверждение стоимости труда</div>
            </div>

            <table class="card-cols">
              <tr>
                <td class="col-meta">
                  <table class="meta-tbl">
                    @if(!empty($entry['employer_name']))
                      <tr>
                        <td class="ml">Работодатель</td>
                        <td class="mv">{{ $entry['employer_name'] }}</td>
                      </tr>
                    @endif
                    @if(!empty($entry['source_label']) || !empty($entry['provider_title']))
                      <tr>
                        <td class="ml">Источник</td>
                        <td class="mv">{{ $entry['provider_title'] ?? $entry['source_label'] }}</td>
                      </tr>
                    @endif
                    @if(!empty($entry['region_name']))
                      <tr>
                        <td class="ml">Регион</td>
                        <td class="mv">{{ $entry['region_name'] }}</td>
                      </tr>
                    @endif
                    @if(!empty($entry['salary_display']))
                      <tr>
                        <td class="ml">Заработная плата</td>
                        <td class="mv">{{ $entry['salary_display'] }}</td>
                      </tr>
                    @endif
                    @if(!empty($entry['hours_per_month']))
                      <tr>
                        <td class="ml">Часов в месяц</td>
                        <td class="mv">{{ $entry['hours_per_month'] }}</td>
                      </tr>
                    @endif
                    @if(!empty($entry['hourly_rate_display']))
                      <tr>
                        <td class="ml">Расчётная ставка</td>
                        <td class="mv"><span class="price-val">{{ $entry['hourly_rate_display'] }}</span></td>
                      </tr>
                    @endif
                    @if(!empty($entry['source_date_display']))
                      <tr>
                        <td class="ml">Дата источника</td>
                        <td class="mv">{{ $entry['source_date_display'] }}</td>
                      </tr>
                    @endif
                    @if(!empty($entry['labor_note']))
                      <tr>
                        <td class="ml">Примечание</td>
                        <td class="mv">{{ $entry['labor_note'] }}</td>
                      </tr>
                    @endif
                  </table>
                </td>

                <td class="col-img">
                  @if($entry['attachment_mode'] === 'image' && $entry['image_exists'])
                    <div class="shot-box">
                      <img src="{{ storage_path('app/public/' . $entry['image_path']) }}" alt="Подтверждающий материал" />
                    </div>
                  @elseif($entry['attachment_mode'] === 'document' && !empty($entry['doc_assets']))
                    @foreach($entry['doc_assets'] as $docAsset)
                      <div class="asset-doc">
                        Документ: {{ $docAsset['filename'] ?? $docAsset['type'] }}
                        @if(!empty($docAsset['mime']))({{ $docAsset['mime'] }})@endif
                      </div>
                    @endforeach
                  @else
                    <div class="no-attach">{{ $entry['attachment_caption'] }}</div>
                  @endif
                </td>
              </tr>
            </table>

            @if(!empty($entry['source_url']))
              <div class="full-url-line">Полная ссылка:&nbsp;<a href="{{ $entry['source_url'] }}">{{ $entry['source_url'] }}</a></div>
            @endif

            <div class="confirm-line">{{ $entry['confirmation_note'] }}</div>
          </div>
        @endforeach
      @endif

    @elseif($section['is_internal'])
        {{-- ── Other internal sections: name + value table ── --}}
        <div class="section-note">
          Значения по позициям данного раздела приняты по внутренним расчётным параметрам,
          используемым в смете. Подробное числовое обоснование приведено в соответствующем
          разделе расчётной части.
        </div>
        <table class="int-table">
          <thead>
            <tr>
              <th class="col-name">Наименование позиции</th>
              <th class="col-val">Значение, принятое в расчёте</th>
            </tr>
          </thead>
          <tbody>
            @foreach($section['entries'] as $entry)
              <tr>
                <td class="col-name">{{ $entry['entry_title'] }}</td>
                <td class="col-val">{{ $entry['accepted_display'] ?? '—' }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>

    @else
      {{-- ── Compact 2-column cards for external (material) sections ── --}}
      @foreach($section['entries'] as $entry)
        <div class="entry-ext">

          <div class="entry-head">
            <div class="entry-title">{{ $entry['entry_title'] }}</div>
            @if(!empty($entry['entry_kind_label']))
              <div class="entry-kind">{{ $entry['entry_kind_label'] }}</div>
            @endif
          </div>

          <table class="card-cols">
            <tr>

              {{-- Left: meta fields --}}
              <td class="col-meta">
                <table class="meta-tbl">
                @if(!empty($entry['is_snapshot_summary']) && !empty($entry['snapshot_summary']))
                  @php
                    $presentation = $entry['facade_snapshot_presentation'] ?? [];
                    $identity = $presentation['facade_identity'] ?? [];
                    $pricing = $presentation['pricing_summary'] ?? [];
                    $sourceLevel = $presentation['sources'] ?? [];
                  @endphp
                    <tr>
                      <td class="ml">Спецификация</td>
                      <td class="mv">{{ $identity['display_name'] ?? $entry['entry_title'] }}</td>
                    </tr>
                    @if(!empty($identity['article']))
                      <tr>
                        <td class="ml">Артикул</td>
                        <td class="mv">{{ $identity['article'] }}</td>
                      </tr>
                    @endif
                    @if(!empty($identity['characteristics_text']))
                      <tr>
                        <td class="ml">Характеристики</td>
                        <td class="mv">{{ $identity['characteristics_text'] }}</td>
                      </tr>
                    @endif
                    @if(!empty($pricing['computed_price_per_m2_display']))
                      <tr>
                        <td class="ml">Цена в расчёте</td>
                        <td class="mv"><span class="price-val">{{ $entry['accepted_display'] }}</span></td>
                      </tr>
                    @endif
                    <tr>
                      <td class="ml">Основание</td>
                      <td class="mv">{{ $presentation['compact_summary_text'] ?? '—' }}</td>
                    </tr>
                    @if(!empty($pricing['captured_at_display']) || !empty($pricing['computed_at_display']))
                      <tr>
                        <td class="ml">Дата фиксации</td>
                        <td class="mv">{{ $pricing['captured_at_display'] ?? $pricing['computed_at_display'] }}</td>
                      </tr>
                    @endif
                    @if(!empty($sourceLevel))
                      <tr>
                        <td class="ml">Источники</td>
                        <td class="mv">Зафиксировано {{ count($sourceLevel) }} source-level записей.</td>
                      </tr>
                    @endif
                  @endif
                  @if(!empty($entry['extracted_article']))
                    <tr>
                      <td class="ml">Артикул</td>
                      <td class="mv">{{ $entry['extracted_article'] }}</td>
                    </tr>
                  @endif
                  @if(!empty($entry['unit_hint']))
                    <tr>
                      <td class="ml">Единица товара</td>
                      <td class="mv">{{ $entry['unit_hint'] }}</td>
                    </tr>
                  @endif
                  @if(!empty($entry['source_label']))
                    <tr>
                      <td class="ml">Источник сведений</td>
                      <td class="mv">{{ $entry['source_label'] }}</td>
                    </tr>
                  @endif
                  @if($entry['price_display'] !== null)
                    <tr>
                      <td class="ml">Цена в источнике</td>
                      <td class="mv"><span class="price-val">{{ $entry['price_display'] }}</span></td>
                    </tr>
                  @endif
                  @if(!empty($entry['capture_date']))
                    <tr>
                      <td class="ml">Дата фиксации</td>
                      <td class="mv">{{ $entry['capture_date'] }}</td>
                    </tr>
                  @endif
                  @if(!empty($entry['recalculation_note']))
                    <tr>
                      <td colspan="2" style="padding-top:1mm;">
                        <span class="recalc">{{ $entry['recalculation_note'] }}</span>
                      </td>
                    </tr>
                  @endif
                </table>
              </td>

              {{-- Right: screenshot / document / note --}}
              <td class="col-img">
                @if(!empty($entry['is_snapshot_summary']) && !empty($entry['snapshot_summary']))
                  @php
                    $presentation = $entry['facade_snapshot_presentation'] ?? [];
                    $pricing = $presentation['pricing_summary'] ?? [];
                    $position = $presentation['position_summary'] ?? [];
                    $sourceLevel = $presentation['sources'] ?? [];
                  @endphp
                  <div class="snapshot-box">
                    <div><strong>Snapshot-derived pricing basis</strong></div>
                    <div>Цена за м²: {{ $entry['accepted_display'] ?? '—' }}</div>
                    <div>{{ $presentation['compact_summary_text'] ?? '—' }}</div>
                    <div>Позиция: {{ $position['detail_name'] ?? 'Фасад' }}, {{ $position['quantity'] ?? '—' }} шт.</div>
                    <div>Площадь: {{ $position['area_m2_display'] ?? '—' }} м²; сумма: {{ $position['total_cost_display'] ?? '—' }}</div>
                    @if(!empty($sourceLevel))
                      <div>Источник(и):</div>
                      @foreach($sourceLevel as $source)
                        <div style="margin-left:2mm;">
                          {{ $source['supplier_name'] ?? '—' }} —
                          {{ $source['normalized_price_per_m2_display'] ?? '—' }}
                          @if(!empty($source['evidence_assets_count'])) (вложений: {{ $source['evidence_assets_count'] }}) @endif
                        </div>
                      @endforeach
                    @endif
                    <div>{{ $presentation['basis_note'] ?? 'Источник основан на зафиксированном snapshot позиции.' }}</div>
                  </div>
                @elseif($entry['attachment_mode'] === 'image' && $entry['image_exists'])
                  <div class="shot-box">
                    <img src="{{ storage_path('app/public/' . $entry['image_path']) }}" alt="Подтверждающий материал" />
                  </div>
                @elseif($entry['attachment_mode'] === 'document' && !empty($entry['doc_assets']))
                  @foreach($entry['doc_assets'] as $docAsset)
                    <div class="asset-doc">
                      Документ: {{ $docAsset['filename'] ?? $docAsset['type'] }}
                      @if(!empty($docAsset['mime']))({{ $docAsset['mime'] }})@endif
                    </div>
                  @endforeach
                @else
                  <div class="no-attach">{{ $entry['attachment_caption'] }}</div>
                @endif
              </td>

            </tr>
          </table>

          {{-- Full URL of source page --}}
          @if(!empty($entry['source_url']))
            <div class="full-url-line">Полная ссылка:&nbsp;<a href="{{ $entry['source_url'] }}">{{ $entry['source_url'] }}</a></div>
          @endif

          <div class="confirm-line">{{ $entry['confirmation_note'] }}</div>

        </div>
      @endforeach
    @endif

  @empty
    <div class="empty">Позиции не найдены.</div>
  @endforelse

  {{-- ══════════════════════════ ЗАКЛЮЧИТЕЛЬНЫЕ ПРИМЕЧАНИЯ ══════════════════════════ --}}
  @if(!empty($closing_notes))
    <div class="section-heading">Заключительные примечания</div>
    <div class="closing-notes">
      @foreach($closing_notes as $note)
        <p>{{ $note }}</p>
      @endforeach
    </div>
  @endif

</div>
</body>
</html>
