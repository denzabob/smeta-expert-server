<template>
  <Teleport to="body">
    <Transition name="drawer">
      <div v-if="modelValue" class="drawer-overlay" @click.self="close">
        <div class="drawer-container">
          <div class="drawer-content">

            <!-- User identity block -->
            <div class="drawer-header">
              <div class="user-block">
                <div class="user-avatar">{{ userInitial }}</div>
                <div class="user-info">
                  <div class="user-name">{{ userName || userEmail }}</div>
                  <div class="user-email">{{ userEmail }}</div>
                </div>
              </div>
              <button class="close-btn" @click="close">
                <v-icon icon="mdi-close" size="20" />
              </button>
            </div>

            <div class="drawer-divider" />

            <!-- Flat navigation hub — no sections, no overflow bucket -->
            <nav class="drawer-nav">
              <template v-for="item in accountMenuItems" :key="item.id">
                <div v-if="item.dividerBefore" class="drawer-divider drawer-divider--inner" />
                <button
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
              </template>
            </nav>

            <div class="drawer-divider" />

            <!-- Theme quick-switch — persistent utility action -->
            <div class="theme-quick">
              <span class="theme-quick__label">Тема</span>
              <div class="theme-toggle">
                <button
                  v-for="mode in themeModes"
                  :key="mode.value"
                  class="theme-btn"
                  :class="{ 'theme-btn--active': themeMode === mode.value }"
                  :title="mode.title"
                  @click="setThemeMode(mode.value)"
                >
                  <v-icon :icon="mode.icon" size="18" />
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
import { ref, onMounted, onBeforeUnmount } from 'vue'
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
  (e: 'open-settings'): void
  (e: 'open-profile'): void
  (e: 'open-notifications'): void
  (e: 'logout'): void
}>()

const router = useRouter()
const notificationsStore = useNotificationsStore()

// ── Theme ──────────────────────────────────────────────────────────────────
const themeModes = [
  { value: 'light' as const, icon: 'mdi-white-balance-sunny', title: 'Светлая тема' },
  { value: 'dark'  as const, icon: 'mdi-moon-waning-crescent', title: 'Тёмная тема' },
  { value: 'auto'  as const, icon: 'mdi-theme-light-dark', title: 'Авто' },
]
const savedMode = localStorage.getItem('app-theme-mode') as 'light' | 'dark' | 'auto' | null
const themeMode = ref<'light' | 'dark' | 'auto'>(savedMode ?? 'auto')

function setThemeMode(mode: 'light' | 'dark' | 'auto') {
  themeMode.value = mode
  localStorage.setItem('app-theme-mode', mode)
  window.dispatchEvent(new CustomEvent('theme-mode-change', { detail: mode }))
}

function handleThemeModeChange(e: Event) {
  themeMode.value = (e as CustomEvent).detail as 'light' | 'dark' | 'auto'
}

// ── Menu actions ────────────────────────────────────────────────────────────
function close() {
  emit('update:modelValue', false)
}

function handleItemClick(item: AccountMenuItem) {
  switch (item.action) {
    case 'logout':
      close(); emit('logout'); return
    case 'support':
      close(); window.open('https://t.me/denzabob', '_blank'); return
    case 'notifications':
      close(); emit('open-notifications'); return
    case 'profile':
      close(); emit('open-profile'); return
    case 'settings':
      close(); emit('open-settings'); return
  }
  if (item.route) {
    close()
    router.push(item.route)
  }
}

function handleKeydown(e: KeyboardEvent) {
  if (e.key === 'Escape' && props.modelValue) close()
}

onMounted(() => {
  window.addEventListener('theme-mode-change', handleThemeModeChange)
  document.addEventListener('keydown', handleKeydown)
})
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
  background: rgba(var(--v-theme-on-surface), 0.28);
  backdrop-filter: blur(10px);
}

.drawer-container {
  width: 296px;
  max-width: calc(100vw - 32px);
  max-height: calc(100vh - 32px);
  margin-left: 48px;
}

.drawer-content {
  --drawer-bg: color-mix(in srgb, var(--md-sys-color-surface-container-low) 94%, transparent);
  --drawer-border: rgba(var(--v-theme-outline-variant), 0.72);
  --drawer-text: rgb(var(--v-theme-on-surface));
  --drawer-muted: rgba(var(--v-theme-on-surface-variant), 0.88);
  --drawer-hover: rgba(var(--v-theme-on-surface), 0.06);
  --drawer-avatar-bg: rgba(var(--v-theme-primary), 0.15);
  --drawer-avatar-text: rgb(var(--v-theme-primary));
  --drawer-danger: rgb(var(--v-theme-error));
  --drawer-danger-hover: rgba(var(--v-theme-error), 0.12);
  --drawer-active-bg: rgba(var(--v-theme-secondary-container), 0.92);
  --drawer-active-text: rgb(var(--v-theme-on-secondary-container));
  background: var(--drawer-bg);
  border-radius: var(--md-sys-shape-corner-extra-large);
  border: 1px solid var(--drawer-border);
  box-shadow: var(--ds-shadow-modal);
  overflow: hidden;
}

/* Header */
.drawer-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  padding: 18px;
  background: linear-gradient(180deg, rgba(var(--v-theme-primary), 0.05), transparent 100%);
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
  color: var(--drawer-avatar-text);
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 700;
  font-size: 15px;
  flex-shrink: 0;
}

.user-info {
  min-width: 0;
}

.user-name {
  font-size: 0.95rem;
  font-weight: 700;
  color: var(--drawer-text);
  line-height: 1.3;
}

.user-email {
  font-size: 0.8rem;
  color: var(--drawer-muted);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.close-btn {
  width: 40px;
  height: 40px;
  display: flex;
  align-items: center;
  justify-content: center;
  border: none;
  border-radius: 9999px;
  background: transparent;
  color: var(--drawer-muted);
  cursor: pointer;
  transition: all 0.15s ease;
}

.close-btn:hover {
  background: var(--drawer-hover);
  color: var(--drawer-text);
}

.drawer-divider {
  height: 1px;
  background: var(--drawer-border);
  margin: 0 14px;
}

.drawer-divider--inner {
  margin: 6px 14px;
}

.drawer-nav {
  padding: 8px 10px;
}

.theme-quick {
  padding: 14px 16px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}

.theme-quick__label {
  font-size: 0.72rem;
  font-weight: 700;
  color: var(--drawer-muted);
  text-transform: uppercase;
  letter-spacing: 0.08em;
}

.theme-toggle {
  display: flex;
  border: 1px solid var(--drawer-border);
  border-radius: var(--md-sys-shape-corner-large);
  overflow: hidden;
  background: rgba(var(--v-theme-surface-container-high), 0.74);
}

.theme-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 38px;
  height: 34px;
  border: none;
  background: transparent;
  color: var(--drawer-muted);
  cursor: pointer;
  transition: all 0.13s;
}

.theme-btn:not(:last-child) { border-right: 1px solid var(--drawer-border); }
.theme-btn:hover { background: var(--drawer-hover); }
.theme-btn--active {
  background: var(--drawer-active-bg);
  color: var(--drawer-active-text);
}

.drawer-item {
  display: flex;
  align-items: center;
  gap: 11px;
  width: 100%;
  min-height: 46px;
  padding: 10px 12px;
  border: none;
  border-radius: var(--md-sys-shape-corner-large);
  background: transparent;
  color: var(--drawer-text);
  font-size: 0.9rem;
  font-weight: 500;
  text-align: left;
  cursor: pointer;
  transition: background 0.13s;
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
  opacity: 0.88;
}

.drawer-item-text { flex: 1; }

/* Transitions */
.drawer-enter-active,
.drawer-leave-active { transition: opacity 0.18s ease; }
.drawer-enter-active .drawer-content,
.drawer-leave-active .drawer-content { transition: transform 0.18s ease, opacity 0.18s ease; }
.drawer-enter-from,
.drawer-leave-to { opacity: 0; }
.drawer-enter-from .drawer-content,
.drawer-leave-to .drawer-content { transform: translateY(12px); opacity: 0; }

/* Mobile — full-screen */
@media (max-width: 600px) {
  .drawer-overlay {
    padding: 0;
    align-items: stretch;
    justify-content: stretch;
  }
  .drawer-container {
    width: 100%;
    max-width: 100vw;
    max-height: 100%;
    margin: 0;
    display: flex;
    flex-direction: column;
    flex: 1;
  }
  .drawer-content {
    border-radius: 0;
    display: flex;
    flex-direction: column;
    flex: 1;
    max-height: none;
  }
  .drawer-nav { flex: 1; overflow-y: auto; }
  .drawer-header {
    padding: 16px;
    padding-top: max(16px, env(safe-area-inset-top));
  }
  .theme-quick {
    padding-bottom: max(10px, env(safe-area-inset-bottom));
  }
}
</style>
