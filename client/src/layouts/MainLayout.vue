<template>
  <v-app>
    <!-- Навигационная панель -->
    <v-navigation-drawer
      v-model="drawer"
      :rail="rail"
      expand-on-hover
      permanent
      location="left"
      width="260"
      class="surface"
    >
      <!-- Логотип / Название -->
      <v-list-item
        prepend-avatar="https://via.placeholder.com/40"
        title="ЭкспертСмета"
        subtitle="Мебель"
        class="pa-4"
      ></v-list-item>

      <v-divider></v-divider>

      <!-- Навигация -->
      <v-list density="comfortable" nav>
        <v-list-item
          v-for="item in navItems"
          :key="item.to"
          :to="item.to"
          :title="item.title"
          :prepend-icon="item.icon"
          rounded="xl"
          color="primary"
          class="mx-2 my-1"
        ></v-list-item>
      </v-list>
    </v-navigation-drawer>

    <!-- Основной контент -->
    <v-main>
      <!-- App Bar -->
      <v-app-bar elevation="2" color="surface">
        <!-- Кнопка меню на мобильных -->
        <v-app-bar-nav-icon @click="drawer = !drawer" class="d-lg-none"></v-app-bar-nav-icon>

        <!-- Кнопка rail на десктопе -->
        <v-btn
          icon
          variant="text"
          class="d-none d-lg-flex ml-2"
          @click="rail = !rail"
        >
          <v-icon>{{ rail ? 'mdi-menu-open' : 'mdi-menu' }}</v-icon>
        </v-btn>

        <v-app-bar-title class="font-weight-medium">
          {{ currentTitle }}
        </v-app-bar-title>

        <v-spacer></v-spacer>

        <!-- Уведомления -->
        <v-btn icon variant="text" class="mx-1">
          <v-icon>mdi-bell-outline</v-icon>
          <v-badge v-if="notificationsCount" color="error" :content="notificationsCount"></v-badge>
        </v-btn>

        <!-- Переключатель темы в хедере -->
        <v-menu offset-y>
          <template #activator="{ props }">
            <v-btn icon variant="text" v-bind="props" class="mx-1" :title="themeModeLabel">
              <v-icon size="22">
                <v-fade-transition leave-absolute>
                  <v-icon v-if="themeMode === 'auto'">mdi-theme-light-dark</v-icon>
                  <v-icon v-else-if="isDark">mdi-weather-night</v-icon>
                  <v-icon v-else>mdi-weather-sunny</v-icon>
                </v-fade-transition>
              </v-icon>
            </v-btn>
          </template>
          <v-list density="compact">
            <v-list-item
              :active="themeMode === 'auto'"
              prepend-icon="mdi-theme-light-dark"
              title="Автоматическая"
              @click="setThemeMode('auto')"
            />
            <v-list-item
              :active="themeMode === 'light'"
              prepend-icon="mdi-weather-sunny"
              title="Дневная"
              @click="setThemeMode('light')"
            />
            <v-list-item
              :active="themeMode === 'dark'"
              prepend-icon="mdi-weather-night"
              title="Ночная"
              @click="setThemeMode('dark')"
            />
          </v-list>
        </v-menu>

        <!-- Меню пользователя -->
        <v-menu offset-y>
          <template #activator="{ props }">
            <v-btn icon v-bind="props" class="mx-1">
              <v-avatar size="36" color="primary">
                <span class="text-white text-subtitle-1">{{ userInitial }}</span>
              </v-avatar>
            </v-btn>
          </template>

          <v-list density="compact">
            <v-list-item>
              <template #prepend>
                <v-avatar size="40" color="primary">
                  <span class="text-white">{{ userInitial }}</span>
                </v-avatar>
              </template>
              <v-list-item-title class="font-weight-medium">
                {{ authStore.user?.name || 'Пользователь' }}
              </v-list-item-title>
              <v-list-item-subtitle>{{ authStore.user?.email }}</v-list-item-subtitle>
            </v-list-item>

            <v-divider></v-divider>

            <v-list-item to="/profile" prepend-icon="mdi-account">
              Профиль
            </v-list-item>
            <v-list-item to="/settings" prepend-icon="mdi-cog">
              Настройки
            </v-list-item>
            <v-list-item to="/subscription" prepend-icon="mdi-credit-card">
              Тариф и оплата
            </v-list-item>
            <v-divider></v-divider>
            <v-list-item prepend-icon="mdi-help-circle-outline" @click="openSupport">
              Поддержка
            </v-list-item>
            <v-list-item prepend-icon="mdi-logout" color="error" @click="handleLogout">
              Выйти
            </v-list-item>
          </v-list>
        </v-menu>
      </v-app-bar>

      <!-- Контент страниц -->
      <v-container fluid class="pa-4 pa-md-8">
        <router-view></router-view>
      </v-container>

      <v-fab-transition>
        <v-btn
          v-show="showScrollTop"
          icon
          color="primary"
          class="position-fixed bottom-0 right-0 ma-4"
          @click="scrollToTop"
          aria-label="Наверх"
        >
          <v-icon>mdi-arrow-up</v-icon>
        </v-btn>
      </v-fab-transition>
    </v-main>
  </v-app>
</template>

<script setup lang="ts">
import { computed, ref, watch, onMounted, onBeforeUnmount } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useDisplay, useTheme } from 'vuetify' // ← добавьте useTheme
import { useAuthStore } from '@/stores/auth'

const authStore = useAuthStore()
const router = useRouter()
const route = useRoute()
const { lgAndUp } = useDisplay()
const theme = useTheme() // ← реактивный объект темы

const drawer = ref(true)
const rail = ref(false)
const showScrollTop = ref(false)

watch(lgAndUp, (val) => {
  rail.value = !val
}, { immediate: true })

// Режим темы: light | dark | auto
const legacyTheme = localStorage.getItem('app-theme')
const savedMode = localStorage.getItem('app-theme-mode') as 'light' | 'dark' | 'auto' | null
const themeMode = ref<'light' | 'dark' | 'auto'>(
  savedMode || (legacyTheme === 'myThemeDark' ? 'dark' : legacyTheme === 'myTheme' ? 'light' : 'auto')
)

let mediaQuery: MediaQueryList | null = null
let mediaListener: ((e: MediaQueryListEvent) => void) | null = null
const systemPrefersDark = ref(false)

const applyThemeMode = () => {
  const shouldDark = themeMode.value === 'auto' ? systemPrefersDark.value : themeMode.value === 'dark'
  theme.change(shouldDark ? 'myThemeDark' : 'myTheme')
}

const handleScroll = () => {
  showScrollTop.value = window.scrollY > 600
}

const scrollToTop = () => {
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

const setThemeMode = (mode: 'light' | 'dark' | 'auto') => {
  themeMode.value = mode
}

const themeModeLabel = computed(() => {
  if (themeMode.value === 'auto') return 'Автоматическая тема'
  return themeMode.value === 'dark' ? 'Ночная тема' : 'Дневная тема'
})

onMounted(() => {
  if (typeof window !== 'undefined' && 'matchMedia' in window) {
    mediaQuery = window.matchMedia('(prefers-color-scheme: dark)')
    systemPrefersDark.value = mediaQuery.matches
    mediaListener = (e: MediaQueryListEvent) => {
      systemPrefersDark.value = e.matches
      if (themeMode.value === 'auto') applyThemeMode()
    }
    mediaQuery.addEventListener('change', mediaListener)
  }
  if (typeof window !== 'undefined') {
    window.addEventListener('scroll', handleScroll, { passive: true })
    handleScroll()
  }
  applyThemeMode()
})

onBeforeUnmount(() => {
  if (mediaQuery && mediaListener) {
    mediaQuery.removeEventListener('change', mediaListener)
  }
  if (typeof window !== 'undefined') {
    window.removeEventListener('scroll', handleScroll)
  }
})

watch(themeMode, () => {
  localStorage.setItem('app-theme-mode', themeMode.value)
  applyThemeMode()
})

const baseNavItems = [
  { title: 'Материалы', to: '/materials', icon: 'mdi-layers' },
  { title: 'Проекты', to: '/projects', icon: 'mdi-cogs' },
  { title: 'Объекты', to: '/detail-types', icon: 'mdi-cogs' },
  { title: 'Операции', to: '/operations', icon: 'mdi-file-document' },
  { title: 'Профили работ', to: '/work-profiles', icon: 'mdi-briefcase' },
  { title: 'Парсер', to: '/parser', icon: 'mdi-cloud-download' },
]

const adminNavItem = { title: 'Админ панель', to: '/admin', icon: 'mdi-shield-account' }

const isAdminUser = computed(() => Number(authStore.user?.id) === 1)

const navItems = computed(() => {
  return isAdminUser.value ? [...baseNavItems, adminNavItem] : baseNavItems
})

const currentTitle = computed(() => {
  const item = navItems.value.find((i) => {
    if (i.to === '/') return route.path === '/'
    return route.path === i.to || route.path.startsWith(`${i.to}/`)
  })
  return item ? item.title : 'СметаЭксперт Мебель'
})

const userInitial = computed(() => {
  const name = authStore.user?.name || authStore.user?.email || 'U'
  return name.charAt(0).toUpperCase()
})

const notificationsCount = ref(0)

const handleLogout = async () => {
  await authStore.logout()
  router.push({ name: 'login' })
}

const openSupport = () => {
  window.open('https://t.me/denzabob', '_blank')
}

// Для шаблона: темно ли сейчас?
const isDark = computed(() => theme.global.current.value.dark)
</script>

<style scoped>
.v-navigation-drawer {
  border-right: 1px solid rgba(var(--v-border-color), 0.12);
}
</style>
