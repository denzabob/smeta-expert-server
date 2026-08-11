import { describe, expect, it } from 'vitest'
import {
  analyzeMonthlySeriesContinuity,
  createLatestRequestGuard,
  formatDecimalString,
  resolveSeriesSearchFilters,
  toApiMonth,
} from './dataExplorer'
import type { StatisticalObservation } from './types'

const observation = (period: string, value: string | null): StatisticalObservation => ({
  public_id: period + String(value), period_start: `${period}-01`, value, missing_reason: value === null ? 'ellipsis' : null,
  series: { public_id: 'series', item_code: '31.02.10.140', item_name: 'Наборы кухонной мебели', territory_code: 'RU', territory_name: 'Россия', indicator_code: 'ppi', indicator_name: 'Индекс', frequency: 'monthly', comparison_basis: 'previous_month', unit: 'percent' },
  provenance: { source_file_public_id: 'file', sheet_name: '24', source_row: 1, source_column: 'H', source_cell_address: 'H1', source_value_raw: value, footnote_marker: null },
})

describe('Data Explorer search and formatting', () => {
  it('maps code, text and AG queries to bounded server filters', () => {
    expect(resolveSeriesSearchFilters('31.02')).toMatchObject({ item_code_prefix: '31.02', per_page: 25 })
    expect(resolveSeriesSearchFilters('кухонной мебели')).toMatchObject({ item_name: 'кухонной мебели' })
    expect(resolveSeriesSearchFilters('05.10.10.101.аг')).toMatchObject({ item_code_prefix: '05.10.10.101.АГ' })
    expect(resolveSeriesSearchFilters('31.02.10.140', true)).toMatchObject({ item_code: '31.02.10.140' })
    expect(resolveSeriesSearchFilters('а')).toBeNull()
  })

  it('keeps stale responses from becoming current', () => {
    const guard = createLatestRequestGuard(); const first = guard.next(); const second = guard.next()
    expect(guard.isCurrent(first)).toBe(false); expect(guard.isCurrent(second)).toBe(true)
    guard.invalidate(); expect(guard.isCurrent(second)).toBe(false)
  })

  it('formats decimals without float conversion and maps month input', () => {
    expect(formatDecimalString('109.5100000000')).toBe('109.51')
    expect(formatDecimalString('100.0000000000')).toBe('100')
    expect(formatDecimalString(null)).toBe('Нет данных')
    expect(toApiMonth('2026-06')).toBe('2026-06-01')
  })
})

describe('monthly continuity', () => {
  it('recognizes a complete monthly chain', () => {
    expect(analyzeMonthlySeriesContinuity([observation('2024-01', '1'), observation('2024-02', '2'), observation('2024-03', '3')], '2024-01', '2024-03'))
      .toMatchObject({ isContinuous: true, expectedCount: 3, actualCount: 3 })
  })
  it('reports gaps, null values and duplicates separately', () => {
    expect(analyzeMonthlySeriesContinuity([observation('2024-01', '1'), observation('2024-03', '3')], '2024-01', '2024-03').missingPeriods).toEqual(['2024-02'])
    expect(analyzeMonthlySeriesContinuity([observation('2024-01', '1'), observation('2024-02', null), observation('2024-03', '3')], '2024-01', '2024-03').nullPeriods).toEqual(['2024-02'])
    expect(analyzeMonthlySeriesContinuity([observation('2024-01', '1'), observation('2024-02', '2'), observation('2024-02', '3')], '2024-01', '2024-02').duplicatePeriods).toEqual(['2024-02'])
  })
})
