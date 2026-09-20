// @vitest-environment jsdom
import { createApp, h, nextTick, ref } from 'vue'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { ExpertMessage, ExpertMessageFeedback } from '../../types'

const calls = vi.hoisted(() => ({ save: vi.fn(), remove: vi.fn() }))
vi.mock('../../api', async (loadActual) => {
  const actual = await loadActual<typeof import('../../api')>()
  return { ...actual, expertApi: { saveMessageFeedback: calls.save, deleteMessageFeedback: calls.remove } }
})
vi.mock('vuetify/components', () => ({
  VBtn: { emits: ['click'], template: '<button @click="$emit(\'click\')"><slot /></button>' },
  VIcon: { template: '<i />' },
  VList: { template: '<ul><slot /></ul>' },
  VListItem: { template: '<li />' },
  VMenu: { template: '<div><slot name="activator" :props="{}" /><slot /></div>' },
}))
vi.mock('vuetify/components/VBtn', () => ({ VBtn: { emits: ['click'], template: '<button @click="$emit(\'click\')"><slot /></button>' } }))
vi.mock('vuetify/components/VIcon', () => ({ VIcon: { template: '<i />' } }))
vi.mock('vuetify/components/VList', () => ({ VList: { template: '<ul><slot /></ul>' }, VListItem: { template: '<li />' } }))
vi.mock('vuetify/components/VListItem', () => ({ VListItem: { template: '<li />' } }))
vi.mock('vuetify/components/VMenu', () => ({ VMenu: { template: '<div><slot name="activator" :props="{}" /><slot /></div>' } }))
vi.mock('vuetify/components/VDialog', () => ({ VDialog: { props: ['modelValue'], template: '<div v-if="modelValue"><slot /></div>' } }))
vi.mock('vuetify/components/VCard', () => ({ VCard: { template: '<div><slot /></div>' }, VCardTitle: { template: '<div><slot /></div>' }, VCardText: { template: '<div><slot /></div>' }, VCardActions: { template: '<div><slot /></div>' } }))
vi.mock('vuetify/components/VGrid', () => ({ VSpacer: { template: '<span />' } }))

import ExpertChatMessage from './ExpertChatMessage.vue'

function mountMessage() {
  const message = ref<ExpertMessage>({ id: 'assistant-1', role: 'assistant', text: 'Ответ.', createdAt: new Date().toISOString(), generationStatus: 'completed' })
  const root = document.createElement('div')
  document.body.append(root)
  const app = createApp({ render: () => h(ExpertChatMessage, {
    message: message.value,
    feedbackEnabled: true,
    onFeedbackUpdated: (_id: string, feedback: ExpertMessageFeedback | null) => { message.value = { ...message.value, feedback } },
  }) })
  app.mount(root)
  return { root, app, message }
}

afterEach(() => { calls.save.mockReset(); calls.remove.mockReset(); document.body.innerHTML = '' })

describe('Expert Chat feedback', () => {
  it('saves, removes, and changes reaction with a negative reason and comment', async () => {
    calls.save.mockImplementation(async (_id: string, input: ExpertMessageFeedback) => input)
    calls.remove.mockResolvedValue(undefined)
    const { root, app } = mountMessage()
    const positive = () => root.querySelector('button[aria-label="Хороший ответ"]') as HTMLButtonElement
    const negative = () => root.querySelector('button[aria-label="Плохой ответ"]') as HTMLButtonElement

    positive().click()
    await vi.waitFor(() => expect(calls.save).toHaveBeenCalledWith('assistant-1', { rating: 'positive' }))
    await vi.waitFor(() => expect(positive().getAttribute('aria-pressed')).toBe('true'))
    positive().click()
    await vi.waitFor(() => expect(calls.remove).toHaveBeenCalledWith('assistant-1'))
    await vi.waitFor(() => expect(negative().disabled).toBe(false))
    negative().click()
    await vi.waitFor(() => expect(calls.save).toHaveBeenCalledWith('assistant-1', { rating: 'negative' }))
    await vi.waitFor(() => expect(root.textContent).toContain('Что было не так?'))
    ;(root.querySelector('.expert-message__feedback-reasons button') as HTMLButtonElement).click()
    const comment = root.querySelector('.expert-message__feedback-comment textarea') as HTMLTextAreaElement
    comment.value = 'Не учтён документ.'
    comment.dispatchEvent(new Event('input', { bubbles: true }))
    await nextTick()
    const submit = [...root.querySelectorAll('button')].find((button) => button.textContent === 'Отправить')!
    submit.click()
    await vi.waitFor(() => expect(calls.save).toHaveBeenCalledWith('assistant-1', {
      rating: 'negative', reasonCode: 'incorrect_or_incomplete', comment: 'Не учтён документ.',
    }))
    app.unmount()
  })

  it('hides reactions on a placeholder or interrupted response', async () => {
    const { root, app, message } = mountMessage()
    message.value = { ...message.value, text: '', deliveryState: 'sending' }
    await nextTick()
    expect(root.querySelector('button[aria-label="Хороший ответ"]')).toBeNull()
    message.value = { ...message.value, text: 'Частичный ответ', deliveryState: 'error', generationStatus: 'interrupted' }
    await nextTick()
    expect(root.querySelector('button[aria-label="Плохой ответ"]')).toBeNull()
    app.unmount()
  })
})
