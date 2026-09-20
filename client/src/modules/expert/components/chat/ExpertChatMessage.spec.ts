import { createSSRApp, h } from 'vue'
import { renderToString } from '@vue/server-renderer'
import { describe, expect, it, vi } from 'vitest'
import { readFileSync } from 'node:fs'

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
vi.mock('vuetify/components/VDialog', () => ({ VDialog: { props: ['modelValue'], template: '<div v-if="modelValue"><slot /></div>' } }))
vi.mock('vuetify/components/VCard', () => ({ VCard: { template: '<div><slot /></div>' }, VCardTitle: { template: '<div><slot /></div>' }, VCardText: { template: '<div><slot /></div>' }, VCardActions: { template: '<div><slot /></div>' } }))
vi.mock('vuetify/components/VGrid', () => ({ VSpacer: { template: '<span />' } }))
import type { ExpertMessage } from '../../types'
import ExpertChatMessage from './ExpertChatMessage.vue'

describe('Expert chat failure diagnostics', () => {
  it('keeps nested list markers inside the padded Markdown bubble without inside positioning', () => {
    const source = readFileSync(new URL('./ExpertChatMessage.vue', import.meta.url), 'utf8')
    expect(source).toMatch(/:deep\(ul\).*:deep\(ol\).*margin-inline: 0; padding-inline-start: 1\.65rem; list-style-position: outside/)
    expect(source).toMatch(/:deep\(li\).*overflow-wrap: anywhere/)
    expect(source).toMatch(/:deep\(li > ul\).*:deep\(li > ol\).*padding-inline-start: 1\.45rem/)
    expect(source).toContain(':deep(pre) { overflow-x: auto;')
    expect(source).toContain(':deep(table) { display: block; max-width: 100%; overflow-x: auto;')
  })
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
    const app = createSSRApp({ render: () => h(ExpertChatMessage, { message, allowContinue: true }) })
    app.component('v-icon', { template: '<i />' })
    app.component('v-btn', { template: '<button><slot /></button>' })
    app.component('v-menu', { template: '<div><slot name="activator" :props="{}" /><slot /></div>' })
    app.component('v-list', { template: '<ul><slot /></ul>' })
    app.component('v-list-item', { template: '<li />' })

    const html = await renderToString(app)

    expect(html).toContain('Потоковый ответ AI прерван.')
    expect(html).toContain('Код: provider_timeout')
    expect(html).toContain('ID: run-48217')
    expect(html).toContain('Повторить')
    expect(html).toContain('Подробнее')
    expect(html).not.toContain('SECRET-UPSTREAM-BODY')
  })

  it('renders persisted image and document attachments on the user message, including deleted history', async () => {
    const message: ExpertMessage = {
      id: 'user-1', role: 'user', text: 'Что на фото?', createdAt: '2026-09-19T10:00:00Z',
      attachments: [
        { id: 'image-1', name: 'Дефект.jpg', mimeType: 'image/jpeg', sizeBytes: 123, kind: 'image', available: true, icon: 'mdi-image-outline' },
        { id: 'pdf-1', name: 'Акт.pdf', mimeType: 'application/pdf', sizeBytes: 456, kind: 'document', available: false, icon: 'mdi-file-pdf-box' },
      ],
    }
    const app = createSSRApp({ render: () => h(ExpertChatMessage, { message, imagePreviews: { 'image-1': { status: 'ready', url: 'blob:thumbnail' } } }) })
    app.component('v-icon', { template: '<i />' })
    app.component('v-btn', { template: '<button><slot /></button>' })

    const html = await renderToString(app)

    expect(html).toContain('Дефект.jpg')
    expect(html).toContain('blob:thumbnail')
    expect(html).toContain('Акт.pdf')
    expect(html).toContain('Материал удалён')
    expect(html).toContain('disabled')
  })
})
