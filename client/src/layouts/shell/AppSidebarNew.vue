<template>
  <v-navigation-drawer
    v-model="modelValue"
    :permanent="!mobile"
    :temporary="mobile"
    :width="drawerWidth"
    :rail="isRail && !mobile"
    :rail-width="railWidth"
    class="app-sidebar"
    location="left"
    :style="{
      position: 'fixed',
      top: 0,
      height: '100vh',
      maxHeight: '100vh'
    }"
  >
    <div 
      class="sidebar-inner"
      :class="{ 'sidebar-inner--rail': isRail && !mobile }"
      @click="handleSidebarClick"
    >
      <!-- Header: App Menu + Toggle -->
      <div class="sidebar-header">
        <!-- App Menu (Mistral style) -->
        <AppMenu :rail="isRail && !mobile" />
        
        <!-- Mobile close button -->
        <button 
          v-if="mobile"
          class="header-btn close-btn"
          title="Закрыть меню"
          @click="emit('update:modelValue', false)"
        >
          <v-icon icon="mdi-close" size="20" />
        </button>
        
        <!-- Desktop toggle button -->
        <button 
          v-if="!mobile"
          class="header-btn toggle-btn"
          :class="{ 'toggle-btn--rail': isRail }"
          :title="isRail ? 'Развернуть меню' : 'Свернуть меню'"
          @click="toggleMode"
        >
          <v-icon :icon="isRail ? 'mdi-chevron-right' : 'mdi-chevron-left'" size="20" />
        </button>
      </div>

      <div class="sidebar-divider" />

      <!-- Navigation -->
      <nav class="sidebar-nav">
        <template v-for="(section, sectionIndex) in visibleSections" :key="section.title">
          <!-- Section divider (except first) -->
          <div v-if="sectionIndex > 0" class="section-divider" />
          
          <!-- Section title (only in wide mode) -->
          <div v-if="!isRail" class="section-title">{{ section.title }}</div>

          <!-- Items -->
          <template v-for="item in getVisibleItems(section)" :key="item.routeName">
            <router-link
              v-if="shouldShowItem(item)"
              :to="{ name: item.routeName }"
              custom
              v-slot="{ isActive, isExactActive, navigate }"
            >
              <v-tooltip v-if="isRail" location="end">
        <template #activator="{ props: tooltipProps }">
                  <button
                    v-bind="tooltipProps"
                    class="nav-item"
                    :class="{ 
                      'nav-item--active': isItemActive(item, isActive, isExactActive),
                      'nav-item--rail': isRail
                    }"
                    @click="handleNavClick(navigate)"
                  >
                    <v-icon :icon="item.icon" size="20" class="nav-item-icon" />
                  </button>
                </template>
                {{ item.title }}
              </v-tooltip>
              <button
                v-else
                class="nav-item"
                :class="{ 
                  'nav-item--active': isItemActive(item, isActive, isExactActive),
                  'nav-item--rail': isRail
                }"
                @click="handleNavClick(navigate)"
              >
                <v-icon :icon="item.icon" size="20" class="nav-item-icon" />
                <span class="nav-item-text">{{ item.title }}</span>
              </button>
            </router-link>
          </template>
        </template>
      </nav>

      <!-- Account Section -->
      <div class="sidebar-footer">
        <div class="sidebar-divider" />
        
        <v-tooltip v-if="isRail" location="end">
          <template #activator="{ props: tooltipProps }">
            <button 
              v-bind="tooltipProps"
              class="account-btn"
              :class="{ 'account-btn--rail': isRail }"
              @click="accountDrawerOpen = true"
            >
              <v-badge
                :model-value="notificationsStore.hasUnread"
                :content="notificationsStore.badgeText"
                color="error"
                offset-x="-2"
                offset-y="-2"
              >
                <div class="account-avatar">
                  {{ userInitial }}
                </div>
              </v-badge>
            </button>
          </template>
          Аккаунт и настройки
        </v-tooltip>
        <button 
          v-else
          class="account-btn"
          :class="{ 'account-btn--rail': isRail }"
          @click="accountDrawerOpen = true"
        >
          <v-badge
            :model-value="notificationsStore.hasUnread"
            :content="notificationsStore.badgeText"
            color="error"
            offset-x="-2"
            offset-y="-2"
          >
            <div class="account-avatar">
              {{ userInitial }}
            </div>
          </v-badge>
          <div class="account-info">
            <div class="account-name">{{ userName }}</div>
            <div class="account-email">{{ userEmail }}</div>
          </div>
          <v-icon icon="mdi-chevron-up" size="18" class="account-chevron" />
        </button>
      </div>
    </div>
  </v-navigation-drawer>

  <!-- Account Drawer -->
  <AccountDrawer
    v-model="accountDrawerOpen"
    :user-name="userName"
    :user-email="userEmail"
    :user-initial="userInitial"
    @open-settings="handleOpenSettings"
    @open-profile="handleOpenProfile"
    @open-notifications="handleOpenNotifications"
    @logout="$emit('logout')"
  />

  <!-- Notifications Panel: fullscreen dialog on mobile, side drawer on desktop -->
  <v-dialog
    v-if="mobile"
    v-model="notificationsPanelOpen"
    fullscreen
    transition="dialog-bottom-transition"
    :scrim="false"
  >
    <v-card class="notifications-screen" flat>
      <v-toolbar color="surface" flat border="b">
        <v-btn icon="mdi-arrow-left" variant="text" @click="notificationsPanelOpen = false" />
        <v-toolbar-title>Уведомления</v-toolbar-title>
      </v-toolbar>
      <div class="notifications-screen__body">
        <UserNotificationsPanel />
      </div>
    </v-card>
  </v-dialog>
  <v-navigation-drawer
    v-else
    v-model="notificationsPanelOpen"
    location="left"
    temporary
    :width="400"
    :style="{ position: 'fixed', top: 0, height: '100vh', maxHeight: '100vh' }"
  >
    <UserNotificationsPanel />
  </v-navigation-drawer>
</template>

<script setup lang="ts">
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import { useDisplay } from 'vuetify'
import { useAuthStore } from '@/stores/auth'
import { useNotificationsStore } from '@/stores/notifications'
import { sidebarSections, type MenuSection, type MenuItem } from './sidebarConfig'
import AccountDrawer from './AccountDrawer.vue'
import AppMenu from './AppMenu.vue'
import UserNotificationsPanel from '@/components/notifications/UserNotificationsPanel.vue'

const STORAGE_KEY = 'ui.sidebarMode'
const WIDE_WIDTH = 260
const RAIL_WIDTH = 68

const props = defineProps<{
  modelValue: boolean
}>()

const emit = defineEmits<{
  (e: 'update:modelValue', value: boolean): void
  (e: 'open-settings', tab?: string): void
  (e: 'open-profile'): void
  (e: 'logout'): void
}>()

const { smAndDown } = useDisplay()
const authStore = useAuthStore()
const notificationsStore = useNotificationsStore()
const mobile = computed(() => smAndDown.value)

// Состояние
const sidebarMode = ref<'wide' | 'rail'>('wide')
const accountDrawerOpen = ref(false)
const notificationsPanelOpen = ref(false)

const modelValue = computed({
  get: () => props.modelValue,
  set: (v) => emit('update:modelValue', v)
})

const isRail = computed(() => sidebarMode.value === 'rail' && !mobile.value)
const drawerWidth = computed(() => isRail.value ? RAIL_WIDTH : WIDE_WIDTH)
const railWidth = RAIL_WIDTH

// User data
const me = computed(() => authStore.user)
const userName = computed(() => authStore.user?.name || 'Пользователь')
const userEmail = computed(() => authStore.user?.email || '')
const userInitial = computed(() => {
  const name = userName.value || userEmail.value || 'U'
  return name.charAt(0).toUpperCase()
})

// Фильтрация секций и пунктов по visibleIf
const visibleSections = computed<MenuSection[]>(() => {
  return sidebarSections.filter(section => {
    if (section.visibleIf && !section.visibleIf(me.value)) {
      return false
    }
    // Проверяем, есть ли хоть один видимый пункт
    return section.items.some(item => !item.visibleIf || item.visibleIf(me.value))
  })
})

function getVisibleItems(section: MenuSection): MenuItem[] {
  return section.items.filter(item => !item.visibleIf || item.visibleIf(me.value))
}

function shouldShowItem(item: MenuItem): boolean {
  // В rail режиме показываем только пункты с showInRail
  if (isRail.value && !item.showInRail) {
    return false
  }
  return true
}

function isItemActive(item: MenuItem, isActive: boolean, isExactActive: boolean): boolean {
  if (item.exact) {
    return isExactActive
  }
  return isActive
}

// Toggle wide/rail
function toggleMode() {
  sidebarMode.value = sidebarMode.value === 'wide' ? 'rail' : 'wide'
  localStorage.setItem(STORAGE_KEY, sidebarMode.value)
}

// Handle click on sidebar empty area to expand in rail mode
function handleSidebarClick(event: MouseEvent) {
  if (!isRail.value || mobile.value) return
  
  // Check if click was on an interactive element
  const target = event.target as HTMLElement
  const isInteractive = target.closest('.nav-item, .account-btn, .header-btn, .app-menu, button, a')
  
  if (!isInteractive) {
    // Click on empty area - expand sidebar
    toggleMode()
  }
}

// Закрыть мобильный drawer при навигации
function handleNavClick(navigate: () => void) {
  navigate()
  if (mobile.value) {
    emit('update:modelValue', false)
  }
}

function handleOpenSettings(tab?: string) {
  accountDrawerOpen.value = false
  emit('open-settings', tab)
}

function handleOpenProfile() {
  accountDrawerOpen.value = false
  emit('open-profile')
}

function handleOpenNotifications() {
  accountDrawerOpen.value = false
  notificationsPanelOpen.value = true
}

// Восстановление режима из localStorage
let savedModeBeforeOverride: 'wide' | 'rail' | null = null

function handleRequestRail() {
  if (mobile.value) return
  savedModeBeforeOverride = sidebarMode.value
  sidebarMode.value = 'rail'
}

function handleRestore() {
  if (savedModeBeforeOverride) {
    sidebarMode.value = savedModeBeforeOverride
    savedModeBeforeOverride = null
  }
}

onMounted(() => {
  const saved = localStorage.getItem(STORAGE_KEY) as 'wide' | 'rail' | null
  if (saved === 'wide' || saved === 'rail') {
    sidebarMode.value = saved
  }
  // Start polling unread count
  if (authStore.isAuthenticated) {
    notificationsStore.startPolling()
  }
  window.addEventListener('app-sidebar:request-rail', handleRequestRail)
  window.addEventListener('app-sidebar:restore', handleRestore)
})

onBeforeUnmount(() => {
  notificationsStore.stopPolling()
  window.removeEventListener('app-sidebar:request-rail', handleRequestRail)
  window.removeEventListener('app-sidebar:restore', handleRestore)
})

// На мобильных всегда wide
watch(mobile, (isMobile) => {
  if (isMobile) {
    sidebarMode.value = 'wide'
  }
})

// === Mobile overlay exclusivity ===
// Only one primary overlay at a time on mobile.
// When account drawer opens → close nav drawer.
// When notifications opens → close nav drawer.
// When nav drawer opens → close account drawer and notifications.
watch(accountDrawerOpen, (opened) => {
  if (opened && mobile.value) {
    emit('update:modelValue', false)
    notificationsPanelOpen.value = false
  }
})

watch(notificationsPanelOpen, (opened) => {
  if (opened && mobile.value) {
    emit('update:modelValue', false)
    accountDrawerOpen.value = false
  }
})

watch(modelValue, (opened) => {
  if (opened && mobile.value) {
    accountDrawerOpen.value = false
    notificationsPanelOpen.value = false
  }
})

// Start/stop polling on auth change
watch(() => authStore.isAuthenticated, (authed) => {
  if (authed) {
    notificationsStore.startPolling()
  } else {
    notificationsStore.stopPolling()
  }
})
</script>

<style scoped>
/* === Notifications fullscreen screen (mobile) === */
.notifications-screen {
  display: flex;
  flex-direction: column;
  height: 100%;
  background:
    linear-gradient(180deg, rgba(var(--v-theme-primary), 0.04), transparent 180px),
    var(--ds-surface-page);
}
.notifications-screen__body {
  flex: 1;
  overflow-y: auto;
  padding: 12px;
}

/* Sidebar always fixed to viewport */
.app-sidebar {
  --sidebar-bg: color-mix(in srgb, var(--md-sys-color-surface-container-low) 92%, transparent);
  --sidebar-border: rgba(var(--v-theme-outline-variant), 0.72);
  --sidebar-text: rgb(var(--v-theme-on-surface));
  --sidebar-muted: rgba(var(--v-theme-on-surface-variant), 0.88);
  --sidebar-hover: rgba(var(--v-theme-on-surface), 0.06);
  --sidebar-active-bg: rgba(var(--v-theme-secondary-container), 0.92);
  --sidebar-active-text: rgb(var(--v-theme-on-secondary-container));
  --sidebar-accent: rgb(var(--v-theme-primary));
  --sidebar-avatar-bg: rgba(var(--v-theme-primary), 0.14);
  position: fixed !important;
  top: 0 !important;
  height: 100vh !important;
  max-height: 100vh !important;
  background: var(--sidebar-bg);
  border-right: 1px solid var(--sidebar-border);
  backdrop-filter: blur(14px);
}

/* Override Vuetify's navigation drawer - make it truly fixed */
:deep(.v-navigation-drawer) {
  position: fixed !important;
  top: 0 !important;
  height: 100vh !important;
  max-height: 100vh !important;
}

.app-sidebar:deep(.v-navigation-drawer__content) {
  overflow-y: auto;
  height: 100%;
  background:
    linear-gradient(180deg, rgba(var(--v-theme-primary), 0.05), transparent 180px),
    var(--sidebar-bg);
}

.sidebar-inner {
  display: flex;
  flex-direction: column;
  height: 100%;
  padding: 12px 10px 10px;
  cursor: default;
}

/* Rail mode - show resize cursor on empty areas */
.sidebar-inner--rail {
  cursor: ew-resize;
}

/* Interactive elements in rail mode should have pointer cursor */
.sidebar-inner--rail .nav-item,
.sidebar-inner--rail .account-btn,
.sidebar-inner--rail .header-btn,
.sidebar-inner--rail .app-menu {
  cursor: pointer;
}

/* Header */
.sidebar-header {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 6px 4px 8px;
  min-height: 56px;
}

.header-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 36px;
  height: 36px;
  border: none;
  border-radius: 9999px;
  background: transparent;
  color: var(--sidebar-text);
  cursor: pointer;
  transition: background-color 0.15s ease, transform 0.15s ease;
  flex-shrink: 0;
}

.header-btn:hover {
  background: var(--sidebar-hover);
}

.toggle-btn {
  margin-left: auto;
}

.toggle-btn--rail {
  margin-left: 0;
  margin: 0 auto;
}

/* Dividers */
.sidebar-divider {
  height: 1px;
  background: var(--sidebar-border);
  margin: 10px 6px;
}

.section-divider {
  height: 1px;
  background: var(--sidebar-border);
  margin: 12px 8px;
}

/* Navigation */
.sidebar-nav {
  flex: 1;
  overflow-y: auto;
  overflow-x: hidden;
}

.section-title {
  padding: 12px 14px 8px;
  font-size: 0.72rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: var(--sidebar-muted);
}

.nav-item {
  display: flex;
  align-items: center;
  gap: 12px;
  width: 100%;
  min-height: 48px;
  padding: 12px 14px;
  border: none;
  border-radius: var(--md-sys-shape-corner-large);
  background: transparent;
  color: var(--sidebar-text);
  font-size: 0.92rem;
  font-weight: 500;
  text-align: left;
  cursor: pointer;
  transition: background-color 0.15s ease, color 0.15s ease, transform 0.15s ease;
  position: relative;
}

.nav-item:hover {
  background: var(--sidebar-hover);
}

.nav-item--active {
  background: var(--sidebar-active-bg);
  color: var(--sidebar-active-text);
  font-weight: 700;
}

.nav-item--active::before {
  content: '';
  position: absolute;
  left: 8px;
  top: 10px;
  bottom: 10px;
  width: 4px;
  background: var(--sidebar-accent);
  border-radius: 999px;
}

.nav-item--rail {
  justify-content: center;
  padding: 12px;
  min-height: 52px;
}

.nav-item--rail .nav-item-icon {
  margin: 0;
}

.nav-item-icon {
  flex-shrink: 0;
  color: inherit;
  opacity: 0.88;
}

.nav-item--active .nav-item-icon {
  opacity: 1;
}

.nav-item-text {
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

/* Footer / Account */
.sidebar-footer {
  flex-shrink: 0;
}

.account-btn {
  display: flex;
  align-items: center;
  gap: 10px;
  width: 100%;
  min-height: 60px;
  padding: 12px;
  border: none;
  border-radius: calc(var(--md-sys-shape-corner-large) + 4px);
  background: rgba(var(--v-theme-surface-container-high), 0.62);
  border: 1px solid rgba(var(--v-theme-outline-variant), 0.64);
  cursor: pointer;
  transition: background-color 0.15s ease, border-color 0.15s ease;
  text-align: left;
}

.account-btn:hover {
  background: rgba(var(--v-theme-secondary-container), 0.54);
  border-color: rgba(var(--v-theme-outline), 0.72);
}

.account-btn--rail {
  justify-content: center;
  min-height: 52px;
  padding: 8px;
}

.account-avatar {
  width: 36px;
  height: 36px;
  border-radius: 50%;
  background: var(--sidebar-avatar-bg);
  color: rgb(var(--v-theme-primary));
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 700;
  font-size: 14px;
  flex-shrink: 0;
}

.account-info {
  flex: 1;
  min-width: 0;
}

.account-name {
  font-size: 0.84rem;
  font-weight: 700;
  color: var(--sidebar-text);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.account-email {
  font-size: 0.74rem;
  color: var(--sidebar-muted);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.account-chevron {
  color: var(--sidebar-muted);
  flex-shrink: 0;
}

@media (max-width: 600px) {
  .sidebar-inner {
    padding: 10px 8px 8px;
  }

  .notifications-screen__body {
    padding: 8px;
  }
}
</style>
