import { describe, expect, it } from 'vitest'
import { buildApplicationMenu, resolvePriceIndicesGuardDecision } from '../application'
import { priceIndicesSidebarSections } from '../navigation'
import type { PriceIndicesCapabilityStatus } from '../types'
import {
  buildAdminPriceIndicesNavigation,
  isAdminPriceIndicesNavigationItemActive,
} from './navigation'
import { adminPriceIndicesRoutes } from './routes'

describe('Price Indices admin routes', () => {
  it('declares four lazy admin routes with required access metadata', () => {
    expect(adminPriceIndicesRoutes.map((route) => `/admin/${route.path}`)).toEqual([
      '/admin/indices/sources',
      '/admin/indices/imports',
      '/admin/indices/mappings',
      '/admin/indices/logs',
    ])

    for (const route of adminPriceIndicesRoutes) {
      expect(route.meta).toMatchObject({
        requiresAuth: true,
        requiresAdmin: true,
        requiresPriceIndices: true,
      })
      expect(route.name).toBeTruthy()
      expect(typeof route.component).toBe('function')
    }

    expect(new Set(adminPriceIndicesRoutes.map((route) => route.name)).size).toBe(4)
  })

  it('requires both the existing admin gate and available capability', () => {
    for (const route of adminPriceIndicesRoutes) {
      expect(route.meta?.requiresAdmin).toBe(true)
      expect(resolvePriceIndicesGuardDecision('available')).toBe('allow')
    }

    expect(resolvePriceIndicesGuardDecision('forbidden')).not.toBe('allow')
    expect(resolvePriceIndicesGuardDecision('disabled')).not.toBe('allow')
    expect(resolvePriceIndicesGuardDecision('error')).not.toBe('allow')
  })
})

describe('Price Indices admin navigation', () => {
  it('contains the four items in the required order only when available', () => {
    const section = buildAdminPriceIndicesNavigation('available', '/admin/indices/sources')

    expect(section?.items.map((item) => item.label)).toEqual([
      'Источники данных',
      'Импорты XLSX',
      'Шаблоны маппинга',
      'Журнал импорта',
    ])
  })

  it.each<PriceIndicesCapabilityStatus>([
    'idle',
    'loading',
    'forbidden',
    'disabled',
    'error',
  ])('hides the group for %s capability', (status) => {
    expect(buildAdminPriceIndicesNavigation(status, '/admin')).toBeNull()
  })

  it('marks only the current exact route as active', () => {
    const section = buildAdminPriceIndicesNavigation('available', '/admin/indices/mappings')

    expect(section?.items.filter((item) => item.active).map((item) => item.to)).toEqual([
      '/admin/indices/mappings',
    ])
    expect(isAdminPriceIndicesNavigationItemActive(
      '/admin/indices/mappings',
      '/admin/indices/mappings/example',
    )).toBe(false)
  })

  it('does not alter the user Price Indices navigation', () => {
    expect(priceIndicesSidebarSections.map((section) => section.title)).toEqual(['Работа', 'Данные'])
    expect(priceIndicesSidebarSections.flatMap((section) => section.items.map((item) => item.routeName))).toEqual([
      'price-indices-overview',
      'price-indices-new-calculation',
      'price-indices-calculations',
      'price-indices-indicators',
      'price-indices-sources',
    ])
  })

  it('preserves the existing Admin and Parser application visibility rules', () => {
    expect(buildApplicationMenu({ id: 1, role: 'user' }, 'available').map((item) => item.id)).toEqual([
      'estimates',
      'admin',
      'parser',
    ])
    expect(buildApplicationMenu({ id: 2, role: 'user' }, 'available').map((item) => item.id)).toEqual([
      'estimates',
    ])
  })
})
