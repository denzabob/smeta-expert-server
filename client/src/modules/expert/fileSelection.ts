import type { ExpertFileBrowserMode } from './fileBrowser'

export interface ExpertFileSelectionInput {
  selectedIds: string[]
  visibleIds: string[]
  targetId: string
  anchorId: string | null
  mode: ExpertFileBrowserMode
  ctrlKey?: boolean
  metaKey?: boolean
  shiftKey?: boolean
}

export interface ExpertFileSelectionResult {
  selectedIds: string[]
  anchorId: string
}

export function selectExpertFiles(input: ExpertFileSelectionInput): ExpertFileSelectionResult {
  const current = new Set(input.selectedIds)
  const additive = Boolean(input.ctrlKey || input.metaKey)

  if (input.shiftKey && input.anchorId) {
    const anchorIndex = input.visibleIds.indexOf(input.anchorId)
    const targetIndex = input.visibleIds.indexOf(input.targetId)
    if (anchorIndex >= 0 && targetIndex >= 0) {
      const range = input.visibleIds.slice(Math.min(anchorIndex, targetIndex), Math.max(anchorIndex, targetIndex) + 1)
      return {
        selectedIds: additive ? unique([...current, ...range]) : range,
        anchorId: input.anchorId,
      }
    }
  }

  const toggles = input.mode === 'picker' || additive
  if (toggles) {
    current.has(input.targetId) ? current.delete(input.targetId) : current.add(input.targetId)
    return { selectedIds: [...current], anchorId: input.targetId }
  }

  return { selectedIds: [input.targetId], anchorId: input.targetId }
}

export function idsIntersectingRectangle(
  items: Array<{ id: string; bounds: Pick<DOMRect, 'left' | 'top' | 'right' | 'bottom'> }>,
  selection: Pick<DOMRect, 'left' | 'top' | 'right' | 'bottom'>,
): string[] {
  return items.filter(({ bounds }) =>
    bounds.left <= selection.right
    && bounds.right >= selection.left
    && bounds.top <= selection.bottom
    && bounds.bottom >= selection.top,
  ).map(({ id }) => id)
}

function unique(values: string[]): string[] {
  return [...new Set(values)]
}
