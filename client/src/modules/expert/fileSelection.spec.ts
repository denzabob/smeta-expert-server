import { describe, expect, it } from 'vitest'
import { idsIntersectingRectangle, selectExpertFiles } from './fileSelection'

describe('Expert file selection', () => {
  it('selects one library item on a plain click and toggles with Ctrl or Cmd', () => {
    expect(selectExpertFiles({ selectedIds: ['a', 'b'], visibleIds: ['a', 'b', 'c'], targetId: 'c', anchorId: 'a', mode: 'library' }))
      .toEqual({ selectedIds: ['c'], anchorId: 'c' })
    expect(selectExpertFiles({ selectedIds: ['a'], visibleIds: ['a', 'b'], targetId: 'b', anchorId: 'a', mode: 'library', ctrlKey: true }))
      .toEqual({ selectedIds: ['a', 'b'], anchorId: 'b' })
    expect(selectExpertFiles({ selectedIds: ['a', 'b'], visibleIds: ['a', 'b'], targetId: 'a', anchorId: 'b', mode: 'library', metaKey: true }))
      .toEqual({ selectedIds: ['b'], anchorId: 'a' })
  })

  it('toggles plain clicks in picker mode and selects visible ranges with Shift', () => {
    expect(selectExpertFiles({ selectedIds: ['a'], visibleIds: ['a', 'b', 'c', 'd'], targetId: 'c', anchorId: 'a', mode: 'picker' }))
      .toEqual({ selectedIds: ['a', 'c'], anchorId: 'c' })
    expect(selectExpertFiles({ selectedIds: ['d'], visibleIds: ['a', 'b', 'c', 'd'], targetId: 'c', anchorId: 'a', mode: 'picker', shiftKey: true }))
      .toEqual({ selectedIds: ['a', 'b', 'c'], anchorId: 'a' })
    expect(selectExpertFiles({ selectedIds: ['d'], visibleIds: ['a', 'b', 'c', 'd'], targetId: 'c', anchorId: 'a', mode: 'picker', shiftKey: true, ctrlKey: true }).selectedIds)
      .toEqual(['d', 'a', 'b', 'c'])
  })

  it('finds file items whose bounds intersect the mouse selection rectangle', () => {
    const items = [
      { id: 'inside', bounds: { left: 10, top: 10, right: 20, bottom: 20 } },
      { id: 'touching', bounds: { left: 20, top: 20, right: 30, bottom: 30 } },
      { id: 'outside', bounds: { left: 31, top: 31, right: 40, bottom: 40 } },
    ]
    expect(idsIntersectingRectangle(items, { left: 5, top: 5, right: 20, bottom: 20 })).toEqual(['inside', 'touching'])
  })
})
