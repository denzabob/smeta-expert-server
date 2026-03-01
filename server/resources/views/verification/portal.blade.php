<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Проверка неизменности документа</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Inter', 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background: #fff;
            color: #1d1d1f;
            line-height: 1.55;
            -webkit-font-smoothing: antialiased;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .page {
            max-width: 680px;
            margin: 0 auto;
            padding: 48px 24px 32px;
        }

        /* --- Header --- */
        .header { margin-bottom: 28px; }
        .header h1 {
            font-size: 20px;
            font-weight: 600;
            color: #1d1d1f;
            margin-bottom: 6px;
        }
        .header .subtitle {
            font-size: 13px;
            color: #86868b;
            letter-spacing: 0.2px;
            line-height: 1.45;
        }

        /* --- Divider --- */
        .divider {
            border: none;
            border-top: 1px solid #e5e5e5;
            margin: 22px 0;
        }

        /* --- Identification table --- */
        .id-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        .id-table tr + tr td { border-top: 1px solid #f0f0f0; }
        .id-table td {
            padding: 7px 0;
            vertical-align: top;
        }
        .id-table td:first-child {
            color: #86868b;
            width: 200px;
            padding-right: 16px;
            white-space: nowrap;
        }
        .id-table td:last-child {
            color: #1d1d1f;
        }
        .id-table .strong { font-weight: 600; }
        .qr-note {
            font-size: 12px;
            color: #86868b;
            margin-top: 8px;
        }

        /* --- Hash --- */
        .hash-section { margin-top: 0; }
        .hash-label {
            font-size: 12px;
            font-weight: 600;
            color: #86868b;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            margin-bottom: 10px;
        }
        .hash-row {
            display: flex;
            gap: 10px;
            align-items: flex-start;
        }
        .hash-value {
            font-family: ui-monospace, 'SF Mono', 'JetBrains Mono', 'Fira Code', 'Cascadia Code', 'Consolas', monospace;
            font-size: 16px;
            color: #1d1d1f;
            word-break: break-all;
            line-height: 1.75;
            letter-spacing: 0.35px;
            flex: 1;
        }
        .copy-btn {
            border: 1px solid #e5e5e5;
            background: #fff;
            color: #1d1d1f;
            font-size: 12px;
            padding: 6px 10px;
            border-radius: 8px;
            cursor: pointer;
            line-height: 1;
            user-select: none;
            transition: opacity 0.15s, border-color 0.15s;
            flex: 0 0 auto;
        }
        .copy-btn:hover { opacity: 0.7; border-color: #d6d6d6; }
        .copy-btn:active { opacity: 0.55; }
        .copy-btn:focus { outline: none; }
        .copy-status {
            margin-top: 8px;
            font-size: 12px;
            color: #86868b;
            min-height: 16px;
        }

        .hash-explain {
            margin-top: 12px;
            font-size: 13px;
            color: #6e6e73;
            line-height: 1.6;
        }
        .hash-explain p + p { margin-top: 2px; }

        /* --- How to verify --- */
        .howto {
            font-size: 13px;
            color: #6e6e73;
        }
        .howto-title {
            font-weight: 600;
            color: #1d1d1f;
            margin-bottom: 6px;
            font-size: 13px;
        }
        .howto ol {
            padding-left: 18px;
            margin: 0;
        }
        .howto ol li {
            margin-bottom: 3px;
            line-height: 1.55;
        }

        /* --- Download --- */
        .download-link {
            display: inline-block;
            font-size: 14px;
            color: #1d1d1f;
            text-decoration: none;
            border-bottom: 1px solid #1d1d1f;
            padding-bottom: 1px;
            transition: opacity 0.15s;
        }
        .download-link:hover { opacity: 0.6; }

        /* --- Tech details --- */
        .tech-details {
            margin-top: 10px;
        }
        .tech-details summary {
            font-size: 13px;
            color: #86868b;
            cursor: pointer;
            list-style: none;
            user-select: none;
        }
        .tech-details summary::-webkit-details-marker { display: none; }
        .tech-details summary::before { content: '▸ '; font-size: 10px; }
        .tech-details[open] summary::before { content: '▾ '; }
        .tech-details-body {
            margin-top: 10px;
            font-size: 13px;
            color: #6e6e73;
        }
        .tech-details-body table {
            border-collapse: collapse;
            width: 100%;
        }
        .tech-details-body td {
            padding: 3px 0;
            vertical-align: top;
        }
        .tech-details-body td:first-child {
            color: #86868b;
            width: 200px;
            padding-right: 12px;
            white-space: nowrap;
        }
        .tech-details-body .mono {
            font-family: ui-monospace, 'SF Mono', 'Consolas', monospace;
            font-size: 11px;
            word-break: break-all;
        }

        /* --- Footer --- */
        .footer {
            font-size: 11px;
            color: #c7c7cc;
            margin-top: 28px;
        }

        /* --- Print --- */
        @media print {
            body { padding: 0; }
            .page { padding: 16px 12px; max-width: 100%; }
            .download-link { display: none; }
            .tech-details { display: none; }
            .footer { display: none; }
            .copy-btn, .copy-status { display: none; }
            .divider { margin: 16px 0; }
        }

        /* --- Mobile --- */
        @media (max-width: 520px) {
            .page { padding: 28px 16px 20px; }
            .id-table td:first-child { width: auto; white-space: normal; }
            .header h1 { font-size: 17px; }
            .hash-value { font-size: 14px; }
        }
    </style>
</head>
<body>
<div class="page">
    @php
        $publicVerifyBaseUrl = rtrim((string) config('app.public_verify_base_url'), '/');
    @endphp

    <div class="header">
        <h1>Проверка неизменности документа</h1>
        <div class="subtitle">Зафиксированная версия · Система фиксации версий расчётов</div>
    </div>

    <hr class="divider">

    <table class="id-table">
        <tr><td>Документ</td><td>{{ $document['title'] }}</td></tr>
        <tr><td>Дело</td><td class="strong">{{ $document['project_number'] }}</td></tr>
        <tr><td>Объект</td><td>{{ $document['address'] }}</td></tr>
        <tr><td>Эксперт/специалист</td><td>{{ $document['expert_name'] }}</td></tr>
        <tr><td>Дата расчёта</td><td>{{ $document['created_at'] }}</td></tr>
        <tr><td>Дата фиксации</td><td class="strong">{{ $document['locked_at_tz'] }}</td></tr>
        <tr><td>Версия</td><td class="strong">{{ $document['revision_number'] }}</td></tr>
        @if($document['grand_total'])
            <tr><td>Итого</td><td class="strong">{{ number_format((float)$document['grand_total'], 2, ',', ' ') }} ₽</td></tr>
        @endif
        <tr><td>Источник данных</td><td>Зафиксированная версия №{{ $document['revision_number'] }}</td></tr>
    </table>
    <div class="qr-note">QR-код размещён в оригинале документа (стр.&nbsp;1 PDF)</div>

    <hr class="divider">

    <div class="hash-section">
        <div class="hash-label">Криптографический идентификатор (SHA-256)</div>

        <div class="hash-row">
            <div class="hash-value" id="hashValue">{{ $revision->snapshot_hash }}</div>
            <button class="copy-btn" type="button" id="copyBtn" aria-label="Скопировать хеш">Копировать</button>
        </div>
        <div class="copy-status" id="copyStatus" aria-live="polite"></div>

        <div class="hash-explain">
            <p>Хеш вычислен от зафиксированной версии документа.</p>
            <p>Любое изменение данных изменит это значение.</p>
            <p>Совпадение значения означает отсутствие изменений в документе после даты фиксации.</p>
        </div>
    </div>

    <hr class="divider">

    <div class="howto">
        <div class="howto-title">Как проверить</div>
        <ol>
            <li>Откройте PDF-версию документа.</li>
            <li>Сравните указанный в нём хеш со значением на этой странице.</li>
            <li>При совпадении версия не изменялась после даты фиксации.</li>
        </ol>
    </div>

    <hr class="divider">

    <a class="download-link" href="{{ $publicVerifyBaseUrl . '/v/' . $publication->public_id . '/pdf' }}">Скачать зафиксированную версию документа (PDF)</a>

    @if(!empty($priceSources))
      <hr class="divider">
      <div style="margin-bottom: 12px;">
        <div style="font-size: 13px; font-weight: 600; color: #1d1d1f; margin-bottom: 8px;">Источники ценовых данных</div>
        <div style="font-size: 12px; color: #86868b; margin-bottom: 10px;">
          Прайс-листы, использованные при расчёте стоимости.
        </div>
        <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
          <thead>
            <tr style="border-bottom: 1px solid #e5e5e5;">
              <th style="text-align: left; padding: 4px 0; color: #86868b; font-weight: 500;">Прайс-лист</th>
              <th style="text-align: left; padding: 4px 0; color: #86868b; font-weight: 500;">Тип</th>
              <th style="text-align: left; padding: 4px 0; color: #86868b; font-weight: 500;">Дата</th>
              <th style="text-align: left; padding: 4px 0; color: #86868b; font-weight: 500;">Файл / URL</th>
              <th style="text-align: left; padding: 4px 0; color: #86868b; font-weight: 500;">SHA-256</th>
            </tr>
          </thead>
          <tbody>
            @foreach($priceSources as $src)
              <tr style="border-bottom: 1px solid #f0f0f0;">
                <td style="padding: 5px 0;">{{ $src['price_list_name'] }} (v{{ $src['version_number'] ?? '?' }})</td>
                <td style="padding: 5px 0;">{{ $src['source_type'] ?? '—' }}</td>
                <td style="padding: 5px 0;">{{ $src['effective_date'] ?? $src['captured_at'] ?? '—' }}</td>
                <td style="padding: 5px 0; font-size: 11px; word-break: break-all;">
                  @if(!empty($src['source_url']))
                    <a href="{{ $src['source_url'] }}" style="color: #1d1d1f;">{{ \Illuminate\Support\Str::limit($src['source_url'], 40) }}</a>
                  @elseif(!empty($src['original_filename']))
                    @if(!empty($src['price_list_version_id']) && ($src['source_type'] ?? '') === 'file' && !empty($documentToken))
                      <a href="{{ $publicVerifyBaseUrl . '/public/price-file/' . $src['price_list_version_id'] . '/' . $documentToken }}" style="color: #1d1d1f;">{{ $src['original_filename'] }}</a>
                    @else
                      {{ $src['original_filename'] }}
                    @endif
                  @else
                    —
                  @endif
                </td>
                <td style="padding: 5px 0; font-family: ui-monospace, 'Consolas', monospace; font-size: 11px; word-break: break-all;">
                  @if(!empty($src['sha256']))
                    {{ substr($src['sha256'], 0, 16) }}…
                  @else
                    —
                  @endif
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    @endif

    @if(!empty($facadeQuoteEvidence))
      <hr class="divider">
      <div style="margin-bottom: 12px;">
        <div style="font-size: 13px; font-weight: 600; color: #1d1d1f; margin-bottom: 8px;">Котировки фасадов по группам позиций</div>
        <div style="font-size: 12px; color: #86868b; margin-bottom: 10px;">
          Детализация ценовых источников для групп позиций с одинаковым набором котировок и методом агрегации.
        </div>
        @php
          $facadeQuoteGroups = [];
          foreach ($facadeQuoteEvidence as $fqe) {
              $quotesForKey = array_map(function ($q) {
                  return [
                      'supplier_id' => $q['supplier_id'] ?? null,
                      'price_list_version_id' => $q['price_list_version_id'] ?? null,
                      'price_per_m2' => isset($q['price_per_m2']) ? (float) $q['price_per_m2'] : null,
                      'source_type' => $q['source_type'] ?? null,
                      'sha256' => $q['sha256'] ?? null,
                  ];
              }, $fqe['quotes'] ?? []);

              usort($quotesForKey, function ($a, $b) {
                  return strcmp(
                      json_encode($a, JSON_UNESCAPED_UNICODE),
                      json_encode($b, JSON_UNESCAPED_UNICODE)
                  );
              });

              $groupKey = md5(json_encode([
                  'price_method' => $fqe['price_method'] ?? 'single',
                  'quotes' => $quotesForKey,
              ], JSON_UNESCAPED_UNICODE));

              if (!isset($facadeQuoteGroups[$groupKey])) {
                  $facadeQuoteGroups[$groupKey] = [
                      'price_method' => $fqe['price_method'] ?? 'single',
                      'price_sources_count' => $fqe['price_sources_count'] ?? count($fqe['quotes'] ?? []),
                      'price_per_m2' => $fqe['price_per_m2'] ?? 0,
                      'price_min' => $fqe['price_min'] ?? null,
                      'price_max' => $fqe['price_max'] ?? null,
                      'quotes' => $fqe['quotes'] ?? [],
                      'positions' => [],
                  ];
              }

              $positionLabel = ($fqe['name'] ?? 'Фасад')
                  . ' — ' . ($fqe['detail_type'] ?? 'Деталь')
                  . ' (' . ($fqe['width'] ?? 0) . '×' . ($fqe['length'] ?? 0) . ' мм, '
                  . ($fqe['quantity'] ?? 1) . ' шт.)';

              $facadeQuoteGroups[$groupKey]['positions'][$positionLabel] = $positionLabel;
          }

          foreach ($facadeQuoteGroups as &$fqg) {
              $fqg['positions'] = array_values($fqg['positions']);
          }
          unset($fqg);
        @endphp

        @foreach($facadeQuoteGroups as $group)
          @php
            $mLabel = match($group['price_method'] ?? 'single') {
                'mean' => 'Среднее',
                'median' => 'Медиана',
                'trimmed_mean' => 'Усеч. среднее',
                default => $group['price_method'] ?? 'single',
            };
          @endphp
          <div style="font-size: 13px; font-weight: 500; margin-top: 10px; margin-bottom: 4px;">
            {{ $mLabel }} из {{ $group['price_sources_count'] ?? count($group['quotes']) }} источников
            → {{ number_format($group['price_per_m2'] ?? 0, 2, ',', ' ') }} ₽/м²
            @if(!empty($group['price_min']) && !empty($group['price_max']))
              <span style="color: #86868b;">({{ number_format($group['price_min'], 2, ',', ' ') }} – {{ number_format($group['price_max'], 2, ',', ' ') }})</span>
            @endif
          </div>

          @if(!empty($group['positions']))
            <div style="font-size: 12px; color: #4b5563; margin-bottom: 6px;">
              Позиции:
              {{ implode('; ', $group['positions']) }}
            </div>
          @endif

          <table style="width: 100%; border-collapse: collapse; font-size: 12px;">
            <thead>
              <tr style="border-bottom: 1px solid #e5e5e5;">
                <th style="text-align: left; padding: 3px 0; color: #86868b; font-weight: 500;">Поставщик</th>
                <th style="text-align: left; padding: 3px 0; color: #86868b; font-weight: 500;">Прайс-лист</th>
                <th style="text-align: right; padding: 3px 0; color: #86868b; font-weight: 500;">Цена/м²</th>
                <th style="text-align: left; padding: 3px 0; color: #86868b; font-weight: 500;">Тип</th>
                <th style="text-align: left; padding: 3px 0; color: #86868b; font-weight: 500;">Дата</th>
                <th style="text-align: left; padding: 3px 0; color: #86868b; font-weight: 500;">SHA-256</th>
              </tr>
            </thead>
            <tbody>
              @foreach($group['quotes'] as $q)
                <tr style="border-bottom: 1px solid #f0f0f0;">
                  <td style="padding: 3px 0;">{{ $q['supplier_name'] ?? '—' }}</td>
                  <td style="padding: 3px 0;">{{ $q['price_list_name'] }} (v{{ $q['version_number'] ?? '?' }})</td>
                  <td style="padding: 3px 0; text-align: right; font-family: ui-monospace, 'Consolas', monospace;">{{ number_format($q['price_per_m2'], 2, ',', ' ') }}</td>
                  <td style="padding: 3px 0;">
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
                  <td style="padding: 3px 0;">{{ $q['effective_date'] ?? '—' }}</td>
                  <td style="padding: 3px 0; font-family: ui-monospace, 'Consolas', monospace; font-size: 11px; word-break: break-all;">
                    @if(!empty($q['sha256']))
                      {{ substr($q['sha256'], 0, 16) }}…
                    @else
                      —
                    @endif
                  </td>
                </tr>
              @endforeach
            </tbody>
          </table>
        @endforeach
      </div>
    @endif

    @if(!empty($supplierSources) && count($supplierSources) > 1)
      <hr class="divider">
      <div style="margin-bottom: 12px;">
        <div style="font-size: 13px; font-weight: 600; color: #1d1d1f; margin-bottom: 8px;">Источники по поставщикам</div>
        <div style="font-size: 12px; color: #86868b; margin-bottom: 10px;">
          Все ценовые источники, использованные в расчёте, сгруппированные по поставщику.
        </div>
        @foreach($supplierSources as $supplierKey => $sources)
          @if($supplierKey === '__general__')
            @continue
          @endif
          <div style="font-size: 13px; font-weight: 500; margin-top: 10px; margin-bottom: 4px;">
            {{ $supplierKey }}
          </div>
          <table style="width: 100%; border-collapse: collapse; font-size: 12px;">
            <thead>
              <tr style="border-bottom: 1px solid #e5e5e5;">
                <th style="text-align: left; padding: 3px 0; color: #86868b; font-weight: 500;">Прайс-лист</th>
                <th style="text-align: left; padding: 3px 0; color: #86868b; font-weight: 500;">Тип</th>
                <th style="text-align: left; padding: 3px 0; color: #86868b; font-weight: 500;">Дата</th>
                <th style="text-align: left; padding: 3px 0; color: #86868b; font-weight: 500;">Файл / URL</th>
                <th style="text-align: left; padding: 3px 0; color: #86868b; font-weight: 500;">SHA-256</th>
              </tr>
            </thead>
            <tbody>
              @foreach($sources as $src)
                <tr style="border-bottom: 1px solid #f0f0f0;">
                  <td style="padding: 3px 0;">{{ $src['price_list_name'] }} (v{{ $src['version_number'] ?? '?' }})</td>
                  <td style="padding: 3px 0;">{{ $src['source_type'] ?? '—' }}</td>
                  <td style="padding: 3px 0;">{{ $src['effective_date'] ?? '—' }}</td>
                  <td style="padding: 3px 0; font-size: 11px; word-break: break-all;">
                    @if(!empty($src['source_url']))
                      <a href="{{ $src['source_url'] }}" style="color: #1d1d1f;">{{ \Illuminate\Support\Str::limit($src['source_url'], 40) }}</a>
                    @elseif(!empty($src['original_filename']))
                      @if(!empty($src['price_list_version_id']) && ($src['source_type'] ?? '') === 'file' && !empty($documentToken))
                        <a href="{{ $publicVerifyBaseUrl . '/public/price-file/' . $src['price_list_version_id'] . '/' . $documentToken }}" style="color: #1d1d1f;">{{ $src['original_filename'] }}</a>
                      @else
                        {{ $src['original_filename'] }}
                      @endif
                    @else
                      —
                    @endif
                  </td>
                  <td style="padding: 3px 0; font-family: ui-monospace, 'Consolas', monospace; font-size: 11px; word-break: break-all;">
                    @if(!empty($src['sha256']))
                      {{ substr($src['sha256'], 0, 16) }}…
                    @else
                      —
                    @endif
                  </td>
                </tr>
              @endforeach
            </tbody>
          </table>
        @endforeach
      </div>
    @endif

    <details class="tech-details">
        <summary>Техническая информация</summary>
        <div class="tech-details-body">
            <table>
                <tr><td>Алгоритм</td><td>SHA-256</td></tr>
                <tr><td>Дата и время фиксации</td><td>{{ $document['locked_at_tz'] }}</td></tr>
                <tr><td>Идентификатор версии</td><td class="mono">{{ $revision->id }}</td></tr>
                <tr><td>Статус</td><td>Версия зафиксирована</td></tr>
            </table>
        </div>
    </details>

    <div class="footer">Адрес этой страницы постоянный и не требует авторизации.</div>

</div>

<script>
(function () {
    const hashEl = document.getElementById('hashValue');
    const btn = document.getElementById('copyBtn');
    const status = document.getElementById('copyStatus');

    if (!hashEl || !btn || !status) return;

    function setStatus(text) {
        status.textContent = text;
        if (text) {
            window.clearTimeout(setStatus._t);
            setStatus._t = window.setTimeout(() => { status.textContent = ''; }, 1800);
        }
    }

    async function copyToClipboard(text) {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(text);
            return;
        }
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', '');
        ta.style.position = 'absolute';
        ta.style.left = '-9999px';
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
    }

    btn.addEventListener('click', async () => {
        const value = (hashEl.textContent || '').trim();
        if (!value) return;

        try {
            await copyToClipboard(value);
            setStatus('Скопировано');
        } catch (e) {
            setStatus('Не удалось скопировать');
        }
    });
})();
</script>
</body>
</html>
