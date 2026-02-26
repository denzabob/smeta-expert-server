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
    ],
  },
  {
    title: 'Справочники',
    items: [
      {
        title: 'Материалы',
        routeName: 'materials',
        icon: 'mdi-package-variant-closed',
        showInRail: true,
      },
      {
        title: 'Готовые изделия',
        routeName: 'products',
        icon: 'mdi-door',
        showInRail: true,
      },
      {
        title: 'Поставщики',
        routeName: 'suppliers',
        icon: 'mdi-truck-outline',
        showInRail: true,
      },
      {
        title: 'Объекты',
        routeName: 'detail-types',
        icon: 'mdi-shape-outline',
        showInRail: false,
      },
      {
        title: 'Операции',
        routeName: 'operations',
        icon: 'mdi-cog-outline',
        showInRail: false,
      },
      {
        title: 'Профили работ',
        routeName: 'work-profiles',
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
  action?: 'logout' | 'support' | 'notifications'
  tab?: string // для открытия вкладки в настройках
  route?: string // для перехода на отдельную страницу
  badge?: boolean // показывать badge с кол-вом непрочитанных
}

export const accountMenuItems: AccountMenuItem[] = [
  {
    id: 'notifications',
    title: 'Уведомления',
    icon: 'mdi-bell-outline',
    action: 'notifications',
    badge: true,
  },
  {
    id: 'profile',
    title: 'Профиль',
    icon: 'mdi-account-outline',
    tab: 'profile',
  },
  {
    id: 'security',
    title: 'Безопасность',
    icon: 'mdi-lock-outline',
    tab: 'security',
  },
  {
    id: 'preferences',
    title: 'Предпочтения',
    icon: 'mdi-tune-variant',
    tab: 'preferences',
  },
  {
    id: 'project-defaults',
    title: 'Проект',
    icon: 'mdi-folder-cog-outline',
    route: '/settings/project',
  },
  {
    id: 'support',
    title: 'Поддержка',
    icon: 'mdi-help-circle-outline',
    action: 'support',
  },
  {
    id: 'logout',
    title: 'Выйти',
    icon: 'mdi-logout',
    action: 'logout',
  },
]
