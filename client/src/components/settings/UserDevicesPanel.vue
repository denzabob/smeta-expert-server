<template>
  <div class="devices-panel">
    <!-- ── Loading skeleton ── -->
    <div v-if="loading && !loaded" class="devices-loading">
      <div class="skeleton-block" />
      <div class="skeleton-block skeleton-block--short" />
    </div>

    <template v-else>
      <!-- ════════════════ Текущая сессия ════════════════ -->
      <div class="devices-section">
        <div class="devices-section__title">Текущая сессия</div>
        <div v-if="current" class="session-item">
          <div class="session-item__icon">
            <v-icon size="22" :color="'primary'">{{ platformIcon(current.platform) }}</v-icon>
          </div>
          <div class="session-item__body">
            <div class="session-item__title">{{ sessionTitle(current) }}</div>
            <div class="session-item__subtitle">{{ sessionSubtitle(current) }}</div>
          </div>
        </div>
      </div>

      <!-- ════════════════ Активные сессии ════════════════ -->
      <div class="devices-section">
        <div class="devices-section__title">Активные сессии</div>

        <template v-if="others.length > 0">
          <div
            v-for="s in others"
            :key="s.id"
            class="session-item"
          >
            <div class="session-item__icon">
              <v-icon size="22">{{ platformIcon(s.platform) }}</v-icon>
            </div>
            <div class="session-item__body">
              <div class="session-item__title">{{ sessionTitle(s) }}</div>
              <div class="session-item__subtitle">{{ sessionSubtitle(s) }}</div>
            </div>
          </div>
        </template>

        <div v-else class="devices-empty">
          <v-icon size="20" color="grey" class="mr-2">mdi-check-circle-outline</v-icon>
          Других активных сессий нет
        </div>
      </div>

      <!-- ════════════════ Кнопка завершения ════════════════ -->
      <div class="devices-terminate">
        <button
          class="terminate-btn"
          :class="{ 'terminate-btn--disabled': !hasOthers || terminating }"
          :disabled="!hasOthers || terminating"
          @click="confirmTerminate"
        >
          <v-icon size="20" class="terminate-btn__icon">mdi-logout-variant</v-icon>
          <span class="terminate-btn__text">
            <span class="terminate-btn__label">
              {{ terminating ? 'Завершение...' : 'Завершить активные сессии' }}
            </span>
            <span class="terminate-btn__hint">Выйти со всех устройств, кроме текущего</span>
          </span>
        </button>
      </div>
    </template>

    <!-- ════════════════ Confirm dialog ════════════════ -->
    <v-dialog v-model="showConfirm" max-width="380" persistent>
      <v-card>
        <v-card-title class="text-subtitle-1 font-weight-bold">
          Завершить все другие сессии?
        </v-card-title>
        <v-card-text class="text-body-2 text-medium-emphasis">
          Все устройства, кроме текущего, будут отключены. Для повторного входа потребуется пароль.
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" @click="showConfirm = false">Отмена</v-btn>
          <v-btn color="error" variant="flat" :loading="terminating" @click="doTerminate">
            Завершить
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Snackbar -->
    <v-snackbar v-model="snack.show" :timeout="3000" :color="snack.color" location="bottom right">
      {{ snack.message }}
    </v-snackbar>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { authApi, type SessionInfo } from '@/api/auth'

const loading = ref(false)
const loaded = ref(false)
const current = ref<SessionInfo | null>(null)
const others = ref<SessionInfo[]>([])
const terminating = ref(false)
const showConfirm = ref(false)

const snack = ref({ show: false, message: '', color: 'info' })
function notify(message: string, color = 'info') {
  snack.value = { show: true, message, color }
}

const hasOthers = computed(() => others.value.length > 0)

// ─── Data loading ───
async function loadSessions() {
  loading.value = true
  try {
    const data = await authApi.getSessions()
    current.value = data.current
    others.value = data.others
    loaded.value = true
  } catch {
    notify('Не удалось загрузить сессии', 'error')
  } finally {
    loading.value = false
  }
}

// ─── Terminate ───
function confirmTerminate() {
  showConfirm.value = true
}

async function doTerminate() {
  terminating.value = true
  try {
    await authApi.terminateOtherSessions()
    others.value = []
    showConfirm.value = false
    notify('Все другие сессии завершены', 'success')
  } catch {
    notify('Ошибка завершения сессий', 'error')
  } finally {
    terminating.value = false
  }
}

// ─── Formatting helpers ───
function platformIcon(platform: string): string {
  const map: Record<string, string> = {
    windows: 'mdi-microsoft-windows',
    mac:     'mdi-apple',
    android: 'mdi-android',
    ios:     'mdi-apple',
    linux:   'mdi-linux',
  }
  return map[platform] || 'mdi-monitor'
}

function sessionTitle(s: SessionInfo): string {
  const name = s.device_name || s.platform || 'Unknown'
  const clientPart = s.browser && s.browser !== 'Unknown'
    ? s.browser
    : (s.client === 'web' ? 'Web' : s.client || '')
  if (clientPart) {
    return `${name} — ${clientPart}`
  }
  return name
}

function sessionSubtitle(s: SessionInfo): string {
  const parts: string[] = []

  // Дата / время
  if (s.is_current) {
    parts.push('Сейчас')
  } else if (s.last_active_at) {
    parts.push(formatLastActive(s.last_active_at))
  }

  // Геолокация
  const geo = [s.city, s.country].filter(Boolean).join(', ')
  if (geo) parts.push(geo)

  // IP
  if (s.ip_address) parts.push(s.ip_address)

  return parts.join(' · ') || '—'
}

function formatLastActive(iso: string): string {
  try {
    const date = new Date(iso)
    const now = new Date()
    const isToday =
      date.getDate() === now.getDate() &&
      date.getMonth() === now.getMonth() &&
      date.getFullYear() === now.getFullYear()

    const time = date.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' })

    if (isToday) return `Сегодня в ${time}`

    const day = date.toLocaleDateString('ru-RU', { day: 'numeric', month: 'long' })
    return `${day} в ${time}`
  } catch {
    return iso
  }
}

onMounted(loadSessions)
</script>

<style scoped>
.devices-panel {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

/* ── Section ── */
.devices-section {
  margin-bottom: 16px;
}

.devices-section__title {
  font-size: 12px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--devices-section-title, #888);
  margin-bottom: 8px;
  padding: 0 4px;
}

/* ── Session item ── */
.session-item {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 8px;
  border-radius: 8px;
  transition: background 0.15s ease;
}

.session-item:hover {
  background: var(--devices-hover, #f5f5f5);
}

.session-item__icon {
  flex-shrink: 0;
  width: 40px;
  height: 40px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--devices-icon-bg, #f0f0f0);
  border-radius: 50%;
}

.session-item__body {
  flex: 1;
  min-width: 0;
}

.session-item__title {
  font-size: 14px;
  font-weight: 500;
  color: var(--devices-title, #1a1a1a);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.session-item__subtitle {
  font-size: 12px;
  color: var(--devices-subtitle, #888);
  margin-top: 1px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

/* ── Empty ── */
.devices-empty {
  display: flex;
  align-items: center;
  padding: 12px 8px;
  font-size: 13px;
  color: var(--devices-subtitle, #888);
}

/* ── Terminate ── */
.devices-terminate {
  margin-top: 8px;
  padding-top: 12px;
  border-top: 1px solid var(--devices-border, #eee);
}

.terminate-btn {
  display: flex;
  align-items: center;
  gap: 12px;
  width: 100%;
  padding: 12px 8px;
  background: transparent;
  border: none;
  border-radius: 8px;
  cursor: pointer;
  text-align: left;
  transition: background 0.15s ease;
}

.terminate-btn:hover:not(:disabled) {
  background: var(--devices-hover, #f5f5f5);
}

.terminate-btn--disabled {
  opacity: 0.45;
  cursor: not-allowed;
}

.terminate-btn__icon {
  flex-shrink: 0;
  color: #c62828;
}

.terminate-btn__text {
  display: flex;
  flex-direction: column;
  gap: 1px;
}

.terminate-btn__label {
  font-size: 14px;
  font-weight: 500;
  color: #c62828;
}

.terminate-btn__hint {
  font-size: 12px;
  color: var(--devices-subtitle, #888);
}

/* ── Loading skeleton ── */
.devices-loading {
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding: 16px 0;
}

.skeleton-block {
  height: 52px;
  background: var(--devices-icon-bg, #f0f0f0);
  border-radius: 8px;
  animation: pulse 1.4s ease-in-out infinite;
}

.skeleton-block--short {
  width: 60%;
}

@keyframes pulse {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.4; }
}

/* ── Dark mode ── */
:global(.dialog-overlay--dark) .devices-section__title {
  color: #808080;
}

:global(.dialog-overlay--dark) .session-item:hover {
  background: #2a2a2c;
}

:global(.dialog-overlay--dark) .session-item__icon {
  background: #3c3c3e;
}

:global(.dialog-overlay--dark) .session-item__title {
  color: #f0f0f0;
}

:global(.dialog-overlay--dark) .session-item__subtitle {
  color: #808080;
}

:global(.dialog-overlay--dark) .devices-empty {
  color: #808080;
}

:global(.dialog-overlay--dark) .devices-terminate {
  border-top-color: #3c3c3e;
}

:global(.dialog-overlay--dark) .terminate-btn:hover:not(:disabled) {
  background: #2a2a2c;
}

:global(.dialog-overlay--dark) .terminate-btn__hint {
  color: #808080;
}

:global(.dialog-overlay--dark) .skeleton-block {
  background: #3c3c3e;
}
</style>
