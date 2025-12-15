import { createRouter, createWebHistory } from 'vue-router'
import MainLayout from '@/layouts/MainLayout.vue'
import HomeView from '@/views/HomeView.vue'
import MaterialsView from '@/views/MaterialsView.vue'
import FittingsView from '@/views/FittingsView.vue'
import SmetaEditorView from '@/views/SmetaEditorView.vue'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    {
      path: '/',
      component: MainLayout,
      children: [
        {
          path: '',
          name: 'home',
          component: HomeView
        },
        {
          path: 'materials',
          name: 'materials',
          component: MaterialsView
        },
        {
          path: 'fittings',
          name: 'fittings',
          component: FittingsView
        },
        {
          path: 'smeta',
          name: 'smeta',
          component: SmetaEditorView
        }
      ]
    }
  ]
})

export default router
