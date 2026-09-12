export type ExpertChatContextKind = 'whole-project' | 'material' | 'normative'

export interface ExpertChatContextSource {
  id: string
  label: string
  detail?: string
  icon?: string
}

export interface ExpertChatContextChip extends ExpertChatContextSource {
  kind: ExpertChatContextKind
}

export interface ExpertChatNormativeSource extends ExpertChatContextSource {
  connected: boolean
}

const wholeProjectContext: ExpertChatContextChip = {
  id: 'whole-project',
  kind: 'whole-project',
  label: 'Весь проект',
  icon: 'mdi-folder-multiple-outline',
}

export function createWholeProjectContext(): ExpertChatContextChip[] {
  return [wholeProjectContext]
}

export function createChatContextDraft(contexts: ExpertChatContextChip[]): ExpertChatContextChip[] {
  return contexts.map((context) => ({ ...context }))
}

export function hasChatContext(contexts: ExpertChatContextChip[], kind: 'material' | 'normative', sourceId: string): boolean {
  return contexts.some((context) => context.kind === kind && context.id === `${kind}:${sourceId}`)
}

export function toggleMaterialChatContext(contexts: ExpertChatContextChip[], material: ExpertChatContextSource): ExpertChatContextChip[] {
  return toggleConcreteContext(contexts, { ...material, id: `material:${material.id}`, kind: 'material' })
}

export function toggleNormativeChatContext(contexts: ExpertChatContextChip[], normative: ExpertChatNormativeSource): ExpertChatContextChip[] {
  if (!normative.connected) return normalizeChatContexts(contexts)

  return toggleConcreteContext(contexts, { ...normative, id: `normative:${normative.id}`, kind: 'normative' })
}

export function removeChatContext(contexts: ExpertChatContextChip[], id: string): ExpertChatContextChip[] {
  const remaining = contexts.filter((context) => context.id !== id)
  if (id === wholeProjectContext.id) return []

  return normalizeChatContexts(remaining)
}

export function selectWholeProjectChatContext(): ExpertChatContextChip[] {
  return createWholeProjectContext()
}

function toggleConcreteContext(contexts: ExpertChatContextChip[], context: ExpertChatContextChip): ExpertChatContextChip[] {
  const concreteContexts = contexts.filter((item) => item.kind !== 'whole-project')
  const exists = concreteContexts.some((item) => item.id === context.id)
  const next = exists ? concreteContexts.filter((item) => item.id !== context.id) : [...concreteContexts, context]

  return normalizeChatContexts(next)
}

function normalizeChatContexts(contexts: ExpertChatContextChip[]): ExpertChatContextChip[] {
  const concreteContexts = contexts.filter((context) => context.kind !== 'whole-project')
  const uniqueContexts = concreteContexts.filter((context, index) => concreteContexts.findIndex((item) => item.id === context.id) === index)

  return uniqueContexts.length ? uniqueContexts : createWholeProjectContext()
}
