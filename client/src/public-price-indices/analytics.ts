import {
  CALCULATION_STARTED_EVENT,
  CALCULATION_SUCCEEDED_EVENT,
} from './shared'

export const PUBLIC_INDICES_ANALYTICS_EVENTS = [
  'indices_search',
  'indices_result_open',
  'indices_calculate',
  'indices_calculate_success',
  'indices_source_open',
  'indices_login_cta',
  'chart_range_change',
  'chart_mode_change',
  'full_history_open',
] as const

export type PublicIndicesAnalyticsEvent = (typeof PUBLIC_INDICES_ANALYTICS_EVENTS)[number]
export type PublicIndicesChartRange = '1y' | '3y' | '5y' | '10y' | 'all'
export type PublicIndicesChartMode = 'monthly' | 'cumulative'
export type PublicIndicesAnalyticsParams = {
  family?: string
  series?: string
  result_count?: number
  period_length?: number
  has_amount?: boolean
  range?: PublicIndicesChartRange
  mode?: PublicIndicesChartMode
}

type AnalyticsInput = Record<string, unknown>

declare global {
  interface Window {
    ym?: (...args: unknown[]) => void
    __prismIndicesAnalyticsInitialized?: boolean
  }
}

function token(value: unknown): string | undefined {
  if (typeof value !== 'string') return undefined

  const normalized = value.trim()
  return normalized !== '' && normalized.length <= 120 && /^[a-zA-Z0-9._-]+$/.test(normalized)
    ? normalized
    : undefined
}

function nonNegativeInteger(value: unknown): number | undefined {
  if (value === null || value === undefined) return undefined
  if (typeof value === 'string' && value.trim() === '') return undefined

  const number = typeof value === 'number' ? value : Number(value)
  return Number.isSafeInteger(number) && number >= 0 ? number : undefined
}

function chartRange(value: unknown): PublicIndicesChartRange | undefined {
  return value === '1y' || value === '3y' || value === '5y' || value === '10y' || value === 'all'
    ? value
    : undefined
}
function chartMode(value: unknown): PublicIndicesChartMode | undefined {
  return value === 'monthly' || value === 'cumulative' ? value : undefined
}

export function buildSafeAnalyticsParams(input: AnalyticsInput): PublicIndicesAnalyticsParams {
  const params: PublicIndicesAnalyticsParams = {}
  const family = token(input.family)
  const series = token(input.series)
  const resultCount = nonNegativeInteger(input.result_count)
  const periodLength = nonNegativeInteger(input.period_length)
  const range = chartRange(input.range)
  const mode = chartMode(input.mode)
  if (family) params.family = family
  if (series) params.series = series
  if (resultCount !== undefined) params.result_count = resultCount
  if (periodLength !== undefined) params.period_length = periodLength
  if (typeof input.has_amount === 'boolean') params.has_amount = input.has_amount
  if (range) params.range = range
  if (mode) params.mode = mode
  return params
}

export function periodLength(start: string, end: string): number | null {
  const startMatch = /^(\d{4})-(\d{2})$/.exec(start)
  const endMatch = /^(\d{4})-(\d{2})$/.exec(end)
  if (!startMatch || !endMatch) return null

  const startMonth = Number(startMatch[2])
  const endMonth = Number(endMatch[2])
  if (startMonth < 1 || startMonth > 12 || endMonth < 1 || endMonth > 12) return null

  const span = (Number(endMatch[1]) - Number(startMatch[1])) * 12 + endMonth - startMonth
  return span >= 0 ? span : null
}

function counterId(): number | null {
  if (typeof document === 'undefined') return null

  const raw = document.body?.dataset.indicesMetrikaId ?? ''
  if (!/^\d+$/.test(raw)) return null

  const id = Number(raw)
  return Number.isSafeInteger(id) && id > 0 ? id : null
}

function context(element: Element | null): PublicIndicesAnalyticsParams {
  const source = element?.closest<HTMLElement>('[data-index-family]')
  if (!source) return {}

  return buildSafeAnalyticsParams({
    family: source.dataset.indexFamily,
    series: source.dataset.indexSeries,
  })
}

function calculationParams(form: HTMLFormElement | null): PublicIndicesAnalyticsParams {
  if (!form) return {}

  const start = form.elements.namedItem('start_period') as HTMLSelectElement | null
  const end = form.elements.namedItem('end_period') as HTMLSelectElement | null
  const amount = form.elements.namedItem('amount') as HTMLInputElement | null

  return buildSafeAnalyticsParams({
    ...context(form),
    period_length: start && end ? periodLength(start.value, end.value) : undefined,
    has_amount: Boolean(amount?.value.trim()),
  })
}

export function trackPublicIndicesEvent(
  event: PublicIndicesAnalyticsEvent,
  input: AnalyticsInput = {},
): boolean {
  if (typeof window === 'undefined') return false
  if (!PUBLIC_INDICES_ANALYTICS_EVENTS.includes(event)) return false

  const id = counterId()
  if (id === null || typeof window.ym !== 'function') return false

  try {
    window.ym(id, 'reachGoal', event, buildSafeAnalyticsParams(input))
    return true
  } catch {
    return false
  }
}

function initializeDomListeners(): void {
  const searchForm = document.querySelector<HTMLFormElement>('[data-indices-search-form]')
  if (searchForm?.dataset.indicesSearchState === 'results') {
    trackPublicIndicesEvent('indices_search', { result_count: searchForm.dataset.searchResultCount })
  }

  document.addEventListener('click', (event) => {
    const target = event.target instanceof Element
      ? event.target.closest<HTMLElement>('[data-indices-event]')
      : null
    const name = target?.dataset.indicesEvent as PublicIndicesAnalyticsEvent | undefined
    if (!target || !name || name === 'full_history_open') return
    if (!PUBLIC_INDICES_ANALYTICS_EVENTS.includes(name)) return

    trackPublicIndicesEvent(name, context(target))
  })

  document.addEventListener('toggle', (event) => {
    const details = event.target instanceof HTMLElement && event.target.tagName === 'DETAILS'
      ? event.target as HTMLDetailsElement
      : null
    if (!details?.open || details.dataset.indicesEvent !== 'full_history_open') return

    trackPublicIndicesEvent('full_history_open', context(details))
  }, true)

  document.addEventListener(CALCULATION_STARTED_EVENT, () => {
    trackPublicIndicesEvent('indices_calculate', calculationParams(
      document.querySelector<HTMLFormElement>('[data-public-index-calculator]'),
    ))
  })
  document.addEventListener(CALCULATION_SUCCEEDED_EVENT, () => {
    trackPublicIndicesEvent('indices_calculate_success', calculationParams(
      document.querySelector<HTMLFormElement>('[data-public-index-calculator]'),
    ))
  })
}

export function initializePublicIndicesAnalytics(): boolean {
  if (typeof document === 'undefined' || typeof window === 'undefined') return false
  if (window.__prismIndicesAnalyticsInitialized) return false

  window.__prismIndicesAnalyticsInitialized = true
  initializeDomListeners()
  return true
}

if (typeof document !== 'undefined') initializePublicIndicesAnalytics()
