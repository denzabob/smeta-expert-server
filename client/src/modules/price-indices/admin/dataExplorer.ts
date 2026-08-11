import type {
  ContinuityDiagnostic,
  ImportSeriesListParams,
  StatisticalObservation,
} from './types'

const CODE_QUERY = /^\d{1,2}(?:\.\d+)*(?:\.АГ)?\.?$/iu
const MONTH_PATTERN = /^(\d{4})-(0[1-9]|1[0-2])(?:-\d{2})?$/
const MONTH_NAMES = [
  'Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь',
  'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь',
]

export function resolveSeriesSearchFilters(query: string, exactCode = false): ImportSeriesListParams | null {
  const normalized = query.trim().replace(/\u00a0/g, ' ').replace(/\s+/gu, ' ')
  if (!normalized) return null
  if (CODE_QUERY.test(normalized)) {
    if (normalized.length < 1) return null
    return exactCode
      ? { item_code: normalized.toUpperCase(), page: 1, per_page: 25, sort: 'item_code', direction: 'asc' }
      : { item_code_prefix: normalized.toUpperCase(), page: 1, per_page: 25, sort: 'item_code', direction: 'asc' }
  }
  if (normalized.length < 2) return null
  return { item_name: normalized, page: 1, per_page: 25, sort: 'item_code', direction: 'asc' }
}

export function createLatestRequestGuard() {
  let sequence = 0
  return {
    next: () => ++sequence,
    invalidate: () => { sequence += 1 },
    isCurrent: (token: number) => token === sequence,
  }
}

export function toApiMonth(value: string): string | undefined {
  return /^\d{4}-(0[1-9]|1[0-2])$/.test(value) ? `${value}-01` : undefined
}

export function toInputMonth(value: string | null | undefined): string {
  return value?.match(MONTH_PATTERN)?.[0]?.slice(0, 7) ?? ''
}

export function formatMonth(value: string): string {
  const match = value.match(MONTH_PATTERN)
  if (!match) return value
  return `${MONTH_NAMES[Number(match[2]) - 1]} ${match[1]}`
}

export function formatDecimalString(value: string | null): string {
  if (value === null) return 'Нет данных'
  const match = value.match(/^([+-]?)(\d+)(?:\.(\d+))?$/)
  if (!match) return value
  const fraction = (match[3] ?? '').replace(/0+$/, '')
  return `${match[1]}${match[2]}${fraction ? `.${fraction}` : ''}`
}

export function shortIdentifier(value: string, head = 8, tail = 4): string {
  return value.length <= head + tail + 1 ? value : `${value.slice(0, head)}…${value.slice(-tail)}`
}

export function sourceCellLabel(observation: StatisticalObservation): string {
  const address = observation.provenance.source_cell_address
    || `${observation.provenance.source_column}${observation.provenance.source_row}`
  return `лист ${observation.provenance.sheet_name} · ${address}`
}

export function analyzeMonthlySeriesContinuity(
  observations: StatisticalObservation[],
  from: string,
  to: string,
): ContinuityDiagnostic {
  const expected = enumerateMonths(from, to)
  const counts = new Map<string, number>()
  const nullPeriods = new Set<string>()
  for (const observation of observations) {
    const month = observation.period_start.slice(0, 7)
    counts.set(month, (counts.get(month) ?? 0) + 1)
    if (observation.value === null) nullPeriods.add(month)
  }
  const missingPeriods = expected.filter((month) => !counts.has(month))
  const duplicatePeriods = [...counts.entries()].filter(([, count]) => count > 1).map(([month]) => month).sort()
  return {
    isContinuous: missingPeriods.length === 0 && nullPeriods.size === 0 && duplicatePeriods.length === 0,
    expectedCount: expected.length,
    actualCount: observations.length,
    missingPeriods,
    nullPeriods: [...nullPeriods].sort(),
    duplicatePeriods,
  }
}

function enumerateMonths(from: string, to: string): string[] {
  if (!/^\d{4}-\d{2}$/.test(from) || !/^\d{4}-\d{2}$/.test(to) || from > to) return []
  const result: string[] = []
  let [year, month] = from.split('-').map(Number) as [number, number]
  const [toYear, toMonth] = to.split('-').map(Number) as [number, number]
  while (year < toYear || (year === toYear && month <= toMonth)) {
    result.push(`${year}-${String(month).padStart(2, '0')}`)
    month += 1
    if (month === 13) { year += 1; month = 1 }
  }
  return result
}
