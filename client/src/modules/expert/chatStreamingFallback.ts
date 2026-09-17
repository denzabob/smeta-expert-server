import type { ExpertMessage } from './types'

/**
 * A stream may discover that the selected provider has no streaming
 * capability only after its `run` event. Reuse the same idempotency key in
 * the legacy request only before any assistant response exists.
 */
export function shouldUseLegacyExpertChatFallback(
  errorCode: string | undefined,
  assistantMessage?: ExpertMessage,
): boolean {
  return errorCode === 'streaming_not_supported' && assistantMessage === undefined
}
