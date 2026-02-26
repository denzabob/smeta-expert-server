<template>
  <div class="app-menu" ref="menuRef">
    <!-- Trigger Button -->
    <button 
      class="menu-trigger"
      :class="{ 'menu-trigger--rail': rail, 'menu-trigger--open': isOpen }"
      @click="toggleMenu"
    >
      <div class="menu-logo">
        <div class="logo-placeholder">
          <img :src="currentLogo" alt="Prism logo" class="logo-image" />
        </div>
      </div>
      <template v-if="!rail">
        <span class="menu-title">ПРИЗМА</span>
        <v-icon 
          :icon="isOpen ? 'mdi-chevron-up' : 'mdi-chevron-down'" 
          size="18" 
          class="menu-chevron"
        />
      </template>
    </button>

    <!-- Dropdown Menu -->
    <Teleport to="body">
      <Transition name="menu-fade">
        <div 
          v-if="isOpen" 
          class="menu-dropdown"
          :class="{ 'menu-dropdown--dark': isDark }"
          :style="dropdownStyle"
          ref="dropdownRef"
        >
          <!-- Workspaces Section -->
          <div class="menu-section">
            <div class="menu-section-title">Приложения</div>
            
            <!-- Main App - always active -->
            <button class="menu-item menu-item--active" @click="navigateTo('home')">
              <div class="menu-item-icon menu-item-icon--app">
                <v-icon icon="mdi-calculator-variant" size="20" />
              </div>
              <span class="menu-item-text">Сметы</span>
              <v-icon icon="mdi-check" size="18" class="menu-item-check" />
            </button>
            
            <!-- Admin Panel - only for admin -->
            <button 
              v-if="isAdmin" 
              class="menu-item"
              @click="navigateTo('admin-panel')"
            >
              <div class="menu-item-icon menu-item-icon--admin">
                <v-icon icon="mdi-shield-crown-outline" size="20" />
              </div>
              <span class="menu-item-text">Админ панель</span>
            </button>
            
            <!-- Parser - only for admin -->
            <button 
              v-if="isAdmin" 
              class="menu-item"
              @click="navigateTo('parser')"
            >
              <div class="menu-item-icon menu-item-icon--parser">
                <v-icon icon="mdi-code-json" size="20" />
              </div>
              <span class="menu-item-text">Парсер</span>
            </button>
          </div>

          <div class="menu-divider" />

          <!-- Resources Section -->
          <div class="menu-section">
            <div class="menu-section-title">Ресурсы</div>
            
            <!-- Documentation - available to all -->
            <button class="menu-item" @click="openDocs">
              <div class="menu-item-icon menu-item-icon--docs">
                <v-icon icon="mdi-book-open-page-variant-outline" size="20" />
              </div>
              <span class="menu-item-text">Документация</span>
              <v-icon icon="mdi-open-in-new" size="16" class="menu-item-external" />
            </button>
            
            <!-- API Docs - available to all -->
            <button class="menu-item" @click="openApiDocs">
              <div class="menu-item-icon menu-item-icon--api">
                <v-icon icon="mdi-api" size="20" />
              </div>
              <span class="menu-item-text">API</span>
              <v-icon icon="mdi-open-in-new" size="16" class="menu-item-external" />
            </button>
          </div>
        </div>
      </Transition>
    </Teleport>

    <!-- Backdrop -->
    <Teleport to="body">
      <Transition name="backdrop-fade">
        <div 
          v-if="isOpen" 
          class="menu-backdrop"
          @click="closeMenu"
        />
      </Transition>
    </Teleport>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted, nextTick } from 'vue'
import { useRouter } from 'vue-router'
import { useTheme } from 'vuetify'
import { useAuthStore } from '@/stores/auth'
import logoLight from '@/assets/logo.svg'
import logoDark from '@/assets/logo_wh.svg'

const props = defineProps<{
  rail?: boolean
}>()

const router = useRouter()
const theme = useTheme()
const authStore = useAuthStore()

const isOpen = ref(false)
const menuRef = ref<HTMLElement | null>(null)
const dropdownRef = ref<HTMLElement | null>(null)
const dropdownStyle = ref<Record<string, string>>({})

// Check if current user is admin (user_id === 1)
const isAdmin = computed(() => authStore.user?.id === 1)

// Theme detection
const isDark = computed(() => theme.global.name.value === 'myThemeDark')
const currentLogo = computed(() => (isDark.value ? logoDark : logoLight))

function toggleMenu() {
  if (isOpen.value) {
    closeMenu()
  } else {
    openMenu()
  }
}

function openMenu() {
  isOpen.value = true
  nextTick(() => {
    updateDropdownPosition()
  })
}

function closeMenu() {
  isOpen.value = false
}

function updateDropdownPosition() {
  if (!menuRef.value) return
  
  const rect = menuRef.value.getBoundingClientRect()
  dropdownStyle.value = {
    position: 'fixed',
    top: `${rect.bottom + 8}px`,
    left: `${rect.left}px`,
    minWidth: props.rail ? '240px' : `${rect.width}px`,
    zIndex: '2100',
  }
}

function navigateTo(routeName: string) {
  router.push({ name: routeName })
  closeMenu()
}

function openDocs() {
  // Заглушка - можно заменить на реальный URL документации
  window.open('/docs', '_blank')
  closeMenu()
}

function openApiDocs() {
  // Заглушка - можно заменить на реальный URL API документации
  window.open('/api/docs', '_blank')
  closeMenu()
}

// Handle click outside
function handleClickOutside(event: MouseEvent) {
  if (!isOpen.value) return
  
  const target = event.target as Node
  if (
    menuRef.value?.contains(target) || 
    dropdownRef.value?.contains(target)
  ) {
    return
  }
  
  closeMenu()
}

// Handle escape key
function handleEscape(event: KeyboardEvent) {
  if (event.key === 'Escape' && isOpen.value) {
    closeMenu()
  }
}

// Handle window resize
function handleResize() {
  if (isOpen.value) {
    updateDropdownPosition()
  }
}

onMounted(() => {
  document.addEventListener('click', handleClickOutside)
  document.addEventListener('keydown', handleEscape)
  window.addEventListener('resize', handleResize)
})

onUnmounted(() => {
  document.removeEventListener('click', handleClickOutside)
  document.removeEventListener('keydown', handleEscape)
  window.removeEventListener('resize', handleResize)
})
</script>

<style scoped>
.app-menu {
  width: 100%;
}

.menu-trigger {
  display: flex;
  align-items: center;
  gap: 12px;
  width: 100%;
  padding: 8px 12px;
  border: none;
  border-radius: 8px;
  background: transparent;
  cursor: pointer;
  transition: background-color 0.15s ease;
  color: rgba(var(--v-theme-on-surface), 0.87);
}

.menu-trigger:hover {
  background: rgba(var(--v-theme-on-surface), 0.05);
}

.menu-trigger--open {
  background: rgba(var(--v-theme-on-surface), 0.08);
}

.menu-trigger--rail {
  justify-content: center;
  padding: 8px;
}

.menu-logo {
  flex-shrink: 0;
}

.logo-placeholder {
  width: 36px;
  height: 36px;
  border-radius: 8px;
  background: transparent;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  overflow: hidden;
}

.logo-placeholder :deep(svg) {
  width: 100% !important;
  height: 100% !important;
  display: block;
}

.logo-image {
  width: 100%;
  height: 100%;
  display: block;
  object-fit: contain;
}

.menu-title {
  flex: 1;
  font-size: 15px;
  font-weight: 600;
  text-align: left;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.menu-chevron {
  flex-shrink: 0;
  opacity: 0.6;
  transition: transform 0.2s ease;
}

.menu-trigger--open .menu-chevron {
  transform: rotate(180deg);
}

/* Dropdown Styles */
.menu-dropdown {
  background: rgb(var(--v-theme-surface));
  border-radius: 12px;
  box-shadow: 
    0 4px 6px -1px rgba(0, 0, 0, 0.1),
    0 2px 4px -1px rgba(0, 0, 0, 0.06),
    0 0 0 1px rgba(0, 0, 0, 0.05);
  overflow: hidden;
  min-width: 240px;
}

.menu-dropdown--dark {
  background: #1e1e1e;
  box-shadow: 
    0 4px 6px -1px rgba(0, 0, 0, 0.3),
    0 2px 4px -1px rgba(0, 0, 0, 0.2),
    0 0 0 1px rgba(255, 255, 255, 0.1);
}

.menu-section {
  padding: 8px;
}

.menu-section-title {
  padding: 8px 12px 4px;
  font-size: 11px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  color: rgba(var(--v-theme-on-surface), 0.5);
}

.menu-dropdown--dark .menu-section-title {
  color: rgba(255, 255, 255, 0.5);
}

.menu-divider {
  height: 1px;
  background: rgba(var(--v-theme-on-surface), 0.08);
  margin: 0 8px;
}

.menu-dropdown--dark .menu-divider {
  background: rgba(255, 255, 255, 0.1);
}

.menu-item {
  display: flex;
  align-items: center;
  gap: 12px;
  width: 100%;
  padding: 10px 12px;
  border: none;
  border-radius: 8px;
  background: transparent;
  cursor: pointer;
  transition: background-color 0.15s ease;
  color: rgba(var(--v-theme-on-surface), 0.87);
  text-align: left;
}

.menu-dropdown--dark .menu-item {
  color: rgba(255, 255, 255, 0.87);
}

.menu-item:hover {
  background: rgba(var(--v-theme-on-surface), 0.05);
}

.menu-dropdown--dark .menu-item:hover {
  background: rgba(255, 255, 255, 0.08);
}

.menu-item--active {
  background: rgba(var(--v-theme-primary), 0.1);
}

.menu-dropdown--dark .menu-item--active {
  background: rgba(103, 126, 234, 0.15);
}

.menu-item-icon {
  width: 32px;
  height: 32px;
  border-radius: 6px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.menu-item-icon--app {
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  color: white;
}

.menu-item-icon--admin {
  background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
  color: white;
}

.menu-item-icon--parser {
  background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
  color: white;
}

.menu-item-icon--docs {
  background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
  color: white;
}

.menu-item-icon--api {
  background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
  color: white;
}

.menu-item-text {
  flex: 1;
  font-size: 14px;
  font-weight: 500;
}

.menu-item-check {
  color: rgb(var(--v-theme-primary));
  flex-shrink: 0;
}

.menu-item-external {
  opacity: 0.4;
  flex-shrink: 0;
}

/* Backdrop */
.menu-backdrop {
  position: fixed;
  inset: 0;
  z-index: 2050;
  background: transparent;
}

/* Transitions */
.menu-fade-enter-active,
.menu-fade-leave-active {
  transition: opacity 0.15s ease, transform 0.15s ease;
}

.menu-fade-enter-from,
.menu-fade-leave-to {
  opacity: 0;
  transform: translateY(-8px);
}

.backdrop-fade-enter-active,
.backdrop-fade-leave-active {
  transition: opacity 0.15s ease;
}

.backdrop-fade-enter-from,
.backdrop-fade-leave-to {
  opacity: 0;
}
</style>
