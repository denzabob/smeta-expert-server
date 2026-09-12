export function normalizeExpertChatDraft(value: string): string {
  return value.trim()
}

export function shouldSubmitExpertChatComposer(event: Pick<KeyboardEvent, 'key' | 'shiftKey' | 'isComposing'>): boolean {
  return event.key === 'Enter' && !event.shiftKey && !event.isComposing
}
