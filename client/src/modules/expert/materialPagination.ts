import type { ExpertMaterialKind, ExpertProjectMaterial } from './types'

export function filterAndPaginateMaterials(
  materials: ExpertProjectMaterial[],
  search: string,
  filter: 'all' | ExpertMaterialKind,
  page: number,
  pageSize: number,
) {
  const normalizedSearch = search.trim().toLowerCase()
  const filtered = materials.filter((material) =>
    (filter === 'all' || material.kind === filter) && material.name.toLowerCase().includes(normalizedSearch),
  )
  const safePageSize = Math.max(1, pageSize)
  const pageCount = Math.max(1, Math.ceil(filtered.length / safePageSize))
  const safePage = Math.min(Math.max(1, page), pageCount)

  return {
    filtered,
    items: filtered.slice((safePage - 1) * safePageSize, safePage * safePageSize),
    pageCount,
  }
}
