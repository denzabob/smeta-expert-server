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
  cumulativePointsForRange,
  escapeHtml,
  formatIndexChange,
  monthlyPoints,
  pointsForRange,
  type PublicPriceIndexChartPayload,
} from './chart'

const payload: PublicPriceIndexChartPayload = {
  series: { slug: '31-02-10-140', title: 'Наборы кухонной мебели', code: '31.02.10.140', family: 'producer_prices' },
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
  it('keeps all 427 CPI monthly points without annual aggregation', () => {
    const cpiPayload: PublicPriceIndexChartPayload = {
      ...payload,
      series: { slug: 'food-products', title: 'Продовольственные товары', code: null, family: 'consumer_prices' },
      points: Array.from({ length: 427 }, (_, index) => ({
        period: `${1991 + Math.floor(index / 12)}-${String((index % 12) + 1).padStart(2, '0')}`,
        display_period: `Период ${index + 1}`,
        value: '100.0000000000',
        sequence: index + 1,
      })),
      limits: {
        first_available_period: '1991-01',
        last_available_period: '2026-07',
        calculator_max_range_months: 120,
      },
    }

    expect(monthlyPoints(cpiPayload)).toHaveLength(427)
    expect(chartPointsForMode(cpiPayload, 'monthly', null)).toHaveLength(427)
    expect(cpiPayload.limits.calculator_max_range_months).toBe(120)
  })

  it('filters CPI ranges by calendar periods and defaults are independent from the calculator', () => {
    const cpiPayload: PublicPriceIndexChartPayload = {
      ...payload,
      series: { slug: 'services', title: 'Услуги', code: null, family: 'consumer_prices' },
      points: [
        { period: '2020-01', display_period: 'Январь 2020', value: '100', sequence: 1 },
        { period: '2021-08', display_period: 'Август 2021', value: '101', sequence: 2 },
        { period: '2026-06', display_period: 'Июнь 2026', value: '102', sequence: 3 },
        { period: '2026-07', display_period: 'Июль 2026', value: '99', sequence: 4 },
      ],
      limits: { ...payload.limits, first_available_period: '2020-01', last_available_period: '2026-07' },
    }

    expect(pointsForRange(cpiPayload, '5y').map((point) => point.period)).toEqual([
      '2021-08',
      '2026-06',
      '2026-07',
    ])
    expect(pointsForRange(cpiPayload, '1y').map((point) => point.period)).toEqual(['2026-06', '2026-07'])
    expect(pointsForRange(cpiPayload, 'all')).toHaveLength(4)
  })

  it('rebases cumulative CPI values to 100 at the first period in each selected range', () => {
    const cpiPayload: PublicPriceIndexChartPayload = {
      ...payload,
      series: { slug: 'food-products', title: 'Продовольственные товары', code: null, family: 'consumer_prices' },
      points: [
        { period: '2024-12', display_period: 'Декабрь 2024', value: '150', sequence: 1 },
        { period: '2025-01', display_period: 'Январь 2025', value: '101', sequence: 2 },
        { period: '2025-02', display_period: 'Февраль 2025', value: '102', sequence: 3 },
      ],
      limits: { ...payload.limits, first_available_period: '2024-12', last_available_period: '2025-02' },
    }

    expect(cumulativePointsForRange(cpiPayload, 'all')).toEqual([
      { x: 'Декабрь 2024', y: 100, period: '2024-12' },
      { x: 'Январь 2025', y: 101, period: '2025-01' },
      { x: 'Февраль 2025', y: 103.02, period: '2025-02' },
    ])
    expect(chartPointsForMode(cpiPayload, 'cumulative', result, '1y')[0]?.y).toBe(100)
  })

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
