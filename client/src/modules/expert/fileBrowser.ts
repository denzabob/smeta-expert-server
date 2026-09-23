import type { ExpertMaterialKind, ExpertProjectMaterial } from './types'

export type ExpertFileViewMode = 'large_tile' | 'tile' | 'list'
export type ExpertFileSortBy = 'name' | 'size' | 'type' | 'updated_at'
export type ExpertFileSortDirection = 'asc' | 'desc'
export type ExpertFileBrowserMode = 'library' | 'picker'

export interface ExpertFileBrowserPreferences {
  viewMode: ExpertFileViewMode
  sortBy: ExpertFileSortBy
  sortDirection: ExpertFileSortDirection
}

export interface ExpertMaterialSelectionLimits {
  maxMaterials: number
  maxImages: number
}

const defaultPreferences: ExpertFileBrowserPreferences = {
  viewMode: 'tile',
  sortBy: 'name',
  sortDirection: 'asc',
}

const viewModes: ExpertFileViewMode[] = ['large_tile', 'tile', 'list']
const sortFields: ExpertFileSortBy[] = ['name', 'size', 'type', 'updated_at']

export function readExpertFileBrowserPreferences(
  storage: Pick<Storage, 'getItem'>,
  prefix: string,
  legacyViewKey?: string,
): ExpertFileBrowserPreferences {
  const savedView = read(storage, `${prefix}.viewMode`)
  const legacyView = legacyViewKey ? read(storage, legacyViewKey) : null

  return {
    viewMode: isViewMode(savedView) ? savedView : legacyView === 'grid' ? 'tile' : legacyView === 'list' ? 'list' : defaultPreferences.viewMode,
    sortBy: isSortBy(read(storage, `${prefix}.sortBy`)) ? read(storage, `${prefix}.sortBy`) as ExpertFileSortBy : defaultPreferences.sortBy,
    sortDirection: read(storage, `${prefix}.sortDirection`) === 'desc' ? 'desc' : defaultPreferences.sortDirection,
  }
}

export function writeExpertFileBrowserPreferences(
  storage: Pick<Storage, 'setItem'>,
  prefix: string,
  preferences: ExpertFileBrowserPreferences,
): void {
  try {
    storage.setItem(`${prefix}.viewMode`, preferences.viewMode)
    storage.setItem(`${prefix}.sortBy`, preferences.sortBy)
    storage.setItem(`${prefix}.sortDirection`, preferences.sortDirection)
  } catch {
    // Browser storage is an optional preference layer.
  }
}

export function sortExpertMaterials(
  materials: ExpertProjectMaterial[],
  sortBy: ExpertFileSortBy,
  direction: ExpertFileSortDirection,
): ExpertProjectMaterial[] {
  const multiplier = direction === 'asc' ? 1 : -1

  return [...materials].sort((left, right) => {
    let primary = 0
    if (sortBy === 'name') {
      primary = compareText(left.name, right.name)
    } else if (sortBy === 'size') {
      if (left.sizeBytes === undefined || right.sizeBytes === undefined) {
        if (left.sizeBytes === undefined && right.sizeBytes !== undefined) return 1
        if (right.sizeBytes === undefined && left.sizeBytes !== undefined) return -1
      } else {
        primary = left.sizeBytes - right.sizeBytes
      }
    } else if (sortBy === 'type') {
      primary = compareText(left.format, right.format)
    } else {
      const leftDate = dateTimestamp(left.updatedAt ?? left.createdAt)
      const rightDate = dateTimestamp(right.updatedAt ?? right.createdAt)
      if (leftDate === null || rightDate === null) {
        if (leftDate === null && rightDate !== null) return 1
        if (rightDate === null && leftDate !== null) return -1
      } else {
        primary = leftDate - rightDate
      }
    }

    return primary * multiplier || compareText(left.name, right.name) || compareText(left.id, right.id)
  })
}

export function filterSortAndPaginateMaterials(
  materials: ExpertProjectMaterial[],
  search: string,
  filter: 'all' | ExpertMaterialKind,
  sortBy: ExpertFileSortBy,
  sortDirection: ExpertFileSortDirection,
  page: number,
  pageSize: number,
) {
  const normalizedSearch = search.trim().toLocaleLowerCase('ru')
  const filtered = materials.filter((material) =>
    (filter === 'all' || material.kind === filter)
    && material.name.toLocaleLowerCase('ru').includes(normalizedSearch),
  )
  const sorted = sortExpertMaterials(filtered, sortBy, sortDirection)
  const safePageSize = Math.max(1, pageSize)
  const pageCount = Math.max(1, Math.ceil(sorted.length / safePageSize))
  const safePage = Math.min(Math.max(1, page), pageCount)

  return {
    filtered: sorted,
    items: sorted.slice((safePage - 1) * safePageSize, safePage * safePageSize),
    pageCount,
  }
}

export function canAddMaterialToSelection(
  selectedIds: string[],
  candidate: ExpertProjectMaterial,
  limits: ExpertMaterialSelectionLimits,
  materialsById: Map<string, ExpertProjectMaterial>,
): boolean {
  const isSelected = selectedIds.includes(candidate.id)
  if (isSelected) return true

  const selected = selectedIds.map((id) => materialsById.get(id)).filter((item): item is ExpertProjectMaterial => Boolean(item))
  if (limits.maxMaterials > 0 && selected.length >= limits.maxMaterials) return false
  if (candidate.kind === 'image' && limits.maxImages > 0) {
    const selectedImages = selected.filter((item) => item.kind === 'image').length
    if (selectedImages >= limits.maxImages) return false
  }

  return true
}

export function selectionWithinMaterialLimits(
  selectedIds: string[],
  limits: ExpertMaterialSelectionLimits,
  materialsById: Map<string, ExpertProjectMaterial>,
): boolean {
  const selected = selectedIds.map((id) => materialsById.get(id)).filter((item): item is ExpertProjectMaterial => Boolean(item))
  const imageCount = selected.filter((item) => item.kind === 'image').length

  return (limits.maxMaterials <= 0 || selected.length <= limits.maxMaterials)
    && (limits.maxImages <= 0 || imageCount <= limits.maxImages)
}

function read(storage: Pick<Storage, 'getItem'>, key: string): string | null {
  try {
    return storage.getItem(key)
  } catch {
    return null
  }
}

function isViewMode(value: string | null): value is ExpertFileViewMode {
  return value !== null && viewModes.includes(value as ExpertFileViewMode)
}

function isSortBy(value: string | null): value is ExpertFileSortBy {
  return value !== null && sortFields.includes(value as ExpertFileSortBy)
}

function compareText(left: string, right: string): number {
  return left.localeCompare(right, 'ru', { sensitivity: 'base', numeric: true })
}

function dateTimestamp(value?: string): number | null {
  if (!value) return null
  const timestamp = Date.parse(value)
  return Number.isNaN(timestamp) ? null : timestamp
}
