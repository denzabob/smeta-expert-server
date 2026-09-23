import { describe, expect, it } from 'vitest'
import {
  canAddMaterialToSelection,
  filterSortAndPaginateMaterials,
  readExpertFileBrowserPreferences,
  selectionWithinMaterialLimits,
  sortExpertMaterials,
  writeExpertFileBrowserPreferences,
} from './fileBrowser'
import type { ExpertProjectMaterial } from './types'

function material(id: string, overrides: Partial<ExpertProjectMaterial> = {}): ExpertProjectMaterial {
  return {
    id, name: `${id}.pdf`, kind: 'document', format: 'PDF', meta: 'application/pdf', size: '1 КБ',
    category: 'document', status: 'Загружен', useInAi: false, icon: 'mdi-file-pdf-box', sizeBytes: 1024,
    ...overrides,
  }
}

describe('Expert file browser helpers', () => {
  it('defaults to a tile view and reads legacy library view preferences', () => {
    const values = new Map<string, string>([['expert.materials.view', 'grid']])
    const storage = { getItem: (key: string) => values.get(key) ?? null }

    expect(readExpertFileBrowserPreferences(storage, 'expert.materials')).toEqual({ viewMode: 'tile', sortBy: 'name', sortDirection: 'asc' })
    expect(readExpertFileBrowserPreferences(storage, 'expert.materialPicker')).toEqual({ viewMode: 'tile', sortBy: 'name', sortDirection: 'asc' })
    values.set('expert.materials.viewMode', 'large_tile')
    expect(readExpertFileBrowserPreferences(storage, 'expert.materials').viewMode).toBe('large_tile')
  })

  it('persists view and sort preferences under the screen-specific prefix', () => {
    const values = new Map<string, string>()
    writeExpertFileBrowserPreferences({ setItem: (key, value) => values.set(key, value) }, 'expert.materialPicker', {
      viewMode: 'list', sortBy: 'updated_at', sortDirection: 'desc',
    })
    expect(values).toEqual(new Map([
      ['expert.materialPicker.viewMode', 'list'],
      ['expert.materialPicker.sortBy', 'updated_at'],
      ['expert.materialPicker.sortDirection', 'desc'],
    ]))
  })

  it('sorts by size, type and updated time while keeping unknown values last', () => {
    const rows = [
      material('unknown-size', { name: 'Z missing size.pdf', sizeBytes: undefined }),
      material('small', { name: 'A small.pdf', sizeBytes: 5 }),
      material('large', { name: 'B large.xlsx', sizeBytes: 20, format: 'XLSX', kind: 'spreadsheet', updatedAt: '2026-01-01' }),
      material('unknown-date', { name: 'Y missing date.pdf', sizeBytes: 12, updatedAt: 'invalid' }),
      material('newest', { name: 'C newest.pdf', sizeBytes: 10, updatedAt: '2026-09-01' }),
    ]

    expect(sortExpertMaterials(rows, 'size', 'desc').map((row) => row.id)).toEqual(['large', 'unknown-date', 'newest', 'small', 'unknown-size'])
    expect(sortExpertMaterials(rows, 'type', 'asc').map((row) => row.id)).toEqual(['small', 'newest', 'unknown-date', 'unknown-size', 'large'])
    expect(sortExpertMaterials(rows, 'updated_at', 'desc').map((row) => row.id)).toEqual(['newest', 'large', 'small', 'unknown-date', 'unknown-size'])
  })

  it('filters and sorts before slicing a page', () => {
    const rows = [material('b', { name: 'Бета.pdf' }), material('a', { name: 'Альфа.pdf' }), material('photo', { name: 'Фото.jpg', kind: 'image', format: 'JPG' })]
    const result = filterSortAndPaginateMaterials(rows, 'а', 'all', 'name', 'asc', 1, 1)

    expect(result.filtered.map((row) => row.name)).toEqual(['Альфа.pdf', 'Бета.pdf'])
    expect(result.items.map((row) => row.id)).toEqual(['a'])
    expect(result.pageCount).toBe(2)
  })

  it('enforces configured material and image limits while allowing deselection', () => {
    const rows = [material('doc-1'), material('image-1', { kind: 'image', format: 'JPG' }), material('doc-2')]
    const byId = new Map(rows.map((row) => [row.id, row]))
    const limits = { maxMaterials: 2, maxImages: 1 }

    expect(canAddMaterialToSelection(['doc-1'], rows[1]!, limits, byId)).toBe(true)
    expect(canAddMaterialToSelection(['doc-1', 'image-1'], rows[2]!, limits, byId)).toBe(false)
    expect(canAddMaterialToSelection(['doc-1', 'image-1'], rows[1]!, limits, byId)).toBe(true)
    expect(selectionWithinMaterialLimits(['doc-1', 'image-1'], limits, byId)).toBe(true)
    expect(selectionWithinMaterialLimits(['doc-1', 'image-1', 'doc-2'], limits, byId)).toBe(false)
    expect(selectionWithinMaterialLimits(['doc-1', 'image-1'], { maxMaterials: 0, maxImages: 0 }, byId)).toBe(true)
  })
})
