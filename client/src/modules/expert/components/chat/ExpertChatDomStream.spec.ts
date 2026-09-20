// @vitest-environment jsdom
import type { AxiosInstance } from 'axios'
import { createApp, h, nextTick, ref } from 'vue'
import { afterEach, describe, expect, it, vi } from 'vitest'
vi.mock('vuetify/components', () => ({
  VBtn: { template: '<button><slot /></button>' },
  VIcon: { template: '<i />' },
  VList: { template: '<ul><slot /></ul>' },
  VListItem: { template: '<li />' },
  VMenu: { template: '<div><slot name="activator" :props="{}" /><slot /></div>' },
  VProgressCircular: { template: '<i />' },
  VSnackbar: { template: '<div><slot /></div>' },
}))
vi.mock('vuetify/components/VIcon', () => ({ VIcon: { template: '<i />' } }))
vi.mock('vuetify/components/VProgressCircular', () => ({ VProgressCircular: { template: '<i />' } }))
vi.mock('vuetify/components/VBtn', () => ({ VBtn: { template: '<button><slot /></button>' } }))
vi.mock('vuetify/components/VList', () => ({ VList: { template: '<ul><slot /></ul>' }, VListItem: { template: '<li />' } }))
vi.mock('vuetify/components/VListItem', () => ({ VListItem: { template: '<li />' } }))
vi.mock('vuetify/components/VMenu', () => ({ VMenu: { template: '<div><slot name="activator" :props="{}" /><slot /></div>' } }))
vi.mock('vuetify/components/VDialog', () => ({ VDialog: { template: '<div><slot /></div>' } }))
vi.mock('vuetify/components/VSnackbar', () => ({ VSnackbar: { template: '<div><slot /></div>' } }))
vi.mock('vuetify/components/VCard', () => ({ VCard: { template: '<div><slot /></div>' }, VCardTitle: { template: '<div><slot /></div>' }, VCardText: { template: '<div><slot /></div>' }, VCardActions: { template: '<div><slot /></div>' } }))
vi.mock('vuetify/components/VGrid', () => ({ VSpacer: { template: '<span />' } }))
import { createExpertApi } from '../../api'
import { renderExpertAssistantMarkdown } from '../../chatMarkdown'
import type { ExpertMessage } from '../../types'
import ExpertChatMessage from './ExpertChatMessage.vue'
import ExpertChatActivityTimeline from './ExpertChatActivityTimeline.vue'
import { applyExpertTimelineActivity, createExpertTimelineRun, finishExpertTimelineRun, type ExpertTimelineRun } from '../../chatTimeline'

const encoder = new TextEncoder()
const pause = (ms: number) => new Promise((resolve) => setTimeout(resolve, ms))

function frame(event: string, data: Record<string, unknown>) {
  return encoder.encode(`event: ${event}\ndata: ${JSON.stringify(data)}\n\n`)
}

afterEach(() => { vi.unstubAllGlobals() })

describe('Expert Chat real DOM stream', () => {
  it('sanitizes assistant Markdown and leaves user text literal', async () => {
    const html = renderExpertAssistantMarkdown('**bold**\n\n### title\n\n<script>alert(1)</script>\n\n[bad](javascript:alert(1))\n\n| A | B |\n|---|---|\n| 1 | 2 |')
    const node = document.createElement('div')
    node.innerHTML = html
    expect(node.querySelector('strong')?.textContent).toBe('bold')
    expect(node.querySelector('h3')?.textContent).toBe('title')
    expect(node.querySelector('table')).not.toBeNull()
    expect(node.querySelector('script')).toBeNull()
    expect(node.querySelector('a[href^="javascript:"]')).toBeNull()

    const user = ref<ExpertMessage>({ id: 'user-1', role: 'user', text: '**bold**', createdAt: '2026-09-19T10:00:00Z' })
    const root = document.createElement('div')
    const app = createApp({ render: () => h(ExpertChatMessage, { message: user.value }) })
    app.component('v-icon', { template: '<i />' })
    app.component('v-btn', { template: '<button><slot /></button>' })
    app.component('v-menu', { template: '<div><slot name="activator" :props="{}" /><slot /></div>' })
    app.component('v-list', { template: '<ul><slot /></ul>' })
    app.component('v-list-item', { template: '<li />' })
    app.mount(root)
    expect(root.querySelector('.expert-message__bubble')?.textContent).toBe('**bold**')
    expect(root.querySelector('strong')).toBeNull()
    app.unmount()
  })

  it('changes assistant DOM between two controlled network deltas before done', async () => {
    let controller!: ReadableStreamDefaultController<Uint8Array>
    const stream = new ReadableStream<Uint8Array>({ start(value) { controller = value } })
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(stream, { status: 200, headers: { 'Content-Type': 'text/event-stream' } })))
    const client = createExpertApi({ getUri: () => 'https://expert.test' } as unknown as AxiosInstance)
    const assistant = ref<ExpertMessage>({ id: 'assistant-1', role: 'assistant', text: '', createdAt: '2026-09-19T10:00:00Z', deliveryState: 'sending' })
    const root = document.createElement('div')
    const app = createApp({ render: () => h(ExpertChatMessage, { message: assistant.value }) })
    app.component('v-icon', { template: '<i />' })
    app.component('v-btn', { template: '<button><slot /></button>' })
    app.component('v-menu', { template: '<div><slot name="activator" :props="{}" /><slot /></div>' })
    app.component('v-list', { template: '<ul><slot /></ul>' })
    app.component('v-list-item', { template: '<li />' })
    app.mount(root)

    let done = false
    let deltaCount = 0
    const request = client.streamMessage('conversation-1', 'Вопрос', 'message-1', [], {
      onRun: () => undefined,
      onDelta: (_runId, _seq, text) => { assistant.value = { ...assistant.value, text: assistant.value.text + text }; deltaCount++ },
      onDone: () => { done = true; assistant.value = { ...assistant.value, deliveryState: 'sent' } },
      onCancelled: () => undefined,
      onError: () => undefined,
    })
    controller.enqueue(frame('run', { version: 1, run_id: 'run-1', user_message: { public_id: 'u1', role: 'user', content: 'Вопрос', created_at: '2026-09-19T10:00:00Z' } }))
    controller.enqueue(frame('delta', { version: 1, seq: 1, text: 'Первый абзац.' }))
    await vi.waitFor(() => expect(deltaCount).toBe(1))
    await pause(60)
    await nextTick()
    expect(root.textContent).toContain('Первый абзац.')
    expect(root.textContent).not.toContain('Второй абзац.')
    expect(done).toBe(false)

    controller.enqueue(frame('delta', { version: 1, seq: 2, text: '\n\nВторой абзац.' }))
    await vi.waitFor(() => expect(deltaCount).toBe(2))
    await pause(60)
    await nextTick()
    expect(root.querySelectorAll('.expert-message__markdown p')).toHaveLength(2)
    expect(root.textContent).toContain('Первый абзац.')
    expect(root.textContent).toContain('Второй абзац.')
    expect(done).toBe(false)

    controller.enqueue(frame('done', { version: 1 }))
    controller.close()
    await request
    expect(done).toBe(true)
    app.unmount()
  })

  it('shows live activity A then B before the first content delta and collapses after done', async () => {
    let controller!: ReadableStreamDefaultController<Uint8Array>
    const stream = new ReadableStream<Uint8Array>({ start(value) { controller = value } })
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(stream, { status: 200, headers: { 'Content-Type': 'text/event-stream' } })))
    const client = createExpertApi({ getUri: () => 'https://expert.test' } as unknown as AxiosInstance)
    const runs = ref<ExpertTimelineRun[]>([])
    const active = ref(true)
    const root = document.createElement('div')
    const app = createApp({ render: () => active.value ? h(ExpertChatActivityTimeline, { runs: runs.value }) : null })
    app.component('v-icon', { template: '<i />' })
    app.component('v-progress-circular', { template: '<i />' })
    app.mount(root)
    let firstDelta = false
    const request = client.streamMessage('conversation-1', 'Вопрос', 'message-1', [], {
      onRun: (runId) => { runs.value = [{ ...createExpertTimelineRun(runId), significant: true }] },
      onDelta: () => { firstDelta = true },
      onActivity: (activity) => { runs.value = applyExpertTimelineActivity(runs.value, activity) },
      onDone: () => { runs.value = finishExpertTimelineRun(runs.value, 'run-1', 'completed'); active.value = false },
      onCancelled: () => undefined,
      onError: () => undefined,
    })
    controller.enqueue(frame('run', { version: 1, run_id: 'run-1', user_message: { public_id: 'u1', role: 'user', content: 'Вопрос', created_at: '2026-09-19T10:00:00Z' } }))
    controller.enqueue(frame('activity', { version: 1, run_id: 'run-1', seq: 1, activity_id: 'a', code: 'material.image_prepare.started', status: 'started', category: 'material' }))
    await vi.waitFor(() => expect(root.textContent).toContain('Подготавливаю материалы'))
    expect(root.textContent).not.toContain('Проверяю текстовый слой PDF')
    expect(firstDelta).toBe(false)

    controller.enqueue(frame('activity', { version: 1, run_id: 'run-1', seq: 2, activity_id: 'a', code: 'material.image_prepare.completed', status: 'completed', category: 'material' }))
    controller.enqueue(frame('activity', { version: 1, run_id: 'run-1', seq: 3, activity_id: 'b', code: 'pdf.local_extract.started', status: 'started', category: 'material' }))
    await vi.waitFor(() => expect(root.textContent).toContain('Проверяю PDF'))
    expect(root.textContent).not.toContain('Изображение подготовлено')
    expect(firstDelta).toBe(false)

    controller.enqueue(frame('delta', { version: 1, seq: 1, text: 'Ответ.' }))
    await vi.waitFor(() => expect(firstDelta).toBe(true))
    controller.enqueue(frame('done', { version: 1 }))
    controller.close()
    await request
    await nextTick()
    expect(root.textContent).toBe('')
    app.unmount()
  })
})
