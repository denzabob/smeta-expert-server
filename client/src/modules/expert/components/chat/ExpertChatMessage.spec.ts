import { createSSRApp, h } from 'vue'
import { renderToString } from '@vue/server-renderer'
import { describe, expect, it, vi } from 'vitest'

vi.mock('vuetify/components', () => ({
  VBtn: { template: '<button><slot /></button>' },
  VIcon: { template: '<i />' },
  VList: { template: '<ul><slot /></ul>' },
  VListItem: { template: '<li />' },
  VMenu: { template: '<div><slot /><slot name="activator" :props="{}" /></div>' },
}))
vi.mock('vuetify/components/VBtn', () => ({ VBtn: { template: '<button><slot /></button>' } }))
vi.mock('vuetify/components/VIcon', () => ({ VIcon: { template: '<i />' } }))
vi.mock('vuetify/components/VList', () => ({
  VList: { template: '<ul><slot /></ul>' },
  VListItem: { template: '<li />' },
}))
vi.mock('vuetify/components/VListItem', () => ({ VListItem: { template: '<li />' } }))
vi.mock('vuetify/components/VMenu', () => ({ VMenu: { template: '<div><slot /><slot name="activator" :props="{}" /></div>' } }))
vi.mock('vuetify/components/VProgressCircular', () => ({ VProgressCircular: { template: '<i />' } }))
import type { ExpertMessage } from '../../types'
import ExpertChatMessage from './ExpertChatMessage.vue'

describe('Expert chat failure diagnostics', () => {
  it('renders only the safe error code and run ID inside collapsed details', async () => {
    const message: ExpertMessage = {
      id: 'assistant-1',
      role: 'assistant',
      text: 'Частичный ответ.',
      createdAt: '2026-09-19T10:00:00Z',
      deliveryState: 'error',
      deliveryError: 'Потоковый ответ AI прерван.',
      diagnostic: { runId: 'run-48217', errorCode: 'provider_timeout', retryable: true },
    }
    const app = createSSRApp({ render: () => h(ExpertChatMessage, { message }) })
    app.component('v-icon', { template: '<i />' })
    app.component('v-btn', { template: '<button><slot /></button>' })
    app.component('v-menu', { template: '<div><slot name="activator" :props="{}" /><slot /></div>' })
    app.component('v-list', { template: '<ul><slot /></ul>' })
    app.component('v-list-item', { template: '<li />' })

    const html = await renderToString(app)

    expect(html).toContain('Потоковый ответ AI прерван.')
    expect(html).toContain('Код: provider_timeout')
    expect(html).toContain('ID: run-48217')
    expect(html).toContain('Подробнее')
    expect(html).not.toContain('SECRET-UPSTREAM-BODY')
  })
})
