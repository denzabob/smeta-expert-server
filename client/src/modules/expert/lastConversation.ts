import type { ExpertConversation } from './types'

const PREFIX = 'expert.chat.lastConversation.v1'

export function expertLastConversationStorageKey(userId: string | number, projectId: string): string {
  return `${PREFIX}:${String(userId)}:${projectId}`
}

export function readExpertLastConversation(storage: Storage, key: string): string | null {
  try {
    return storage.getItem(key)
  } catch {
    return null
  }
}

export function writeExpertLastConversation(storage: Storage, key: string, conversationId: string): void {
  try {
    storage.setItem(key, conversationId)
  } catch {
    // Storage may be unavailable in private/restricted browser contexts.
  }
}

export function clearExpertLastConversation(storage: Storage, key: string): void {
  try {
    storage.removeItem(key)
  } catch {
    // Storage may be unavailable in private/restricted browser contexts.
  }
}

export function selectInitialExpertConversation(
  conversations: ExpertConversation[],
  lastOpenedId: string | null,
): string {
  if (lastOpenedId && conversations.some((conversation) => conversation.id === lastOpenedId)) {
    return lastOpenedId
  }

  return conversations[0]?.id ?? ''
}
