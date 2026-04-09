<template>
  <v-app>
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
      @open-profile="openProfileEdit"
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

    <!-- Profile Edit Modal (compact, ~420px) -->
    <ProfileEditModal v-model="profileEditOpen" />
  </v-app>
</template>

<script setup lang="ts">
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useDisplay, useTheme } from 'vuetify'
import { useAuthStore } from '@/stores/auth'
import AppSidebar from './shell/AppSidebarNew.vue'
import AccountSettingsDialog from './shell/AccountSettingsDialog.vue'
import ProfileEditModal from '@/components/settings/ProfileEditModal.vue'

const router = useRouter()
const route = useRoute()
const authStore = useAuthStore()
const { smAndDown } = useDisplay()
const theme = useTheme()
const compactNav = computed(() => smAndDown.value)

// Drawer state
const drawerOpen = ref(true)

// Settings dialog
const settingsDialogOpen = ref(false)
const settingsInitialTab = ref<string | undefined>(undefined)

// Profile edit modal
const profileEditOpen = ref(false)

function openProfileEdit() {
  profileEditOpen.value = true
}

// Theme mode
const savedMode = localStorage.getItem('app-theme-mode') as 'light' | 'dark' | 'auto' | null
const themeMode = ref<'light' | 'dark' | 'auto'>(savedMode || 'dark')

let mediaQuery: MediaQueryList | null = null
let mediaListener: ((e: MediaQueryListEvent) => void) | null = null
const systemPrefersDark = ref(false)

function applyTheme() {
  const shouldDark = themeMode.value === 'auto' 
    ? systemPrefersDark.value 
    : themeMode.value === 'dark'
  const themeName = shouldDark ? 'saasDark' : 'saasLight'
  if (theme.global.current.value.dark !== shouldDark) {
    theme.global.name.value = themeName
    return
  }

  if (theme.global.name.value !== themeName) {
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
  // Mobile overlay exclusivity: close nav drawer when settings dialog opens
  if (compactNav.value) {
    drawerOpen.value = false
  }
}

function handleSettingsQueryOpen() {
  const requestedTab = route.query.open_settings
  if (typeof requestedTab !== 'string' || requestedTab.trim() === '') {
    return
  }

  openAccountSettings(requestedTab)

  const nextQuery = { ...route.query }
  delete (nextQuery as any).open_settings
  void router.replace({ query: nextQuery })
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
  handleSettingsQueryOpen()
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

watch(
  () => route.query.open_settings,
  () => {
    handleSettingsQueryOpen()
  }
)
</script>

<style scoped>
.app-main {
  min-height: 100vh;
  background:
    radial-gradient(1200px 520px at -8% -16%, rgba(56, 189, 248, 0.1), transparent 55%),
    radial-gradient(900px 440px at 108% 4%, rgba(14, 165, 233, 0.08), transparent 60%),
    rgb(var(--v-theme-background));
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
  background: rgb(var(--v-theme-surface));
  border-bottom: 1px solid rgba(var(--v-theme-on-surface), 0.12);
  z-index: 100;
}

.mobile-menu-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 40px;
  height: 40px;
  border: none;
  border-radius: 12px;
  background: transparent;
  color: rgb(var(--v-theme-on-surface));
  cursor: pointer;
}

.mobile-menu-btn:active {
  background: rgba(var(--v-theme-on-surface), 0.12);
}

.mobile-title {
  font-size: 16px;
  font-weight: 600;
  color: rgb(var(--v-theme-on-surface));
}

/* Mobile */
@media (max-width: 600px) {
  .page-content {
    padding: 16px;
  }
}
</style>
