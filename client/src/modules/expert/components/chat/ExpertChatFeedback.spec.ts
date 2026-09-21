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
  VBtn: { props: ['loading'], emits: ['click'], template: '<button :data-loading="loading ? \'true\' : undefined" @click="$emit(\'click\')"><span v-if="loading" data-test="button-spinner" /><slot /></button>' },
  VIcon: { template: '<i />' },
  VList: { template: '<ul><slot /></ul>' },
  VListItem: { template: '<li />' },
  VMenu: { template: '<div><slot name="activator" :props="{}" /><slot /></div>' },
}))
vi.mock('vuetify/components/VBtn', () => ({ VBtn: { props: ['loading'], emits: ['click'], template: '<button :data-loading="loading ? \'true\' : undefined" @click="$emit(\'click\')"><span v-if="loading" data-test="button-spinner" /><slot /></button>' } }))
vi.mock('vuetify/components/VIcon', () => ({ VIcon: { template: '<i />' } }))
vi.mock('vuetify/components/VList', () => ({ VList: { template: '<ul><slot /></ul>' }, VListItem: { template: '<li />' } }))
vi.mock('vuetify/components/VListItem', () => ({ VListItem: { template: '<li />' } }))
vi.mock('vuetify/components/VMenu', () => ({ VMenu: { template: '<div><slot name="activator" :props="{}" /><slot /></div>' } }))
vi.mock('vuetify/components/VDialog', () => ({ VDialog: { props: ['modelValue'], template: '<div v-if="modelValue"><slot /></div>' } }))
vi.mock('vuetify/components/VCard', () => ({ VCard: { template: '<div><slot /></div>' }, VCardTitle: { template: '<div><slot /></div>' }, VCardText: { template: '<div><slot /></div>' }, VCardActions: { template: '<div><slot /></div>' } }))
vi.mock('vuetify/components/VGrid', () => ({ VSpacer: { template: '<span />' } }))
vi.mock('vuetify/components/VSnackbar', () => ({ VSnackbar: { props: ['modelValue'], template: '<div v-if="modelValue"><slot /></div>' } }))

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
  it('shows loading only on the reaction whose request is pending', async () => {
    let resolveSave: ((feedback: ExpertMessageFeedback) => void) | undefined
    calls.save.mockImplementation(() => new Promise<ExpertMessageFeedback>((resolve) => { resolveSave = resolve }))
    const { root, app } = mountMessage()
    const positive = root.querySelector('button[aria-label="Хороший ответ"]') as HTMLButtonElement
    const negative = root.querySelector('button[aria-label="Плохой ответ"]') as HTMLButtonElement

    positive.click()
    await nextTick()
    expect(positive.dataset.loading).toBe('true')
    expect(positive.querySelector('[data-test="button-spinner"]')).not.toBeNull()
    expect(negative.dataset.loading).toBeUndefined()
    expect(negative.querySelector('[data-test="button-spinner"]')).toBeNull()

    resolveSave?.({ rating: 'positive' })
    await vi.waitFor(() => expect(positive.dataset.loading).toBeUndefined())
    expect(positive.getAttribute('aria-pressed')).toBe('true')
    app.unmount()
  })

  it('keeps the current rating until negative feedback is submitted and shows active states', async () => {
    calls.save.mockImplementation(async (_id: string, input: ExpertMessageFeedback) => input)
    calls.remove.mockResolvedValue(undefined)
    const { root, app } = mountMessage()
    const positive = () => root.querySelector('button[aria-label="Хороший ответ"]') as HTMLButtonElement
    const negative = () => root.querySelector('button[aria-label="Плохой ответ"]') as HTMLButtonElement

    positive().click()
    await vi.waitFor(() => expect(calls.save).toHaveBeenCalledWith('assistant-1', { rating: 'positive' }))
    await vi.waitFor(() => expect(positive().getAttribute('aria-pressed')).toBe('true'))
    expect(positive().getAttribute('icon')).toBe('mdi-thumb-up')
    expect(root.textContent).toContain('Спасибо, оценка сохранена.')

    negative().click()
    await vi.waitFor(() => expect(root.textContent).toContain('Что было не так?'))
    expect(calls.save).toHaveBeenCalledTimes(1)
    const close = [...root.querySelectorAll('button')].find((button) => button.textContent === 'Закрыть')!
    close.click()
    await nextTick()
    expect(positive().getAttribute('aria-pressed')).toBe('true')
    expect(calls.save).toHaveBeenCalledTimes(1)

    negative().click()
    await nextTick()
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
    await vi.waitFor(() => expect(negative().getAttribute('aria-pressed')).toBe('true'))
    expect(negative().getAttribute('icon')).toBe('mdi-thumb-down')
    expect(root.textContent).toContain('Спасибо за обратную связь.')

    positive().click()
    await vi.waitFor(() => expect(calls.save).toHaveBeenLastCalledWith('assistant-1', { rating: 'positive' }))
    await vi.waitFor(() => expect(positive().getAttribute('aria-pressed')).toBe('true'))
    positive().click()
    await vi.waitFor(() => expect(calls.remove).toHaveBeenCalledWith('assistant-1'))
    await vi.waitFor(() => expect(root.textContent).toContain('Оценка удалена.'))
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
