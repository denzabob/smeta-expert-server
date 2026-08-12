import { describe, expect, it } from 'vitest'
import {
  buildCalculatorQuery,
  createLatestRequestGuard,
  formatAmountDisplay,
  formatDecimalDisplay,
  formatMonth,
  isPeriodWithinAvailability,
  normalizeAmountInput,
  readCalculatorQuery,
  resolveUserSeriesSearchFilters,
} from './calculator'
import { getPriceIndicesUserError } from './errors'

describe('Price Indices calculator search', () => {
  it('maps code, text and .АГ to user API filters', () => {
    expect(resolveUserSeriesSearchFilters('31')).toEqual({ item_code_prefix: '31', page: 1, per_page: 25 })
    expect(resolveUserSeriesSearchFilters('31.02')).toMatchObject({ item_code_prefix: '31.02' })
    expect(resolveUserSeriesSearchFilters('05.10.10.101.аг')).toMatchObject({ item_code_prefix: '05.10.10.101.АГ' })
    expect(resolveUserSeriesSearchFilters(' кухонная\u00a0 мебель ')).toMatchObject({ item_name: 'кухонная мебель' })
    expect(resolveUserSeriesSearchFilters('я')).toBeNull()
  })

  it('protects results from a stale search response', () => {
    const guard = createLatestRequestGuard()
    const requestA = guard.next()
    const requestB = guard.next()
    expect(guard.isCurrent(requestA)).toBe(false)
    expect(guard.isCurrent(requestB)).toBe(true)
    guard.invalidate()
    expect(guard.isCurrent(requestB)).toBe(false)
  })
})

describe('string-only decimal input and display', () => {
  it.each([
    ['663940', '663940'],
    ['663940,00', '663940.00'],
    ['663 940,00', '663940.00'],
    ['663\u00a0940.00', '663940.00'],
  ])('normalizes %s without numeric coercion', (input, expected) => {
    expect(normalizeAmountInput(input)).toEqual({ value: expected, error: null })
  })

  it.each(['0', '0,00', '-1', '1e10', 'NaN', 'INF', '1,2,3', '1234567890123456789'])('rejects %s', (input) => {
    expect(normalizeAmountInput(input).error).toBeTruthy()
  })

  it('preserves authoritative decimal precision and formats amount grouping', () => {
    expect(formatDecimalDisplay('1.123456789012', false)).toBe('1,123456789012')
    expect(formatDecimalDisplay('109.5100000000')).toBe('109,51')
    expect(formatDecimalDisplay('100.0000000000')).toBe('100')
    expect(formatAmountDisplay('663940.00')).toBe('663 940,00')
    expect(formatAmountDisplay('1234.57')).toBe('1 234,57')
  })
})

describe('period UX and URL recovery', () => {
  const uuid = '01900000-0000-7000-8000-000000000001'

  it('uses strict YYYY-MM availability and Russian labels', () => {
    expect(isPeriodWithinAvailability('2024-01', '2021-01', '2026-06')).toBe(true)
    expect(isPeriodWithinAvailability('2020-12', '2021-01', '2026-06')).toBe(false)
    expect(isPeriodWithinAvailability('2026-07', '2021-01', '2026-06')).toBe(false)
    expect(formatMonth('2024-02')).toBe('Февраль 2024')
  })

  it('recovers only series/start/end and never amount or result', () => {
    const recovered = readCalculatorQuery({ series: uuid, start: '2024-01', end: '2026-06', base_amount: '9000' })
    expect(recovered).toEqual({ series: uuid, start: '2024-01', end: '2026-06' })
    expect(buildCalculatorQuery(recovered)).toEqual({ series: uuid, start: '2024-01', end: '2026-06' })
    expect(readCalculatorQuery({ series: 'bad', start: '2024-1', end: '2024-13' }))
      .toEqual({ series: null, start: '', end: '' })
  })
})

describe('human-readable backend errors', () => {
  function apiError(status: number, code: string, details?: Record<string, unknown>) {
    return { isAxiosError: true, response: { status, data: { code, details } } }
  }

  it.each([
    [403, '', 'Недостаточно прав'],
    [404, 'series_not_available', 'больше недоступен'],
    [409, 'no_active_publication', 'нет опубликованной версии'],
    [422, 'invalid_period_range', 'не должен быть позже'],
    [500, 'calculation_integrity_error', 'проверку целостности'],
  ])('maps status %s / %s', (status, code, phrase) => {
    expect(getPriceIndicesUserError(apiError(status as number, code as string)).message).toContain(phrase)
  })

  it('shows the exact missing month for an incomplete chain', () => {
    const mapped = getPriceIndicesUserError(apiError(422, 'incomplete_observation_chain', {
      missing_periods: ['2024-03'],
    }))
    expect(mapped.message).toBe('Для марта 2024 отсутствуют необходимые данные.')
    expect(mapped.missingPeriods).toEqual(['2024-03'])
  })
})
