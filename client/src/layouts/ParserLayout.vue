<template>
  <v-app>
    <v-app-bar elevation="0" class="app-bar">
      <v-app-bar-nav-icon @click="drawer = !drawer" />

      <v-toolbar-title class="d-flex align-center">
        <v-icon icon="mdi-factory" size="large" class="mr-2" />
        <span class="text-h6 font-weight-bold">ПРИЗМА</span>
        <v-chip size="small" color="primary" class="ml-2">Parser</v-chip>
      </v-toolbar-title>

      <v-spacer />

      <!-- Theme Toggle -->
      <v-menu offset-y>
        <template #activator="{ props }">
          <v-btn icon variant="text" v-bind="props" :title="themeModeLabel">
            <v-icon>
              <v-fade-transition leave-absolute>
                <v-icon v-if="themeMode === 'auto'">mdi-theme-light-dark</v-icon>
                <v-icon v-else-if="isDark">mdi-white-balance-sunny</v-icon>
                <v-icon v-else>mdi-moon-waning-crescent</v-icon>
              </v-fade-transition>
            </v-icon>
          </v-btn>
        </template>
        <v-list density="compact">
          <v-list-item
            :active="themeMode === 'auto'"
            prepend-icon="mdi-theme-light-dark"
            title="Автоматическая"
            @click="setThemeMode('auto')"
          />
          <v-list-item
            :active="themeMode === 'light'"
            prepend-icon="mdi-white-balance-sunny"
            title="Дневная"
            @click="setThemeMode('light')"
          />
          <v-list-item
            :active="themeMode === 'dark'"
            prepend-icon="mdi-moon-waning-crescent"
            title="Ночная"
            @click="setThemeMode('dark')"
          />
        </v-list>
      </v-menu>

      <!-- User Menu -->
      <v-menu>
        <template v-slot:activator="{ props }">
          <v-btn
            icon="mdi-account-circle"
            variant="text"
            v-bind="props"
          />
        </template>
        <v-list>
          <v-list-item>
            <v-list-item-title>Admin User</v-list-item-title>
            <v-list-item-subtitle>admin@smeta.expert</v-list-item-subtitle>
          </v-list-item>
          <v-divider />
          <v-list-item @click="logout" prepend-icon="mdi-logout">
            Logout
          </v-list-item>
        </v-list>
      </v-menu>
    </v-app-bar>

    <v-navigation-drawer v-model="drawer" class="nav-drawer">
      <v-list nav>
        <v-list-item
          prepend-icon="mdi-view-dashboard"
          title="Dashboard"
          value="dashboard"
          to="/parser"
          exact
        />
        <v-list-item
          prepend-icon="mdi-history"
          title="History & Analytics"
          value="history"
          to="/parser/history"
        />
        <v-list-item
          prepend-icon="mdi-cog"
          title="Settings"
          value="settings"
          to="/parser/settings"
        />

        <v-divider class="my-4" />

        <v-list-subheader>SYSTEM</v-list-subheader>

        <v-list-item
          prepend-icon="mdi-information"
          title="System Status"
          value="status"
          @click="openSystemStatus"
        />

        <v-list-item
          prepend-icon="mdi-book-open-variant"
          title="Documentation"
          value="docs"
          href="https://github.com/your-repo/docs"
          target="_blank"
        />
      </v-list>

      <template v-slot:append>
        <div class="pa-4 text-center">
          <v-chip size="small" color="grey" variant="text">
            v2.0.0
          </v-chip>
        </div>
      </template>
    </v-navigation-drawer>

    <v-main>
      <router-view v-slot="{ Component }">
        <transition name="fade" mode="out-in">
          <component :is="Component" />
        </transition>
      </router-view>

      <v-fab-transition>
        <v-btn
          v-show="showScrollTop"
          icon
          color="primary"
          class="position-fixed bottom-0 right-0 ma-4"
          @click="scrollToTop"
          aria-label="Наверх"
        >
          <v-icon>mdi-arrow-up</v-icon>
        </v-btn>
      </v-fab-transition>
    </v-main>

    <!-- System Status Dialog -->
    <v-dialog v-model="statusDialog" max-width="600">
      <v-card>
        <v-card-title>System Status</v-card-title>
        <v-card-text>
          <v-list>
            <v-list-item>
              <template v-slot:prepend>
                <v-icon
                  :icon="systemStatus?.scheduler_running ? 'mdi-check-circle' : 'mdi-close-circle'"
                  :color="systemStatus?.scheduler_running ? 'success' : 'error'"
                />
              </template>
              <v-list-item-title>Laravel Scheduler</v-list-item-title>
              <v-list-item-subtitle>
                {{ systemStatus?.scheduler_running ? 'Running' : 'Offline' }}
              </v-list-item-subtitle>
            </v-list-item>

            <v-list-item>
              <template v-slot:prepend>
                <v-icon icon="mdi-play" color="primary" />
              </template>
              <v-list-item-title>Active Sessions</v-list-item-title>
              <v-list-item-subtitle>{{ systemStatus?.active_sessions || 0 }}</v-list-item-subtitle>
            </v-list-item>

            <v-list-item>
              <template v-slot:prepend>
                <v-icon icon="mdi-heart-pulse" color="success" />
              </template>
              <v-list-item-title>Health Score</v-list-item-title>
              <v-list-item-subtitle>{{ systemStatus?.health_score || 0 }}%</v-list-item-subtitle>
            </v-list-item>
          </v-list>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" @click="statusDialog = false">Close</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </v-app>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, watch, onBeforeUnmount } from 'vue'
import { useTheme } from 'vuetify'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { parserApi, type SystemStatus } from '@/api/parser'

const theme = useTheme()
const router = useRouter()
const authStore = useAuthStore()

const drawer = ref(true)
const statusDialog = ref(false)
const systemStatus = ref<SystemStatus | null>(null)
const showScrollTop = ref(false)

const legacyTheme = localStorage.getItem('theme')
const savedMode = localStorage.getItem('app-theme-mode') as 'light' | 'dark' | 'auto' | null
const themeMode = ref<'light' | 'dark' | 'auto'>(
  savedMode || (legacyTheme === 'expertDark' ? 'dark' : legacyTheme === 'expertLight' ? 'light' : 'auto')
)

let mediaQuery: MediaQueryList | null = null
let mediaListener: ((e: MediaQueryListEvent) => void) | null = null
const systemPrefersDark = ref(false)

const applyThemeMode = () => {
  const shouldDark = themeMode.value === 'auto' ? systemPrefersDark.value : themeMode.value === 'dark'
  theme.global.name.value = shouldDark ? 'expertDark' : 'expertLight'
}

const handleScroll = () => {
  showScrollTop.value = window.scrollY > 600
}

const scrollToTop = () => {
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

const setThemeMode = (mode: 'light' | 'dark' | 'auto') => {
  themeMode.value = mode
}

const themeModeLabel = computed(() => {
  if (themeMode.value === 'auto') return 'Автоматическая тема'
  return themeMode.value === 'dark' ? 'Ночная тема' : 'Дневная тема'
})

const isDark = computed(() => theme.global.current.value.dark)

onMounted(() => {
  if (typeof window !== 'undefined' && 'matchMedia' in window) {
    mediaQuery = window.matchMedia('(prefers-color-scheme: dark)')
    systemPrefersDark.value = mediaQuery.matches
    mediaListener = (e: MediaQueryListEvent) => {
      systemPrefersDark.value = e.matches
      if (themeMode.value === 'auto') applyThemeMode()
    }
    mediaQuery.addEventListener('change', mediaListener)
  }
  if (typeof window !== 'undefined') {
    window.addEventListener('scroll', handleScroll, { passive: true })
    handleScroll()
  }
  applyThemeMode()
})

onBeforeUnmount(() => {
  if (mediaQuery && mediaListener) {
    mediaQuery.removeEventListener('change', mediaListener)
  }
  if (typeof window !== 'undefined') {
    window.removeEventListener('scroll', handleScroll)
  }
})

watch(themeMode, () => {
  localStorage.setItem('app-theme-mode', themeMode.value)
  applyThemeMode()
})

async function openSystemStatus() {
  statusDialog.value = true
  try {
    systemStatus.value = await parserApi.getSystemStatus()
  } catch (error) {
    console.error('Failed to load system status:', error)
  }
}

async function logout() {
  await authStore.logout()
  await router.push({ name: 'login' })
}
</script>

<style scoped lang="scss">
.app-bar {
  border-bottom: 1px solid rgba(0, 0, 0, 0.12);
}

.nav-drawer {
  border-right: 1px solid rgba(0, 0, 0, 0.12);
}

// Page transition
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>
