import ApexCharts from 'apexcharts'
import {
  CALCULATION_FAILED_EVENT,
  CALCULATION_STARTED_EVENT,
  CALCULATION_SUCCEEDED_EVENT,
  formatPeriod,
  type PublicCalculationResult,
} from './shared'
import { trackPublicIndicesEvent, type PublicIndicesAnalyticsParams } from './analytics'

export type PublicPriceIndexChartPayload = {
  series: { slug: string; title: string; code: string | null; family: string }
  points: Array<{ period: string; display_period: string; value: string | null; sequence: number }>
  limits: {
    first_available_period: string | null
    last_available_period: string | null
    calculator_max_range_months: number
  }
}

export type ChartPoint = { x: string; y: number | null; period: string }
export type ChartRange = '1y' | '3y' | '5y' | '10y' | 'all'

const RANGE_MONTHS: Record<Exclude<ChartRange, 'all'>, number> = {
  '1y': 12,
  '3y': 36,
  '5y': 60,
  '10y': 120,
}

function periodNumber(period: string): number | null {
  const match = /^(\d{4})-(\d{2})$/.exec(period)
  if (!match) return null
  const month = Number(match[2])
  if (month < 1 || month > 12) return null

  return Number(match[1]) * 12 + month - 1
}

export function pointsForRange(
  payload: PublicPriceIndexChartPayload,
  range: ChartRange,
): PublicPriceIndexChartPayload['points'] {
  if (range === 'all' || payload.points.length === 0) return payload.points

  const lastPeriod = periodNumber(payload.points[payload.points.length - 1]?.period ?? '')
  if (lastPeriod === null) return payload.points
  const firstPeriod = lastPeriod - RANGE_MONTHS[range] + 1

  return payload.points.filter((point) => {
    const period = periodNumber(point.period)
    return period !== null && period >= firstPeriod && period <= lastPeriod
  })
}

export function monthlyPoints(
  payload: PublicPriceIndexChartPayload,
  range: ChartRange = 'all',
): ChartPoint[] {
  return pointsForRange(payload, range).map((point) => ({
    x: point.display_period,
    y: point.value === null ? null : Number(point.value),
    period: point.period,
  }))
}

export function cumulativePoints(result: PublicCalculationResult): ChartPoint[] {
  return [
    { x: formatPeriod(result.period.start), y: 100, period: result.period.start },
    ...result.chain.map((factor) => ({
      x: formatPeriod(factor.period),
      y: Math.round(Number(factor.running_coefficient) * 100 * 1e10) / 1e10,
      period: factor.period,
    })),
  ]
}

export function cumulativePointsForRange(
  payload: PublicPriceIndexChartPayload,
  range: ChartRange,
): ChartPoint[] {
  const points = pointsForRange(payload, range)
  let runningCoefficient = 1
  let chainIsComplete = true

  return points.map((point, index) => {
    if (index === 0) {
      return { x: point.display_period, y: 100, period: point.period }
    }
    if (point.value === null || !chainIsComplete) {
      chainIsComplete = false
      return { x: point.display_period, y: null, period: point.period }
    }

    runningCoefficient = Math.round(runningCoefficient * (Number(point.value) / 100) * 1e12) / 1e12

    return {
      x: point.display_period,
      y: Math.round(runningCoefficient * 100 * 1e10) / 1e10,
      period: point.period,
    }
  })
}

export function chartPointsForMode(
  payload: PublicPriceIndexChartPayload,
  mode: 'monthly' | 'cumulative',
  result: PublicCalculationResult | null,
  range: ChartRange = 'all',
): ChartPoint[] {
  if (mode === 'monthly') return monthlyPoints(payload, range)
  if (payload.series.family === 'consumer_prices') return cumulativePointsForRange(payload, range)

  return result ? cumulativePoints(result) : []
}

export function chartHeight(viewportWidth: number): number {
  return viewportWidth <= 480 ? 300 : 380
}

export function formatIndex(value: number): string {
  return new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 10 }).format(value)
}

export function formatIndexChange(value: number): string {
  const change = Math.round((value - 100) * 1e10) / 1e10
  if (change === 0) return '0 %'
  return `${change < 0 ? '−' : '+'}${formatIndex(Math.abs(change))} %`
}

export function escapeHtml(value: string): string {
  return value.replace(/[&<>"']/g, (character) => {
    const entities: Record<string, string> = {
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#039;',
    }
    return entities[character] ?? character
  })
}

function rangeAnnotations(start: string, end: string): Array<Record<string, unknown>> {
  return [
    { x: formatPeriod(start), borderColor: '#315fb5', strokeDashArray: 4, label: { text: 'Начало' } },
    { x: formatPeriod(end), borderColor: '#315fb5', strokeDashArray: 4, label: { text: 'Конец' } },
  ]
}

function options(
  points: ChartPoint[],
  mode: 'monthly' | 'cumulative',
  viewportWidth: number,
  selectedRange?: { start: string; end: string },
): ApexCharts.ApexOptions {
  return {
    chart: {
      type: 'line',
      height: chartHeight(viewportWidth),
      fontFamily: 'Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif',
      toolbar: { show: false },
      animations: { enabled: false },
      zoom: { enabled: false },
    },
    series: [{ name: mode === 'monthly' ? 'Индекс за месяц' : 'Накопленное изменение', data: points }],
    colors: ['#315fb5'],
    stroke: { curve: 'straight', width: 2.5 },
    markers: { size: points.length <= 72 ? 3 : 0, hover: { sizeOffset: 3 } },
    dataLabels: { enabled: false },
    noData: { text: mode === 'monthly' ? 'Нет значений для отображения' : 'Выполните расчёт для выбранного периода' },
    xaxis: {
      type: 'category',
      labels: { rotate: -45, hideOverlappingLabels: true, trim: false },
      tickAmount: viewportWidth <= 480 ? 5 : 10,
      tooltip: { enabled: false },
    },
    yaxis: {
      decimalsInFloat: 2,
      labels: { formatter: (value) => `${formatIndex(value)} %` },
    },
    annotations: {
      yaxis: [
        {
          y: 100,
          borderColor: '#697386',
          strokeDashArray: 5,
          label: { text: '100 %', style: { color: '#ffffff', background: '#697386' } },
        },
      ],
      xaxis: selectedRange ? rangeAnnotations(selectedRange.start, selectedRange.end) : [],
    },
    tooltip: {
      shared: false,
      intersect: true,
      custom: ({ dataPointIndex }) => {
        const point = points[dataPointIndex]
        if (!point || point.y === null) return '<div class="price-chart-tooltip">Нет значения</div>'
        const label = mode === 'monthly' ? 'Индекс' : 'Накопленное значение'
        const changeLabel = mode === 'monthly' ? 'Изменение' : 'Изменение от начала'
        return `<div class="price-chart-tooltip"><strong>${escapeHtml(point.x)}</strong><span>${label}: ${formatIndex(point.y)} %</span><span>${changeLabel}: ${formatIndexChange(point.y)}</span></div>`
      },
    },
    grid: { borderColor: '#d9dee8', padding: { left: 8, right: 8 } },
    responsive: [{ breakpoint: 480, options: { legend: { show: false } } }],
  }
}

export function initializePublicPriceIndexChart(): boolean {
  const container = document.querySelector<HTMLElement>('[data-public-index-chart]')
  const payloadNode = document.getElementById('public-price-index-chart-data')
  const form = document.querySelector<HTMLFormElement>('[data-public-index-calculator]')
  const buttons = Array.from(document.querySelectorAll<HTMLButtonElement>('[data-chart-mode]'))
  const rangeButtons = Array.from(document.querySelectorAll<HTMLButtonElement>('[data-chart-range]'))
  const status = document.querySelector<HTMLElement>('[data-chart-status]')
  if (!container || !payloadNode || !form || buttons.length !== 2 || !status) return false

  let payload: PublicPriceIndexChartPayload
  try {
    payload = JSON.parse(payloadNode.textContent || '') as PublicPriceIndexChartPayload
  } catch {
    status.textContent = 'Не удалось подготовить график. Все значения доступны в таблице ниже.'
    return false
  }

  const start = form.elements.namedItem('start_period') as HTMLSelectElement | null
  const end = form.elements.namedItem('end_period') as HTMLSelectElement | null
  if (!start || !end) return false

  const isConsumer = payload.series.family === 'consumer_prices'
  let mode: 'monthly' | 'cumulative' = 'monthly'
  let range: ChartRange = isConsumer ? '5y' : 'all'
  let lastResult: PublicCalculationResult | null = null
  const analyticsContext = (): PublicIndicesAnalyticsParams => ({
    family: form.dataset.indexFamily,
    series: form.dataset.indexSeries,
  })
  const startedAt = performance.now()
  const chart = new ApexCharts(
    container,
    options(
      monthlyPoints(payload, range),
      mode,
      window.innerWidth,
      isConsumer ? undefined : { start: start.value, end: end.value },
    ),
  )
  void chart.render().then(() => {
    container.dataset.initializationMs = (performance.now() - startedAt).toFixed(1)
    status.textContent = ''
  })

  const renderMode = (): void => {
    const points = chartPointsForMode(payload, mode, lastResult, range)
    const selectedRange = !isConsumer && mode === 'monthly' ? { start: start.value, end: end.value } : undefined
    void chart.updateOptions(options(points, mode, window.innerWidth, selectedRange), true, false)
    buttons.forEach((button) => {
      const active = button.dataset.chartMode === mode
      button.setAttribute('aria-pressed', String(active))
      button.classList.toggle('chart-mode-button--active', active)
    })
    rangeButtons.forEach((button) => {
      const active = button.dataset.chartRange === range
      button.setAttribute('aria-pressed', String(active))
      button.classList.toggle('chart-range-button--active', active)
    })
    status.textContent = !isConsumer && mode === 'cumulative' && !lastResult
      ? 'Получаем расчёт для выбранного периода…'
      : ''
  }

  buttons.forEach((button) => {
    button.addEventListener('click', () => {
      const nextMode = button.dataset.chartMode
      if (nextMode !== 'monthly' && nextMode !== 'cumulative') return
      mode = nextMode
      renderMode()
      trackPublicIndicesEvent('chart_mode_change', { ...analyticsContext(), mode: nextMode })
      if (!isConsumer && mode === 'cumulative' && !lastResult) form.requestSubmit()
    })
  })

  rangeButtons.forEach((button) => {
    button.addEventListener('click', () => {
      const nextRange = button.dataset.chartRange
      if (!nextRange || !['1y', '3y', '5y', '10y', 'all'].includes(nextRange)) return
      range = nextRange as ChartRange
      renderMode()
      trackPublicIndicesEvent('chart_range_change', { ...analyticsContext(), range })
    })
  })

  const updateRange = (): void => {
    if (isConsumer) return
    lastResult = null
    if (mode === 'monthly') renderMode()
  }
  start.addEventListener('change', updateRange)
  end.addEventListener('change', updateRange)

  document.addEventListener(CALCULATION_STARTED_EVENT, () => {
    if (!isConsumer && mode === 'cumulative') status.textContent = 'Получаем расчёт для выбранного периода…'
  })
  document.addEventListener(CALCULATION_SUCCEEDED_EVENT, (event) => {
    if (isConsumer) return
    lastResult = (event as CustomEvent<PublicCalculationResult>).detail
    if (mode === 'cumulative') renderMode()
  })
  document.addEventListener(CALCULATION_FAILED_EVENT, () => {
    if (isConsumer) return
    lastResult = null
    if (mode === 'cumulative') {
      renderMode()
      status.textContent = 'Накопленный график недоступен: исправьте параметры расчёта.'
    }
  })

  return true
}

if (typeof document !== 'undefined') initializePublicPriceIndexChart()
