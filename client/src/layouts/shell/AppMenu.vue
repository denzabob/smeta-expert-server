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
          :style="dropdownStyle"
          ref="dropdownRef"
        >
          <!-- Workspaces Section -->
          <div class="menu-section">
            <div class="menu-section-title">Приложения</div>
            
            <button
              v-for="item in applicationMenuItems"
              :key="item.id"
              class="menu-item"
              :class="{ 'menu-item--active': activeApplication === item.id }"
              @click="navigateTo(item)"
            >
              <div class="menu-item-icon" :class="item.iconClass">
                <v-icon :icon="item.icon" size="20" />
              </div>
              <span class="menu-item-text">{{ item.label }}</span>
              <v-icon
                v-if="activeApplication === item.id"
                icon="mdi-check"
                size="18"
                class="menu-item-check"
              />
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
import { useRoute, useRouter } from 'vue-router'
import { useTheme } from 'vuetify'
import { useAuthStore } from '@/stores/auth'
import { usePriceIndicesCapabilitiesStore } from '@/modules/price-indices/stores/priceIndicesCapabilities'
import {
  buildApplicationMenu,
  getPriceIndicesCapabilityScope,
  hasPriceIndicesRole,
  resolveActiveApplication,
} from '@/modules/price-indices/application'
import type { ApplicationMenuItem } from '@/modules/price-indices/types'
import logoLight from '@/assets/logo.svg'
import logoDark from '@/assets/logo_wh.svg'

const props = defineProps<{
  rail?: boolean
}>()

const emit = defineEmits<{
  (e: 'navigated'): void
}>()

const router = useRouter()
const route = useRoute()
const theme = useTheme()
const authStore = useAuthStore()
const priceIndicesCapabilities = usePriceIndicesCapabilitiesStore()

const isOpen = ref(false)
const menuRef = ref<HTMLElement | null>(null)
const dropdownRef = ref<HTMLElement | null>(null)
const dropdownStyle = ref<Record<string, string>>({})

const activeApplication = computed(() => resolveActiveApplication(route.path))
const applicationMenuItems = computed(() => buildApplicationMenu(
  authStore.user,
  priceIndicesCapabilities.status,
))

// Theme detection
const isDark = computed(() => theme.global.current.value.dark)
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
  const desiredWidth = props.rail ? 240 : Math.max(rect.width, 240)
  const width = Math.min(desiredWidth, window.innerWidth - 16)
  const left = Math.max(8, Math.min(rect.left, window.innerWidth - width - 8))
  dropdownStyle.value = {
    position: 'fixed',
    top: `${rect.bottom + 8}px`,
    left: `${left}px`,
    width: `${width}px`,
    maxWidth: 'calc(100vw - 16px)',
    maxHeight: `calc(100vh - ${rect.bottom + 16}px)`,
    overflowY: 'auto',
    zIndex: '2100',
  }
}

async function navigateTo(item: ApplicationMenuItem) {
  try {
    if (router.hasRoute(item.routeName)) {
      await router.push({ name: item.routeName })
    } else if (item.id === 'admin') {
      // Fallback for compatibility if route names are refactored again.
      await router.push('/admin')
    } else if (item.id === 'parser') {
      await router.push('/parser')
    } else if (item.id === 'price_indices') {
      await router.push('/app/indices')
    } else {
      await router.push('/projects')
    }
    emit('navigated')
  } catch (error) {
    console.error('Menu navigation failed:', error)
  } finally {
    closeMenu()
  }
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
  if (hasPriceIndicesRole(authStore.user)) {
    void priceIndicesCapabilities.load(getPriceIndicesCapabilityScope(authStore.user))
  }
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
  min-height: 48px;
  padding: 8px 12px;
  border: none;
  border-radius: var(--md-sys-shape-corner-large);
  background: transparent;
  cursor: pointer;
  transition: background-color 0.15s ease, color 0.15s ease;
  color: rgba(var(--v-theme-on-surface), 0.87);
}

.menu-trigger:hover {
  background: rgba(var(--v-theme-on-surface), 0.06);
}

.menu-trigger--open {
  background: rgba(var(--v-theme-secondary-container), 0.82);
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
  border-radius: var(--md-sys-shape-corner-medium);
  background: rgba(var(--v-theme-primary), 0.08);
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
  font-size: 0.94rem;
  font-weight: 700;
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
  background:
    linear-gradient(180deg, rgba(var(--v-theme-primary), 0.04), transparent 160px),
    var(--ds-surface-card);
  border-radius: var(--md-sys-shape-corner-extra-large);
  border: 1px solid rgba(var(--v-theme-outline-variant), 0.72);
  box-shadow: var(--ds-shadow-dropdown);
  overflow: hidden;
  min-width: 240px;
}

.menu-section {
  padding: 10px;
}

.menu-section-title {
  padding: 8px 12px 6px;
  font-size: 0.72rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: rgba(var(--v-theme-on-surface-variant), 0.88);
}

.menu-divider {
  height: 1px;
  background: rgba(var(--v-theme-outline-variant), 0.72);
  margin: 0 10px;
}

.menu-item {
  display: flex;
  align-items: center;
  gap: 12px;
  width: 100%;
  min-height: 48px;
  padding: 10px 12px;
  border: none;
  border-radius: var(--md-sys-shape-corner-large);
  background: transparent;
  cursor: pointer;
  transition: background-color 0.15s ease, color 0.15s ease;
  color: rgba(var(--v-theme-on-surface), 0.87);
  text-align: left;
}

.menu-item:hover {
  background: rgba(var(--v-theme-on-surface), 0.06);
}

.menu-item--active {
  background: rgba(var(--v-theme-secondary-container), 0.92);
}

.menu-item-icon {
  width: 32px;
  height: 32px;
  border-radius: var(--md-sys-shape-corner-medium);
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.menu-item-icon--app {
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  color: white;
}

.menu-item-icon--indices {
  background: rgb(var(--v-theme-tertiary-container));
  color: rgb(var(--v-theme-on-tertiary-container));
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
  font-size: 0.9rem;
  font-weight: 600;
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
