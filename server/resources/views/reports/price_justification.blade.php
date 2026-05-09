<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8" />
  <title>Обоснование цен</title>
  @php
    $reportSettings = is_array($reportSettings ?? null) ? $reportSettings : [];
    $label = static fn (string $path, string $default): string => (string) data_get($reportSettings, $path, $default);

    $isMeaningful = static function (mixed $value): bool {
      if ($value === null) return false;
      if (is_string($value)) {
        $trimmed = trim($value);
        return $trimmed !== '' && $trimmed !== '—' && $trimmed !== '-';
      }
      return true;
    };

    $formatMoney = static function (mixed $value, ?string $unit = null): ?string {
      if ($value === null || $value === '') return null;
      $suffix = $unit ? '/' . $unit : '';
      return number_format((float) $value, 2, ',', ' ') . ' ₽' . $suffix;
    };

    $formatDate = static function (mixed $value): ?string {
      if (!$value) return null;
      try {
        return \Carbon\Carbon::parse($value)->format('d.m.Y');
      } catch (\Throwable) {
        return is_string($value) ? $value : null;
      }
    };

    $cleanUrl = static function (?string $url): ?string {
      if (!$url) return null;
      $url = trim($url);
      if ($url === '' || $url === '—') return null;

      $parts = parse_url($url);
      if (!is_array($parts) || empty($parts['host'])) return $url;

      $blocked = array_flip(['utm_source','utm_medium','utm_campaign','utm_content','utm_term','yclid','gclid','fbclid','at','ref','referrer','from']);
      $query = [];
      if (!empty($parts['query'])) {
        parse_str($parts['query'], $query);
        $query = array_filter(
          $query,
          static fn ($value, $key) => !isset($blocked[mb_strtolower((string) $key)]),
          ARRAY_FILTER_USE_BOTH
        );
      }

      $scheme = $parts['scheme'] ?? 'https';
      $host = $parts['host'];
      $port = isset($parts['port']) ? ':' . $parts['port'] : '';
      $path = $parts['path'] ?? '';
      $normalized = $scheme . '://' . $host . $port . $path;
      if ($query !== []) {
        $normalized .= '?' . http_build_query($query);
      }
      if (!empty($parts['fragment'])) {
        $normalized .= '#' . $parts['fragment'];
      }

      return $normalized;
    };

    $displayUrl = static function (?string $url) use ($cleanUrl): ?string {
      $clean = $cleanUrl($url);
      if (!$clean) return null;

      $parts = parse_url($clean);
      if (!is_array($parts) || empty($parts['host'])) return $clean;

      $host = preg_replace('/^www\./', '', $parts['host']);
      $path = $parts['path'] ?? '';
      if (mb_strlen($host . $path) > 56) {
        $segments = array_values(array_filter(explode('/', trim($path, '/'))));
        $first = $segments[0] ?? '';
        $last = end($segments) ?: '';
        if (preg_match('/(\d{6,})$/u', $last, $match)) {
          $last = '...' . $match[1];
        } elseif (mb_strlen($last) > 34) {
          $last = mb_substr($last, 0, 18) . '...' . mb_substr($last, -10);
        }

        return $host . ($first ? '/' . $first : '') . ($last ? '/...' . $last : '');
      }

      return $host . $path;
    };

    $assetOpenUrl = static function (array $asset): ?string {
      if (empty($asset['asset_id'])) return null;

      return url('/api/finished-product-price-evidence-assets/' . (int) $asset['asset_id'] . '/open');
    };

    $assetStoragePath = static function (array $asset): ?string {
      $path = $asset['file_path'] ?? data_get($asset, 'storage_reference.path');
      if (!$path || !is_string($path)) return null;

      $absolutePath = storage_path('app/public/' . ltrim($path, '/'));

      return file_exists($absolutePath) ? $absolutePath : null;
    };

    $moneyForPdf = static fn (?string $value): ?string => $value
      ? str_replace([' руб./м²', ' руб.'], [' ₽/м²', ' ₽'], $value)
      : null;

    $compactFacadeParameters = static function (array $identity): ?string {
      $characteristics = (array) ($identity['characteristics'] ?? []);
      $parts = [];

      foreach (['base_type', 'covering', 'cover_type', 'collection', 'decor_label'] as $key) {
        if (!empty($characteristics[$key])) {
          $parts[] = (string) $characteristics[$key];
        }
      }
      if (!empty($characteristics['thickness_mm'])) {
        $parts[] = $characteristics['thickness_mm'] . ' мм';
      }

      $parts = array_values(array_unique(array_filter($parts)));

      return $parts === [] ? null : implode(', ', $parts);
    };

    $driverLabels = [
      'plate' => 'Плита',
      'edge' => 'Кромка',
      'facade' => 'Фасад',
      'fitting' => 'Фурнитура',
      'operation' => 'Операция',
      'labor_work' => 'Работа',
      'expense' => 'Расход',
    ];

    $reasonLabels = [
      'no_source_url' => $label('evidence_reasons.no_source_url', 'нет ссылки на источник цены'),
      'no_screenshot_or_document' => $label('evidence_reasons.no_screenshot_or_document', 'нет скриншота или документа'),
      'outdated_price' => $label('evidence_reasons.outdated_price_confirmation', 'подтверждение цены устарело'),
      'outdated_price_confirmation' => $label('evidence_reasons.outdated_price_confirmation', 'подтверждение цены устарело'),
      'outdated_screenshot' => 'скриншот устарел',
      'price_mismatch' => 'цена в подтверждении отличается от цены в смете',
      'no_linked_material' => 'позиция не связана с материалом каталога',
      'no_evidence_record' => $label('evidence_reasons.no_linked_evidence', 'нет связанного подтверждения цены'),
      'parse_failed' => 'ошибка обновления цены',
      'source_unavailable' => 'источник цены недоступен',
    ];

    $formatReason = static function (mixed $value) use ($reasonLabels): string {
      $items = is_array($value) ? $value : [$value];
      $labels = collect($items)
        ->filter()
        ->map(fn ($reason) => $reasonLabels[(string) $reason] ?? (string) $reason)
        ->filter()
        ->values();

      return $labels->isNotEmpty() ? $labels->implode('; ') : 'не указана';
    };

    $internalTypes = ['operation', 'labor_work', 'expense'];
    $internalRows = collect($rows)->filter(fn ($row) => in_array($row['cost_driver_type'] ?? null, $internalTypes, true))->values();
    $evidenceRows = collect($rows)->reject(fn ($row) => in_array($row['cost_driver_type'] ?? null, $internalTypes, true))->values();
    $missingRows = collect(data_get($evidenceSummary, 'missing_items') ?? data_get($evidenceSummary, 'missing') ?? [])->values();
    $missingCount = $missingRows->count();

    $dates = collect($rows)
      ->pluck('observed_at')
      ->filter()
      ->map(function ($value) {
        try { return \Carbon\Carbon::parse($value); } catch (\Throwable) { return null; }
      })
      ->filter()
      ->values();
    $periodText = null;
    if ($dates->isNotEmpty()) {
      $minDate = $dates->min();
      $maxDate = $dates->max();
      $periodText = $minDate->isSameDay($maxDate)
        ? $minDate->format('d.m.Y')
        : $minDate->format('d.m.Y') . ' — ' . $maxDate->format('d.m.Y');
    }
  @endphp

  <style>
    @page {
      size: A4;
      margin: 5mm 10mm 10mm 25mm; /* top right bottom left */
    }

    body {
      font-family: "DejaVu Sans", sans-serif;
      font-size: 8pt;
      line-height: 1.18;
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
      margin: 0 0 3mm 0;
      padding: 0 0 2mm 0;
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
      display: block;
      width: 100%;
      margin: 0 0 3.2mm 0;
      border: 1px solid #d7d7d7;
      border-left: 2px solid #9a9a9a;
      background: #fff;
      page-break-inside: avoid;
      break-inside: avoid;
      font-size: 8pt;
    }

    .item-head {
      padding: 1.35mm 2mm 0.9mm 2mm;
      background: #fafafa;
      border-bottom: 1px solid #e4e4e4;
    }

    .item-title {
      margin: 0;
      font-size: 8.4pt;
      line-height: 1.12;
      font-weight: 800;
      color: #111;
      word-break: break-word;
    }

    .item-body {
      padding: 1.2mm 2mm 1.8mm 2mm;
    }

    .card-meta {
      margin: 0 0 1.1mm 0;
      font-size: 7.6pt;
      line-height: 1.14;
      color: #111;
      word-break: break-word;
      overflow-wrap: anywhere;
    }

    .meta-line { margin: 0 0 0.35mm 0; }
    .meta-line:last-child { margin-bottom: 0; }
    .meta-key {
      font-weight: 700;
      color: #333;
    }

    .compact-source {
      font-size: 7.2pt;
      line-height: 1.08;
    }

    .price-badge {
      display: inline-block;
      padding: 0.35mm 1.2mm;
      border: 1px solid #cfcfcf;
      background: #f5f5f5;
      font-weight: 700;
      font-size: 7.3pt;
      line-height: 1.1;
      white-space: nowrap;
    }

    .shot-wrap {
      border: 1px solid #dcdcdc;
      background: #fcfcfc;
      padding: 0.8mm;
      text-align: center;
    }

    .shot-wrap img {
      display: block;
      max-width: 100%;
      max-height: 95mm;
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

    .empty {
      border: 1px dashed #cfcfcf;
      padding: 5mm;
      text-align: center;
      color: #666;
      font-size: 9pt;
      margin-top: 6mm;
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

    .snapshot-summary {
      border: 1px solid #dcdcdc;
      background: #fafafa;
      padding: 0.9mm 1.2mm;
      font-size: 7.2pt;
      line-height: 1.12;
    }
    .snapshot-summary div { margin: 0 0 0.45mm 0; }
    .snapshot-summary div:last-child { margin-bottom: 0; }

    .file-list {
      margin-top: 0.8mm;
      font-size: 7pt;
      line-height: 1.12;
    }

    .file-line {
      margin: 0 0 0.35mm 0;
      word-break: break-word;
    }

    .internal-table,
    .missing-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 7.5pt;
      margin: 0 0 3mm 0;
    }

    .internal-table th,
    .internal-table td,
    .missing-table th,
    .missing-table td {
      border: 1px solid #ddd;
      padding: 0.9mm 1.2mm;
      vertical-align: top;
      line-height: 1.12;
    }

    .internal-table th,
    .missing-table th {
      background: #f4f4f4;
      font-weight: 700;
      text-align: left;
    }

    .confirmation-note {
      border: 1px solid #d8e3d8;
      background: #f5faf5;
      padding: 1mm 1.3mm;
      font-size: 7.2pt;
      color: #223f22;
    }
  </style>
</head>
<body>
  <div class="container">
    <div class="header">
      <h1 class="header-title">{{ $label('price_evidence_report.title', 'Документ подтверждения цен') }}</h1>
      <div class="section-note" style="text-align:center;margin-bottom:2mm;">
        {{ $label('price_evidence_report.subtitle', 'Источники, скриншоты и файлы, подтверждающие стоимость позиций сметы.') }}
      </div>
      <table class="header-meta">
        <tr>
          <td class="left">{{ $label('price_evidence_report.project_label', 'Проект') }}: <span class="bold">{{ $project->name ?? $project->number ?? '—' }}</span></td>
          <td class="center">{{ $label('price_evidence_report.report_version_label', 'Версия отчета') }}: <span class="bold">{{ $revision->number ?? '—' }}</span></td>
          <td class="right">{{ $label('price_evidence_report.report_created_at_label', 'Дата формирования отчета') }}: <span class="bold">{{ now()->format('d.m.Y') }}</span></td>
        </tr>
        <tr>
          <td class="left">{{ $label('price_evidence_report.total_items_label', 'Всего позиций') }}: {{ $evidenceSummary['total_items'] ?? count($rows) }}</td>
          <td class="center">{{ $label('price_evidence_report.confirmed_items_label', 'Подтверждено') }}: {{ $evidenceSummary['with_evidence'] ?? count($rows) }}</td>
          <td class="right">{{ $label('price_evidence_report.missing_items_label', 'Без подтверждения') }}: {{ $missingCount }}</td>
        </tr>
        @if($periodText)
          <tr>
            <td colspan="3" class="center">{{ $label('price_evidence_report.fixation_period_label', 'Период фиксации цен') }}: {{ $periodText }}</td>
          </tr>
        @endif
      </table>
    </div>

    @if($missingCount > 0)
      <div class="section-title">{{ $label('price_evidence_report.missing_evidence_section_title', 'Позиции без подтверждения цены') }}</div>
      <table class="missing-table">
        <thead>
          <tr>
            <th>Наименование</th>
            <th style="width:18%;">Раздел</th>
            <th style="width:18%;">Цена</th>
            <th style="width:25%;">Причина</th>
          </tr>
        </thead>
        <tbody>
          @if($missingRows->isNotEmpty())
            @foreach($missingRows as $missing)
              @php
                $missingName = $missing['name'] ?? $missing['item_name'] ?? $missing['title'] ?? 'Позиция';
                $missingType = $driverLabels[$missing['cost_driver_type'] ?? $missing['component'] ?? ''] ?? ($missing['component'] ?? '—');
                $missingPrice = $formatMoney($missing['price_per_unit'] ?? $missing['estimate_price'] ?? $missing['price'] ?? null, $missing['unit'] ?? null);
                $missingReason = $formatReason($missing['reasons'] ?? $missing['reason'] ?? null);
              @endphp
              <tr>
                <td>{{ $missingName }}</td>
                <td>{{ $missingType }}</td>
                <td>{{ $missingPrice ?? '—' }}</td>
                <td>{{ $missingReason }}</td>
              </tr>
            @endforeach
          @endif
        </tbody>
      </table>
    @endif

    @if($internalRows->isNotEmpty())
      <div class="section-title">{{ $label('price_evidence_report.internal_calculation_section_title', 'Позиции, рассчитанные внутренним способом') }}</div>
      <table class="internal-table">
        <thead>
          <tr>
            <th>Наименование</th>
            <th style="width:16%;">Раздел</th>
            <th style="width:10%;">Ед.</th>
            <th style="width:18%;">Цена</th>
            <th style="width:24%;">Основание</th>
          </tr>
        </thead>
        <tbody>
          @foreach($internalRows as $row)
            <tr>
              <td>{{ $row['name'] ?? 'Позиция' }}</td>
              <td>{{ $driverLabels[$row['cost_driver_type'] ?? ''] ?? 'Внутренний расчет' }}</td>
              <td>{{ $row['unit'] ?? (($row['cost_driver_type'] ?? null) === 'labor_work' ? 'н/ч' : '—') }}</td>
              <td>{{ $formatMoney($row['price_per_unit'] ?? null, $row['unit'] ?? null) ?? '—' }}</td>
              <td>{{ $label('price_evidence_report.internal_calculation_basis_text', 'внутренний расчет; скриншот не требуется') }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @endif

    <div class="section-title">{{ $label('price_evidence_report.materials_evidence_section_title', 'Материалы и ценовые подтверждения') }}</div>

    <div class="cards">
    @forelse($evidenceRows as $row)
      @php
        $sourceUrl = $cleanUrl($row['source_url'] ?? null);
        $sourceDisplay = $displayUrl($row['source_url'] ?? null);
        $unit = $row['unit'] ?? null;
        $priceText = $formatMoney($row['price_per_unit'] ?? null, $unit);
        $article = $isMeaningful($row['article'] ?? null) ? trim((string) $row['article']) : null;
        $driverText = $driverLabels[$row['cost_driver_type'] ?? ''] ?? ($row['cost_driver_type'] ?? null);
        $isFacadeSnapshot = ($row['reference_type'] ?? null) === 'snapshot_summary' && ($row['cost_driver_type'] ?? null) === 'facade';
        $presentation = $isFacadeSnapshot ? ($row['facade_snapshot_presentation'] ?? []) : [];
        $identity = $presentation['facade_identity'] ?? [];
        $sources = $presentation['sources'] ?? [];
        $facadeSourceName = collect($sources)
          ->pluck('supplier_name')
          ->first(fn ($value) => $isMeaningful($value));
        $facadeParams = $isFacadeSnapshot ? $compactFacadeParameters($identity) : null;
      @endphp
      <div class="item">
        <div class="item-head">
          <div class="item-title">
            {{ $row['name'] ?? ('Позиция #' . ($row['project_position_id'] ?? $row['project_fitting_id'] ?? '—')) }}
          </div>
        </div>

        <div class="item-body">
          <div class="card-meta">
            @if($article || $isMeaningful($unit))
              <div class="meta-line">
                @if($article) <span class="meta-key">Арт.:</span> {{ $article }}@endif
                @if($article && $isMeaningful($unit)) · @endif
                @if($isMeaningful($unit)) <span class="meta-key">Ед.:</span> {{ $unit }}@endif
              </div>
            @elseif($isFacadeSnapshot)
              <div class="meta-line"><span class="meta-key">Ед.:</span> м²</div>
            @endif

            @if($isMeaningful($driverText))
              <div class="meta-line"><span class="meta-key">Раздел:</span> {{ $driverText }}</div>
            @endif

            @if($sourceUrl && $sourceDisplay)
              <div class="meta-line compact-source"><span class="meta-key">Источник:</span> <a href="{{ $sourceUrl }}">{{ $sourceDisplay }}</a></div>
            @elseif($isMeaningful($facadeSourceName))
              <div class="meta-line"><span class="meta-key">Источник:</span> {{ $facadeSourceName }}</div>
            @endif

            @if($priceText)
              <div class="meta-line"><span class="meta-key">Цена:</span> <span class="price-badge">{{ $priceText }}</span></div>
            @endif

            @if($isMeaningful($facadeParams))
              <div class="meta-line"><span class="meta-key">Параметры:</span> {{ $facadeParams }}</div>
            @endif
          </div>

          @if($isFacadeSnapshot)
            @php
              $facadeAssets = collect($sources)
                ->flatMap(fn ($source) => (array) ($source['evidence_assets'] ?? []))
                ->values();
              $previewAsset = $facadeAssets->first(function (array $asset) use ($assetStoragePath): bool {
                $mime = (string) ($asset['mime_type'] ?? '');
                return str_starts_with($mime, 'image/') && $assetStoragePath($asset) !== null;
              });
            @endphp
            @if($facadeAssets->isNotEmpty())
            <div class="snapshot-summary">
                @foreach($facadeAssets as $asset)
                  @php
                    $assetLabel = $asset['display_label'] ?? $asset['original_name'] ?? $asset['source_url'] ?? null;
                    $assetUrl = $cleanUrl($asset['source_url'] ?? null) ?: $assetOpenUrl($asset);
                    $extension = mb_strtolower(pathinfo((string) ($asset['original_name'] ?? $assetLabel ?? ''), PATHINFO_EXTENSION));
                    $assetType = $asset['asset_type'] ?? null;
                    $assetKind = match (true) {
                      $assetType === 'link' => 'Источник',
                      in_array($assetType, ['screenshot', 'image'], true) => 'Скриншот',
                      in_array($extension, ['xls', 'xlsx', 'csv', 'ods'], true) => 'Прайс',
                      default => 'Файл',
                    };
                  @endphp
                  @if($isMeaningful($assetLabel))
                    <div class="file-line">
                      <span class="meta-key">{{ $assetKind }}:</span>
                      @if($assetUrl)
                        <a href="{{ $assetUrl }}">{{ $assetLabel }}</a>
                      @else
                        {{ $assetLabel }}
                      @endif
                    </div>
                  @endif
                @endforeach
            </div>
            @endif
            @if($previewAsset)
              <div class="shot-wrap" style="margin-top:1mm;">
                <img src="{{ $assetStoragePath($previewAsset) }}" alt="evidence" />
              </div>
            @endif
          @else
            @php
              $screenshotPath = !empty($row['screenshot_path']) && file_exists(storage_path('app/public/' . $row['screenshot_path']))
                ? storage_path('app/public/' . $row['screenshot_path'])
                : null;
            @endphp
            @if($screenshotPath)
              <div class="shot-wrap">
                <img src="{{ storage_path('app/public/' . $row['screenshot_path']) }}" alt="screenshot" />
              </div>
            @elseif($sourceUrl)
              <div class="confirmation-note">Подтверждение: источник цены указан.</div>
            @endif
          @endif
        </div>
      </div>
    @empty
      <div class="empty">
        Нет данных подтверждения цен в сохраненной версии отчета.
      </div>
    @endforelse
    </div>
  </div>
</body>
</html>
