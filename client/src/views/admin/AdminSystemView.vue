<template>
  <PageContainer>
    <PageHeader
      title="Системные настройки"
      subtitle="LLM провайдеры, пользователи и системные журналы"
    />

    <v-tabs v-model="activeTab" color="primary" class="mb-4">
      <v-tab value="llm" prepend-icon="mdi-robot">LLM</v-tab>
      <v-tab value="prompts" prepend-icon="mdi-text-box-edit">Промпты</v-tab>
      <v-tab value="stats" prepend-icon="mdi-chart-line">Статистика</v-tab>
      <v-tab value="users" prepend-icon="mdi-account-group">Пользователи</v-tab>
      <v-tab value="notifications" prepend-icon="mdi-bell-outline">Уведомления</v-tab>
      <v-tab value="logs" prepend-icon="mdi-text-box-search">Логи</v-tab>
    </v-tabs>

    <v-window v-model="activeTab">
      <v-window-item value="llm">
        <AdminLlmSettings />
      </v-window-item>
      
      <v-window-item value="prompts">
        <AdminPromptsSettings />
      </v-window-item>
      
      <v-window-item value="stats">
        <AdminLlmStats />
      </v-window-item>
      
      <v-window-item value="users">
        <AdminUsersTab />
      </v-window-item>

      <v-window-item value="notifications">
        <AdminNotificationsTab />
      </v-window-item>
      
      <v-window-item value="logs">
        <AdminSystemLogsTab />
      </v-window-item>
    </v-window>
  </PageContainer>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AdminLlmSettings from '@/components/admin/AdminLlmSettings.vue'
import AdminPromptsSettings from '@/components/admin/AdminPromptsSettings.vue'
import AdminLlmStats from '@/components/admin/AdminLlmStats.vue'
import AdminUsersTab from '@/components/admin/AdminUsersTab.vue'
import AdminSystemLogsTab from '@/components/admin/AdminSystemLogsTab.vue'
import AdminNotificationsTab from '@/components/notifications/AdminNotificationsTab.vue'
import PageContainer from '@/components/layout/PageContainer.vue'
import PageHeader from '@/components/layout/PageHeader.vue'

const route = useRoute()
const router = useRouter()

const routeToTab: Record<string, string> = {
  'admin-system': 'llm',
  'admin-system-llm': 'llm',
  'admin-system-prompts': 'prompts',
  'admin-system-stats': 'stats',
  'admin-system-users': 'users',
  'admin-system-notifications': 'notifications',
  'admin-system-logs': 'logs',
}

const tabToRoute: Record<string, string> = {
  llm: 'admin-system-llm',
  prompts: 'admin-system-prompts',
  stats: 'admin-system-stats',
  users: 'admin-system-users',
  notifications: 'admin-system-notifications',
  logs: 'admin-system-logs',
}

function getInitialTab(): string {
  const routeName = String(route.name || '')
  if (routeName === 'admin-system') {
    const queryTab = typeof route.query.tab === 'string' ? route.query.tab : ''
    if (queryTab && tabToRoute[queryTab]) {
      return queryTab
    }
    return 'llm'
  }

  return routeToTab[routeName] || 'llm'
}

const activeTab = ref(getInitialTab())

watch(activeTab, (tab) => {
  const targetRoute = tabToRoute[tab]
  if (targetRoute && route.name !== targetRoute) {
    router.replace({ name: targetRoute })
    return
  }

  if (route.name === 'admin-system' && route.query.tab !== tab) {
    router.replace({ query: { ...route.query, tab } })
  }
})

watch(() => route.name, (name) => {
  const tab = routeToTab[String(name || '')]
  if (tab && activeTab.value !== tab) {
    activeTab.value = tab
  }
})

watch(() => route.query.tab, (tab) => {
  if (route.name !== 'admin-system') return
  if (!tab || typeof tab !== 'string') return
  if (tabToRoute[tab] && activeTab.value !== tab) {
    activeTab.value = tab
  }
})
</script>


