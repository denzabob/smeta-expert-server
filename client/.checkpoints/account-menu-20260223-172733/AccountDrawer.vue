<template>
  <Teleport to="body">
    <Transition name="drawer">
      <div v-if="modelValue" class="drawer-overlay" :class="{ 'drawer-overlay--dark': isDark }" @click.self="close">
        <div class="drawer-container">
          <div class="drawer-content">
            <!-- User header -->
            <div class="drawer-header">
              <div class="user-block">
                <div class="user-avatar">
                  {{ userInitial }}
                </div>
                <div class="user-info">
                  <div class="user-name">{{ userName }}</div>
                  <div class="user-email">{{ userEmail }}</div>
                </div>
              </div>
              <button class="close-btn" @click="close">
                <v-icon icon="mdi-close" size="20" />
              </button>
            </div>

            <div class="drawer-divider" />

            <!-- Menu items -->
            <nav class="drawer-nav">
              <button
                v-for="item in menuItems"
                :key="item.id"
                class="drawer-item"
                :class="{ 'drawer-item--danger': item.action === 'logout' }"
                @click="handleItemClick(item)"
              >
                <v-icon :icon="item.icon" size="20" class="drawer-item-icon" />
                <span class="drawer-item-text">{{ item.title }}</span>
                <v-badge
                  v-if="item.badge && notificationsStore.hasUnread"
                  :content="notificationsStore.badgeText"
                  color="error"
                  inline
                  class="ml-auto"
                />
              </button>
            </nav>

            <!-- Theme switcher -->
            <div class="drawer-divider" />
            
            <div class="theme-section">
              <div class="theme-label">Тема</div>
              <div class="theme-toggle">
                <button
                  class="theme-btn"
                  :class="{ 'theme-btn--active': themeMode === 'light' }"
                  @click="setThemeMode('light')"
                >
                  <v-icon icon="mdi-white-balance-sunny" size="18" />
                </button>
                <button
                  class="theme-btn"
                  :class="{ 'theme-btn--active': themeMode === 'dark' }"
                  @click="setThemeMode('dark')"
                >
                  <v-icon icon="mdi-moon-waning-crescent" size="18" />
                </button>
                <button
                  class="theme-btn"
                  :class="{ 'theme-btn--active': themeMode === 'auto' }"
                  @click="setThemeMode('auto')"
                >
                  <v-icon icon="mdi-theme-light-dark" size="18" />
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { useRouter } from 'vue-router'
import { accountMenuItems, type AccountMenuItem } from './sidebarConfig'
import { useNotificationsStore } from '@/stores/notifications'

const props = defineProps<{
  modelValue: boolean
  userName: string
  userEmail: string
  userInitial: string
}>()

const emit = defineEmits<{
  (e: 'update:modelValue', value: boolean): void
  (e: 'open-settings', tab?: string): void
  (e: 'open-notifications'): void
  (e: 'logout'): void
}>()

const router = useRouter()
const notificationsStore = useNotificationsStore()
const menuItems = accountMenuItems

// Theme mode
const savedMode = localStorage.getItem('app-theme-mode') as 'light' | 'dark' | 'auto' | null
const themeMode = ref<'light' | 'dark' | 'auto'>(savedMode || 'auto')
const systemPrefersDark = ref(false)

let mediaQuery: MediaQueryList | null = null
let mediaListener: ((e: MediaQueryListEvent) => void) | null = null

const isDark = computed(() => {
  return themeMode.value === 'auto' ? systemPrefersDark.value : themeMode.value === 'dark'
})

function setThemeMode(mode: 'light' | 'dark' | 'auto') {
  themeMode.value = mode
  localStorage.setItem('app-theme-mode', mode)
  window.dispatchEvent(new CustomEvent('theme-mode-change', { detail: mode }))
}

function handleThemeModeChange(e: Event) {
  themeMode.value = (e as CustomEvent).detail as 'light' | 'dark' | 'auto'
}

function close() {
  emit('update:modelValue', false)
}

function handleItemClick(item: AccountMenuItem) {
  if (item.action === 'logout') {
    close()
    emit('logout')
    return
  }
  
  if (item.action === 'support') {
    close()
    window.open('https://t.me/denzabob', '_blank')
    return
  }

  if (item.action === 'notifications') {
    close()
    emit('open-notifications')
    return
  }
  
  if (item.route) {
    close()
    router.push(item.route)
    return
  }

  if (item.tab) {
    emit('open-settings', item.tab)
    return
  }
}

// Close on Escape
function handleKeydown(e: KeyboardEvent) {
  if (e.key === 'Escape' && props.modelValue) {
    close()
  }
}

onMounted(() => {
  if (typeof window !== 'undefined' && 'matchMedia' in window) {
    mediaQuery = window.matchMedia('(prefers-color-scheme: dark)')
    systemPrefersDark.value = mediaQuery.matches
    mediaListener = (e: MediaQueryListEvent) => {
      systemPrefersDark.value = e.matches
    }
    mediaQuery.addEventListener('change', mediaListener)
  }

  window.addEventListener('theme-mode-change', handleThemeModeChange)
  document.addEventListener('keydown', handleKeydown)
})

onBeforeUnmount(() => {
  if (mediaQuery && mediaListener) {
    mediaQuery.removeEventListener('change', mediaListener)
  }
  window.removeEventListener('theme-mode-change', handleThemeModeChange)
  document.removeEventListener('keydown', handleKeydown)
})
</script>

<style scoped>
.drawer-overlay {
  position: fixed;
  inset: 0;
  z-index: 2000;
  display: flex;
  align-items: flex-end;
  justify-content: flex-start;
  padding: 16px;
  background: rgba(0, 0, 0, 0.3);
}

.drawer-container {
  width: 320px;
  max-width: calc(100vw - 32px);
  max-height: calc(100vh - 32px);
  margin-left: 48px;
  margin-bottom: 0;
}

.drawer-content {
  background: var(--drawer-bg, #fff);
  border-radius: 12px;
  box-shadow: 0 8px 32px rgba(0, 0, 0, 0.15);
  overflow: hidden;
}

/* Header */
.drawer-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  padding: 16px;
}

.user-block {
  display: flex;
  align-items: center;
  gap: 12px;
}

.user-avatar {
  width: 44px;
  height: 44px;
  border-radius: 50%;
  background: var(--drawer-avatar-bg, #e5e5e5);
  color: var(--drawer-text, #333);
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 600;
  font-size: 16px;
  flex-shrink: 0;
}

.user-info {
  min-width: 0;
}

.user-name {
  font-size: 15px;
  font-weight: 600;
  color: var(--drawer-text, #1a1a1a);
  line-height: 1.3;
}

.user-email {
  font-size: 13px;
  color: var(--drawer-muted, #666);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.close-btn {
  width: 32px;
  height: 32px;
  display: flex;
  align-items: center;
  justify-content: center;
  border: none;
  border-radius: 6px;
  background: transparent;
  color: var(--drawer-muted, #888);
  cursor: pointer;
  transition: all 0.15s ease;
}

.close-btn:hover {
  background: var(--drawer-hover, #f0f0f0);
  color: var(--drawer-text, #333);
}

/* Divider */
.drawer-divider {
  height: 1px;
  background: var(--drawer-border, #e5e5e5);
  margin: 0 12px;
}

/* Nav */
.drawer-nav {
  padding: 8px;
}

.drawer-item {
  display: flex;
  align-items: center;
  gap: 12px;
  width: 100%;
  padding: 10px 12px;
  border: none;
  border-radius: 8px;
  background: transparent;
  color: var(--drawer-text, #333);
  font-size: 14px;
  text-align: left;
  cursor: pointer;
  transition: background-color 0.15s ease;
}

.drawer-item:hover {
  background: var(--drawer-hover, #f5f5f5);
}

.drawer-item--danger {
  color: var(--drawer-danger, #dc2626);
}

.drawer-item--danger:hover {
  background: var(--drawer-danger-hover, #fef2f2);
}

.drawer-item-icon {
  flex-shrink: 0;
  opacity: 0.8;
}

.drawer-item-text {
  flex: 1;
}

/* Theme section */
.theme-section {
  padding: 12px 16px 16px;
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.theme-label {
  font-size: 13px;
  color: var(--drawer-muted, #666);
}

.theme-toggle {
  display: flex;
  border: 1px solid var(--drawer-border, #e5e5e5);
  border-radius: 6px;
  overflow: hidden;
}

.theme-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 36px;
  height: 32px;
  border: none;
  background: transparent;
  color: var(--drawer-muted, #666);
  cursor: pointer;
  transition: all 0.15s ease;
}

.theme-btn:not(:last-child) {
  border-right: 1px solid var(--drawer-border, #e5e5e5);
}

.theme-btn:hover {
  background: var(--drawer-hover, #f5f5f5);
}

.theme-btn--active {
  background: var(--drawer-active-bg, #1a1a1a);
  color: var(--drawer-active-text, #fff);
}

.theme-btn--active:hover {
  background: var(--drawer-active-bg, #333);
}

/* Transitions */
.drawer-enter-active,
.drawer-leave-active {
  transition: opacity 0.2s ease;
}

.drawer-enter-active .drawer-content,
.drawer-leave-active .drawer-content {
  transition: transform 0.2s ease, opacity 0.2s ease;
}

.drawer-enter-from,
.drawer-leave-to {
  opacity: 0;
}

.drawer-enter-from .drawer-content,
.drawer-leave-to .drawer-content {
  transform: translateY(16px);
  opacity: 0;
}

/* Dark theme */
.drawer-overlay--dark .drawer-content {
  --drawer-bg: #252527;
  --drawer-border: #3c3c3e;
  --drawer-text: #e0e0e0;
  --drawer-muted: #909090;
  --drawer-hover: #2e2e30;
  --drawer-avatar-bg: #3c3c3e;
  --drawer-danger: #f87171;
  --drawer-danger-hover: #2d1f1f;
  --drawer-active-bg: #f0f0f0;
  --drawer-active-text: #1a1a1a;
}

/* Mobile */
@media (max-width: 600px) {
  .drawer-overlay {
    padding: 12px;
    align-items: flex-end;
    justify-content: center;
  }
  
  .drawer-container {
    width: 100%;
    max-width: none;
    margin-left: 0;
  }
}
</style>
