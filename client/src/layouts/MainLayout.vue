<template>
  <v-navigation-drawer
    v-model="drawerOpen"
    app
    floating
    border="0"
    elevation="2"
    :rail="railMode"
    :expand-on-hover="railMode"
    :width="260"
  >
    <v-list nav density="comfortable" lines="one">
      <v-list-subheader>Навигация</v-list-subheader>
      <v-list-item
        v-for="item in items"
        :key="item.to"
        :to="item.to"
        :title="item.title"
        :prepend-icon="item.icon"
        :active="isActive(item.to)"
        rounded="lg"
        color="primary"
        @click="handleNavigate(item.to)"
      />
    </v-list>

    <template #append>
      <v-divider />
      <div class="drawer-footer">
        <v-btn
          block
          variant="text"
          prepend-icon="mdi-help-circle-outline"
          class="text-none"
        >
          Поддержка
        </v-btn>
      </div>
    </template>
  </v-navigation-drawer>

  <v-app-bar color="primary" density="comfortable" elevation="1">
    <v-app-bar-nav-icon class="d-sm-none" @click="drawerOpen = !drawerOpen" />

    <v-btn
      icon
      variant="text"
      class="d-none d-sm-flex"
      @click="railMode = !railMode"
    >
      <v-icon :icon="railMode ? 'mdi-menu-open' : 'mdi-menu'" />
    </v-btn>

    <v-app-bar-title>{{ currentTitle }}</v-app-bar-title>

    <v-spacer />

    <v-btn icon variant="text">
      <v-icon icon="mdi-bell-outline" />
    </v-btn>
    <v-btn icon variant="text">
      <v-icon icon="mdi-account-circle" />
    </v-btn>
  </v-app-bar>

  <v-main class="bg-surface">
    <v-container fluid class="py-6">
      <router-view />
    </v-container>
  </v-main>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useDisplay } from 'vuetify'

type NavItem = {
  title: string
  to: string
  icon: string
}

const items: NavItem[] = [
  { title: 'Главная', to: '/', icon: 'mdi-home' },
  { title: 'Материалы', to: '/materials', icon: 'mdi-layers' },
  { title: 'Фурнитура', to: '/fittings', icon: 'mdi-cogs' },
  { title: 'Смета', to: '/smeta', icon: 'mdi-file-document' },
]

const route = useRoute()
const router = useRouter()
const { mobile } = useDisplay()

const drawerOpen = ref(true)
const railMode = ref(false)

watch(
  () => mobile.value,
  (isMobile) => {
    drawerOpen.value = !isMobile
    railMode.value = !isMobile
  },
  { immediate: true }
)

const currentTitle = computed(() => {
  const match = items.find((item) => route.path.startsWith(item.to))
  return match?.title ?? 'СметаЭксперт Мебель'
})

const isActive = (path: string) => route.path.startsWith(path)

const handleNavigate = (path: string) => {
  router.push(path)
  if (mobile.value) drawerOpen.value = false
}
</script>

<style scoped>
.drawer-footer {
  padding: 12px;
}
</style>
