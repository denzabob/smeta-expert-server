import type { ExpertMessage } from './types'

let optimisticMessageSequence = 0

export function createOptimisticUserMessage(text: string, createdAt = new Date().toISOString()): ExpertMessage {
  optimisticMessageSequence += 1
  const clientMessageId = crypto.randomUUID()

  return {
    id: `local-${clientMessageId}-${optimisticMessageSequence}`,
    role: 'user',
    text,
    createdAt,
    clientMessageId,
    deliveryState: 'sending',
  }
}

export function setExpertMessageDeliveryState(
  messages: ExpertMessage[],
  messageId: string,
  deliveryState: NonNullable<ExpertMessage['deliveryState']>,
  deliveryError?: string,
): ExpertMessage[] {
  return messages.map((message) => message.id === messageId
    ? { ...message, deliveryState, deliveryError, ...(deliveryState === 'sending' ? { diagnostic: undefined } : {}) }
    : message)
}

export function replaceOptimisticExpertMessage(
  messages: ExpertMessage[],
  optimisticId: string,
  savedMessage: ExpertMessage,
): ExpertMessage[] {
  const alreadySaved = messages.some((message) => message.id === savedMessage.id)

  return messages.flatMap((message) => {
    if (message.id !== optimisticId) return [message]
    if (optimisticId === savedMessage.id) return [{ ...savedMessage, deliveryState: 'sent', deliveryError: undefined }]
    if (alreadySaved) return []
    return [{ ...savedMessage, deliveryState: 'sent', deliveryError: undefined }]
  })
}

export function appendUniqueExpertMessage(messages: ExpertMessage[], message: ExpertMessage): ExpertMessage[] {
  return messages.some((item) => item.id === message.id)
    ? messages
    : [...messages, message]
}
