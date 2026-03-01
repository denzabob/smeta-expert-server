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
    @open-notifications="handleOpenNotifications"
    @logout="$emit('logout')"
  />

  <!-- Notifications Panel (right side drawer) -->
  <v-navigation-drawer
    v-model="notificationsPanelOpen"
    location="left"
    temporary
    width="400"
    :style="{
      position: 'fixed',
      top: 0,
      height: '100vh',
      maxHeight: '100vh'
    }"
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

function handleOpenNotifications() {
  accountDrawerOpen.value = false
  notificationsPanelOpen.value = true
}

// Восстановление режима из localStorage
onMounted(() => {
  const saved = localStorage.getItem(STORAGE_KEY) as 'wide' | 'rail' | null
  if (saved === 'wide' || saved === 'rail') {
    sidebarMode.value = saved
  }
  // Start polling unread count
  if (authStore.isAuthenticated) {
    notificationsStore.startPolling()
  }
})

onBeforeUnmount(() => {
  notificationsStore.stopPolling()
})

// На мобильных всегда wide
watch(mobile, (isMobile) => {
  if (isMobile) {
    sidebarMode.value = 'wide'
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
/* Sidebar always fixed to viewport */
.app-sidebar {
  position: fixed !important;
  top: 0 !important;
  height: 100vh !important;
  max-height: 100vh !important;
  background: var(--sidebar-bg, #fafafa);
  border-right: 1px solid var(--sidebar-border, #e5e5e5);
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
}

.sidebar-inner {
  display: flex;
  flex-direction: column;
  height: 100%;
  padding: 8px;
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
  padding: 4px;
  min-height: 52px;
}

.header-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  border: none;
  border-radius: 6px;
  background: transparent;
  color: var(--sidebar-text, #555);
  cursor: pointer;
  transition: background-color 0.15s ease;
  flex-shrink: 0;
}

.header-btn:hover {
  background: var(--sidebar-hover, #f0f0f0);
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
  background: var(--sidebar-border, #e5e5e5);
  margin: 8px 4px;
}

.section-divider {
  height: 1px;
  background: var(--sidebar-border, #e5e5e5);
  margin: 8px 8px;
}

/* Navigation */
.sidebar-nav {
  flex: 1;
  overflow-y: auto;
  overflow-x: hidden;
}

.section-title {
  padding: 12px 12px 6px;
  font-size: 11px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  color: var(--sidebar-muted, #888);
}

.nav-item {
  display: flex;
  align-items: center;
  gap: 12px;
  width: 100%;
  padding: 10px 12px;
  border: none;
  border-radius: 8px;
  background: transparent;
  color: var(--sidebar-text, #444);
  font-size: 14px;
  text-align: left;
  cursor: pointer;
  transition: all 0.15s ease;
  position: relative;
}

.nav-item:hover {
  background: var(--sidebar-hover, #f0f0f0);
}

.nav-item--active {
  background: var(--sidebar-active-bg, #e8e8e8);
  color: var(--sidebar-active-text, #1a1a1a);
  font-weight: 500;
}

.nav-item--active::before {
  content: '';
  position: absolute;
  left: 0;
  top: 8px;
  bottom: 8px;
  width: 3px;
  background: var(--sidebar-accent, #333);
  border-radius: 0 2px 2px 0;
}

.nav-item--rail {
  justify-content: center;
  padding: 12px;
}

.nav-item--rail .nav-item-icon {
  margin: 0;
}

.nav-item-icon {
  flex-shrink: 0;
  color: inherit;
  opacity: 0.8;
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
  padding: 10px;
  border: none;
  border-radius: 8px;
  background: transparent;
  cursor: pointer;
  transition: background-color 0.15s ease;
  text-align: left;
}

.account-btn:hover {
  background: var(--sidebar-hover, #f0f0f0);
}

.account-btn--rail {
  justify-content: center;
  padding: 10px;
}

.account-avatar {
  width: 36px;
  height: 36px;
  border-radius: 50%;
  background: var(--sidebar-avatar-bg, #e0e0e0);
  color: var(--sidebar-text, #444);
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 600;
  font-size: 14px;
  flex-shrink: 0;
}

.account-info {
  flex: 1;
  min-width: 0;
}

.account-name {
  font-size: 13px;
  font-weight: 500;
  color: var(--sidebar-text, #1a1a1a);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.account-email {
  font-size: 11px;
  color: var(--sidebar-muted, #666);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.account-chevron {
  color: var(--sidebar-muted, #888);
  flex-shrink: 0;
}

/* Dark theme */
.app-shell--dark .app-sidebar,
.v-theme--dark .app-sidebar,
.v-theme--myThemeDark .app-sidebar,
:deep(.v-theme--dark) .app-sidebar,
:deep(.v-theme--myThemeDark) .app-sidebar {
  --sidebar-bg: #1c1c1e;
  --sidebar-border: #2c2c2e;
  --sidebar-text: #d0d0d0;
  --sidebar-muted: #808080;
  --sidebar-hover: #2a2a2c;
  --sidebar-active-bg: #2e2e30;
  --sidebar-active-text: #f0f0f0;
  --sidebar-accent: #f0f0f0;
  --sidebar-avatar-bg: #3c3c3e;
}
</style>
