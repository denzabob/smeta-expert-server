/**
 * Конфигурация меню Sidebar
 * Централизованное место для настройки пунктов меню
 */

import { useBillingCapabilitiesStore } from '@/stores/billingCapabilities'

export interface MenuItem {
  title: string
  routeName: string
  icon: string
  /** Точное совпадение маршрута для активного состояния */
  exact?: boolean
  /** Показывать в Rail режиме */
  showInRail?: boolean
  /** Условие видимости */
  visibleIf?: (me: { id: number } | null) => boolean
}

export interface MenuSection {
  title: string
  items: MenuItem[]
  /** Условие видимости всей секции */
  visibleIf?: (me: { id: number } | null) => boolean
}

/** Проверка: владелец (user_id === 1) */
const isOwner = (me: { id: number } | null) => me?.id === 1

const canShowUserBilling = () => {
  const billingCapabilities = useBillingCapabilitiesStore()
  return billingCapabilities.userUiEnabled
}

export const sidebarSections: MenuSection[] = [
  {
    title: 'Работа',
    items: [
      {
        title: 'Проекты',
        routeName: 'projects',
        icon: 'mdi-folder-outline',
        showInRail: true,
      },
    ],
  },
  {
    title: 'Справочники',
    items: [
      {
        title: 'Цены',
        routeName: 'pricing',
        icon: 'mdi-currency-usd',
        showInRail: true,
      },
      {
        title: 'Материалы',
        routeName: 'catalog',
        icon: 'mdi-book-open-variant',
        showInRail: true,
      },
      {
        title: 'Изделия',
        routeName: 'products',
        icon: 'mdi-door',
        showInRail: true,
      },
      {
        title: 'Операции',
        routeName: 'pricing-operations',
        icon: 'mdi-cog-outline',
        showInRail: true,
      },
      {
        title: 'Труд',
        routeName: 'pricing-labor',
        icon: 'mdi-clipboard-list-outline',
        showInRail: true,
      },
      {
        title: 'Объекты',
        routeName: 'detail-types',
        icon: 'mdi-shape-outline',
        showInRail: false,
      },
    ],
  },
  // Парсер и Админ панель перенесены в AppMenu (выпадающее меню)
]

/** Пункты меню аккаунта */
export interface AccountMenuItem {
  id: string
  title: string
  icon: string
  /** Специальное действие */
  action?: 'logout' | 'support' | 'profile' | 'settings'
  /** Навигация на маршрут (закрывает меню) */
  route?: string
  /** Показывать badge с кол-вом непрочитанных */
  badge?: boolean
  /** Визуальный разделитель перед элементом */
  dividerBefore?: boolean
  /** Условие видимости */
  visibleIf?: (me: { id: number } | null) => boolean
}

/**
 * Flat menu structure — no sections, no overflow bucket.
 * Container taxonomy:
 *   action:'profile'        → compact ProfileEditModal (~420px)
 *   action:'settings'       → AccountSettingsDialog (settings shell, 800px)
 *   route:'/settings'       → UserSettingsView (full page)
 */
export const accountMenuItems: AccountMenuItem[] = [
  {
    id: 'profile',
    title: 'Профиль',
    icon: 'mdi-account-outline',
    action: 'profile',
  },
  {
    id: 'account-settings',
    title: 'Настройки аккаунта',
    icon: 'mdi-cog-outline',
    action: 'settings',
  },
  {
    id: 'project-defaults',
    title: 'Настройки проекта по умолчанию',
    icon: 'mdi-tune-variant',
    route: '/settings',
  },
  {
    id: 'billing-preview',
    title: 'Тариф и лимиты',
    icon: 'mdi-chart-box-outline',
    route: '/settings/billing',
    visibleIf: canShowUserBilling,
  },
  {
    id: 'support',
    title: 'Поддержка',
    icon: 'mdi-help-circle-outline',
    action: 'support',
    dividerBefore: true,
  },
  {
    id: 'logout',
    title: 'Выйти',
    icon: 'mdi-logout',
    action: 'logout',
  },
]
