import type { LocationQuery, LocationQueryRaw } from 'vue-router'
import type { UserSeriesSearchFilters } from './types'

const CODE_QUERY = /^\d{1,2}(?:\.\d+)*(?:\.АГ)?\.?$/iu
const MONTH_PATTERN = /^(\d{4})-(0[1-9]|1[0-2])$/
const UUID_PATTERN = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i
const MONTH_NAMES: Record<string, string> = {
  '01': 'Январь', '02': 'Февраль', '03': 'Март', '04': 'Апрель',
  '05': 'Май', '06': 'Июнь', '07': 'Июль', '08': 'Август',
  '09': 'Сентябрь', '10': 'Октябрь', '11': 'Ноябрь', '12': 'Декабрь',
}
const MONTH_NAMES_GENITIVE: Record<string, string> = {
  '01': 'января', '02': 'февраля', '03': 'марта', '04': 'апреля',
  '05': 'мая', '06': 'июня', '07': 'июля', '08': 'августа',
  '09': 'сентября', '10': 'октября', '11': 'ноября', '12': 'декабря',
}

export interface NormalizedAmount {
  value: string | null
  error: string | null
}

export interface CalculatorQueryState {
  series: string | null
  start: string
  end: string
}

export function resolveUserSeriesSearchFilters(query: string, page = 1): UserSeriesSearchFilters | null {
  const normalized = query.trim().replace(/\u00a0/g, ' ').replace(/\s+/gu, ' ')
  if (!normalized) return null
  if (CODE_QUERY.test(normalized)) {
    return {
      item_code_prefix: normalized.toUpperCase(),
      page,
      per_page: 25,
    }
  }
  if (normalized.length < 2) return null
  return { item_name: normalized, page, per_page: 25 }
}

export function createLatestRequestGuard() {
  let sequence = 0
  return {
    next: () => ++sequence,
    invalidate: () => { sequence += 1 },
    isCurrent: (token: number) => token === sequence,
  }
}

export function normalizeAmountInput(input: string): NormalizedAmount {
  const compact = input.trim().replace(/[\s\u00a0\u202f]+/gu, '').replace(',', '.')
  if (!compact) return { value: null, error: null }
  if (!/^\d{1,18}(?:\.\d{1,10})?$/.test(compact)) {
    return { value: null, error: 'Введите положительную сумму: до 18 цифр до запятой и до 10 после.' }
  }
  if (/^0+(?:\.0+)?$/.test(compact)) {
    return { value: null, error: 'Исходная стоимость должна быть больше нуля.' }
  }
  return { value: compact, error: null }
}

export function formatDecimalDisplay(value: string, trimTrailingZeros = true): string {
  const match = value.match(/^([+-]?)(\d+)(?:\.(\d+))?$/)
  if (!match) return value
  const fraction = trimTrailingZeros ? (match[3] ?? '').replace(/0+$/, '') : (match[3] ?? '')
  return `${match[1]}${match[2]}${fraction ? `,${fraction}` : ''}`
}

export function formatAmountDisplay(value: string): string {
  const match = value.match(/^([+-]?)(\d+)(?:\.(\d+))?$/)
  if (!match) return value
  const grouped = (match[2] ?? '').replace(/\B(?=(\d{3})+(?!\d))/g, ' ')
  return `${match[1]}${grouped}${match[3] ? `,${match[3]}` : ''}`
}

export function formatMonth(value: string, lowercase = false): string {
  const match = value.match(MONTH_PATTERN)
  if (!match) return value
  const monthKey = match[2] ?? ''
  const month = MONTH_NAMES[monthKey] ?? monthKey
  const label = `${month} ${match[1]}`
  return lowercase ? `${label.charAt(0).toLocaleLowerCase('ru-RU')}${label.slice(1)}` : label
}

export function formatMonthGenitive(value: string): string {
  const match = value.match(MONTH_PATTERN)
  if (!match) return value
  const monthKey = match[2] ?? ''
  return `${MONTH_NAMES_GENITIVE[monthKey] ?? monthKey} ${match[1]}`
}

export function formatPublishedAt(value: string | null): string {
  if (!value) return '—'
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? value : date.toLocaleString('ru-RU')
}

export function shortIdentifier(value: string, head = 8, tail = 4): string {
  return value.length <= head + tail + 1 ? value : `${value.slice(0, head)}…${value.slice(-tail)}`
}

export function readCalculatorQuery(query: LocationQuery): CalculatorQueryState {
  const seriesValue = singleQueryValue(query.series)
  const start = singleQueryValue(query.start)
  const end = singleQueryValue(query.end)
  return {
    series: seriesValue && UUID_PATTERN.test(seriesValue) ? seriesValue : null,
    start: start && MONTH_PATTERN.test(start) ? start : '',
    end: end && MONTH_PATTERN.test(end) ? end : '',
  }
}

export function buildCalculatorQuery(state: CalculatorQueryState): LocationQueryRaw {
  const query: LocationQueryRaw = {}
  if (state.series) query.series = state.series
  if (MONTH_PATTERN.test(state.start)) query.start = state.start
  if (MONTH_PATTERN.test(state.end)) query.end = state.end
  return query
}

export function isPeriodWithinAvailability(value: string, from: string, to: string): boolean {
  return MONTH_PATTERN.test(value) && value >= from && value <= to
}

export function userFrequencyLabel(value: string): string {
  return value === 'monthly' ? 'Ежемесячно' : value
}

export function userComparisonLabel(value: string): string {
  return value === 'previous_month' ? 'К предыдущему месяцу' : value
}

export function userUnitLabel(value: string): string {
  return value === 'percent' ? '%' : value
}

function singleQueryValue(value: LocationQuery[string] | undefined): string {
  return typeof value === 'string' ? value : ''
}
