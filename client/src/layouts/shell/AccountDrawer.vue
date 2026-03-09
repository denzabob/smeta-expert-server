<template>
  <Teleport to="body">
    <Transition name="drawer">
      <div v-if="modelValue" class="drawer-overlay" @click.self="close">
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

            <!-- Menu items -->
            <nav class="drawer-nav">
              <div class="drawer-section-title">Рабочее</div>
              <button
                v-for="item in quickWorkItems"
                :key="item.id"
                class="drawer-item"
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

              <div class="drawer-section-title mt-1">Аккаунт</div>
              <button
                v-for="item in quickAccountItems"
                :key="item.id"
                class="drawer-item"
                @click="handleItemClick(item)"
              >
                <v-icon :icon="item.icon" size="20" class="drawer-item-icon" />
                <span class="drawer-item-text">{{ item.title }}</span>
              </button>

              <button
                v-if="extraItems.length > 0"
                class="drawer-item drawer-item--more"
                @click="showMore = !showMore"
              >
                <v-icon icon="mdi-dots-horizontal" size="20" class="drawer-item-icon" />
                <span class="drawer-item-text">Дополнительно ({{ extraItems.length }})</span>
                <v-icon :icon="showMore ? 'mdi-chevron-up' : 'mdi-chevron-down'" size="18" class="drawer-item-chevron" />
              </button>

              <Transition name="drawer-expand">
                <div v-if="showMore" class="drawer-extra">
                  <button
                    v-for="item in extraItems"
                    :key="item.id"
                    class="drawer-item"
                    @click="handleItemClick(item)"
                  >
                    <v-icon :icon="item.icon" size="20" class="drawer-item-icon" />
                    <span class="drawer-item-text">{{ item.title }}</span>
                  </button>
                </div>
              </Transition>

              <div class="theme-quick">
                <div class="theme-quick__label">Тема</div>
                <div class="theme-toggle">
                  <button
                    class="theme-btn"
                    :class="{ 'theme-btn--active': themeMode === 'light' }"
                    title="Светлая тема"
                    @click="setThemeMode('light')"
                  >
                    <v-icon icon="mdi-white-balance-sunny" size="18" />
                  </button>
                  <button
                    class="theme-btn"
                    :class="{ 'theme-btn--active': themeMode === 'dark' }"
                    title="Тёмная тема"
                    @click="setThemeMode('dark')"
                  >
                    <v-icon icon="mdi-moon-waning-crescent" size="18" />
                  </button>
                  <button
                    class="theme-btn"
                    :class="{ 'theme-btn--active': themeMode === 'auto' }"
                    title="Авто тема"
                    @click="setThemeMode('auto')"
                  >
                    <v-icon icon="mdi-theme-light-dark" size="18" />
                  </button>
                </div>
              </div>
            </nav>

            <div class="drawer-divider" />

            <div class="drawer-footer">
              <button
                v-if="logoutItem"
                class="drawer-item drawer-item--danger"
                @click="handleItemClick(logoutItem)"
              >
                <v-icon :icon="logoutItem.icon" size="20" class="drawer-item-icon" />
                <span class="drawer-item-text">{{ logoutItem.title }}</span>
              </button>
            </div>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue'
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
const showMore = ref(false)

const quickWorkItemIds = new Set(['notifications', 'project-defaults'])
const quickAccountItemIds = new Set(['profile'])
const quickWorkItems = computed(() =>
  accountMenuItems.filter((item) => quickWorkItemIds.has(item.id) && item.action !== 'logout')
)
const quickAccountItems = computed(() =>
  accountMenuItems.filter((item) => quickAccountItemIds.has(item.id) && item.action !== 'logout')
)
const extraItems = computed(() =>
  accountMenuItems.filter((item) => !quickWorkItemIds.has(item.id) && !quickAccountItemIds.has(item.id) && item.id !== 'logout')
)
const logoutItem = computed(() => accountMenuItems.find((item) => item.id === 'logout') ?? null)

// Theme mode controls shared with the topbar toggle.
const savedMode = localStorage.getItem('app-theme-mode') as 'light' | 'dark' | 'auto' | null
const themeMode = ref<'light' | 'dark' | 'auto'>(savedMode || 'auto')

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
    close()
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
  window.addEventListener('theme-mode-change', handleThemeModeChange)
  document.addEventListener('keydown', handleKeydown)
})

watch(
  () => props.modelValue,
  (opened) => {
    if (!opened) {
      showMore.value = false
    }
  }
)

onBeforeUnmount(() => {
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
  background: rgba(var(--v-theme-on-surface), 0.36);
}

.drawer-container {
  width: 320px;
  max-width: calc(100vw - 32px);
  max-height: calc(100vh - 32px);
  margin-left: 48px;
  margin-bottom: 0;
}

.drawer-content {
  --drawer-bg: rgb(var(--v-theme-surface));
  --drawer-border: rgba(var(--v-theme-on-surface), 0.12);
  --drawer-text: rgb(var(--v-theme-on-surface));
  --drawer-muted: rgba(var(--v-theme-on-surface), 0.65);
  --drawer-hover: rgba(var(--v-theme-on-surface), 0.08);
  --drawer-avatar-bg: rgba(var(--v-theme-on-surface), 0.1);
  --drawer-danger: rgb(var(--v-theme-error));
  --drawer-danger-hover: rgba(var(--v-theme-error), 0.14);
  --drawer-active-bg: rgba(var(--v-theme-primary), 0.2);
  --drawer-active-text: rgb(var(--v-theme-on-surface));
  background: var(--drawer-bg);
  border-radius: 16px;
  box-shadow: 0 12px 32px rgba(5, 10, 20, 0.35);
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
  background: var(--drawer-avatar-bg);
  color: var(--drawer-text);
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
  color: var(--drawer-text);
  line-height: 1.3;
}

.user-email {
  font-size: 13px;
  color: var(--drawer-muted);
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
  border-radius: 8px;
  background: transparent;
  color: var(--drawer-muted);
  cursor: pointer;
  transition: all 0.15s ease;
}

.close-btn:hover {
  background: var(--drawer-hover);
  color: var(--drawer-text);
}

/* Divider */
.drawer-divider {
  height: 1px;
  background: var(--drawer-border);
  margin: 0 12px;
}

/* Nav */
.drawer-nav {
  padding: 8px;
}

.drawer-section-title {
  padding: 6px 12px;
  font-size: 11px;
  font-weight: 600;
  letter-spacing: 0.4px;
  text-transform: uppercase;
  color: var(--drawer-muted);
}

.theme-quick {
  padding: 10px 16px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}

.theme-quick__label {
  font-size: 12px;
  font-weight: 600;
  color: var(--drawer-muted);
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.theme-toggle {
  display: flex;
  border: 1px solid var(--drawer-border);
  border-radius: 12px;
  overflow: hidden;
}

.theme-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 36px;
  height: 30px;
  border: none;
  background: transparent;
  color: var(--drawer-muted);
  cursor: pointer;
  transition: all 0.15s ease;
}

.theme-btn:not(:last-child) {
  border-right: 1px solid var(--drawer-border);
}

.theme-btn:hover {
  background: var(--drawer-hover);
}

.theme-btn--active {
  background: var(--drawer-active-bg);
  color: var(--drawer-active-text);
}

.drawer-item {
  display: flex;
  align-items: center;
  gap: 12px;
  width: 100%;
  padding: 10px 12px;
  border: none;
  border-radius: 12px;
  background: transparent;
  color: var(--drawer-text);
  font-size: 14px;
  text-align: left;
  cursor: pointer;
  transition: background-color 0.15s ease;
}

.drawer-item:hover {
  background: var(--drawer-hover);
}

.drawer-item--danger {
  color: var(--drawer-danger);
}

.drawer-item--danger:hover {
  background: var(--drawer-danger-hover);
}

.drawer-item-icon {
  flex-shrink: 0;
  opacity: 0.8;
}

.drawer-item-text {
  flex: 1;
}

.drawer-item--more {
  color: var(--drawer-muted);
}

.drawer-item-chevron {
  opacity: 0.6;
}

.drawer-extra {
  padding-top: 2px;
}

.drawer-footer {
  padding: 8px;
}

.drawer-expand-enter-active,
.drawer-expand-leave-active {
  transition: opacity 0.16s ease, max-height 0.16s ease;
  max-height: 220px;
}

.drawer-expand-enter-from,
.drawer-expand-leave-to {
  opacity: 0;
  max-height: 0;
  overflow: hidden;
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
