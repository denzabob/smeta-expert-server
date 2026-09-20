export const expertChatBottomThreshold = 96

export interface ExpertChatScrollMetrics {
  scrollTop: number
  scrollHeight: number
  clientHeight: number
}

export function isNearExpertChatBottom(metrics: ExpertChatScrollMetrics): boolean {
  return metrics.scrollHeight - metrics.scrollTop - metrics.clientHeight <= expertChatBottomThreshold
}

export type ExpertChatFollowEvent = 'own-message' | 'stream-growth' | 'user-scroll-up' | 'user-scroll-bottom' | 'new-messages-click'

export function nextExpertChatFollowState(follow: boolean, event: ExpertChatFollowEvent): boolean {
  if (event === 'user-scroll-up') return false
  if (event === 'stream-growth') return follow
  return true
}
