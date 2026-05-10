<template>
  <v-app>
    <!-- Mobile Header (только на мобильных) -->
    <div v-if="compactNav && !drawerOpen" class="mobile-header">
      <button class="mobile-menu-btn" @click="drawerOpen = true">
        <v-icon icon="mdi-menu" size="24" />
      </button>
      <span class="mobile-title">ПРИЗМА</span>
      <div class="mobile-actions">
        <v-tooltip v-if="lastProject" :text="lastProjectTooltip" location="bottom">
          <template #activator="{ props: tooltipProps }">
            <v-btn
              v-bind="tooltipProps"
              class="toolbar-action toolbar-action--icon"
              icon="mdi-history"
              variant="tonal"
              size="small"
              aria-label="Открыть последний проект"
              @click="openLastProject"
            />
          </template>
        </v-tooltip>

        <v-tooltip text="Идеи и предложения" location="bottom">
          <template #activator="{ props: tooltipProps }">
            <v-btn
              v-bind="tooltipProps"
              class="toolbar-action toolbar-action--icon"
              :class="{ 'toolbar-action--active': isIdeasRoute }"
              icon="mdi-lightbulb-outline"
              :variant="isIdeasRoute ? 'flat' : 'tonal'"
              :color="isIdeasRoute ? 'primary' : undefined"
              size="small"
              aria-label="Идеи и предложения"
              @click="goToIdeas"
            />
          </template>
        </v-tooltip>

        <v-tooltip text="Уведомления" location="bottom">
          <template #activator="{ props: tooltipProps }">
            <v-btn
              v-bind="tooltipProps"
              class="toolbar-action toolbar-action--icon"
              variant="tonal"
              size="small"
              icon
              aria-label="Уведомления"
              @click="openNotifications"
            >
              <v-badge
                :model-value="notificationsStore.hasUnread"
                :content="notificationsStore.badgeText"
                color="error"
                offset-x="-2"
                offset-y="-2"
              >
                <v-icon icon="mdi-bell-outline" size="20" />
              </v-badge>
            </v-btn>
          </template>
        </v-tooltip>
      </div>
    </div>

    <!-- Sidebar (Mistral-style) -->
    <AppSidebar
      v-model="drawerOpen"
      @open-settings="openAccountSettings"
      @open-profile="openProfileEdit"
      @logout="handleLogout"
    />

    <!-- Main content area -->
    <v-main
      class="app-main md3-app-shell"
      :class="{
        'app-main--mobile-header': compactNav,
        'app-main--project-editor': isProjectEditorRoute,
        'app-main--with-toolbar': showTopToolbar,
      }"
    >
      <div v-if="showTopToolbar" class="app-top-toolbar">
        <div class="app-top-toolbar__inner md3-app-shell__content">
          <div class="app-top-toolbar__search-slot" aria-hidden="true" />
          <div class="toolbar-actions">
            <v-tooltip v-if="lastProject" :text="lastProjectTooltip" location="bottom">
              <template #activator="{ props: tooltipProps }">
                <v-btn
                  v-bind="tooltipProps"
                  class="toolbar-action toolbar-action--last-project"
                  variant="tonal"
                  size="small"
                  aria-label="Открыть последний проект"
                  @click="openLastProject"
                >
                  <v-icon icon="mdi-history" size="18" />
                  <span class="toolbar-action__label">{{ lastProjectLabel }}</span>
                </v-btn>
              </template>
            </v-tooltip>

            <v-tooltip text="Идеи и предложения" location="bottom">
              <template #activator="{ props: tooltipProps }">
                <v-btn
                  v-bind="tooltipProps"
                  class="toolbar-action"
                  :class="{ 'toolbar-action--active': isIdeasRoute }"
                  :variant="isIdeasRoute ? 'flat' : 'tonal'"
                  :color="isIdeasRoute ? 'primary' : undefined"
                  size="small"
                  aria-label="Идеи и предложения"
                  @click="goToIdeas"
                >
                  <v-icon icon="mdi-lightbulb-outline" size="18" />
                  <span class="toolbar-action__label">Идеи</span>
                </v-btn>
              </template>
            </v-tooltip>

            <v-tooltip text="Уведомления" location="bottom">
              <template #activator="{ props: tooltipProps }">
                <v-btn
                  v-bind="tooltipProps"
                  class="toolbar-action toolbar-action--icon"
                  variant="tonal"
                  size="small"
                  icon
                  aria-label="Уведомления"
                  @click="openNotifications"
                >
                  <v-badge
                    :model-value="notificationsStore.hasUnread"
                    :content="notificationsStore.badgeText"
                    color="error"
                    offset-x="-2"
                    offset-y="-2"
                  >
                    <v-icon icon="mdi-bell-outline" size="20" />
                  </v-badge>
                </v-btn>
              </template>
            </v-tooltip>
          </div>
        </div>
      </div>

      <!-- Page content -->
      <div
        class="page-content md3-app-shell__content"
        :class="{ 'page-content--project-editor': isProjectEditorRoute }"
      >
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

    <!-- Support chat widget -->
    <SupportChatWidget />
  </v-app>
</template>

<script setup lang="ts">
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useDisplay, useTheme } from 'vuetify'
import { useAuthStore } from '@/stores/auth'
import { useNotificationsStore } from '@/stores/notifications'
import api from '@/api/axios'
import {
  isAppThemeMode,
  readStoredThemeMode,
  resolveThemeName,
  writeStoredThemeMode,
  type AppThemeMode,
} from '@/plugins/appTheme'
import AppSidebar from './shell/AppSidebarNew.vue'
import AccountSettingsDialog from './shell/AccountSettingsDialog.vue'
import ProfileEditModal from '@/components/settings/ProfileEditModal.vue'
import SupportChatWidget from '@/components/support/SupportChatWidget.vue'

const router = useRouter()
const route = useRoute()
const authStore = useAuthStore()
const notificationsStore = useNotificationsStore()
const { smAndDown } = useDisplay()
const theme = useTheme()
const compactNav = computed(() => smAndDown.value)
const isProjectEditorRoute = computed(() => route.name === 'ProjectEditorView')
const isIdeasRoute = computed(() => String(route.path).startsWith('/ideas'))
const showTopToolbar = computed(() => !compactNav.value)

const LAST_PROJECT_KEY = 'prism.lastProject'

interface LastProjectLink {
  id: string
  number: string
  address?: string | null
  updated_at?: string | null
}

const lastProject = ref<LastProjectLink | null>(readLastProject())
const lastProjectLabel = computed(() => lastProject.value?.number || 'Последний проект')
const lastProjectTooltip = computed(() => {
  if (!lastProject.value) return ''
  const address = lastProject.value.address?.trim()
  return address
    ? `Открыть последний проект: ${address}`
    : `Открыть последний проект: ${lastProjectLabel.value}`
})

function readLastProject(): LastProjectLink | null {
  try {
    const raw = localStorage.getItem(LAST_PROJECT_KEY)
    if (!raw) return null
    const parsed = JSON.parse(raw) as Partial<LastProjectLink>
    const id = String(parsed.id ?? '').trim()
    if (!id) return null
    return {
      id,
      number: String(parsed.number ?? 'Последний проект').trim() || 'Последний проект',
      address: typeof parsed.address === 'string' ? parsed.address : null,
      updated_at: typeof parsed.updated_at === 'string' ? parsed.updated_at : null,
    }
  } catch {
    localStorage.removeItem(LAST_PROJECT_KEY)
    return null
  }
}

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

function goToIdeas() {
  if (!isIdeasRoute.value) {
    void router.push({ name: 'ideas' })
  }
}

async function openLastProject() {
  const target = lastProject.value
  if (!target) return

  try {
    await api.get(`/api/projects/${encodeURIComponent(target.id)}`)
    await router.push({ name: 'ProjectEditorView', params: { projectPublicId: target.id } })
  } catch {
    localStorage.removeItem(LAST_PROJECT_KEY)
    lastProject.value = null
  }
}

function openNotifications() {
  window.dispatchEvent(new CustomEvent('app-notifications:open'))
}

function handleLastProjectUpdated() {
  lastProject.value = readLastProject()
}

// Theme mode
const themeMode = ref<AppThemeMode>(readStoredThemeMode())

let mediaQuery: MediaQueryList | null = null
let mediaListener: ((e: MediaQueryListEvent) => void) | null = null
const systemPrefersDark = ref(false)

function applyTheme() {
  const themeName = resolveThemeName(themeMode.value, systemPrefersDark.value)
  if (theme.global.name.value !== themeName) {
    theme.global.name.value = themeName
  }
}

function handleThemeModeChange(e: Event) {
  const mode = (e as CustomEvent).detail
  if (!isAppThemeMode(mode)) return

  themeMode.value = mode
  writeStoredThemeMode(mode)
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
  window.addEventListener('storage', handleLastProjectUpdated)
  window.addEventListener('prism:last-project-updated', handleLastProjectUpdated)
  
  applyTheme()
  handleSettingsQueryOpen()
})

onBeforeUnmount(() => {
  if (mediaQuery && mediaListener) {
    mediaQuery.removeEventListener('change', mediaListener)
  }
  window.removeEventListener('theme-mode-change', handleThemeModeChange)
  window.removeEventListener('storage', handleLastProjectUpdated)
  window.removeEventListener('prism:last-project-updated', handleLastProjectUpdated)
})

watch(themeMode, () => {
  writeStoredThemeMode(themeMode.value)
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
    radial-gradient(1200px 520px at -8% -16%, rgba(var(--v-theme-primary), 0.1), transparent 55%),
    radial-gradient(900px 440px at 108% 4%, rgba(var(--v-theme-tertiary), 0.08), transparent 60%),
    rgb(var(--v-theme-background));
}

.app-main--mobile-header {
  padding-top: 56px;
}

.md3-app-shell {
  position: relative;
}

.md3-app-shell::before {
  content: '';
  position: absolute;
  inset: 0;
  pointer-events: none;
  background:
    linear-gradient(180deg, rgba(var(--v-theme-primary), 0.04), transparent 220px),
    linear-gradient(120deg, rgba(var(--v-theme-secondary), 0.03), transparent 38%);
}

.page-content {
  position: relative;
  z-index: 1;
  padding: 24px;
  min-height: 100vh;
}

.app-main--with-toolbar .page-content {
  min-height: calc(100vh - 64px);
}

.md3-app-shell__content {
  max-width: 1600px;
  margin: 0 auto;
}

.app-main--project-editor {
  overflow: hidden;
}

.page-content--project-editor {
  width: 100%;
  max-width: none;
  height: 100dvh;
  min-height: 0;
  overflow: hidden;
  box-sizing: border-box;
}

.app-main--with-toolbar .page-content--project-editor {
  height: calc(100dvh - 64px);
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
  background: rgba(var(--v-theme-surface-container-low), 0.94);
  backdrop-filter: blur(12px);
  border-bottom: 1px solid rgba(var(--v-theme-outline-variant), 0.72);
  z-index: 100;
}

.mobile-menu-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 40px;
  height: 40px;
  border: none;
  border-radius: 9999px;
  background: transparent;
  color: rgb(var(--v-theme-on-surface));
  cursor: pointer;
}

.mobile-menu-btn:active {
  background: rgba(var(--v-theme-primary), 0.12);
}

.mobile-title {
  font-size: 0.95rem;
  font-weight: 700;
  letter-spacing: 0.02em;
  color: rgb(var(--v-theme-on-surface));
}

.mobile-actions {
  margin-left: auto;
  display: flex;
  align-items: center;
  gap: 6px;
}

.app-top-toolbar {
  position: relative;
  z-index: 2;
  min-height: 64px;
  padding: 12px 24px 0;
}

.app-top-toolbar__inner {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
}

.app-top-toolbar__search-slot {
  flex: 1;
  min-width: 0;
}

.toolbar-actions {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 8px;
}

.toolbar-action {
  min-width: 0;
  border-radius: 999px;
  text-transform: none;
  font-weight: 700;
  color: rgb(var(--v-theme-on-surface));
}

.toolbar-action__label {
  margin-left: 6px;
  max-width: 180px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.toolbar-action--active {
  box-shadow: var(--ds-shadow-soft);
}

.toolbar-action--icon {
  flex-shrink: 0;
}

/* Mobile */
@media (max-width: 600px) {
  .page-content {
    padding: 16px;
  }

  .page-content--project-editor {
    height: calc(100dvh - 56px);
  }
}

@media (max-width: 960px) {
  .toolbar-action__label {
    display: none;
  }
}
</style>
