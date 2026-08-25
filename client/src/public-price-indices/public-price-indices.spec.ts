import { describe, expect, it, vi } from 'vitest'

vi.mock('apexcharts', () => ({ default: class ApexCharts {} }))

import {
  calculationSummary,
  errorMessage,
  formatChange,
  validatePeriodRange,
  type PublicCalculationResult,
} from './calculator'
import {
  chartHeight,
  chartPointsForMode,
  cumulativePoints,
  escapeHtml,
  formatIndexChange,
  monthlyPoints,
  type PublicPriceIndexChartPayload,
} from './chart'

const payload: PublicPriceIndexChartPayload = {
  series: { slug: '31-02-10-140', title: 'Наборы кухонной мебели', code: '31.02.10.140' },
  points: [
    { period: '2025-01', display_period: 'Январь 2025', value: '100.0000000000', sequence: 1 },
    { period: '2025-02', display_period: 'Февраль 2025', value: null, sequence: 2 },
    { period: '2025-03', display_period: 'Март 2025', value: '102.4000000000', sequence: 3 },
  ],
  limits: {
    first_available_period: '2025-01',
    last_available_period: '2025-03',
    calculator_max_range_months: 120,
  },
}

const result: PublicCalculationResult = {
  period: { start: '2025-01', end: '2025-03' },
  coefficient: '1.634100000000',
  change_percent: '63.41',
  amount: { original: '500000.00', adjusted: '817073.00' },
  chain: [
    { period: '2025-02', index: '98.7', factor: '0.987', running_coefficient: '0.987' },
    { period: '2025-03', index: '102.4', factor: '1.024', running_coefficient: '1.010688' },
  ],
  page: { title: 'Наборы кухонной мебели', classifier: { code: '31.02.10.140' } },
  provenance: {
    provider: 'Росстат',
    publication: { reference: 'publication' },
    source: { filename: 'source.xlsx', sha256: 'a'.repeat(64) },
    snapshot: { period_to: '2025-03' },
  },
}

describe('public PriceIndices chart', () => {
  it('initializes monthly mode with every ordered point and preserves a missing value', () => {
    expect(monthlyPoints(payload)).toEqual([
      { x: 'Январь 2025', y: 100, period: '2025-01' },
      { x: 'Февраль 2025', y: null, period: '2025-02' },
      { x: 'Март 2025', y: 102.4, period: '2025-03' },
    ])
    expect(chartPointsForMode(payload, 'monthly', result)).toHaveLength(3)
  })

  it('switches to backend-chain cumulative mode with a 100 baseline', () => {
    expect(chartPointsForMode(payload, 'cumulative', null)).toEqual([])
    expect(cumulativePoints(result)).toEqual([
      { x: 'январь 2025', y: 100, period: '2025-01' },
      { x: 'февраль 2025', y: 98.7, period: '2025-02' },
      { x: 'март 2025', y: 101.0688, period: '2025-03' },
    ])
  })

  it('formats monthly tooltip values without floating artefacts and escapes text', () => {
    expect(formatIndexChange(102.4)).toBe('+2,4 %')
    expect(formatIndexChange(98.7)).toBe('−1,3 %')
    expect(escapeHtml('</script><img onerror="x">')).toBe('&lt;/script&gt;&lt;img onerror=&quot;x&quot;&gt;')
  })

  it('uses a compact mobile height', () => {
    expect(chartHeight(390)).toBe(300)
    expect(chartHeight(1280)).toBe(380)
  })
})

describe('public PriceIndices calculator integration values', () => {
  it('represents period changes and the backend 120-month limit', () => {
    expect(validatePeriodRange('2025-01', '2025-03', 120)).toBeNull()
    expect(validatePeriodRange('2025-03', '2025-01', 120)).toBe('invalid_period_range')
    expect(validatePeriodRange('2015-01', '2025-02', 120)).toBe('period_too_long')
  })

  it('renders final factor, change, and amount only from the backend response', () => {
    expect(calculationSummary(result)).toEqual({
      period: 'январь 2025 → март 2025',
      coefficient: '×1,6341',
      change: '+63,41 %',
      amount: '500 000,00 ₽ → 817 073,00 ₽',
    })
    expect(formatChange('-1.30')).toBe('−1,3 %')
  })

  it('maps a 422 and does not create any cumulative result after an error', () => {
    expect(errorMessage({ code: 'incomplete_observation_chain' })).toContain('нет полной последовательности')
    expect(chartPointsForMode(payload, 'cumulative', null)).toEqual([])
  })
})
