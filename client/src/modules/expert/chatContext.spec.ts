import { describe, expect, it } from 'vitest'
import {
  createChatContextDraft,
  createWholeProjectContext,
  removeChatContext,
  selectWholeProjectChatContext,
  toggleMaterialChatContext,
  toggleNormativeChatContext,
} from './chatContext'

const material = { id: 'material-1', label: 'Проектная документация.pdf', detail: '48 страниц', icon: 'mdi-file-pdf-box' }
const secondMaterial = { id: 'material-2', label: 'Акт обследования.docx', detail: '9 страниц', icon: 'mdi-file-word-outline' }
const normative = { id: 'normative-1', label: 'СП 70.13330.2012', detail: 'Несущие конструкции', icon: 'mdi-book-open-page-variant-outline', connected: true }

describe('chat context', () => {
  it('starts with an explicit whole-project chip', () => {
    expect(createWholeProjectContext()).toEqual([expect.objectContaining({ id: 'whole-project', kind: 'whole-project', label: 'Весь проект' })])
  })

  it('replaces whole project with a material and keeps multiple concrete materials', () => {
    const first = toggleMaterialChatContext(createWholeProjectContext(), material)
    const next = toggleMaterialChatContext(first, secondMaterial)

    expect(first).toEqual([expect.objectContaining({ kind: 'material', label: material.label })])
    expect(next).toHaveLength(2)
    expect(next.map((item) => item.kind)).toEqual(['material', 'material'])
  })

  it('adds a connected normative, but omits a disconnected one', () => {
    const withNormative = toggleNormativeChatContext(createWholeProjectContext(), normative)
    const disconnected = toggleNormativeChatContext(withNormative, { ...normative, id: 'normative-2', connected: false })

    expect(withNormative).toEqual([expect.objectContaining({ kind: 'normative', label: normative.label })])
    expect(disconnected).toEqual(withNormative)
  })

  it('removes a chip and restores whole project after the last concrete context', () => {
    const contexts = toggleNormativeChatContext(toggleMaterialChatContext(createWholeProjectContext(), material), normative)
    const afterMaterialRemoval = removeChatContext(contexts, 'material:material-1')
    const afterLastRemoval = removeChatContext(afterMaterialRemoval, 'normative:normative-1')

    expect(afterMaterialRemoval).toEqual([expect.objectContaining({ kind: 'normative' })])
    expect(afterLastRemoval).toEqual(createWholeProjectContext())
  })

  it('never mixes whole-project and concrete contexts, and can explicitly restore whole project after closing it', () => {
    const empty = removeChatContext(createWholeProjectContext(), 'whole-project')
    const concrete = toggleMaterialChatContext(createWholeProjectContext(), material)

    expect(empty).toEqual([])
    expect(selectWholeProjectChatContext()).toEqual(createWholeProjectContext())
    expect(concrete.some((item) => item.kind === 'whole-project')).toBe(false)
  })

  it('keeps the original context available when picker changes are discarded', () => {
    const original = createWholeProjectContext()
    const draft = createChatContextDraft(original)
    const changedDraft = toggleMaterialChatContext(draft, material)

    expect(changedDraft).toEqual([expect.objectContaining({ kind: 'material' })])
    expect(original).toEqual(createWholeProjectContext())
    expect(createChatContextDraft(original)).toEqual(createWholeProjectContext())
  })
})
