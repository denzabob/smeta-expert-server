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
  'operations': 'Операции',
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
  background: #fff;
  border-bottom: 1px solid #e5e5e5;
}

.topbar-left {
  display: flex;
  align-items: center;
  gap: 16px;
}

.menu-toggle {
  padding: 8px 12px;
  font-size: 13px;
  color: #333;
  background: transparent;
  border: 1px solid #ddd;
  border-radius: 4px;
  cursor: pointer;
  transition: background-color 0.15s ease;
}

.menu-toggle:hover {
  background: #f5f5f5;
}

.page-title {
  font-size: 16px;
  font-weight: 500;
  color: #1a1a1a;
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
  border: 1px solid #e5e5e5;
  border-radius: 4px;
  overflow: hidden;
}

.theme-btn {
  padding: 6px 10px;
  font-size: 12px;
  color: #666;
  background: transparent;
  border: none;
  cursor: pointer;
  transition: all 0.15s ease;
}

.theme-btn:not(:last-child) {
  border-right: 1px solid #e5e5e5;
}

.theme-btn:hover {
  background: #f5f5f5;
}

.theme-btn--active {
  background: #1a1a1a;
  color: #fff;
}

.theme-btn--active:hover {
  background: #333;
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
  color: #333;
  background: transparent;
  border: 1px solid #ddd;
  border-radius: 4px;
  cursor: pointer;
  transition: all 0.15s ease;
}

.account-trigger:hover {
  background: #f5f5f5;
  border-color: #ccc;
}

.dropdown-arrow {
  font-size: 10px;
  color: #888;
}

.dropdown-menu {
  position: absolute;
  top: calc(100% + 4px);
  right: 0;
  min-width: 200px;
  background: #fff;
  border: 1px solid #e5e5e5;
  border-radius: 6px;
  box-shadow: 0 4px 16px rgba(0, 0, 0, 0.12);
  z-index: 100;
  padding: 6px 0;
}

.dropdown-section-label {
  padding: 8px 16px 4px;
  font-size: 11px;
  font-weight: 500;
  text-transform: uppercase;
  letter-spacing: 0.3px;
  color: #888;
}

.dropdown-item {
  display: block;
  width: 100%;
  padding: 10px 16px;
  font-size: 13px;
  color: #333;
  background: transparent;
  border: none;
  text-align: left;
  cursor: pointer;
  transition: background-color 0.15s ease;
}

.dropdown-item:hover {
  background: #f5f5f5;
}

.dropdown-item--danger {
  color: #c00;
}

.dropdown-item--danger:hover {
  background: #fee;
}

.dropdown-divider {
  height: 1px;
  background: #e5e5e5;
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

/* Dark theme */
:deep(.v-theme--dark) .app-topbar,
.v-theme--dark .app-topbar {
  background: #1c1c1e;
  border-bottom-color: #2c2c2e;
}

:deep(.v-theme--dark) .page-title,
.v-theme--dark .page-title {
  color: #f0f0f0;
}

:deep(.v-theme--dark) .menu-toggle,
.v-theme--dark .menu-toggle {
  color: #b0b0b0;
  border-color: #3c3c3e;
}

:deep(.v-theme--dark) .menu-toggle:hover,
.v-theme--dark .menu-toggle:hover {
  background: #2a2a2c;
}

:deep(.v-theme--dark) .theme-toggle,
.v-theme--dark .theme-toggle {
  border-color: #3c3c3e;
}

:deep(.v-theme--dark) .theme-btn,
.v-theme--dark .theme-btn {
  color: #909090;
}

:deep(.v-theme--dark) .theme-btn:not(:last-child),
.v-theme--dark .theme-btn:not(:last-child) {
  border-right-color: #3c3c3e;
}

:deep(.v-theme--dark) .theme-btn:hover,
.v-theme--dark .theme-btn:hover {
  background: #2a2a2c;
}

:deep(.v-theme--dark) .theme-btn--active,
.v-theme--dark .theme-btn--active {
  background: #f0f0f0;
  color: #1a1a1a;
}

:deep(.v-theme--dark) .account-trigger,
.v-theme--dark .account-trigger {
  color: #b0b0b0;
  border-color: #3c3c3e;
}

:deep(.v-theme--dark) .account-trigger:hover,
.v-theme--dark .account-trigger:hover {
  background: #2a2a2c;
  border-color: #4c4c4e;
}

:deep(.v-theme--dark) .dropdown-menu,
.v-theme--dark .dropdown-menu {
  background: #252527;
  border-color: #3c3c3e;
  box-shadow: 0 4px 16px rgba(0, 0, 0, 0.3);
}

:deep(.v-theme--dark) .dropdown-section-label,
.v-theme--dark .dropdown-section-label {
  color: #707070;
}

:deep(.v-theme--dark) .dropdown-item,
.v-theme--dark .dropdown-item {
  color: #b0b0b0;
}

:deep(.v-theme--dark) .dropdown-item:hover,
.v-theme--dark .dropdown-item:hover {
  background: #2e2e30;
}

:deep(.v-theme--dark) .dropdown-item--danger,
.v-theme--dark .dropdown-item--danger {
  color: #f87171;
}

:deep(.v-theme--dark) .dropdown-item--danger:hover,
.v-theme--dark .dropdown-item--danger:hover {
  background: #2d1f1f;
}

:deep(.v-theme--dark) .dropdown-divider,
.v-theme--dark .dropdown-divider {
  background: #3c3c3e;
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
