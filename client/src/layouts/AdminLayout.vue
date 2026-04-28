<template>
  <v-app class="admin-layout">
    <!-- Admin Sidebar -->
    <v-navigation-drawer
      v-model="drawerOpen"
      :permanent="!mobile"
      :temporary="mobile"
      :width="240"
      class="admin-sidebar"
      location="left"
    >
      <div class="sidebar-inner">
        <!-- Header -->
        <div class="sidebar-header">
          <router-link to="/admin" class="sidebar-brand">
            <v-icon icon="mdi-shield-account" size="24" class="mr-2" />
            <span v-if="!mobile">Администрирование</span>
          </router-link>
          <v-btn
            v-if="mobile"
            icon="mdi-close"
            variant="text"
            size="small"
            @click="drawerOpen = false"
          />
        </div>

        <v-divider class="my-2" />

        <!-- Navigation Sections -->
        <nav class="sidebar-nav">
          <template v-for="section in navSections" :key="section.title">
            <div class="nav-section">
              <div class="nav-section-title">{{ section.title }}</div>
              <router-link
                v-for="item in section.items"
                :key="item.to"
                :to="item.to"
                custom
                v-slot="{ isActive, isExactActive, navigate }"
              >
                <button
                  class="nav-item"
                  :class="{ 'nav-item--active': item.exact ? isExactActive : isActive }"
                  @click="handleNavClick(navigate)"
                >
                  <v-icon :icon="item.icon" size="20" class="nav-item-icon" />
                  <span class="nav-item-text">{{ item.label }}</span>
                  <v-chip
                    v-if="item.badge"
                    :color="item.badgeColor || 'error'"
                    size="x-small"
                    class="nav-item-badge"
                  >
                    {{ item.badge }}
                  </v-chip>
                </button>
              </router-link>
            </div>
          </template>
        </nav>

        <!-- Footer -->
        <div class="sidebar-footer">
          <v-divider class="mb-2" />
          <router-link to="/" custom v-slot="{ navigate }">
            <button class="nav-item nav-item--back" @click="navigate">
              <v-icon icon="mdi-arrow-left" size="20" class="nav-item-icon" />
              <span class="nav-item-text">Вернуться в систему</span>
            </button>
          </router-link>
        </div>
      </div>
    </v-navigation-drawer>

    <!-- Main Content -->
    <v-main class="admin-main">
      <!-- Top Action Bar -->
      <div class="admin-topbar">
        <v-btn
          v-if="mobile"
          icon="mdi-menu"
          variant="text"
          @click="drawerOpen = true"
        />
        
        <div class="topbar-title">
          <h1 class="text-h6">{{ pageTitle }}</h1>
        </div>

        <v-spacer />

        <!-- Quick Actions -->
        <div class="topbar-actions">
          <v-text-field
            v-model="globalSearch"
            density="compact"
            variant="outlined"
            placeholder="Поиск..."
            prepend-inner-icon="mdi-magnify"
            hide-details
            class="topbar-search"
            style="max-width: 280px"
            @keyup.enter="handleGlobalSearch"
          />
        </div>
      </div>

      <!-- Page Content with Inspector Panel -->
      <div class="admin-content">
        <div class="admin-workspace" :class="{ 'admin-workspace--with-panel': inspectorOpen }">
          <router-view 
            v-slot="{ Component }"
            @open-inspector="handleOpenInspector"
            @close-inspector="handleCloseInspector"
          >
            <component 
              :is="Component" 
              :inspector-open="inspectorOpen"
              @open-inspector="handleOpenInspector"
              @close-inspector="handleCloseInspector"
            />
          </router-view>
        </div>

        <!-- Side Inspector Panel -->
        <transition name="slide-right">
          <div v-if="inspectorOpen" class="admin-inspector">
            <div class="inspector-header">
              <h3 class="text-subtitle-1 font-weight-medium">{{ inspectorTitle }}</h3>
              <v-btn
                icon="mdi-close"
                variant="text"
                size="small"
                @click="handleCloseInspector"
              />
            </div>
            <v-divider />
            <div class="inspector-content">
              <component 
                :is="inspectorComponent" 
                v-bind="inspectorProps"
                @close="handleCloseInspector"
                @saved="handleInspectorSaved"
              />
            </div>
          </div>
        </transition>
      </div>
    </v-main>
  </v-app>
</template>

<script setup lang="ts">
import { ref, computed, provide, shallowRef, onMounted, onUnmounted, type Component } from 'vue'
import { useRoute } from 'vue-router'
import { useDisplay } from 'vuetify'
import { adminChatApi } from '@/api/adminSupportChat'

const route = useRoute()
const { smAndDown } = useDisplay()
const mobile = computed(() => smAndDown.value)

const drawerOpen = ref(true)
const globalSearch = ref('')

// Inspector state
const inspectorOpen = ref(false)
const inspectorTitle = ref('')
const inspectorComponent = shallowRef<Component | null>(null)
const inspectorProps = ref<Record<string, any>>({})

// Page title from route meta
const pageTitle = computed(() => {
  return (route.meta.title as string) || 'Администрирование'
})

// Navigation sections
const navSections = computed(() => [
  {
    title: 'Работа с материалами',
    items: [
      { to: '/admin', label: 'Рабочий стол', icon: 'mdi-view-dashboard-outline', exact: true },
      { to: '/admin/materials', label: 'Материалы', icon: 'mdi-package-variant-closed' },
      { to: '/admin/problems', label: 'Проблемные случаи', icon: 'mdi-alert-circle-outline', badge: problemCount.value || undefined, badgeColor: 'warning' },
      { to: '/admin/ideas', label: 'Модерация идей', icon: 'mdi-lightbulb-on-outline' },
    ]
  },
  {
    title: 'Правила распознавания',
    items: [
      { to: '/admin/rules', label: 'Все правила', icon: 'mdi-ruler-square-compass' },
    ]
  },
  {
    title: 'Система',
    items: [
      { to: '/admin/system/llm', label: 'LLM', icon: 'mdi-robot-outline' },
      { to: '/admin/system/users', label: 'Пользователи', icon: 'mdi-account-group-outline' },
      { to: '/admin/billing', label: 'Биллинг', icon: 'mdi-credit-card-cog-outline' },
      { to: '/admin/system/notifications', label: 'Уведомления', icon: 'mdi-bell-outline' },
      { to: '/admin/system/logs', label: 'Журнал системы', icon: 'mdi-text-box-search-outline' },
    ]
  },
  {
    title: 'Поддержка',
    items: [
      { to: '/admin/chat', label: 'Чаты пользователей', icon: 'mdi-chat-processing-outline', badge: chatUnreadTotal.value || undefined, badgeColor: 'error' },
    ]
  }
])

// Problem count (will be fetched from store/API)
const problemCount = ref<number | null>(null)

// ── Chat unread count ─────────────────────────────────────────────────────────
const chatUnreadTotal = ref(0)
let chatPollTimer: ReturnType<typeof setInterval> | null = null

async function fetchChatUnread(): Promise<void> {
  try {
    const res = await adminChatApi.list({ per_page: 50 })
    chatUnreadTotal.value = res.conversations.reduce((sum, c) => sum + (c.unread_count ?? 0), 0)
  } catch { /* silent */ }
}

onMounted(() => {
  fetchChatUnread()
  chatPollTimer = setInterval(fetchChatUnread, 30_000)
})

onUnmounted(() => {
  if (chatPollTimer) { clearInterval(chatPollTimer); chatPollTimer = null }
})

// Provide inspector controls to child components
function handleOpenInspector(options: { 
  title: string
  component: Component
  props?: Record<string, any>
}) {
  inspectorTitle.value = options.title
  inspectorComponent.value = options.component
  inspectorProps.value = options.props || {}
  inspectorOpen.value = true
}

function handleCloseInspector() {
  inspectorOpen.value = false
  inspectorComponent.value = null
  inspectorProps.value = {}
}

function handleInspectorSaved() {
  // Will be handled by child components
}

// Provide inspector API to children
provide('adminInspector', {
  open: handleOpenInspector,
  close: handleCloseInspector,
  isOpen: computed(() => inspectorOpen.value)
})

function handleNavClick(navigate: () => void) {
  navigate()
  if (mobile.value) {
    drawerOpen.value = false
  }
}

function handleGlobalSearch() {
  // Will implement global search
  console.log('Global search:', globalSearch.value)
}

// Close drawer on mobile by default
if (mobile.value) {
  drawerOpen.value = false
}
</script>

<style scoped>
.admin-layout {
  background: rgb(var(--v-theme-background));
}

.admin-sidebar {
  background: rgb(var(--v-theme-surface));
  border-right: 1px solid rgba(var(--v-theme-on-surface), 0.12);
}

.sidebar-inner {
  display: flex;
  flex-direction: column;
  height: 100%;
  padding: 16px 12px;
}

.sidebar-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 8px;
  margin-bottom: 8px;
}

.sidebar-brand {
  display: flex;
  align-items: center;
  font-weight: 600;
  font-size: 14px;
  color: rgb(var(--v-theme-on-surface));
  text-decoration: none;
}

.sidebar-nav {
  flex: 1;
  overflow-y: auto;
}

.nav-section {
  margin-bottom: 20px;
}

.nav-section-title {
  padding: 8px 12px 6px;
  font-size: 11px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  color: rgb(var(--v-theme-on-surface-variant));
}

.nav-item {
  display: flex;
  align-items: center;
  width: 100%;
  padding: 10px 12px;
  border: none;
  border-radius: 8px;
  background: transparent;
  color: rgb(var(--v-theme-on-surface));
  font-size: 14px;
  text-align: left;
  cursor: pointer;
  transition: all 0.15s ease;
  gap: 10px;
}

.nav-item:hover {
  background: rgba(148, 163, 184, 0.12);
}

.nav-item--active {
  background: rgba(var(--v-theme-primary), 0.2);
  color: rgb(var(--v-theme-primary));
}

.nav-item--active .nav-item-icon {
  color: rgb(var(--v-theme-primary));
}

.nav-item-icon {
  flex-shrink: 0;
  color: rgb(var(--v-theme-on-surface-variant));
}

.nav-item-text {
  flex: 1;
}

.nav-item-badge {
  flex-shrink: 0;
}

.nav-item--back {
  color: rgb(var(--v-theme-on-surface-variant));
}

.nav-item--back:hover {
  background: rgba(148, 163, 184, 0.12);
}

.sidebar-footer {
  margin-top: auto;
}

/* Main content */
.admin-main {
  background: rgb(var(--v-theme-background));
}

.admin-topbar {
  display: flex;
  align-items: center;
  gap: 16px;
  padding: 12px 24px;
  background: rgb(var(--v-theme-surface));
  border-bottom: 1px solid rgba(var(--v-theme-on-surface), 0.12);
  position: sticky;
  top: 0;
  z-index: 10;
}

.topbar-title h1 {
  margin: 0;
  font-weight: 500;
}

.topbar-actions {
  display: flex;
  align-items: center;
  gap: 12px;
}

.admin-content {
  display: flex;
  height: calc(100vh - 65px);
  overflow: hidden;
}

.admin-workspace {
  flex: 1;
  overflow-y: auto;
  padding: 24px;
  transition: all 0.3s ease;
}

.admin-workspace--with-panel {
  padding-right: 0;
}

/* Inspector Panel */
.admin-inspector {
  width: 420px;
  min-width: 420px;
  background: rgb(var(--v-theme-surface-bright));
  border-left: 1px solid rgba(var(--v-theme-on-surface), 0.12);
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

.inspector-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 16px 20px;
}

.inspector-content {
  flex: 1;
  overflow-y: auto;
  padding: 20px;
}

/* Slide animation */
.slide-right-enter-active,
.slide-right-leave-active {
  transition: all 0.3s ease;
}

.slide-right-enter-from,
.slide-right-leave-to {
  transform: translateX(100%);
  opacity: 0;
}

/* Mobile adjustments */
@media (max-width: 960px) {
  .admin-inspector {
    position: fixed;
    right: 0;
    top: 0;
    height: 100vh;
    z-index: 100;
    box-shadow: -4px 0 20px rgba(var(--v-theme-on-surface), 0.14);
  }
  
  .topbar-search {
    display: none;
  }
}
</style>
