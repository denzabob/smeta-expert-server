// src/router/index.ts
import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import api from '@/api/axios'
import { setProjectsFlashMessage, storePrefetchedProject } from './projectAccess'

import AppShell from '@/layouts/AppShell.vue'
import ParserLayout from '@/layouts/ParserLayout.vue'
import OperationsView from "@/views/OperationsView.vue"
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
        {
          path: 'materials',
          name: 'materials',
          component: () => import('@/views/MaterialsView.vue'),
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
          path: '/projects/:id/edit',
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
          path: 'operations',
          name: 'operations',
          component: OperationsView,
          meta: { title: 'Операции' }
        },
        {
          path: 'work-profiles',
          name: 'work-profiles',
          component: () => import('@/views/WorkProfilesView.vue'),
          meta: { title: 'Профили работ' }
        },
        {
          path: 'settings',
          name: 'settings',
          component: () => import('@/views/UserSettingsView.vue'),
          meta: { title: 'Настройки' }
        },
        {
          path: 'settings/project',
          name: 'settings-project',
          component: () => import('@/views/ProjectDefaultsView.vue'),
          meta: { title: 'Настройки проекта' }
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
          path: 'admin',
          name: 'admin-panel',
          component: () => import('@/views/AdminPanelView.vue'),
          meta: { requiresAdmin: true, title: 'Админ панель' }
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

  const isAdminUser = () => Number(authStore.user?.id) === 1

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
    
    // Если уже авторизован — отправляем на главную
    if (authStore.isAuthenticated) {
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
  }

  if (to.meta.requiresAdmin) {
    if (!authStore.authChecked) {
      await authStore.checkAuth()
    }

    if (!isAdminUser()) {
      return next({ name: 'projects' })
    }
  }

  if (to.name === 'ProjectEditorView') {
    const rawProjectId = String(to.params.id ?? '').trim()
    const projectId = Number(rawProjectId)

    if (!Number.isInteger(projectId) || projectId <= 0) {
      setProjectsFlashMessage('Проект не существует')
      return next({ name: 'projects', replace: true })
    }

    try {
      const { data } = await api.get(`/api/projects/${projectId}`)
      storePrefetchedProject(projectId, data)
    } catch (error: any) {
      if (error?.response?.status === 404) {
        setProjectsFlashMessage('Проект не существует')
        return next({ name: 'projects', replace: true })
      }
    }
  }

  next()
})

export default router
