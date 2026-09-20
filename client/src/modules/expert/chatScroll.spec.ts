import { describe, expect, it } from 'vitest'
import { isNearExpertChatBottom, nextExpertChatFollowState } from './chatScroll'

describe('chat scroll policy', () => {
  it('detects the bottom independently of response growth', () => {
    expect(isNearExpertChatBottom({ scrollTop: 808, scrollHeight: 1000, clientHeight: 100 })).toBe(true)
    expect(isNearExpertChatBottom({ scrollTop: 700, scrollHeight: 1000, clientHeight: 100 })).toBe(false)
  })
  it('enables follow for own messages and retains it across stream growth', () => {
    expect(nextExpertChatFollowState(false, 'own-message')).toBe(true)
    expect(nextExpertChatFollowState(true, 'stream-growth')).toBe(true)
  })
  it('stops following only on intentional upward scroll and does not steal later deltas', () => {
    const unfollowed = nextExpertChatFollowState(true, 'user-scroll-up')
    expect(unfollowed).toBe(false)
    expect(nextExpertChatFollowState(unfollowed, 'stream-growth')).toBe(false)
  })
  it('restores follow on the new messages button or intentional return to bottom', () => {
    expect(nextExpertChatFollowState(false, 'new-messages-click')).toBe(true)
    expect(nextExpertChatFollowState(false, 'user-scroll-bottom')).toBe(true)
  })
})
