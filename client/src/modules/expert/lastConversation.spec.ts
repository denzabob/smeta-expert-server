// @vitest-environment jsdom
import { describe, expect, it } from 'vitest'
import {
  clearExpertLastConversation,
  expertLastConversationStorageKey,
  readExpertLastConversation,
  selectInitialExpertConversation,
  writeExpertLastConversation,
} from './lastConversation'
import type { ExpertConversation } from './types'

const conversations: ExpertConversation[] = [
  { id: 'recent', title: 'Недавний', messages: [] },
  { id: 'older', title: 'Старый', messages: [] },
]

describe('Expert last opened conversation', () => {
  it('restores an existing saved conversation and falls back to API order for a stale id', () => {
    expect(selectInitialExpertConversation(conversations, 'older')).toBe('older')
    expect(selectInitialExpertConversation(conversations, 'deleted')).toBe('recent')
    expect(selectInitialExpertConversation([], 'older')).toBe('')
  })

  it('scopes storage by user and project', () => {
    localStorage.clear()
    const first = expertLastConversationStorageKey(10, 'project-a')
    const second = expertLastConversationStorageKey(11, 'project-a')
    const third = expertLastConversationStorageKey(10, 'project-b')
    writeExpertLastConversation(localStorage, first, 'conversation-1')

    expect(readExpertLastConversation(localStorage, first)).toBe('conversation-1')
    expect(readExpertLastConversation(localStorage, second)).toBeNull()
    expect(readExpertLastConversation(localStorage, third)).toBeNull()

    clearExpertLastConversation(localStorage, first)
    expect(readExpertLastConversation(localStorage, first)).toBeNull()
  })
})
