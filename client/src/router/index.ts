// src/router/index.ts
import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useBillingCapabilitiesStore } from '@/stores/billingCapabilities'
import { usePriceIndicesCapabilitiesStore } from '@/modules/price-indices/stores/priceIndicesCapabilities'
import {
  getPriceIndicesCapabilityScope,
  persistLastApplication,
  resolveActiveApplication,
  resolvePriceIndicesGuardDecision,
} from '@/modules/price-indices/application'
import { priceIndicesRoutes } from '@/modules/price-indices/router/routes'
import { adminPriceIndicesRoutes } from '@/modules/price-indices/admin/routes'
import api from '@/api/axios'
import { setProjectsFlashMessage, storePrefetchedProject } from './projectAccess'

import AppShell from '@/layouts/AppShell.vue'
import ParserLayout from '@/layouts/ParserLayout.vue'
import DetailTypesView from "@/views/DetailTypesView.vue"
import { parserRoutes } from './parser'



const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    {
      path: '/login',
      name: 'login',
      component: () => import('@/views/LoginView.vue'),
      meta: { requiresAuth: false }
    },
    {
      path: '/denzabob',
      name: 'admin-login',
      component: () => import('@/views/AdminLoginView.vue'),
      meta: { requiresAuth: false }
    },
    {
      path: '/reset-password',
      name: 'reset-password',
      component: () => import('@/views/ResetPasswordView.vue'),
      meta: { requiresAuth: false }
    },
    {
      path: '/operations',
      redirect: '/pricing/operations',
    },
    {
      path: '/',
      component: AppShell,
      meta: { requiresAuth: true },
      children: [
        {
          path: '',
          name: 'home',
          redirect: { name: 'projects' },
          meta: { title: 'Проекты' }
        },
        ...priceIndicesRoutes,
        {
          path: 'materials',
          name: 'materials',
          redirect: { name: 'admin-materials' },
          meta: { title: 'Материалы' }
        },
        {
          path: 'catalog',
          name: 'catalog',
          component: () => import('@/views/MaterialsCatalogView.vue'),
          meta: { title: 'Каталог материалов' }
        },
        {
          path: 'products',
          name: 'products',
          component: () => import('@/views/ProductsView.vue'),
          meta: { title: 'Готовые изделия' }
        },
        {
          path: 'products/:id/pricing',
          name: 'finished-product-pricing',
          component: () => import('@/views/FinishedProductPricingView.vue'),
          meta: { title: 'Управление pricing фасада' }
        },
        {
          path: 'facades',
          name: 'facades',
          redirect: { name: 'products', query: { type: 'facade' } },
          meta: { title: 'Готовые изделия' }
        },
        {
          path: 'projects',
          name: 'projects',
          component: () => import('@/views/ProjectsView.vue'),
          meta: { title: 'Проекты' }
        },
        {
          path: 'ideas',
          name: 'ideas',
          component: () => import('@/views/IdeaList.vue'),
          meta: { title: 'Ideas Board' }
        },
        {
          path: 'ideas/create',
          name: 'ideas-create',
          component: () => import('@/views/IdeaCreate.vue'),
          meta: { title: 'Создать идею' }
        },
        {
          path: 'ideas/:id',
          name: 'ideas-detail',
          component: () => import('@/views/IdeaPage.vue'),
          meta: { title: 'Идея' }
        },
        {
          path: '/projects/:projectPublicId/edit',
          name: 'ProjectEditorView',
          component: () => import('@/views/ProjectEditorView.vue'),
          meta: { title: 'Редактор сметы' }
        },
        {
          path: 'detail-types',
          name: 'detail-types',
          component: DetailTypesView,
          meta: { title: 'Объекты' }
        },
        {
          path: 'pricing',
          name: 'pricing',
          component: () => import('@/views/PricingHomeView.vue'),
          meta: { title: 'Цены' }
        },
        {
          path: 'pricing/operations',
          name: 'pricing-operations',
          component: () => import('@/views/PricingOperationsView.vue'),
          meta: { title: 'Ценообразование — Операции' }
        },
        {
          path: 'pricing/labor',
          name: 'pricing-labor',
          component: () => import('@/views/PricingLaborView.vue'),
          meta: { title: 'Ценообразование — Труд' }
        },
        {
          path: 'price-imports',
          name: 'price-imports',
          component: () => import('@/views/PriceImportsView.vue'),
          meta: {
            title: 'Импорт цен',
            navLabel: 'Импорт цен',
            navGroup: 'pricing',
            icon: 'mdi-file-import-outline',
          },
        },
        {
          path: 'work-profiles',
          name: 'work-profiles',
          redirect: { name: 'pricing-labor' },
          meta: { title: 'Профили работ' }
        },
        {
          path: 'settings',
          name: 'settings',
          component: () => import('@/views/UserSettingsView.vue'),
          meta: { title: 'Настройки проекта по умолчанию' }
        },
        {
          path: 'settings/billing',
          name: 'settings-billing',
          component: () => import('@/views/settings/UserBillingView.vue'),
          meta: { title: 'Тариф и лимиты', requiresBillingUserUi: true }
        },
        {
          path: 'billing/payment-result',
          name: 'billing-payment-result',
          component: () => import('@/views/billing/PaymentResultView.vue'),
          meta: { title: 'Результат оплаты', requiresBillingUserUi: true }
        },
        {
          path: 'settings/project',
          redirect: { name: 'settings' }
        },
        {
          path: 'settings/security',
          redirect: { name: 'projects' }
        },
        {
          path: 'suppliers',
          name: 'suppliers',
          component: () => import('@/views/SuppliersIndex.vue'),
          meta: { title: 'Поставщики' }
        },
        {
          path: 'suppliers/:id',
          name: 'supplier-show',
          component: () => import('@/views/SupplierShow.vue'),
          meta: { title: 'Поставщик' }
        },
        {
          path: 'suppliers/:supplierId/price-lists/:priceListId/versions',
          name: 'price-list-versions',
          component: () => import('@/views/PriceListVersions.vue'),
          meta: { title: 'Версии прайс-листа' }
        },
        {
          path: 'suppliers/:supplierId/price-lists/:priceListId/versions/:versionId',
          name: 'price-list-version-show',
          component: () => import('@/views/PriceListVersionShow.vue'),
          meta: { title: 'Аудит версии' }
        },
        {
          path: 'dev/ui-foundation',
          name: 'dev-ui-foundation',
          component: () => import('@/views/dev/UiFoundationShowcase.vue'),
          meta: { requiresAdmin: true, title: 'UI Foundation Showcase' }
        },
        // Legacy admin panel (will be deprecated)
        {
          path: 'admin-legacy',
          name: 'admin-panel-legacy',
          component: () => import('@/views/AdminPanelView.vue'),
          meta: { requiresAdmin: true, title: 'Админ панель (старая)' }
        },
      ]
    },
    // Admin interface — top-level so AdminLayout is the sole v-app
    {
      path: '/admin',
      component: () => import('@/layouts/AdminLayout.vue'),
      meta: { requiresAuth: true, requiresAdmin: true, title: 'Админ панель' },
      children: [
        {
          path: '',
          name: 'admin-panel',
          component: () => import('@/views/admin/AdminDashboard.vue'),
          meta: { title: 'Обзор' }
        },
        {
          path: 'dashboard',
          name: 'admin-dashboard',
          redirect: { name: 'admin-panel' }
        },
        {
          path: 'problems',
          name: 'admin-problems',
          component: () => import('@/views/admin/AdminProblemsView.vue'),
          meta: { title: 'Проблемные случаи' }
        },
        {
          path: 'ideas',
          name: 'admin-ideas',
          component: () => import('@/views/admin/AdminIdeasView.vue'),
          meta: { title: 'Модерация идей' }
        },
        {
          path: 'materials',
          name: 'admin-materials',
          component: () => import('@/views/MaterialsView.vue'),
          meta: { title: 'Материалы' }
        },
        {
          path: 'rules',
          name: 'admin-rules',
          component: () => import('@/views/admin/AdminRulesView.vue'),
          meta: { title: 'Правила распознавания' }
        },
        {
          path: 'system',
          name: 'admin-system',
          component: () => import('@/views/admin/AdminSystemView.vue'),
          meta: { title: 'Системные настройки' }
        },
        {
          path: 'system/llm',
          name: 'admin-system-llm',
          component: () => import('@/views/admin/AdminSystemView.vue'),
          meta: { title: 'LLM' }
        },
        {
          path: 'system/prompts',
          name: 'admin-system-prompts',
          component: () => import('@/views/admin/AdminSystemView.vue'),
          meta: { title: 'Промпты' }
        },
        {
          path: 'system/stats',
          name: 'admin-system-stats',
          component: () => import('@/views/admin/AdminSystemView.vue'),
          meta: { title: 'Статистика LLM' }
        },
        {
          path: 'system/users',
          name: 'admin-system-users',
          component: () => import('@/views/admin/AdminSystemView.vue'),
          meta: { title: 'Пользователи' }
        },
        {
          path: 'system/notifications',
          name: 'admin-system-notifications',
          component: () => import('@/views/admin/AdminSystemView.vue'),
          meta: { title: 'Уведомления' }
        },
        {
          path: 'system/logs',
          name: 'admin-system-logs',
          component: () => import('@/views/admin/AdminSystemView.vue'),
          meta: { title: 'Журнал системы' }
        },
        {
          path: 'billing',
          name: 'admin-billing',
          component: () => import('@/views/admin/AdminBillingView.vue'),
          meta: { title: 'Биллинг' }
        },
        {
          path: 'billing/plans',
          name: 'admin-billing-plans',
          component: () => import('@/views/admin/AdminBillingView.vue'),
          meta: { title: 'Тарифы' }
        },
        {
          path: 'billing/subscriptions',
          name: 'admin-billing-subscriptions',
          component: () => import('@/views/admin/AdminBillingView.vue'),
          meta: { title: 'Подписки пользователей' }
        },
        {
          path: 'billing/payments',
          name: 'admin-billing-payments',
          component: () => import('@/views/admin/AdminBillingPaymentsView.vue'),
          meta: { title: 'Платежи' }
        },
        {
          path: 'billing/webhooks',
          name: 'admin-billing-webhooks',
          component: () => import('@/views/admin/AdminBillingPaymentsView.vue'),
          meta: { title: 'Webhook-события' }
        },
        {
          path: 'billing/gate-events',
          name: 'admin-billing-gate-events',
          component: () => import('@/views/admin/AdminBillingView.vue'),
          meta: { title: 'Log-only лимиты' }
        },
        ...adminPriceIndicesRoutes,
        {
          path: 'chat',
          name: 'admin-chat',
          component: () => import('@/views/admin/AdminChatView.vue'),
          meta: { title: 'Чаты поддержки' }
        }
      ]
    },
    // Parser module with separate layout
    {
      path: '/parser',
      component: ParserLayout,
      meta: { requiresAuth: true },
      children: parserRoutes
    }
  ]
})

// Глобальный navigation guard
router.beforeEach(async (to, from, next) => {
  const authStore = useAuthStore()
  const billingCapabilities = useBillingCapabilitiesStore()
  const priceIndicesCapabilities = usePriceIndicesCapabilitiesStore()

  const isAdminUser = () => {
    const u = authStore.user as any
    if (!u) return false
    if (u.is_admin) return true
    const role = String(u.role ?? u.user_role ?? '').toLowerCase()
    return Number(u.id) === 1 || role === 'admin' || role === 'superadmin'
  }

  if (to.name === 'admin-login') {
    if (!authStore.authChecked) {
      await authStore.checkAuth()
    }

    if (authStore.isAuthenticated) {
      return isAdminUser() ? next({ name: 'admin-panel' }) : next({ name: 'projects' })
    }

    return next()
  }

  // Пропускаем маршруты auth без проверки авторизации
  if (to.name === 'login' || to.name === 'reset-password') {
    // Проверяем свежую сессию при первом заходе
    if (!authStore.authChecked) {
      await authStore.checkAuth()
    }
    
    // Если уже авторизован — проверяем onboarding
    if (authStore.isAuthenticated) {
      if (authStore.needsOnboarding) {
        // Разрешаем оставаться на login с mode=onboarding
        if (to.query.mode === 'onboarding') {
          return next()
        }
        return next({ name: 'login', query: { mode: 'onboarding' } })
      }
      return next({ name: 'projects' })
    }
    return next()
  }

  // Маршруты, которые требуют авторизации
  if (to.meta.requiresAuth) {
    // Всегда проверяем сессию при первом заходе (убираем условие isAuthenticated)
    if (!authStore.authChecked) {
      await authStore.checkAuth()
    }

    // Если после проверки пользователь не авторизован — на логин
    if (!authStore.isAuthenticated) {
      return next({ name: 'login', query: { intended: to.fullPath } })
    }

    // Если авторизован, но не прошёл onboarding — на onboarding
    if (authStore.needsOnboarding) {
      return next({ name: 'login', query: { mode: 'onboarding' } })
    }
  }

  if (to.meta.requiresAdmin) {
    if (!authStore.authChecked) {
      await authStore.checkAuth()
    }

    if (!isAdminUser()) {
      return next({ name: 'projects' })
    }
  }

  if (to.meta.requiresBillingUserUi) {
    await billingCapabilities.load()

    if (!billingCapabilities.userUiEnabled) {
      return next({ name: 'settings', replace: true })
    }
  }

  if (authStore.isAuthenticated && !billingCapabilities.loaded && !billingCapabilities.loading) {
    void billingCapabilities.load()
  }

  if (to.meta.requiresPriceIndices) {
    const capabilityStatus = await priceIndicesCapabilities.load(
      getPriceIndicesCapabilityScope(authStore.user),
    )
    const decision = resolvePriceIndicesGuardDecision(capabilityStatus)

    if (to.meta.requiresAdmin && decision !== 'allow') {
      return next({ name: 'admin-panel', replace: true })
    }

    if (decision === 'forbidden') {
      setProjectsFlashMessage('Приложение „Индексы“ недоступно для вашей учётной записи.')
      return next({ name: 'projects', replace: true })
    }

    if (decision !== 'allow') {
      return next({ name: 'projects', replace: true })
    }
  }

  if (to.name === 'ProjectEditorView') {
    const projectIdentifier = String(to.params.projectPublicId ?? to.params.id ?? '').trim()

    if (!projectIdentifier) {
      setProjectsFlashMessage('Проект не существует')
      return next({ name: 'projects', replace: true })
    }

    try {
      const { data } = await api.get(`/api/projects/${encodeURIComponent(projectIdentifier)}`)
      const publicId = String(data?.public_id ?? '').trim()

      if (/^\d+$/.test(projectIdentifier) && publicId) {
        storePrefetchedProject(publicId, data)
        return next({
          name: 'ProjectEditorView',
          params: { projectPublicId: publicId },
          replace: true,
        })
      }

      storePrefetchedProject(projectIdentifier, data)
    } catch (error: any) {
      if (error?.response?.status === 404) {
        setProjectsFlashMessage('Проект не существует')
        return next({ name: 'projects', replace: true })
      }
    }
  }

  next()
})

router.afterEach((to, _from, failure) => {
  if (!failure) {
    persistLastApplication(resolveActiveApplication(to.path))
  }
})

export default router
