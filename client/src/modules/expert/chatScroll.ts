export const expertChatBottomThreshold = 96

export interface ExpertChatScrollMetrics {
  scrollTop: number
  scrollHeight: number
  clientHeight: number
}

export function isNearExpertChatBottom(metrics: ExpertChatScrollMetrics): boolean {
  return metrics.scrollHeight - metrics.scrollTop - metrics.clientHeight <= expertChatBottomThreshold
}

export function shouldFollowNewExpertMessage(wasNearBottom: boolean, isOwnMessage: boolean): boolean {
  return wasNearBottom || isOwnMessage
}
