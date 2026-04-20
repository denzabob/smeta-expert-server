<template>
  <header class="app-topbar">
    <div class="topbar-left">
      <!-- Mobile menu toggle -->
      <button 
        v-if="mobile" 
        class="menu-toggle"
        @click="$emit('toggle-drawer')"
      >
        Menu
      </button>
      
      <h1 class="page-title">{{ pageTitle }}</h1>
    </div>

    <div class="topbar-right">
      <!-- Theme toggle -->
      <div class="theme-toggle">
        <button
          class="theme-btn"
          :class="{ 'theme-btn--active': themeMode === 'light' }"
          @click="setThemeMode('light')"
        >
          Светлая
        </button>
        <button
          class="theme-btn"
          :class="{ 'theme-btn--active': themeMode === 'dark' }"
          @click="setThemeMode('dark')"
        >
          Тёмная
        </button>
        <button
          class="theme-btn"
          :class="{ 'theme-btn--active': themeMode === 'auto' }"
          @click="setThemeMode('auto')"
        >
          Авто
        </button>
      </div>

      <!-- Account dropdown -->
      <div class="account-dropdown" ref="dropdownRef">
        <button class="account-trigger" @click="dropdownOpen = !dropdownOpen">
          {{ userName }}
          <span class="dropdown-arrow">{{ dropdownOpen ? '▲' : '▼' }}</span>
        </button>

        <Transition name="dropdown">
          <div v-if="dropdownOpen" class="dropdown-menu">
            <!-- Секция: Аккаунт (открывает модалку) -->
            <div class="dropdown-section-label">Аккаунт</div>
            <button class="dropdown-item" @click="openSettings('profile')">
              Профиль
            </button>
            <button class="dropdown-item" @click="openSettings('security')">
              Безопасность
            </button>
            <button class="dropdown-item" @click="openSettings('preferences')">
              Предпочтения
            </button>
            
            <div class="dropdown-divider"></div>
            
            <!-- Секция: Приложение (переход на страницу) -->
            <div class="dropdown-section-label">Приложение</div>
            <button class="dropdown-item" @click="goToSettings">
              Настройки
            </button>
            <button class="dropdown-item" @click="goToProjectDefaults">
              Проект
            </button>
            <button class="dropdown-item" @click="openSupport">
              Поддержка
            </button>
            
            <div class="dropdown-divider"></div>
            
            <button class="dropdown-item dropdown-item--danger" @click="handleLogout">
              Выйти
            </button>
          </div>
        </Transition>
      </div>
    </div>
  </header>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useDisplay } from 'vuetify'
import { useAuthStore } from '@/stores/auth'

const emit = defineEmits<{
  (e: 'toggle-drawer'): void
  (e: 'open-settings', tab?: string): void
  (e: 'logout'): void
}>()

const route = useRoute()
const router = useRouter()
const { mobile } = useDisplay()
const authStore = useAuthStore()

const dropdownOpen = ref(false)
const dropdownRef = ref<HTMLElement | null>(null)

// Theme mode
const savedMode = localStorage.getItem('app-theme-mode') as 'light' | 'dark' | 'auto' | null
const themeMode = ref<'light' | 'dark' | 'auto'>(savedMode || 'auto')

function setThemeMode(mode: 'light' | 'dark' | 'auto') {
  themeMode.value = mode
  localStorage.setItem('app-theme-mode', mode)
  // Emit event for parent to handle theme change
  window.dispatchEvent(new CustomEvent('theme-mode-change', { detail: mode }))
}

// Page titles mapping
const pageTitles: Record<string, string> = {
  'home': 'Проекты',
  'materials': 'Материалы',
  'products': 'Готовые изделия',
  'facades': 'Готовые изделия',
  'projects': 'Проекты',
  'ProjectEditorView': 'Редактор сметы',
  'detail-types': 'Объекты',
  'work-profiles': 'Профили работ',
  'settings': 'Настройки',
  'settings-project': 'Настройки проекта',
  'admin-panel': 'Админ панель',
  'parser': 'Парсер',
  'parser-status': 'Статус парсера',
  'parser-materials': 'Материалы парсера',
  'parser-settings': 'Настройки парсера',
}

const pageTitle = computed(() => {
  const name = route.name as string
  return pageTitles[name] || route.meta?.title as string || 'СметаЭксперт'
})

const userName = computed(() => authStore.user?.name || 'Аккаунт')

function openSettings(tab?: string) {
  dropdownOpen.value = false
  emit('open-settings', tab)
}

function goToSettings() {
  dropdownOpen.value = false
  router.push('/settings')
}

function goToProjectDefaults() {
  dropdownOpen.value = false
  router.push('/settings/project')
}

function openSupport() {
  dropdownOpen.value = false
  window.open('https://t.me/denzabob', '_blank')
}

function handleLogout() {
  dropdownOpen.value = false
  emit('logout')
}

// Close dropdown on outside click
function handleClickOutside(e: MouseEvent) {
  if (dropdownRef.value && !dropdownRef.value.contains(e.target as Node)) {
    dropdownOpen.value = false
  }
}

onMounted(() => {
  document.addEventListener('click', handleClickOutside)
})

onBeforeUnmount(() => {
  document.removeEventListener('click', handleClickOutside)
})
</script>

<style scoped>
.app-topbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 24px;
  height: 56px;
  background: rgb(var(--v-theme-surface));
  border-bottom: 1px solid rgba(var(--v-theme-on-surface), 0.12);
}

.topbar-left {
  display: flex;
  align-items: center;
  gap: 16px;
}

.menu-toggle {
  padding: 8px 12px;
  font-size: 13px;
  color: rgb(var(--v-theme-on-surface));
  background: transparent;
  border: 1px solid rgba(var(--v-theme-on-surface), 0.2);
  border-radius: 4px;
  cursor: pointer;
  transition: background-color 0.15s ease;
}

.menu-toggle:hover {
  background: rgba(var(--v-theme-on-surface), 0.08);
}

.page-title {
  font-size: 16px;
  font-weight: 500;
  color: rgb(var(--v-theme-on-surface));
  margin: 0;
  letter-spacing: -0.2px;
}

.topbar-right {
  display: flex;
  align-items: center;
  gap: 16px;
}

.theme-toggle {
  display: flex;
  border: 1px solid rgba(var(--v-theme-on-surface), 0.2);
  border-radius: 4px;
  overflow: hidden;
}

.theme-btn {
  padding: 6px 10px;
  font-size: 12px;
  color: rgb(var(--v-theme-on-surface-variant));
  background: transparent;
  border: none;
  cursor: pointer;
  transition: all 0.15s ease;
}

.theme-btn:not(:last-child) {
  border-right: 1px solid rgba(var(--v-theme-on-surface), 0.2);
}

.theme-btn:hover {
  background: rgba(var(--v-theme-on-surface), 0.08);
}

.theme-btn--active {
  background: rgb(var(--v-theme-primary));
  color: rgb(var(--v-theme-on-primary));
}

.theme-btn--active:hover {
  background: rgba(var(--v-theme-primary), 0.85);
}

.account-dropdown {
  position: relative;
}

.account-trigger {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 8px 12px;
  font-size: 13px;
  color: rgb(var(--v-theme-on-surface));
  background: transparent;
  border: 1px solid rgba(var(--v-theme-on-surface), 0.2);
  border-radius: 4px;
  cursor: pointer;
  transition: all 0.15s ease;
}

.account-trigger:hover {
  background: rgba(var(--v-theme-on-surface), 0.08);
  border-color: rgba(var(--v-theme-on-surface), 0.3);
}

.dropdown-arrow {
  font-size: 10px;
  color: rgb(var(--v-theme-on-surface-variant));
}

.dropdown-menu {
  position: absolute;
  top: calc(100% + 4px);
  right: 0;
  min-width: 200px;
  background: rgb(var(--v-theme-surface));
  border: 1px solid rgba(var(--v-theme-on-surface), 0.12);
  border-radius: 6px;
  box-shadow: 0 4px 16px rgba(var(--v-theme-on-surface), 0.16);
  z-index: 100;
  padding: 6px 0;
}

.dropdown-section-label {
  padding: 8px 16px 4px;
  font-size: 11px;
  font-weight: 500;
  text-transform: uppercase;
  letter-spacing: 0.3px;
  color: rgb(var(--v-theme-on-surface-variant));
}

.dropdown-item {
  display: block;
  width: 100%;
  padding: 10px 16px;
  font-size: 13px;
  color: rgb(var(--v-theme-on-surface));
  background: transparent;
  border: none;
  text-align: left;
  cursor: pointer;
  transition: background-color 0.15s ease;
}

.dropdown-item:hover {
  background: rgba(var(--v-theme-on-surface), 0.08);
}

.dropdown-item--danger {
  color: rgb(var(--v-theme-error));
}

.dropdown-item--danger:hover {
  background: rgba(var(--v-theme-error), 0.12);
}

.dropdown-divider {
  height: 1px;
  background: rgba(var(--v-theme-on-surface), 0.12);
  margin: 4px 0;
}

/* Dropdown animation */
.dropdown-enter-active,
.dropdown-leave-active {
  transition: all 0.15s ease;
}

.dropdown-enter-from,
.dropdown-leave-to {
  opacity: 0;
  transform: translateY(-8px);
}

/* Mobile adjustments */
@media (max-width: 600px) {
  .app-topbar {
    padding: 0 12px;
  }

  .theme-toggle {
    display: none;
  }

  .page-title {
    font-size: 14px;
  }
}
</style>
