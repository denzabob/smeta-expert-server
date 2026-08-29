import { afterEach, describe, expect, it, vi } from 'vitest'

import {
  buildSafeAnalyticsParams,
  initializePublicIndicesAnalytics,
  periodLength,
  PUBLIC_INDICES_ANALYTICS_EVENTS,
  trackPublicIndicesEvent,
} from './analytics'
import { CALCULATION_STARTED_EVENT, CALCULATION_SUCCEEDED_EVENT } from './shared'

class FakeElement {
  tagName = 'DIV'
  open = false
  value = ''
  dataset: DOMStringMap

  constructor(dataset: Record<string, string> = {}) {
    this.dataset = dataset as DOMStringMap
    this.value = dataset.value ?? ''
  }

  closest<T extends Element>(): T {
    return this as unknown as T
  }
}

class FakeForm extends FakeElement {
  elements: { namedItem: (name: string) => FakeElement | null }

  constructor(dataset: Record<string, string>, fields: Record<string, FakeElement>) {
    super(dataset)
    this.elements = { namedItem: (name) => fields[name] ?? null }
  }
}

class FakeDocument {
  body = { dataset: { indicesMetrikaId: '111537697' } }
  private readonly selectors = new Map<string, unknown>()
  private readonly listeners = new Map<string, Array<(event: unknown) => void>>()

  setSelector(selector: string, value: unknown): void {
    this.selectors.set(selector, value)
  }

  querySelector<T>(selector: string): T | null {
    return (this.selectors.get(selector) as T | undefined) ?? null
  }

  addEventListener(type: string, listener: (event: unknown) => void): void {
    this.listeners.set(type, [...(this.listeners.get(type) ?? []), listener])
  }

  dispatch(type: string, event: unknown = {}): void {
    this.listeners.get(type)?.forEach((listener) => listener(event))
  }
}

function setupBrowser(withCounter = true): { document: FakeDocument; calls: unknown[][] } {
  const document = new FakeDocument()
  const calls: unknown[][] = []
  const window = withCounter
    ? { ym: (...args: unknown[]) => calls.push(args) }
    : {}

  vi.stubGlobal('document', document)
  vi.stubGlobal('window', window)
  vi.stubGlobal('Element', FakeElement)
  vi.stubGlobal('HTMLElement', FakeElement)

  return { document, calls }
}

afterEach(() => vi.unstubAllGlobals())

describe('public PriceIndices analytics adapter', () => {
  it('keeps only technical allowlisted parameters', () => {
    expect(buildSafeAnalyticsParams({
      family: 'producer_prices',
      series: 'series-123',
      result_count: '12',
      period_length: 24,
      has_amount: true,
      amount: '500000.00',
      email: 'user@example.com',
      full_name: 'Иван Иванов',
      q: 'сырой поисковый запрос',
      range: 'all',
      mode: 'monthly',
    })).toEqual({
      family: 'producer_prices',
      series: 'series-123',
      result_count: 12,
      period_length: 24,
      has_amount: true,
      range: 'all',
      mode: 'monthly',
    })
  })

  it('does not accept unsafe token values or negative counters', () => {
    expect(buildSafeAnalyticsParams({
      family: 'consumer prices',
      series: 'series/with/query',
      result_count: -1,
      period_length: 'not-a-number',
      has_amount: 'true',
    })).toEqual({})
  })

  it('calculates a technical period length without exposing period values', () => {
    expect(periodLength('2025-01', '2025-03')).toBe(2)
    expect(periodLength('2025-03', '2025-01')).toBeNull()
    expect(periodLength('2025-00', '2025-03')).toBeNull()
  })

  it('declares the complete public event contract', () => {
    expect(PUBLIC_INDICES_ANALYTICS_EVENTS).toEqual([
      'indices_search',
      'indices_result_open',
      'indices_calculate',
      'indices_calculate_success',
      'indices_source_open',
      'indices_login_cta',
      'chart_range_change',
      'chart_mode_change',
      'full_history_open',
    ])
  })

  it('is a no-op without the existing Yandex counter and dispatches one safe goal when available', () => {
    setupBrowser(false)
    expect(trackPublicIndicesEvent('indices_login_cta', { family: 'producer_prices' })).toBe(false)

    const { calls } = setupBrowser()
    expect(trackPublicIndicesEvent('indices_login_cta', {
      family: 'producer_prices',
      series: 'series-123',
      q: 'не отправлять',
      amount: '500000',
    })).toBe(true)
    expect(calls).toEqual([[
      111537697,
      'reachGoal',
      'indices_login_cta',
      { family: 'producer_prices', series: 'series-123' },
    ]])
  })

  it('tracks rendered search count without query and uses only index context for clicks', () => {
    const { document, calls } = setupBrowser()
    document.setSelector('[data-indices-search-form]', new FakeElement({
      indicesSearchState: 'results',
      searchResultCount: '7',
    }))
    initializePublicIndicesAnalytics()

    document.dispatch('click', {
      target: new FakeElement({
        indicesEvent: 'indices_result_open',
        indexFamily: 'producer_prices',
        indexSeries: 'series-123',
      }),
    })

    expect(calls).toEqual([
      [111537697, 'reachGoal', 'indices_search', { result_count: 7 }],
      [111537697, 'reachGoal', 'indices_result_open', { family: 'producer_prices', series: 'series-123' }],
    ])
  })

  it('separates calculation start/success and never sends amount or period values', () => {
    const { document, calls } = setupBrowser()
    const form = new FakeForm(
      { indexFamily: 'consumer_prices', indexSeries: 'all-items-and-services' },
      {
        start_period: new FakeElement({ value: '2025-01' }),
        end_period: new FakeElement({ value: '2025-03' }),
        amount: new FakeElement({ value: '500000' }),
      },
    )
    document.setSelector('[data-public-index-calculator]', form)
    initializePublicIndicesAnalytics()

    document.dispatch(CALCULATION_STARTED_EVENT, { detail: { amount: '500000' } })
    document.dispatch(CALCULATION_SUCCEEDED_EVENT, { detail: { amount: { adjusted: '817073' } } })

    expect(calls).toEqual([
      [111537697, 'reachGoal', 'indices_calculate', {
        family: 'consumer_prices', series: 'all-items-and-services', period_length: 2, has_amount: true,
      }],
      [111537697, 'reachGoal', 'indices_calculate_success', {
        family: 'consumer_prices', series: 'all-items-and-services', period_length: 2, has_amount: true,
      }],
    ])
  })

  it('sends only index context for chart interactions and history only on open', () => {
    const { document, calls } = setupBrowser()
    initializePublicIndicesAnalytics()

    trackPublicIndicesEvent('chart_range_change', {
      family: 'consumer_prices', series: 'series-123', range: '5y', q: 'hidden',
    })
    trackPublicIndicesEvent('chart_mode_change', {
      family: 'consumer_prices', series: 'series-123', mode: 'cumulative', amount: 'hidden',
    })

    const details = new FakeElement({
      indicesEvent: 'full_history_open',
      indexFamily: 'consumer_prices',
      indexSeries: 'series-123',
    })
    details.tagName = 'DETAILS'
    document.dispatch('toggle', { target: details })
    details.open = true
    document.dispatch('toggle', { target: details })
    details.open = false
    document.dispatch('toggle', { target: details })
    details.open = true
    document.dispatch('toggle', { target: details })

    expect(calls).toEqual([
      [111537697, 'reachGoal', 'chart_range_change', { family: 'consumer_prices', series: 'series-123', range: '5y' }],
      [111537697, 'reachGoal', 'chart_mode_change', { family: 'consumer_prices', series: 'series-123', mode: 'cumulative' }],
      [111537697, 'reachGoal', 'full_history_open', { family: 'consumer_prices', series: 'series-123' }],
      [111537697, 'reachGoal', 'full_history_open', { family: 'consumer_prices', series: 'series-123' }],
    ])
  })
})
