<template>
  <v-app :class="appThemeClass">
    <!-- Mobile Header (только на мобильных) -->
    <div v-if="compactNav && !drawerOpen" class="mobile-header">
      <button class="mobile-menu-btn" @click="drawerOpen = true">
        <v-icon icon="mdi-menu" size="24" />
      </button>
      <span class="mobile-title">ПРИЗМА</span>
    </div>

    <!-- Sidebar (Mistral-style) -->
    <AppSidebar
      v-model="drawerOpen"
      @open-settings="openAccountSettings"
      @logout="handleLogout"
    />

    <!-- Main content area -->
    <v-main class="app-main" :class="{ 'app-main--mobile-header': compactNav }">
      <!-- Page content -->
      <div class="page-content">
        <router-view />
      </div>
    </v-main>

    <!-- Account Settings Dialog -->
    <AccountSettingsDialog
      v-model="settingsDialogOpen"
      :initial-tab="settingsInitialTab"
    />
  </v-app>
</template>

<script setup lang="ts">
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import { useRouter } from 'vue-router'
import { useDisplay, useTheme } from 'vuetify'
import { useAuthStore } from '@/stores/auth'
import AppSidebar from './shell/AppSidebarNew.vue'
import AccountSettingsDialog from './shell/AccountSettingsDialog.vue'

const router = useRouter()
const authStore = useAuthStore()
const { mdAndDown } = useDisplay()
const theme = useTheme()
const compactNav = computed(() => mdAndDown.value)

// Drawer state
const drawerOpen = ref(true)

// Settings dialog
const settingsDialogOpen = ref(false)
const settingsInitialTab = ref<string | undefined>(undefined)

// Theme mode
const savedMode = localStorage.getItem('app-theme-mode') as 'light' | 'dark' | 'auto' | null
const themeMode = ref<'light' | 'dark' | 'auto'>(savedMode || 'auto')

let mediaQuery: MediaQueryList | null = null
let mediaListener: ((e: MediaQueryListEvent) => void) | null = null
const systemPrefersDark = ref(false)

const appThemeClass = computed(() => {
  const isDark = themeMode.value === 'auto' 
    ? systemPrefersDark.value 
    : themeMode.value === 'dark'
  return isDark ? 'app-shell--dark' : 'app-shell--light'
})

function applyTheme() {
  const shouldDark = themeMode.value === 'auto' 
    ? systemPrefersDark.value 
    : themeMode.value === 'dark'
  const themeName = shouldDark ? 'myThemeDark' : 'myTheme'
  if (theme.global.current.value.dark !== shouldDark) {
    theme.global.name.value = themeName
  }
}

function handleThemeModeChange(e: Event) {
  const mode = (e as CustomEvent).detail as 'light' | 'dark' | 'auto'
  themeMode.value = mode
  applyTheme()
}

function openAccountSettings(tab?: string) {
  settingsInitialTab.value = tab
  settingsDialogOpen.value = true
}

async function handleLogout() {
  await authStore.logout()
  router.push({ name: 'login' })
}

// Responsive: на мобильных drawer закрыт по умолчанию
watch(compactNav, (isCompact) => {
  drawerOpen.value = !isCompact
}, { immediate: true })

onMounted(() => {
  // System theme detection
  if (typeof window !== 'undefined' && 'matchMedia' in window) {
    mediaQuery = window.matchMedia('(prefers-color-scheme: dark)')
    systemPrefersDark.value = mediaQuery.matches
    mediaListener = (e: MediaQueryListEvent) => {
      systemPrefersDark.value = e.matches
      if (themeMode.value === 'auto') {
        applyTheme()
      }
    }
    mediaQuery.addEventListener('change', mediaListener)
  }
  
  // Listen for theme mode changes from topbar/dialog
  window.addEventListener('theme-mode-change', handleThemeModeChange)
  
  applyTheme()
})

onBeforeUnmount(() => {
  if (mediaQuery && mediaListener) {
    mediaQuery.removeEventListener('change', mediaListener)
  }
  window.removeEventListener('theme-mode-change', handleThemeModeChange)
})

watch(themeMode, () => {
  applyTheme()
})
</script>

<style scoped>
.app-main {
  min-height: 100vh;
  background: #f5f5f5;
}

.app-main--mobile-header {
  padding-top: 56px;
}

.page-content {
  padding: 24px;
  min-height: 100vh;
}

/* Mobile header */
.mobile-header {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  height: 56px;
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 0 12px;
  background: var(--mobile-header-bg, #fff);
  border-bottom: 1px solid var(--mobile-header-border, #e5e5e5);
  z-index: 100;
}

.mobile-menu-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 40px;
  height: 40px;
  border: none;
  border-radius: 8px;
  background: transparent;
  color: var(--mobile-header-text, #333);
  cursor: pointer;
}

.mobile-menu-btn:active {
  background: var(--mobile-header-hover, #f0f0f0);
}

.mobile-title {
  font-size: 16px;
  font-weight: 600;
  color: var(--mobile-header-text, #1a1a1a);
}

/* Dark theme */
.app-shell--dark .app-main {
  background: #121214;
}

.app-shell--dark .mobile-header {
  --mobile-header-bg: #1c1c1e;
  --mobile-header-border: #2c2c2e;
  --mobile-header-text: #f0f0f0;
  --mobile-header-hover: #2a2a2c;
}

/* Mobile */
@media (max-width: 600px) {
  .page-content {
    padding: 16px;
  }
}
</style>
