// @vitest-environment jsdom
import { createApp, nextTick } from 'vue'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { StorageUsageSnapshot } from '@/api/billing'

const { getMyStorageUsage } = vi.hoisted(() => ({ getMyStorageUsage: vi.fn() }))

vi.mock('@/api/billing', () => ({ getMyStorageUsage }))
vi.mock('@/components/layout/AppStateBlock.vue', () => ({
  default: {
    props: ['title', 'description'],
    template: '<div class="state-block"><strong>{{ title }}</strong><span>{{ description }}</span><slot name="actions" /></div>',
  },
}))
vi.mock('vuetify/components/VBtn', () => ({ VBtn: { inheritAttrs: false, template: '<button v-bind="$attrs"><slot /></button>' } }))
vi.mock('vuetify/components/VCard', () => ({ VCard: { template: '<div class="v-card"><slot /></div>' } }))
vi.mock('vuetify/components/VProgressLinear', () => ({ VProgressLinear: { props: ['color', 'modelValue'], template: '<div class="progress" :data-color="color" :data-value="modelValue" />' } }))
vi.mock('vuetify/components/VAlert', () => ({ VAlert: { template: '<div class="alert"><slot /></div>' } }))
vi.mock('vuetify/components/VIcon', () => ({ VIcon: { template: '<i />' } }))

import AccountStorageSection from './AccountStorageSection.vue'

const storage = (overrides: Partial<StorageUsageSnapshot> = {}): StorageUsageSnapshot => ({
  used_bytes: 2 * 1024 ** 3,
  limit_bytes: 5 * 1024 ** 3,
  remaining_bytes: 3 * 1024 ** 3,
  usage_percent: 40,
  materials_count: 184,
  is_unlimited: false,
  is_over_limit: false,
  limit_available: true,
  limit_visible: true,
  enforcement_enabled: false,
  ...overrides,
})

const componentStub = (template: string, props: string[] = []) => ({ props, template })

function mountStorageSection() {
  const root = document.createElement('div')
  document.body.append(root)
  const app = createApp(AccountStorageSection)
  app.component('v-btn', componentStub('<button v-bind="$attrs"><slot /></button>', ['loading', 'ariaLabel', 'to', 'color', 'variant', 'prependIcon']))
  app.component('VBtn', componentStub('<button v-bind="$attrs"><slot /></button>', ['loading', 'ariaLabel', 'to', 'color', 'variant', 'prependIcon']))
  app.component('v-card', componentStub('<div class="v-card"><slot /></div>'))
  app.component('VCard', componentStub('<div class="v-card"><slot /></div>'))
  app.component('v-progress-linear', componentStub('<div class="progress" :data-color="color" :data-value="modelValue" />', ['color', 'modelValue']))
  app.component('VProgressLinear', componentStub('<div class="progress" :data-color="color" :data-value="modelValue" />', ['color', 'modelValue']))
  app.component('v-alert', componentStub('<div class="alert"><slot /></div>', ['type']))
  app.component('VAlert', componentStub('<div class="alert"><slot /></div>', ['type']))
  app.component('v-icon', componentStub('<i />', ['icon']))
  app.component('VIcon', componentStub('<i />', ['icon']))
  app.mount(root)
  return { app, root }
}

async function flush() {
  await Promise.resolve()
  await nextTick()
  await Promise.resolve()
  await nextTick()
}

beforeEach(() => getMyStorageUsage.mockReset())
afterEach(() => { document.body.innerHTML = '' })

describe('account storage section', () => {
  it('shows used, limit, remaining bytes and file count, then refreshes after a mutation event', async () => {
    getMyStorageUsage.mockResolvedValueOnce(storage()).mockResolvedValueOnce(storage({ used_bytes: 3 * 1024 ** 3, remaining_bytes: 2 * 1024 ** 3, usage_percent: 60 }))
    const { app, root } = mountStorageSection()
    await flush()

    expect(root.textContent).toContain('2 ГБ из 5 ГБ использовано')
    expect(root.textContent).toContain('Свободно 3 ГБ')
    expect(root.textContent).toContain('184')
    expect(root.querySelector('.progress')?.getAttribute('data-value')).toBe('40')

    window.dispatchEvent(new Event('prism:storage-usage-changed'))
    await flush()

    expect(getMyStorageUsage).toHaveBeenCalledTimes(2)
    expect(root.textContent).toContain('3 ГБ из 5 ГБ использовано')
    app.unmount()
  })

  it('shows the unlimited message without a progress bar or a manager', async () => {
    getMyStorageUsage.mockResolvedValue(storage({ limit_bytes: null, remaining_bytes: null, usage_percent: null, is_unlimited: true }))
    const { app, root } = mountStorageSection()
    await flush()

    expect(root.textContent).toContain('2 ГБ использовано')
    expect(root.textContent).toContain('Лимит не установлен')
    expect(root.querySelector('.progress')).toBeNull()
    expect(root.textContent).toContain('Открыть проекты Эксперта')
    app.unmount()
  })

  it('uses the error state for an over-limit account while keeping existing files accessible', async () => {
    getMyStorageUsage.mockResolvedValue(storage({ used_bytes: 6 * 1024 ** 3, remaining_bytes: 0, usage_percent: 120, is_over_limit: true, enforcement_enabled: true }))
    const { app, root } = mountStorageSection()
    await flush()

    expect(root.querySelector('.progress')?.getAttribute('data-color')).toBe('error')
    expect(root.textContent).toContain('Использовано больше лимита')
    expect(root.textContent).toContain('Существующие файлы остаются доступны')
    app.unmount()
  })
})
