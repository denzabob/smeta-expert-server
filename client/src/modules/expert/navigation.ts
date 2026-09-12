import type { MenuSection } from '@/layouts/shell/sidebarConfig'

export const expertSidebarSections: MenuSection[] = [
  {
    title: 'Эксперт',
    items: [
      { title: 'Обзор', routeName: 'expert-dashboard', icon: 'mdi-view-dashboard-outline', exact: true, showInRail: true },
      { title: 'Проекты', routeName: 'expert-projects', icon: 'mdi-folder-search-outline', showInRail: true },
    ],
  },
]
