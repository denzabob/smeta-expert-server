import { describe, expect, it } from 'vitest'
import { filterAndPaginateMaterials } from './materialPagination'
import type { ExpertProjectMaterial } from './types'

const material = (id: number, kind: 'image' | 'document'): ExpertProjectMaterial => ({
  id: String(id), name: kind === 'image' ? `Фото ${id}.jpg` : `Документ ${id}.pdf`, kind,
  format: kind === 'image' ? 'JPG' : 'PDF', meta: kind === 'image' ? 'image/jpeg' : 'application/pdf',
  size: '1 КБ', category: kind, status: 'Загружен', useInAi: false, icon: 'mdi-file-outline',
})

describe('material pagination', () => {
  it('filters before slicing the requested page', () => {
    const materials = Array.from({ length: 100 }, (_, index) => material(index, index % 2 ? 'document' : 'image'))
    const result = filterAndPaginateMaterials(materials, 'Фото', 'image', 2, 25)

    expect(result.filtered).toHaveLength(50)
    expect(result.items).toHaveLength(25)
    expect(result.items[0]?.name).toBe('Фото 50.jpg')
    expect(result.pageCount).toBe(2)
  })

  it('clamps an out-of-range page after delete/filter changes', () => {
    const result = filterAndPaginateMaterials([material(1, 'image')], '', 'all', 4, 25)

    expect(result.items).toHaveLength(1)
    expect(result.pageCount).toBe(1)
  })
})
