<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8" />
  <title>{{ $cover['document_title'] }}</title>

  <style>
    @page {
      size: A4;
      margin: 15mm 12mm 15mm 25mm;
    }

    body {
      font-family: "DejaVu Sans", sans-serif;
      font-size: 9pt;
      line-height: 1.30;
      color: #111;
      background: #fff;
      margin: 0;
      padding: 0;
    }

    * { box-sizing: border-box; }
    a { color: inherit; text-decoration: underline; }

    .container {
      width: 100%;
      padding-top: 2mm;
    }

    /* ── Title / Cover ─────────────────────────────────────────────── */
    .cover {
      margin: 0 0 8mm 0;
      padding: 0 0 6mm 0;
      border-bottom: 1.5pt solid #222;
      page-break-after: avoid;
      page-break-inside: avoid;
    }

    .cover-subtitle {
      margin: 0 0 1.5mm 0;
      font-size: 9pt;
      text-align: center;
      color: #555;
      letter-spacing: 0.3px;
    }

    .cover-title {
      margin: 0 0 4mm 0;
      font-size: 13pt;
      font-weight: 700;
      text-align: center;
      color: #111;
      line-height: 1.20;
    }

    .cover-intro {
      font-size: 8.6pt;
      color: #333;
      line-height: 1.35;
      text-align: justify;
      margin: 0 0 4mm 0;
      padding: 3mm 4mm;
      border-left: 2pt solid #aaa;
      background: #f8f8f8;
    }

    .cover-meta {
      width: 100%;
      border-collapse: collapse;
      font-size: 8.6pt;
      color: #222;
    }

    .cover-meta td {
      padding: 0.8mm 0;
      vertical-align: top;
    }

    .cover-meta .lbl {
      font-weight: 700;
      width: 28%;
      padding-right: 3mm;
    }

    /* ── Section headings ──────────────────────────────────────────── */
    .section-heading {
      font-size: 10.5pt;
      font-weight: 700;
      margin: 7mm 0 3mm 0;
      padding: 1.5mm 3mm;
      background: #f0f0f0;
      border-left: 3pt solid #555;
      page-break-after: avoid;
    }

    /* ── Summary box ───────────────────────────────────────────────── */
    .summary-box {
      border: 1pt solid #ccc;
      background: #fafafa;
      padding: 3mm 4mm;
      margin: 3mm 0 6mm 0;
      page-break-inside: avoid;
    }

    .summary-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 8.8pt;
    }

    .summary-table td {
      padding: 0.7mm 2mm 0.7mm 0;
      vertical-align: top;
      line-height: 1.25;
    }

    .summary-table .lbl {
      font-weight: 700;
      color: #333;
      width: 55%;
    }

    .summary-table .val {
      color: #111;
    }

    /* ── Entry cards ───────────────────────────────────────────────── */
    .entry {
      margin: 0 0 4mm 0;
      border: 1pt solid #ccc;
      background: #fff;
      page-break-inside: avoid;
      break-inside: avoid;
    }

    .entry-external {
      border-left: 3pt solid #666;
    }

    .entry-internal {
      border-left: 3pt solid #bbb;
      background: #fafafa;
    }

    .entry-head {
      padding: 2mm 3mm 1.5mm 3mm;
      background: #f5f5f5;
      border-bottom: 1pt solid #e0e0e0;
    }

    .entry-title {
      margin: 0;
      font-size: 9pt;
      font-weight: 700;
      color: #111;
      word-break: break-word;
      line-height: 1.20;
    }

    .entry-kind {
      margin: 0.5mm 0 0 0;
      font-size: 7.8pt;
      color: #555;
    }

    .entry-body {
      padding: 2.5mm 3mm 3mm 3mm;
    }

    /* ── Meta table inside entry ───────────────────────────────────── */
    .meta-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 8.2pt;
      margin-bottom: 2mm;
    }

    .meta-table td {
      padding: 0.5mm 0 0.5mm 0;
      vertical-align: top;
      line-height: 1.20;
    }

    .meta-lbl {
      width: 34%;
      font-weight: 700;
      color: #444;
      padding-right: 3mm;
      white-space: nowrap;
    }

    .meta-val {
      width: 66%;
      color: #111;
      word-break: break-word;
      overflow-wrap: anywhere;
    }

    .meta-val.source-url {
      font-size: 7.6pt;
    }

    /* ── Price values ──────────────────────────────────────────────── */
    .price-val {
      font-weight: 700;
    }

    .price-accepted {
      font-size: 9.5pt;
    }

    /* ── Notes ─────────────────────────────────────────────────────── */
    .recalc-note {
      font-size: 7.8pt;
      color: #444;
      font-style: italic;
    }

    .confirmation-line {
      font-size: 8pt;
      color: #333;
      margin: 2mm 0 1.5mm 0;
      padding-left: 2mm;
      border-left: 1.5pt solid #bbb;
    }

    .attachment-note {
      font-size: 7.8pt;
      color: #666;
      margin-top: 1.5mm;
    }

    /* ── Screenshot wrap ───────────────────────────────────────────── */
    .shot-wrap {
      border: 1pt solid #d0d0d0;
      background: #fafafa;
      padding: 1.5mm;
      text-align: center;
      margin-top: 2mm;
    }

    .shot-wrap img {
      display: block;
      max-width: 100%;
      max-height: 90mm;
      width: auto;
      height: auto;
      margin: 0 auto;
    }

    /* ── Document asset ────────────────────────────────────────────── */
    .asset-doc {
      font-size: 8pt;
      padding: 1.5mm 2mm;
      border: 1pt solid #e0e0e0;
      background: #f8f8f8;
      margin-top: 1.5mm;
      color: #333;
    }

    /* ── Closing notes ─────────────────────────────────────────────── */
    .closing-notes {
      margin-top: 4mm;
      padding: 3mm 4mm;
      border: 1pt solid #ccc;
      background: #fafafa;
      font-size: 8.4pt;
      color: #333;
      line-height: 1.35;
    }

    .closing-notes p {
      margin: 0 0 2mm 0;
    }

    .closing-notes p:last-child {
      margin: 0;
    }

    /* ── Empty state ───────────────────────────────────────────────── */
    .empty {
      border: 1pt dashed #ccc;
      padding: 6mm;
      text-align: center;
      color: #666;
      font-size: 9pt;
      margin-top: 4mm;
    }
  </style>
</head>
<body>
<div class="container">

  {{-- ══════════════════════════ COVER / TITLE PAGE ══════════════════════════ --}}
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

  {{-- ══════════════════════════ КРАТКАЯ СВОДКА ══════════════════════════════ --}}
  <div class="section-heading">Краткая сводка</div>
  <div class="summary-box">
    <table class="summary-table">
      <tr>
        <td class="lbl">Всего позиций в приложении:</td>
        <td class="val">{{ $summary['total_items'] }}</td>
      </tr>
      <tr>
        <td class="lbl">Подтверждено внешними источниками:</td>
        <td class="val">{{ $summary['external_confirmed'] }}</td>
      </tr>
      <tr>
        <td class="lbl">Принято по внутреннему расчёту:</td>
        <td class="val">{{ $summary['internal_calc'] }}</td>
      </tr>
      @if($summary['with_images'] > 0)
        <tr>
          <td class="lbl">Приложено графических материалов:</td>
          <td class="val">{{ $summary['with_images'] }}</td>
        </tr>
      @endif
    </table>
  </div>

  {{-- ══════════════════════════ РАЗДЕЛЫ ═════════════════════════════════════ --}}
  @forelse($sections as $section)
    <div class="section-heading">{{ $section['title'] }}</div>

    @foreach($section['entries'] as $entry)

      @if($entry['is_external'])
        {{-- ── External entry (material with external price source) ── --}}
        <div class="entry entry-external">

          <div class="entry-head">
            <div class="entry-title">{{ $entry['entry_title'] }}</div>
            @if(!empty($entry['entry_kind_label']))
              <div class="entry-kind">{{ $entry['entry_kind_label'] }}</div>
            @endif
          </div>

          <div class="entry-body">
            <table class="meta-table">

              @if(!empty($entry['extracted_name']))
                <tr>
                  <td class="meta-lbl">Наименование</td>
                  <td class="meta-val">{{ $entry['extracted_name'] }}</td>
                </tr>
              @endif

              @if(!empty($entry['extracted_article']))
                <tr>
                  <td class="meta-lbl">Артикул / обозначение</td>
                  <td class="meta-val">{{ $entry['extracted_article'] }}</td>
                </tr>
              @endif

              @if(!empty($entry['source_url']) || !empty($entry['source_label']))
                <tr>
                  <td class="meta-lbl">Источник сведений</td>
                  <td class="meta-val source-url">
                    @if(!empty($entry['source_url']))
                      <a href="{{ $entry['source_url'] }}">{{ $entry['source_label'] ?? $entry['source_url'] }}</a>
                    @else
                      {{ $entry['source_label'] }}
                    @endif
                  </td>
                </tr>
              @endif

              @if($entry['price_in_source'] !== null && $entry['price_in_source'] !== '')
                <tr>
                  <td class="meta-lbl">Цена в источнике</td>
                  <td class="meta-val">
                    <span class="price-val">{{ number_format((float) $entry['price_in_source'], 2, ',', '\u{00A0}') }} {{ $entry['currency'] }}</span>
                  </td>
                </tr>
              @endif

              @if($entry['accepted_value'] !== null && $entry['accepted_value'] !== '')
                <tr>
                  <td class="meta-lbl">Значение, принятое в расчёте</td>
                  <td class="meta-val">
                    <span class="price-val price-accepted">{{ number_format((float) $entry['accepted_value'], 2, ',', '\u{00A0}') }} {{ $entry['currency'] }}</span>
                  </td>
                </tr>
              @endif

              @if(!empty($entry['capture_date']))
                <tr>
                  <td class="meta-lbl">Дата фиксации</td>
                  <td class="meta-val">{{ $entry['capture_date'] }}</td>
                </tr>
              @endif

              @if(!empty($entry['recalculation_note']))
                <tr>
                  <td class="meta-lbl">Пояснение о перерасчёте</td>
                  <td class="meta-val recalc-note">{{ $entry['recalculation_note'] }}</td>
                </tr>
              @endif

            </table>

            <div class="confirmation-line">{{ $entry['confirmation_note'] }}</div>

            {{-- Graphic / documentary attachment --}}
            @if($entry['attachment_mode'] === 'image' && $entry['image_exists'])
              <div class="shot-wrap">
                <img src="{{ storage_path('app/public/' . $entry['image_path']) }}" alt="Подтверждающий материал" />
              </div>
            @elseif($entry['attachment_mode'] === 'document' && !empty($entry['doc_assets']))
              @foreach($entry['doc_assets'] as $docAsset)
                <div class="asset-doc">
                  Приложенный документ: {{ $docAsset['filename'] ?? $docAsset['type'] }}
                  @if(!empty($docAsset['mime']))
                    ({{ $docAsset['mime'] }})
                  @endif
                </div>
              @endforeach
            @else
              <div class="attachment-note">{{ $entry['attachment_caption'] }}</div>
            @endif

          </div>
        </div>

      @else
        {{-- ── Internal entry (operation / work / expense by internal calc) ── --}}
        <div class="entry entry-internal">

          <div class="entry-head">
            <div class="entry-title">{{ $entry['entry_title'] }}</div>
            @if(!empty($entry['entry_kind_label']))
              <div class="entry-kind">{{ $entry['entry_kind_label'] }}</div>
            @endif
          </div>

          <div class="entry-body">
            @if($entry['accepted_value'] !== null && $entry['accepted_value'] !== '')
              <table class="meta-table">
                <tr>
                  <td class="meta-lbl">Значение, принятое в расчёте</td>
                  <td class="meta-val">
                    <span class="price-val">{{ number_format((float) $entry['accepted_value'], 2, ',', '\u{00A0}') }} {{ $entry['currency'] }}</span>
                  </td>
                </tr>
              </table>
            @endif
            <div class="confirmation-line">{{ $entry['confirmation_note'] }}</div>
            <div class="attachment-note">{{ $entry['attachment_caption'] }}</div>
          </div>

        </div>
      @endif

    @endforeach

  @empty
    <div class="empty">Позиции не найдены.</div>
  @endforelse

  {{-- ══════════════════════════ ЗАКЛЮЧИТЕЛЬНЫЕ ПРИМЕЧАНИЯ ═══════════════════ --}}
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
