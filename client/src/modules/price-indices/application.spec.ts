import { describe, expect, it, vi } from 'vitest'
import {
  buildApplicationMenu,
  persistLastApplication,
  resolveActiveApplication,
  resolvePriceIndicesGuardDecision,
  selectSidebarConfig,
} from './application'

describe('Price Indices application helpers', () => {
  it.each([
    ['/projects', 'estimates'],
    ['/app/indices', 'price_indices'],
    ['/app/indices/calculations', 'price_indices'],
    ['/admin/system/users', 'admin'],
    ['/parser/history', 'parser'],
  ] as const)('resolves %s as %s', (path, application) => {
    expect(resolveActiveApplication(path)).toBe(application)
  })

  it('uses estimates sidebar by default and indices sidebar only for indices routes', () => {
    const estimates = [{ id: 'estimates' }]
    const indices = [{ id: 'indices' }]

    expect(selectSidebarConfig('estimates', estimates, indices)).toBe(estimates)
    expect(selectSidebarConfig('admin', estimates, indices)).toBe(estimates)
    expect(selectSidebarConfig('price_indices', estimates, indices)).toBe(indices)
  })

  it('shows Price Indices only to an exact admin role with available capability', () => {
    const regularItems = buildApplicationMenu({ id: 2, role: 'user' }, 'available')
    const pendingAdminItems = buildApplicationMenu({ id: 3, role: 'admin' }, 'idle')
    const availableAdminItems = buildApplicationMenu({ id: 3, role: 'admin' }, 'available')

    expect(regularItems.map((item) => item.id)).toEqual(['estimates'])
    expect(pendingAdminItems.map((item) => item.id)).toEqual(['estimates', 'admin', 'parser'])
    expect(availableAdminItems.map((item) => item.id)).toEqual([
      'estimates',
      'price_indices',
      'admin',
      'parser',
    ])
  })

  it('preserves existing id=1 visibility for admin and parser without exposing indices', () => {
    const items = buildApplicationMenu({ id: 1, role: 'user' }, 'available')

    expect(items.map((item) => item.id)).toEqual(['estimates', 'admin', 'parser'])
  })

  it.each([
    ['available', 'allow'],
    ['forbidden', 'forbidden'],
    ['idle', 'unavailable'],
    ['loading', 'unavailable'],
    ['disabled', 'unavailable'],
    ['error', 'unavailable'],
  ] as const)('maps %s capability to %s guard decision', (status, decision) => {
    expect(resolvePriceIndicesGuardDecision(status)).toBe(decision)
  })

  it('stores the last application without affecting route resolution', () => {
    const storage = { setItem: vi.fn() }

    persistLastApplication('price_indices', storage)

    expect(storage.setItem).toHaveBeenCalledWith('prisma.lastApplication', 'price_indices')
    expect(resolveActiveApplication('/projects')).toBe('estimates')
  })
})
