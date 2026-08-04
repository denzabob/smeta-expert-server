import type { MenuSection } from '@/layouts/shell/sidebarConfig'

export const priceIndicesSidebarSections: MenuSection[] = [
  {
    title: 'Работа',
    items: [
      {
        title: 'Обзор',
        routeName: 'price-indices-overview',
        icon: 'mdi-view-dashboard-outline',
        exact: true,
        showInRail: true,
      },
      {
        title: 'Новый расчёт',
        routeName: 'price-indices-new-calculation',
        icon: 'mdi-calculator-variant-outline',
        showInRail: true,
      },
      {
        title: 'Мои расчёты',
        routeName: 'price-indices-calculations',
        icon: 'mdi-history',
        showInRail: true,
      },
    ],
  },
  {
    title: 'Данные',
    items: [
      {
        title: 'Показатели',
        routeName: 'price-indices-indicators',
        icon: 'mdi-chart-line',
        showInRail: true,
      },
      {
        title: 'Источники',
        routeName: 'price-indices-sources',
        icon: 'mdi-database-outline',
        showInRail: true,
      },
    ],
  },
]
