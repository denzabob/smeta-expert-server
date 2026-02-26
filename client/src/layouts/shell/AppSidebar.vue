<template>
  <v-navigation-drawer
    v-model="modelValue"
    :permanent="!mobile"
    :temporary="mobile"
    :width="sidebarWidth"
    class="app-sidebar"
    location="left"
  >
    <!-- Logo / Brand -->
    <div class="sidebar-brand">
      <span class="brand-text">{{ collapsed ? 'ЭС' : 'ЭкспертСмета' }}</span>
    </div>

    <v-divider />

    <!-- Navigation Groups -->
    <div class="sidebar-nav">
      <template v-for="(group, index) in menuGroups" :key="group.title">
        <!-- Разделитель перед admin/service группами -->
        <div 
          v-if="isOwnerGroup(group.title)" 
          class="owner-group-separator"
        ></div>
        
        <div class="nav-group" :class="{ 'nav-group--owner': isOwnerGroup(group.title) }">
          <div v-if="!collapsed" class="nav-group-title">{{ group.title }}</div>
          <div v-else class="nav-group-divider"></div>
          
          <div
            v-for="item in group.items"
            :key="item.to"
            class="nav-item"
            :class="{ 
              'nav-item--active': isActive(item.to),
              'nav-item--collapsed': collapsed 
            }"
            @click="navigate(item.to)"
          >
            <span class="nav-item-text">
              {{ collapsed ? item.short : item.title }}
            </span>
          </div>
        </div>
      </template>
    </div>

    <v-spacer />

    <!-- Account Section -->
    <div class="sidebar-footer">
      <v-divider />
      
      <div v-if="!collapsed" class="account-section">
        <div class="account-info">
          <div class="account-name">{{ userName }}</div>
          <div class="account-email">{{ userEmail }}</div>
        </div>
        <button class="account-settings-btn" @click="$emit('open-settings')">
          Настройки
        </button>
      </div>
      <div v-else class="account-section-collapsed">
        <button class="account-btn-collapsed" @click="$emit('open-settings')">
          {{ userInitial }}
        </button>
      </div>

      <button 
        class="logout-btn" 
        :class="{ 'logout-btn--collapsed': collapsed }"
        @click="$emit('logout')"
      >
        {{ collapsed ? 'X' : 'Выйти' }}
      </button>

      <!-- Collapse Toggle (desktop only) -->
      <button 
        v-if="!mobile"
        class="collapse-toggle"
        @click="$emit('toggle-collapse')"
      >
        {{ collapsed ? '→' : '←' }}
      </button>
    </div>
  </v-navigation-drawer>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useDisplay } from 'vuetify'
import { useAuthStore } from '@/stores/auth'

interface MenuItem {
  title: string
  short: string
  to: string
}

interface MenuGroup {
  title: string
  items: MenuItem[]
}

const props = defineProps<{
  modelValue: boolean
  collapsed: boolean
}>()

const emit = defineEmits<{
  (e: 'update:modelValue', value: boolean): void
  (e: 'toggle-collapse'): void
  (e: 'open-settings'): void
  (e: 'logout'): void
}>()

const route = useRoute()
const router = useRouter()
const { mobile } = useDisplay()
const authStore = useAuthStore()

const modelValue = computed({
  get: () => props.modelValue,
  set: (v) => emit('update:modelValue', v)
})

const sidebarWidth = computed(() => props.collapsed ? 72 : 240)

const isAdminUser = computed(() => Number(authStore.user?.id) === 1)

const userName = computed(() => authStore.user?.name || 'Пользователь')
const userEmail = computed(() => authStore.user?.email || '')
const userInitial = computed(() => userName.value.charAt(0).toUpperCase())

// Базовые группы - доступны всем пользователям
const userGroups: MenuGroup[] = [
  {
    title: 'Работа',
    items: [
      { title: 'Проекты', short: 'Пр', to: '/projects' },
    ]
  },
  {
    title: 'Справочники',
    items: [
      { title: 'Материалы', short: 'Мт', to: '/materials' },
      { title: 'Готовые изделия', short: 'ГИ', to: '/products' },
      { title: 'Поставщики', short: 'Пс', to: '/suppliers' },
      { title: 'Объекты', short: 'Об', to: '/detail-types' },
      { title: 'Операции', short: 'Оп', to: '/operations' },
      { title: 'Профили работ', short: 'ПР', to: '/work-profiles' },
    ]
  },
]

// Группы только для владельца (user_id === 1)
const ownerOnlyGroups: MenuGroup[] = [
  {
    title: 'Сервис',
    items: [
      { title: 'Парсер', short: 'Пс', to: '/parser' },
    ]
  },
  {
    title: 'Администрирование',
    items: [
      { title: 'Админ панель', short: 'АП', to: '/admin' },
    ]
  },
]

// Показываем owner-группы только для user_id === 1
const menuGroups = computed<MenuGroup[]>(() => {
  if (isAdminUser.value) {
    return [...userGroups, ...ownerOnlyGroups]
  }
  return userGroups
})

// Проверка, является ли группа admin/service (для визуального разделения)
const isOwnerGroup = (groupTitle: string) => {
  return ownerOnlyGroups.some(g => g.title === groupTitle)
}

function isActive(to: string): boolean {
  if (to === '/') {
    return route.path === '/'
  }
  return route.path === to || route.path.startsWith(`${to}/`)
}

function navigate(to: string) {
  router.push(to)
  if (mobile.value) {
    emit('update:modelValue', false)
  }
}
</script>

<style scoped>
.app-sidebar {
  background: #fafafa;
  border-right: 1px solid #e5e5e5;
  display: flex;
  flex-direction: column;
}

.sidebar-brand {
  padding: 20px 16px;
  font-weight: 600;
  font-size: 16px;
  color: #1a1a1a;
  letter-spacing: -0.3px;
}

.brand-text {
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.sidebar-nav {
  flex: 1;
  overflow-y: auto;
  padding: 8px 0;
}

.nav-group {
  margin-bottom: 8px;
}

.nav-group--owner {
  margin-top: 4px;
}

.owner-group-separator {
  height: 1px;
  background: linear-gradient(to right, transparent, #d0d0d0 20%, #d0d0d0 80%, transparent);
  margin: 12px 16px 8px;
}

.nav-group-title {
  padding: 8px 16px 4px;
  font-size: 11px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  color: #888;
}

.nav-group-divider {
  height: 1px;
  background: #e5e5e5;
  margin: 8px 12px;
}

.nav-item {
  position: relative;
  padding: 10px 16px;
  margin: 2px 8px;
  cursor: pointer;
  border-radius: 4px;
  transition: background-color 0.15s ease;
  user-select: none;
}

.nav-item:hover {
  background: #f0f0f0;
}

.nav-item--active {
  background: #e8e8e8;
}

.nav-item--active::before {
  content: '';
  position: absolute;
  left: 0;
  top: 4px;
  bottom: 4px;
  width: 3px;
  background: #1a1a1a;
  border-radius: 0 2px 2px 0;
}

.nav-item--collapsed {
  text-align: center;
  padding: 10px 8px;
}

.nav-item-text {
  font-size: 14px;
  color: #333;
  font-weight: 400;
}

.nav-item--active .nav-item-text {
  font-weight: 500;
  color: #1a1a1a;
}

.sidebar-footer {
  padding: 12px;
}

.account-section {
  padding: 12px 4px;
}

.account-info {
  margin-bottom: 8px;
}

.account-name {
  font-size: 13px;
  font-weight: 500;
  color: #1a1a1a;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.account-email {
  font-size: 12px;
  color: #666;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.account-settings-btn {
  display: block;
  width: 100%;
  padding: 8px 12px;
  margin-top: 8px;
  font-size: 13px;
  color: #333;
  background: transparent;
  border: 1px solid #ddd;
  border-radius: 4px;
  cursor: pointer;
  transition: background-color 0.15s ease;
  text-align: center;
}

.account-settings-btn:hover {
  background: #f5f5f5;
}

.account-section-collapsed {
  display: flex;
  justify-content: center;
  padding: 8px 0;
}

.account-btn-collapsed {
  width: 36px;
  height: 36px;
  border-radius: 50%;
  background: #e5e5e5;
  border: none;
  cursor: pointer;
  font-weight: 500;
  font-size: 14px;
  color: #333;
  transition: background-color 0.15s ease;
}

.account-btn-collapsed:hover {
  background: #ddd;
}

.logout-btn {
  display: block;
  width: 100%;
  padding: 8px 12px;
  margin-top: 8px;
  font-size: 13px;
  color: #666;
  background: transparent;
  border: 1px solid transparent;
  border-radius: 4px;
  cursor: pointer;
  transition: all 0.15s ease;
  text-align: center;
}

.logout-btn:hover {
  background: #fee;
  color: #c00;
  border-color: #fcc;
}

.logout-btn--collapsed {
  padding: 8px;
}

.collapse-toggle {
  display: block;
  width: 100%;
  padding: 8px;
  margin-top: 8px;
  font-size: 14px;
  color: #888;
  background: transparent;
  border: none;
  cursor: pointer;
  text-align: center;
  transition: color 0.15s ease;
}

.collapse-toggle:hover {
  color: #333;
}

/* Dark theme support */
:deep(.v-theme--dark) .app-sidebar,
.v-theme--dark .app-sidebar {
  background: #1c1c1e;
  border-right-color: #2c2c2e;
}

:deep(.v-theme--dark) .sidebar-brand,
.v-theme--dark .sidebar-brand {
  color: #f0f0f0;
}

:deep(.v-theme--dark) .nav-group-title,
.v-theme--dark .nav-group-title {
  color: #808080;
}

:deep(.v-theme--dark) .nav-group-divider,
.v-theme--dark .nav-group-divider {
  background: #2c2c2e;
}

:deep(.v-theme--dark) .owner-group-separator,
.v-theme--dark .owner-group-separator {
  background: linear-gradient(to right, transparent, #3c3c3e 20%, #3c3c3e 80%, transparent);
}

:deep(.v-theme--dark) .nav-item:hover,
.v-theme--dark .nav-item:hover {
  background: #2a2a2c;
}

:deep(.v-theme--dark) .nav-item--active,
.v-theme--dark .nav-item--active {
  background: #2e2e30;
}

:deep(.v-theme--dark) .nav-item--active::before,
.v-theme--dark .nav-item--active::before {
  background: #f0f0f0;
}

:deep(.v-theme--dark) .nav-item-text,
.v-theme--dark .nav-item-text {
  color: #b0b0b0;
}

:deep(.v-theme--dark) .nav-item--active .nav-item-text,
.v-theme--dark .nav-item--active .nav-item-text {
  color: #f0f0f0;
}

:deep(.v-theme--dark) .account-name,
.v-theme--dark .account-name {
  color: #f0f0f0;
}

:deep(.v-theme--dark) .account-email,
.v-theme--dark .account-email {
  color: #808080;
}

:deep(.v-theme--dark) .account-settings-btn,
.v-theme--dark .account-settings-btn {
  color: #c0c0c0;
  border-color: #3c3c3e;
}

:deep(.v-theme--dark) .account-settings-btn:hover,
.v-theme--dark .account-settings-btn:hover {
  background: #2a2a2c;
}

:deep(.v-theme--dark) .account-btn-collapsed,
.v-theme--dark .account-btn-collapsed {
  background: #3c3c3e;
  color: #f0f0f0;
}

:deep(.v-theme--dark) .logout-btn,
.v-theme--dark .logout-btn {
  color: #808080;
}

:deep(.v-theme--dark) .logout-btn:hover,
.v-theme--dark .logout-btn:hover {
  background: #2d1f1f;
  color: #f87171;
  border-color: #4c2020;
}
</style>
