import type { PriceIndicesCapabilityStatus } from '../types'

export interface AdminPriceIndicesNavigationItem {
  to: string
  label: string
  icon: string
  exact: true
  active: boolean
  badge?: number
  badgeColor?: string
}

export interface AdminPriceIndicesNavigationSection {
  title: 'Индексы'
  items: AdminPriceIndicesNavigationItem[]
}

const navigationItems = [
  { to: '/admin/indices/sources', label: 'Источники данных', icon: 'mdi-database-outline' },
  { to: '/admin/indices/imports', label: 'Импорты XLSX', icon: 'mdi-file-excel-outline' },
  { to: '/admin/indices/mappings', label: 'Шаблоны маппинга', icon: 'mdi-table-cog' },
  { to: '/admin/indices/logs', label: 'Журнал импорта', icon: 'mdi-history' },
] as const

export function buildAdminPriceIndicesNavigation(
  status: PriceIndicesCapabilityStatus,
  currentPath: string,
): AdminPriceIndicesNavigationSection | null {
  if (status !== 'available') return null

  return {
    title: 'Индексы',
    items: navigationItems.map((item) => ({
      ...item,
      exact: true,
      active: isAdminPriceIndicesNavigationItemActive(item.to, currentPath),
    })),
  }
}

export function isAdminPriceIndicesNavigationItemActive(
  itemPath: string,
  currentPath: string,
): boolean {
  return currentPath === itemPath
}
