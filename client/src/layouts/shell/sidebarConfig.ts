/**
 * Конфигурация меню Sidebar
 * Централизованное место для настройки пунктов меню
 */

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
      {
        title: 'Идеи',
        routeName: 'ideas',
        icon: 'mdi-lightbulb-outline',
        showInRail: true,
      },
    ],
  },
  {
    title: 'Справочники',
    items: [
      {
        title: 'Каталог',
        routeName: 'catalog',
        icon: 'mdi-book-open-variant',
        showInRail: true,
      },
      {
        title: 'Готовые изделия',
        routeName: 'products',
        icon: 'mdi-door',
        showInRail: true,
      },
      {
        title: 'Объекты',
        routeName: 'detail-types',
        icon: 'mdi-shape-outline',
        showInRail: false,
      },
      {
        title: 'Цены',
        routeName: 'pricing',
        icon: 'mdi-currency-usd',
        showInRail: true,
      },
      {
        title: 'Труд',
        routeName: 'pricing-labor',
        icon: 'mdi-clipboard-list-outline',
        showInRail: true,
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
  action?: 'logout' | 'support' | 'notifications' | 'profile' | 'settings'
  /** Навигация на маршрут (закрывает меню) */
  route?: string
  /** Показывать badge с кол-вом непрочитанных */
  badge?: boolean
  /** Визуальный разделитель перед элементом */
  dividerBefore?: boolean
}

/**
 * Flat menu structure — no sections, no overflow bucket.
 * Container taxonomy:
 *   action:'profile'        → compact ProfileEditModal (~420px)
 *   action:'settings'       → AccountSettingsDialog (settings shell, 800px)
 *   route:'/settings'       → UserSettingsView (full page)
 *   action:'notifications'  → notifications panel / fullscreen on mobile
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
    id: 'notifications',
    title: 'Уведомления',
    icon: 'mdi-bell-outline',
    action: 'notifications',
    badge: true,
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
