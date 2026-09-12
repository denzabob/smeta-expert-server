import { describe, expect, it } from 'vitest'
import { isNearExpertChatBottom, shouldFollowNewExpertMessage } from './chatScroll'

describe('chat scroll policy', () => {
  it('follows a new message only near the bottom, except for the sender own message', () => {
    expect(isNearExpertChatBottom({ scrollTop: 808, scrollHeight: 1000, clientHeight: 100 })).toBe(true)
    expect(isNearExpertChatBottom({ scrollTop: 700, scrollHeight: 1000, clientHeight: 100 })).toBe(false)
    expect(shouldFollowNewExpertMessage(true, false)).toBe(true)
    expect(shouldFollowNewExpertMessage(false, false)).toBe(false)
    expect(shouldFollowNewExpertMessage(false, true)).toBe(true)
  })
})
