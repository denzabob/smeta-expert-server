import type {
  ApplicationId,
  ApplicationMenuItem,
  PriceIndicesCapabilityStatus,
  PriceIndicesGuardDecision,
} from './types'

export const LAST_APPLICATION_STORAGE_KEY = 'prisma.lastApplication'

type ApplicationUser = {
  id?: unknown
  role?: unknown
  user_role?: unknown
  is_admin?: unknown
} | null | undefined

export function resolveActiveApplication(path: string): ApplicationId {
  if (matchesPath(path, '/app/indices')) return 'price_indices'
  if (matchesPath(path, '/admin')) return 'admin'
  if (matchesPath(path, '/parser')) return 'parser'

  return 'estimates'
}

export function selectSidebarConfig<T>(
  application: ApplicationId,
  estimatesConfig: T,
  priceIndicesConfig: T,
): T {
  return application === 'price_indices' ? priceIndicesConfig : estimatesConfig
}

export function buildApplicationMenu(
  user: ApplicationUser,
  priceIndicesStatus: PriceIndicesCapabilityStatus,
): ApplicationMenuItem[] {
  const items: ApplicationMenuItem[] = [
    {
      id: 'estimates',
      label: 'Сметы',
      icon: 'mdi-calculator-variant',
      iconClass: 'menu-item-icon--app',
      routeName: 'projects',
    },
  ]

  if (hasPriceIndicesRole(user) && priceIndicesStatus === 'available') {
    items.push({
      id: 'price_indices',
      label: 'Индексы',
      icon: 'mdi-chart-line',
      iconClass: 'menu-item-icon--indices',
      routeName: 'price-indices-overview',
    })
  }

  if (isExistingAdminUser(user)) {
    items.push(
      {
        id: 'admin',
        label: 'Админ панель',
        icon: 'mdi-shield-crown-outline',
        iconClass: 'menu-item-icon--admin',
        routeName: 'admin-panel',
      },
      {
        id: 'parser',
        label: 'Парсер',
        icon: 'mdi-code-json',
        iconClass: 'menu-item-icon--parser',
        routeName: 'parser',
      },
    )
  }

  return items
}

export function hasPriceIndicesRole(user: ApplicationUser): boolean {
  return user?.role === 'admin' || user?.role === 'superadmin'
}

export function getPriceIndicesCapabilityScope(user: ApplicationUser): string {
  return `${String(user?.id ?? 'anonymous')}:${String(user?.role ?? '')}`
}

export function resolvePriceIndicesGuardDecision(
  status: PriceIndicesCapabilityStatus,
): PriceIndicesGuardDecision {
  if (status === 'available') return 'allow'
  if (status === 'forbidden') return 'forbidden'

  return 'unavailable'
}

export function persistLastApplication(
  application: ApplicationId,
  storage: Pick<Storage, 'setItem'> | null = typeof window !== 'undefined' ? window.localStorage : null,
): void {
  if (!storage) return

  try {
    storage.setItem(LAST_APPLICATION_STORAGE_KEY, application)
  } catch {
    // Storage can be unavailable in privacy modes; routing remains authoritative.
  }
}

function matchesPath(path: string, prefix: string): boolean {
  return path === prefix || path.startsWith(`${prefix}/`)
}

function isExistingAdminUser(user: ApplicationUser): boolean {
  if (!user) return false
  if (user.is_admin) return true

  const role = String(user.role ?? user.user_role ?? '').toLowerCase()
  return Number(user.id) === 1 || role === 'admin' || role === 'superadmin'
}
