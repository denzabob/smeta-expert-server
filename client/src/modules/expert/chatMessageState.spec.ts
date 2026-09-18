import { describe, expect, it } from 'vitest'
import { appendUniqueExpertMessage, createOptimisticUserMessage, replaceOptimisticExpertMessage, setExpertMessageDeliveryState } from './chatMessageState'
import type { ExpertMessage } from './types'

describe('chat message delivery state', () => {
  it('creates an optimistic user message and exposes its delivery error for retry', () => {
    const optimistic = createOptimisticUserMessage('Проверить расчёт', '2026-09-12T10:20:00.000Z')
    const failed = setExpertMessageDeliveryState([optimistic], optimistic.id, 'error', 'Сеть недоступна')
    const retried = setExpertMessageDeliveryState(failed, optimistic.id, 'sending')

    expect(optimistic).toMatchObject({ role: 'user', text: 'Проверить расчёт', deliveryState: 'sending' })
    expect(optimistic.clientMessageId).toMatch(/^[0-9a-f-]{36}$/i)
    expect(failed[0]).toMatchObject({ deliveryState: 'error', deliveryError: 'Сеть недоступна' })
    expect(retried[0]).toMatchObject({ deliveryState: 'sending', deliveryError: undefined })
  })

  it('replaces an optimistic message with the saved message and never appends a duplicate on retry', () => {
    const optimistic: ExpertMessage = { id: 'local-1', role: 'user', text: 'Сообщение', createdAt: '2026-09-12T10:20:00.000Z', deliveryState: 'sending' }
    const saved: ExpertMessage = { id: 'message-1', role: 'user', text: 'Сообщение', createdAt: '2026-09-12T10:20:01.000Z' }
    const replaced = replaceOptimisticExpertMessage([optimistic], optimistic.id, saved)
    const repeatedResponse = replaceOptimisticExpertMessage(replaced, optimistic.id, saved)

    expect(replaced).toEqual([expect.objectContaining({ id: 'message-1', deliveryState: 'sent' })])
    expect(repeatedResponse.filter((message) => message.id === 'message-1')).toHaveLength(1)
  })

  it('keeps the saved user message when an immutable snapshot is retried with the same server ID', () => {
    const saved: ExpertMessage = { id: 'message-1', role: 'user', text: 'Проверить PDF', createdAt: '2026-09-12T10:20:00.000Z', clientMessageId: 'request-1', deliveryState: 'error', runtimeMaterialContext: [{ id: 'pdf-1', name: 'Акт.pdf', kind: 'document', format: 'PDF', icon: 'mdi-file-pdf-box' }] }
    const retried = setExpertMessageDeliveryState([saved], saved.id, 'sending')
    const afterRun = replaceOptimisticExpertMessage(retried, saved.id, saved)

    expect(afterRun).toHaveLength(1)
    expect(afterRun[0]).toMatchObject({ id: 'message-1', clientMessageId: 'request-1', deliveryState: 'sent' })
    expect(afterRun[0]?.runtimeMaterialContext?.map((item) => item.id)).toEqual(['pdf-1'])
  })

  it('preserves the original runtime material snapshot while a failed message is retried', () => {
    const optimistic: ExpertMessage = {
      id: 'local-1',
      role: 'user',
      text: 'Сообщение',
      createdAt: '2026-09-12T10:20:00.000Z',
      deliveryState: 'sending',
      runtimeMaterialContext: [{ id: 'm1', name: 'Первый.pdf', kind: 'document', format: 'PDF', icon: 'mdi-file-pdf-box' }],
    }
    const failed = setExpertMessageDeliveryState([optimistic], optimistic.id, 'error', 'Сеть недоступна')
    const retried = setExpertMessageDeliveryState(failed, optimistic.id, 'sending')
    const retriedMessage = retried[0]
    if (!retriedMessage) throw new Error('Retried message must remain in the collection')

    expect(retriedMessage.runtimeMaterialContext?.map((context) => context.id)).toEqual(['m1'])
  })

  it('replaces the optimistic user and appends one server assistant response', () => {
    const optimistic: ExpertMessage = { id: 'local-1', role: 'user', text: 'Вопрос', createdAt: '2026-09-12T10:20:00.000Z', deliveryState: 'sending' }
    const saved: ExpertMessage = { id: 'message-1', role: 'user', text: 'Вопрос', createdAt: '2026-09-12T10:20:01.000Z' }
    const assistant: ExpertMessage = { id: 'message-2', role: 'assistant', text: 'Ответ модели', createdAt: '2026-09-12T10:20:02.000Z' }

    const afterUser = replaceOptimisticExpertMessage([optimistic], optimistic.id, saved)
    const afterAssistant = appendUniqueExpertMessage(afterUser, assistant)
    const repeatedReply = appendUniqueExpertMessage(afterAssistant, assistant)

    expect(afterAssistant.map((message) => message.id)).toEqual(['message-1', 'message-2'])
    expect(repeatedReply.filter((message) => message.id === assistant.id)).toHaveLength(1)
  })
})
