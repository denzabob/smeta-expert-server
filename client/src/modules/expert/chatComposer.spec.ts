import { describe, expect, it } from 'vitest'
import { normalizeExpertChatDraft, shouldSubmitExpertChatComposer } from './chatComposer'

describe('chat composer keyboard policy', () => {
  it('sends by Enter, keeps a line break by Shift+Enter and rejects whitespace-only drafts', () => {
    expect(shouldSubmitExpertChatComposer({ key: 'Enter', shiftKey: false, isComposing: false })).toBe(true)
    expect(shouldSubmitExpertChatComposer({ key: 'Enter', shiftKey: true, isComposing: false })).toBe(false)
    expect(shouldSubmitExpertChatComposer({ key: 'Enter', shiftKey: false, isComposing: true })).toBe(false)
    expect(normalizeExpertChatDraft('  \n\t  ')).toBe('')
    expect(normalizeExpertChatDraft('  Нужна проверка  ')).toBe('Нужна проверка')
  })
})
