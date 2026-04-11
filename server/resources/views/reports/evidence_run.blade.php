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
      margin: 0 0 6mm 0;
      padding: 0 0 5mm 0;
      border-bottom: 1.5pt solid #333;
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
      margin: 0 0 3mm 0;
      padding: 2.5mm 3mm;
      border-left: 1.5pt solid #999;
      background: #f8f8f8;
    }

    .cover-meta { width: 100%; border-collapse: collapse; font-size: 8.3pt; }
    .cover-meta td { padding: 0.6mm 0; vertical-align: top; }
    .cover-meta .lbl { font-weight: 700; width: 28%; padding-right: 2mm; }

    /* ── Section heading ────────────────────────────────────────────── */
    .section-heading {
      font-size: 9.8pt;
      font-weight: 700;
      margin: 5mm 0 2mm 0;
      padding: 1.2mm 2.5mm;
      background: #efefef;
      border-left: 2.5pt solid #555;
      page-break-after: avoid;
    }

    /* ── Summary ────────────────────────────────────────────────────── */
    .summary-box {
      border: 1pt solid #ccc;
      padding: 2.5mm 3mm;
      margin: 2mm 0 4mm 0;
      page-break-inside: avoid;
    }

    .summary-table { width: 100%; border-collapse: collapse; font-size: 8.5pt; }
    .summary-table td { padding: 0.5mm 2mm 0.5mm 0; line-height: 1.20; }
    .summary-table .lbl { font-weight: 700; width: 55%; }

    /* ── External entry card ─────────────────────────────────────────── */
    .entry-ext {
      margin: 0 0 3mm 0;
      border: 1pt solid #bbb;
      border-left: 2.5pt solid #555;
      background: #fff;
      page-break-inside: avoid;
      break-inside: avoid;
    }

    .entry-head {
      padding: 1.5mm 2.5mm 1.2mm 2.5mm;
      background: #f3f3f3;
      border-bottom: 1pt solid #ddd;
    }

    .entry-title { margin: 0; font-size: 8.8pt; font-weight: 700; line-height: 1.15; }
    .entry-kind  { font-size: 7.5pt; color: #666; margin: 0.3mm 0 0 0; }

    /* 2-column layout inside external card */
    .card-cols   { width: 100%; border-collapse: collapse; }
    .col-meta    { width: 45%; vertical-align: top; padding: 2mm 1.5mm 2mm 2.5mm; }
    .col-img     { width: 55%; vertical-align: top; padding: 2mm 2.5mm 2mm 1.5mm; }

    /* Meta fields */
    .meta-tbl { width: 100%; border-collapse: collapse; font-size: 8pt; }
    .meta-tbl td { padding: 0.4mm 0; vertical-align: top; line-height: 1.18; }
    .ml { width: 42%; font-weight: 700; color: #444; padding-right: 1.5mm; }
    .mv { color: #111; word-break: break-word; overflow-wrap: anywhere; }
    .mv.url { font-size: 7.3pt; }

    .price-val  { font-weight: 700; }
    .price-main { font-size: 9pt; }
    .recalc     { font-size: 7.5pt; color: #444; font-style: italic; }

    /* Screenshot */
    .shot-box { border: 1pt solid #ccc; background: #f5f5f5; text-align: center; padding: 1mm; }
    .shot-box img {
      display: block;
      max-width: 100%;
      max-height: 52mm;
      width: auto;
      height: auto;
      margin: 0 auto;
    }

    /* Confirmation footer line */
    .confirm-line {
      font-size: 7.8pt;
      color: #444;
      padding: 1.2mm 2.5mm;
      border-top: 1pt solid #e5e5e5;
    }

    /* No attachment / doc asset */
    .no-attach  { font-size: 7.5pt; color: #888; font-style: italic; }
    .asset-doc  { font-size: 7.8pt; padding: 1mm 2mm; border: 1pt solid #e0e0e0; background: #f8f8f8; }

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
      border: 1pt solid #ccc;
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

  {{-- ══════════════════════════ КРАТКАЯ СВОДКА ══════════════════════════ --}}
  <div class="section-heading">Краткая сводка</div>
  <div class="summary-box">
    <table class="summary-table">
      <tr>
        <td class="lbl">Всего позиций в приложении:</td>
        <td>{{ $summary['total_items'] }}</td>
      </tr>
      <tr>
        <td class="lbl">Подтверждено внешними источниками:</td>
        <td>{{ $summary['external_confirmed'] }}</td>
      </tr>
      <tr>
        <td class="lbl">Принято по внутреннему расчёту:</td>
        <td>{{ $summary['internal_calc'] }}</td>
      </tr>
      @if($summary['with_images'] > 0)
        <tr>
          <td class="lbl">Приложено графических материалов:</td>
          <td>{{ $summary['with_images'] }}</td>
        </tr>
      @endif
    </table>
  </div>

  {{-- ══════════════════════════ РАЗДЕЛЫ ══════════════════════════ --}}
  @forelse($sections as $section)

    <div class="section-heading">{{ $section['title'] }}</div>

    @if($section['is_internal'])
      {{-- ── Compact table for internal sections ── --}}
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

              {{-- Left column: meta fields --}}
              <td class="col-meta">
                <table class="meta-tbl">
                  @if(!empty($entry['extracted_article']))
                    <tr>
                      <td class="ml">Артикул</td>
                      <td class="mv">{{ $entry['extracted_article'] }}</td>
                    </tr>
                  @endif
                  @if(!empty($entry['source_url']) || !empty($entry['source_label']))
                    <tr>
                      <td class="ml">Источник сведений</td>
                      <td class="mv url">
                        @if(!empty($entry['source_url']))
                          <a href="{{ $entry['source_url'] }}">{{ $entry['source_label'] ?? $entry['source_url'] }}</a>
                        @else
                          {{ $entry['source_label'] }}
                        @endif
                      </td>
                    </tr>
                  @endif
                  @if($entry['price_display'] !== null)
                    <tr>
                      <td class="ml">Цена в источнике</td>
                      <td class="mv"><span class="price-val">{{ $entry['price_display'] }}</span></td>
                    </tr>
                  @endif
                  @if($entry['accepted_display'] !== null)
                    <tr>
                      <td class="ml">Принято в расчёте</td>
                      <td class="mv"><span class="price-val price-main">{{ $entry['accepted_display'] }}</span></td>
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

              {{-- Right column: screenshot / document / note --}}
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
