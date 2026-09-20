// @vitest-environment jsdom
import { createApp, nextTick, ref } from 'vue'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { ExpertStreamHandlers } from '../api'
import type { ExpertProject } from '../types'

const state = vi.hoisted(() => ({
  outcomes: [] as Array<'done' | 'error' | 'stop' | 'delayed'>,
  calls: [] as string[],
  cancel: undefined as (() => void) | undefined,
  start: undefined as (() => void) | undefined,
}))

vi.mock('vuetify', () => ({ useDisplay: () => ({ mdAndDown: ref(false) }) }))
vi.mock('vuetify/components', () => ({
  VBtn: { emits: ['click'], template: '<button @click="$emit(\'click\')"><slot /></button>' },
  VIcon: { template: '<i />' },
  VList: { template: '<ul><slot /></ul>' },
  VListItem: { template: '<li />' },
  VMenu: { template: '<div><slot name="activator" :props="{}" /><slot /></div>' },
  VProgressCircular: { template: '<i />' },
}))
vi.mock('vuetify/components/VBtn', () => ({ VBtn: { emits: ['click'], template: '<button @click="$emit(\'click\')"><slot /></button>' } }))
vi.mock('vuetify/components/VIcon', () => ({ VIcon: { template: '<i />' } }))
vi.mock('vuetify/components/VList', () => ({ VList: { template: '<ul><slot /></ul>' }, VListItem: { template: '<li />' }, VListSubheader: { template: '<li><slot /></li>' } }))
vi.mock('vuetify/components/VListItem', () => ({ VListItem: { template: '<li />' } }))
vi.mock('vuetify/components/VMenu', () => ({ VMenu: { template: '<div><slot name="activator" :props="{}" /><slot /></div>' } }))
vi.mock('vuetify/components/VProgressCircular', () => ({ VProgressCircular: { template: '<i />' } }))
vi.mock('vuetify/components/VChip', () => ({ VChip: { template: '<span><slot /></span>' } }))
vi.mock('vuetify/components/VSelect', () => ({ VSelect: { template: '<select />' } }))
vi.mock('vuetify/components/VAlert', () => ({ VAlert: { template: '<div><slot /></div>' } }))
vi.mock('vuetify/components/VProgressLinear', () => ({ VProgressLinear: { template: '<div />' } }))
vi.mock('vuetify/components/VDialog', () => ({ VDialog: { template: '<div><slot /></div>' } }))
vi.mock('vuetify/components/VCard', () => ({ VCard: { template: '<div><slot /></div>' }, VCardTitle: { template: '<div><slot /></div>' }, VCardText: { template: '<div><slot /></div>' }, VCardActions: { template: '<div><slot /></div>' } }))
vi.mock('vuetify/components/VNavigationDrawer', () => ({ VNavigationDrawer: { template: '<div><slot /></div>' } }))
vi.mock('vuetify/components/VSnackbar', () => ({ VSnackbar: { template: '<div><slot /></div>' } }))
vi.mock('vuetify/components/VTextField', () => ({ VTextField: { template: '<input />' } }))
vi.mock('vuetify/components/VTooltip', () => ({ VTooltip: { template: '<div><slot name="activator" :props="{}" /></div>' } }))
vi.mock('vuetify/components/VDivider', () => ({ VDivider: { template: '<hr />' } }))
vi.mock('vuetify/components/VListSubheader', () => ({ VListSubheader: { template: '<div><slot /></div>' } }))
vi.mock('vuetify/components/VSpacer', () => ({ VSpacer: { template: '<span />' } }))
vi.mock('vuetify/components/VGrid', () => ({ VSpacer: { template: '<span />' } }))
vi.mock('../components/chat/ExpertProjectLibraryPicker.vue', () => ({ default: { template: '<div />' } }))
vi.mock('../components/materials/ExpertMaterialDrawer.vue', () => ({ default: { template: '<div />' } }))
vi.mock('../components/chat/ExpertContextPanel.vue', () => ({ default: { template: '<div />' } }))
vi.mock('../composables/useExpertMaterialTransfers', () => ({
  useExpertMaterialTransfers: () => ({
    uploads: ref([]), thumbnailPreviews: ref({}), imagePreviews: ref({}),
    queueUploads: vi.fn(), loadImageThumbnail: vi.fn(), loadImagePreview: vi.fn(),
    clearUploads: vi.fn(), syncImagePreviews: vi.fn(), dispose: vi.fn(),
    downloadMaterial: vi.fn(), isDownloading: () => false,
  }),
}))
vi.mock('../api', async (loadActual) => {
  const actual = await loadActual<typeof import('../api')>()
  return {
    ...actual,
    expertApi: {
      listConversations: async () => [{ id: 'conversation-1', title: 'Чат', messages: [] }],
      listMessages: async () => [],
      streamMessage: async (_conversation: string, content: string, _clientId: string, _materials: string[], handlers: ExpertStreamHandlers) => {
        const index = state.calls.push(content)
        const runId = 'run-' + index
        const outcome = state.outcomes[index - 1] ?? 'done'
        if (outcome === 'delayed') await new Promise<void>((resolve) => { state.start = resolve })
        handlers.onRun(runId, { id: 'user-' + index, role: 'user', text: content, createdAt: new Date().toISOString() })
        if (outcome === 'stop') {
          await new Promise<void>((resolve) => {
            state.cancel = () => { handlers.onCancelled(); resolve() }
          })
        } else if (outcome === 'error') {
          handlers.onError({ code: 'provider_timeout', message: 'Время ожидания истекло.', validationErrors: {} })
        } else {
          handlers.onDelta(runId, 1, 'Ответ ' + index)
          handlers.onDone({ id: 'assistant-' + index, role: 'assistant', text: 'Ответ ' + index, createdAt: new Date().toISOString(), generationStatus: 'completed' })
        }
      },
      cancelStream: async () => { state.cancel?.() },
    },
  }
})

import ExpertChat from './ExpertChat.vue'

async function mountChat() {
  const root = document.createElement('div')
  document.body.append(root)
  const project = { id: 'project-1', conversations: [], materials: [], counts: { conversations: 0, materials: 0 } } as unknown as ExpertProject
  const app = createApp(ExpertChat, { project, projectMode: 'real' })
  app.component('v-btn', { props: ['disabled'], emits: ['click'], template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>' })
  app.component('v-menu', { template: '<div><slot name="activator" :props="{}" /><slot /></div>' })
  app.component('v-tooltip', { template: '<div><slot name="activator" :props="{}" /></div>' })
  app.component('v-icon', { template: '<i />' })
  app.mount(root)
  await vi.waitFor(() => expect(root.querySelector('textarea[aria-label="Сообщение"]')).not.toBeNull())
  await vi.waitFor(() => expect(project.conversations.length).toBe(1))
  return { root, app }
}

async function typeAndSend(root: HTMLElement, text: string) {
  const textarea = root.querySelector('textarea[aria-label="Сообщение"]')!
  const setter = Object.getOwnPropertyDescriptor(HTMLTextAreaElement.prototype, 'value')!.set!
  setter.call(textarea, text)
  textarea.dispatchEvent(new Event('input', { bubbles: true }))
  await nextTick()
  const send = root.querySelector('button[aria-label="Отправить"]') as HTMLButtonElement
  expect(send.disabled).toBe(false)
  send.click()
  await nextTick()
  expect((root.querySelector('textarea[aria-label="Сообщение"]') as HTMLTextAreaElement).value).toBe('')
  expect(root.textContent).toContain(text)
}

beforeEach(() => { Object.defineProperty(HTMLElement.prototype, 'scrollTo', { configurable: true, value: vi.fn() }) })
afterEach(() => { state.calls = []; state.outcomes = []; state.cancel = undefined; state.start = undefined; document.body.innerHTML = ''; delete (HTMLElement.prototype as Partial<HTMLElement>).scrollTo })

describe('Expert Chat terminal cleanup', () => {
  it('shows an assistant placeholder before the first SSE event', async () => {
    state.outcomes = ['delayed']
    const { root, app } = await mountChat()
    await typeAndSend(root, 'Проверь материалы')
    expect(root.textContent).toContain('Призма')
    expect(root.textContent).toContain('Подготавливаю запрос…')
    await vi.waitFor(() => expect(state.start).toBeTypeOf('function'))
    state.start?.()
    await vi.waitFor(() => expect(root.textContent).toContain('Ответ 1'))
    await vi.waitFor(() => expect(root.textContent).not.toContain('Подготавливаю запрос…'))
    app.unmount()
  })

  it.each(['done', 'error', 'stop'] as const)('allows a second request after %s', async (outcome) => {
    state.outcomes = [outcome, 'done']
    const { root, app } = await mountChat()
    await typeAndSend(root, 'Первый запрос')
    await vi.waitFor(() => expect(state.calls).toHaveLength(1))
    if (outcome === 'stop') {
      await vi.waitFor(() => expect(root.querySelector('button[aria-label="Остановить ответ"]')).not.toBeNull())
      ;(root.querySelector('button[aria-label="Остановить ответ"]') as HTMLButtonElement).click()
    }
    await vi.waitFor(() => expect(root.querySelector('button[aria-label="Отправить"]')).not.toBeNull())
    await nextTick()
    await typeAndSend(root, 'Второй запрос')
    await vi.waitFor(() => expect(state.calls).toEqual(['Первый запрос', 'Второй запрос']))
    app.unmount()
  })
})
