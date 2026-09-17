import { describe, expect, it } from 'vitest'
import { shouldUseLegacyExpertChatFallback } from './chatStreamingFallback'

describe('shouldUseLegacyExpertChatFallback', () => {
  it('uses the same user request for an unsupported stream before any assistant output', () => {
    expect(shouldUseLegacyExpertChatFallback('streaming_not_supported')).toBe(true)
  })

  it('does not restart a stream that already produced an assistant message', () => {
    expect(shouldUseLegacyExpertChatFallback('streaming_not_supported', {
      id: 'assistant-1', role: 'assistant', text: 'Частичный ответ', createdAt: '2026-09-16T00:00:00Z',
    })).toBe(false)
  })

  it('does not treat other terminal stream errors as a legacy fallback', () => {
    expect(shouldUseLegacyExpertChatFallback('expert_stream_interrupted')).toBe(false)
  })
})
