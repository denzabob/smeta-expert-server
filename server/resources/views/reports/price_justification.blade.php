<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8" />
  <title>Обоснование цен</title>
  <style>
    @page { size: A4; margin: 12mm; }
    body { font-family: "DejaVu Sans", sans-serif; font-size: 8.5pt; color: #111; line-height: 1.3; }
    h1 { font-size: 13pt; margin: 0 0 4mm 0; }
    .meta { margin: 0 0 4mm 0; color: #444; font-size: 7.5pt; line-height: 1.4; }
    .item { page-break-inside: avoid; margin-bottom: 5mm; border: 1px solid #ddd; padding: 3mm 3.5mm; }
    .title { font-weight: bold; font-size: 9pt; margin-bottom: 1.5mm; }
    .info { font-size: 7.5pt; color: #333; line-height: 1.5; margin-bottom: 1.5mm; }
    .info span { display: inline-block; margin-right: 6mm; }
    .shot { margin-top: 2mm; border: 1px solid #ccc; text-align: center; padding: 1.5mm; }
    .shot img { max-width: 100%; max-height: 160mm; }
    a { color: #1d4ed8; text-decoration: none; }
  </style>
</head>
<body>
  <h1>Обоснование цен</h1>
  <div class="meta">
    Проект: {{ $project->number }}&nbsp;&nbsp;
    Ревизия: {{ $revision->number }}&nbsp;&nbsp;
    Дата: {{ optional($revision->created_at)->format('d.m.Y H:i') }}
  </div>

  @forelse($rows as $row)
    <div class="item">
      <div class="title">{{ $row['name'] ?? ('Позиция #' . ($row['project_position_id'] ?? $row['project_fitting_id'] ?? '—')) }}</div>
      <div class="info">
        <span>Цена: <strong>{{ $row['price_per_unit'] }} {{ $row['currency'] }}</strong></span>
        @if(!empty($row['observed_at']))
          <span>Дата: {{ \Carbon\Carbon::parse($row['observed_at'])->format('d.m.Y') }}</span>
        @endif
        @if(!empty($row['source_url']))
          <span>Источник: <a href="{{ $row['source_url'] }}">{{ $row['source_url'] }}</a></span>
        @endif
      </div>

      <div class="shot">
        @if(!empty($row['screenshot_path']) && file_exists(storage_path('app/public/' . $row['screenshot_path'])))
          <img src="{{ storage_path('app/public/' . $row['screenshot_path']) }}" alt="screenshot" />
        @else
          Скриншот отсутствует
        @endif
      </div>
    </div>
  @empty
    <p>Нет данных обоснования цен в snapshot ревизии.</p>
  @endforelse
</body>
</html>
