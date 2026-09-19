// @vitest-environment jsdom
import { createApp, nextTick } from 'vue'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { expertApi } from '../api'
import type { ExpertProject, ExpertProjectMaterial } from '../types'

vi.mock('vuetify', () => ({ useDisplay: () => ({ mdAndDown: { value: false } }) }))
vi.mock('../components/chat/ExpertChatComposer.vue', () => ({
  default: {
    props: ['busy', 'uploadItems', 'materialContexts'],
    emits: ['send', 'attach-files'],
    data: () => ({ draft: '' }),
    methods: { dropPair(this: { $emit: (event: string, files: File[]) => void }) { this.$emit('attach-files', [new File(['one'], 'Первый.pdf'), new File(['two'], 'Второй.pdf')]) } },
    template: '<div><input aria-label="Текст сообщения" v-model="draft"><button :disabled="busy" @click="$emit(\'send\', draft)">Отправить</button><button data-test="drop-pair" @click="dropPair">Drop</button><span data-test="busy">{{ busy }}</span><span data-test="upload-state">{{ uploadItems.map(item => item.state).join(\',\') }}</span><span data-test="selected">{{ materialContexts.map(item => item.name).join(\',\') }}</span></div>',
  },
}))
vi.mock('../components/chat/ExpertChatMessage.vue', () => ({
  default: { props: ['message', 'allowContinue'], emits: ['retry', 'continue'], template: '<article>{{ message.text }}<span v-if="message.deliveryState === \'error\'">{{ message.deliveryError }}</span><button v-if="message.role === \'user\' && message.deliveryState === \'error\'" @click="$emit(\'retry\', message.id)">Повторить сообщение</button><button v-if="allowContinue" @click="$emit(\'continue\', message.id)">Продолжить ответ</button></article>' },
}))
vi.mock('../components/chat/ExpertContextPanel.vue', () => ({ default: { template: '<div />' } }))
vi.mock('../components/chat/ExpertProjectLibraryPicker.vue', () => ({ default: { template: '<div />' } }))
vi.mock('../components/materials/ExpertMaterialDrawer.vue', () => ({ default: { template: '<div />' } }))
vi.mock('vuetify/components', () => ({
  VBtn: { template: '<button><slot /></button>' },
  VIcon: { template: '<i />' },
  VMenu: { template: '<div><slot name="activator" :props="{}" /><slot /></div>' },
  VList: { template: '<div><slot /></div>' },
  VListItem: { template: '<div />' },
  VListSubheader: { template: '<div><slot /></div>' },
  VDivider: { template: '<hr>' },
  VProgressLinear: { template: '<div />' },
  VAlert: { template: '<div><slot /></div>' },
  VDialog: { template: '<div><slot /></div>' },
  VCard: { template: '<div><slot /></div>' },
  VCardTitle: { template: '<div><slot /></div>' },
  VCardText: { template: '<div><slot /></div>' },
  VCardActions: { template: '<div><slot /></div>' },
  VTextField: { template: '<input>' },
  VSpacer: { template: '<div />' },
  VSnackbar: { template: '<div><slot /></div>' },
}))
vi.mock('vuetify/components/VBtn', () => ({ VBtn: { template: '<button><slot /></button>' } }))
vi.mock('vuetify/components/VIcon', () => ({ VIcon: { template: '<i />' } }))
vi.mock('vuetify/components/VMenu', () => ({ VMenu: { template: '<div><slot name="activator" :props="{}" /><slot /></div>' } }))
vi.mock('vuetify/components/VList', () => ({ VList: { template: '<div><slot /></div>' }, VListItem: { template: '<div />' }, VListSubheader: { template: '<div><slot /></div>' } }))
vi.mock('vuetify/components/VListItem', () => ({ VListItem: { template: '<div />' } }))
vi.mock('vuetify/components/VDivider', () => ({ VDivider: { template: '<hr>' } }))
vi.mock('vuetify/components/VAlert', () => ({ VAlert: { template: '<div><slot /><slot name="append" /></div>' } }))
vi.mock('vuetify/components/VProgressLinear', () => ({ VProgressLinear: { template: '<div />' } }))
vi.mock('vuetify/components/VTooltip', () => ({ VTooltip: { template: '<div><slot name="activator" :props="{}" /></div>' } }))
vi.mock('vuetify/components/VNavigationDrawer', () => ({ VNavigationDrawer: { template: '<div><slot /></div>' } }))
vi.mock('vuetify/components/VDialog', () => ({ VDialog: { template: '<div><slot /></div>' } }))
vi.mock('vuetify/components/VCard', () => ({ VCard: { template: '<div><slot /></div>' }, VCardTitle: { template: '<div><slot /></div>' }, VCardText: { template: '<div><slot /></div>' }, VCardActions: { template: '<div><slot /></div>' } }))
vi.mock('vuetify/components/VCardTitle', () => ({ VCardTitle: { template: '<div><slot /></div>' } }))
vi.mock('vuetify/components/VCardText', () => ({ VCardText: { template: '<div><slot /></div>' } }))
vi.mock('vuetify/components/VCardActions', () => ({ VCardActions: { template: '<div><slot /></div>' } }))
vi.mock('vuetify/components/VTextField', () => ({ VTextField: { template: '<input>' } }))
vi.mock('vuetify/components/VSnackbar', () => ({ VSnackbar: { template: '<div><slot /></div>' } }))
vi.mock('vuetify/components/VSpacer', () => ({ VSpacer: { template: '<div />' } }))
vi.mock('vuetify/components/VGrid', () => ({ VSpacer: { template: '<div />' } }))
vi.mock('vuetify/components/VListSubheader', () => ({ VListSubheader: { template: '<div><slot /></div>' } }))

import ExpertChat from './ExpertChat.vue'

afterEach(() => { vi.restoreAllMocks() })

describe('Expert Chat recovery', () => {
  it('routes a dropped pair through project uploads and selects both completed materials', async () => {
    Element.prototype.scrollTo = vi.fn()
    const project = { id: 'project-1', title: 'Проект', conversations: [], materials: [], quickActions: [] } as unknown as ExpertProject
    vi.spyOn(expertApi, 'listConversations').mockResolvedValue([{ id: 'conversation-1', title: 'Чат', messages: [] }])
    vi.spyOn(expertApi, 'listMessages').mockResolvedValue([])
    const completed: ((material: ExpertProjectMaterial) => void)[] = []
    const upload = vi.spyOn(expertApi, 'uploadMaterial').mockImplementation((_projectId, _file, options) => {
      options?.onProgress?.(42)
      return new Promise<ExpertProjectMaterial>((resolve) => { completed.push(resolve) })
    })
    const root = document.createElement('div')
    document.body.append(root)
    const app = createApp(ExpertChat, { project, projectMode: 'real' })
    app.mount(root)
    await vi.waitFor(() => expect(expertApi.listMessages).toHaveBeenCalled())

    ;(root.querySelector('[data-test="drop-pair"]') as HTMLButtonElement).click()
    await vi.waitFor(() => expect(upload).toHaveBeenCalledTimes(2))
    expect(root.querySelector('[data-test="upload-state"]')?.textContent).toBe('uploading,uploading')
    completed[0]?.({ id: 'material-1', name: 'Первый.pdf', kind: 'document', icon: 'mdi-file-pdf-box', size: '3 Б', format: 'PDF' } as ExpertProjectMaterial)
    completed[1]?.({ id: 'material-2', name: 'Второй.pdf', kind: 'document', icon: 'mdi-file-pdf-box', size: '3 Б', format: 'PDF' } as ExpertProjectMaterial)
    await vi.waitFor(() => expect(root.querySelector('[data-test="selected"]')?.textContent).toBe('Первый.pdf,Второй.pdf'))
    expect(project.materials.map((material) => material.id)).toEqual(['material-2', 'material-1'])
    app.unmount()
    root.remove()
  })

  it('accepts a new text request after a PDF stream failure without reloading', async () => {
    Element.prototype.scrollTo = vi.fn()
    const project = {
      id: 'project-1', title: 'Проект', conversations: [], materials: [], quickActions: [],
    } as unknown as ExpertProject
    vi.spyOn(expertApi, 'listConversations').mockResolvedValue([{ id: 'conversation-1', title: 'Общий анализ', messages: [] }])
    vi.spyOn(expertApi, 'listMessages').mockResolvedValue([])
    const stream = vi.spyOn(expertApi, 'streamMessage')
      .mockImplementationOnce(async (_conversationId, content, _messageId, _materials, handlers) => {
        handlers.onRun('failed-run', { id: 'saved-user-1', role: 'user', text: content, createdAt: new Date().toISOString() })
        handlers.onError({ code: 'pdf_malformed', message: 'Структура PDF повреждена.', validationErrors: {} })
      })
      .mockImplementationOnce(async (_conversationId, content, _messageId, _materials, handlers) => {
        handlers.onRun('next-run', { id: 'saved-user-2', role: 'user', text: content, createdAt: new Date().toISOString() })
        handlers.onDelta('next-run', 1, 'OK')
        handlers.onDone({ id: 'assistant-2', role: 'assistant', text: 'OK', createdAt: new Date().toISOString() })
      })
    const root = document.createElement('div')
    document.body.append(root)
    const app = createApp(ExpertChat, { project, projectMode: 'real' })
    app.mount(root)
    await vi.waitFor(() => expect(expertApi.listMessages).toHaveBeenCalled())

    const input = root.querySelector('input[aria-label="Текст сообщения"]') as HTMLInputElement
    const send = root.querySelector('button:not(.expert-chat__conversation)') as HTMLButtonElement
    input.value = 'Проверь PDF'
    input.dispatchEvent(new Event('input', { bubbles: true }))
    send.click()
    await vi.waitFor(() => expect(stream).toHaveBeenCalledTimes(1))
    await vi.waitFor(() => expect(root.textContent).toContain('Структура PDF повреждена.'))
    expect(send.disabled).toBe(false)
    expect(root.querySelector('[data-test="busy"]')?.textContent).toBe('false')

    input.value = 'Ответь: OK'
    input.dispatchEvent(new Event('input', { bubbles: true }))
    await nextTick()
    send.click()
    await vi.waitFor(() => expect(stream).toHaveBeenCalledTimes(2))
    await vi.waitFor(() => expect(root.textContent).toContain('OK'))
    expect(stream.mock.calls[1]?.[1]).toBe('Ответь: OK')
    expect(send.disabled).toBe(false)
    app.unmount()
    root.remove()
  })

  it('retries a failed loaded user message with its persisted material IDs', async () => {
    Element.prototype.scrollTo = vi.fn()
    const project = { id: 'project-1', title: 'Проект', conversations: [], materials: [], quickActions: [] } as unknown as ExpertProject
    vi.spyOn(expertApi, 'listConversations').mockResolvedValue([{ id: 'conversation-1', title: 'Чат', messages: [] }])
    vi.spyOn(expertApi, 'listMessages').mockResolvedValue([{
      id: 'saved-user', role: 'user', text: 'Прочитай файл', createdAt: '2026-09-19T10:00:00Z',
      clientMessageId: 'original-client-id', metadata: { client_message_id: 'original-client-id' },
      attachments: [{ id: 'original-material', name: 'Акт.pdf', mimeType: 'application/pdf', sizeBytes: 10, kind: 'document', available: true, icon: 'mdi-file-outline' }],
    }])
    const stream = vi.spyOn(expertApi, 'streamMessage').mockImplementation(async (_conversationId, _content, _messageId, _ids, handlers) => {
      handlers.onRun('retry-run', { id: 'saved-user', role: 'user', text: 'Прочитай файл', createdAt: '2026-09-19T10:00:00Z', attachments: [] })
      handlers.onDone({ id: 'assistant-1', role: 'assistant', text: 'Ответ', createdAt: '2026-09-19T10:00:01Z' })
    })
    const root = document.createElement('div')
    document.body.append(root)
    const app = createApp(ExpertChat, { project, projectMode: 'real' })
    app.mount(root)
    await vi.waitFor(() => expect(root.textContent).toContain('Повторить сообщение'))
    ;(Array.from(root.querySelectorAll('button')).find((button) => button.textContent === 'Повторить сообщение') as HTMLButtonElement).click()
    await vi.waitFor(() => expect(stream).toHaveBeenCalled())
    expect(stream.mock.calls[0]?.slice(0, 4)).toEqual(['conversation-1', 'Прочитай файл', 'original-client-id', ['original-material']])
    app.unmount()
    root.remove()
  })

  it('continues a stopped loaded assistant without a browser snapshot', async () => {
    Element.prototype.scrollTo = vi.fn()
    const project = { id: 'project-1', title: 'Проект', conversations: [], materials: [], quickActions: [] } as unknown as ExpertProject
    vi.spyOn(expertApi, 'listConversations').mockResolvedValue([{ id: 'conversation-1', title: 'Чат', messages: [] }])
    vi.spyOn(expertApi, 'listMessages').mockResolvedValue([
      { id: 'saved-user', role: 'user', text: 'Прочитай файл', createdAt: '2026-09-19T10:00:00Z', attachments: [] },
      { id: 'assistant-1', role: 'assistant', text: 'Часть ответа', createdAt: '2026-09-19T10:00:01Z', generationStatus: 'stopped', metadata: { in_reply_to: 'saved-user', generation_status: 'stopped' } },
    ])
    const stream = vi.spyOn(expertApi, 'streamMessage').mockImplementation(async (_conversationId, _content, _messageId, _ids, handlers) => {
      handlers.onRun('continue-run', { id: 'saved-user', role: 'user', text: 'Прочитай файл', createdAt: '2026-09-19T10:00:00Z' })
      handlers.onDone({ id: 'assistant-1', role: 'assistant', text: 'Полный ответ', createdAt: '2026-09-19T10:00:01Z', generationStatus: 'completed' })
    })
    const root = document.createElement('div')
    document.body.append(root)
    const app = createApp(ExpertChat, { project, projectMode: 'real' })
    app.mount(root)
    await vi.waitFor(() => expect(root.textContent).toContain('Продолжить ответ'))
    ;(Array.from(root.querySelectorAll('button')).find((button) => button.textContent === 'Продолжить ответ') as HTMLButtonElement).click()
    await vi.waitFor(() => expect(stream).toHaveBeenCalled())
    expect(stream.mock.calls[0]?.[1]).toBe('')
    expect(stream.mock.calls[0]?.[3]).toEqual([])
    expect(stream.mock.calls[0]?.[6]).toBe('assistant-1')
    app.unmount()
    root.remove()
  })
})
