<template>
  <div class="security-panel">
    <!-- ======================== LIST VIEW ======================== -->
    <template v-if="currentView === 'list'">
      <h3 class="section-title">Вход и безопасность</h3>
      <p class="section-desc">Управление паролем, PIN-кодом и безопасностью аккаунта</p>

      <div class="security-list">
        <!-- Почта для сброса пароля -->
        <button class="security-item" disabled>
          <span class="security-item__icon">
            <v-icon size="20">mdi-email-outline</v-icon>
          </span>
          <span class="security-item__body">
            <span class="security-item__title">Почта для сброса пароля</span>
            <span class="security-item__subtitle">{{ userEmail }}</span>
          </span>
          <span class="security-item__arrow">
            <v-icon size="18" color="grey">mdi-chevron-right</v-icon>
          </span>
        </button>

        <!-- Устройства -->
        <button class="security-item" @click="currentView = 'devices'">
          <span class="security-item__icon">
            <v-icon size="20">mdi-devices</v-icon>
          </span>
          <span class="security-item__body">
            <span class="security-item__title">Устройства</span>
            <span class="security-item__subtitle">{{ devicesSubtitle }}</span>
          </span>
          <span class="security-item__arrow">
            <v-icon size="18">mdi-chevron-right</v-icon>
          </span>
        </button>

        <!-- Расширение Chrome -->
        <button class="security-item" @click="currentView = 'chrome-token'">
          <span class="security-item__icon">
            <v-icon size="20">mdi-puzzle-outline</v-icon>
          </span>
          <span class="security-item__body">
            <span class="security-item__title">Расширение Chrome</span>
            <span class="security-item__subtitle">{{ chromeTokenSubtitle }}</span>
          </span>
          <span class="security-item__arrow">
            <v-icon size="18">mdi-chevron-right</v-icon>
          </span>
        </button>

        <!-- Автоматический выход -->
        <button class="security-item" disabled>
          <span class="security-item__icon">
            <v-icon size="20">mdi-timer-outline</v-icon>
          </span>
          <span class="security-item__body">
            <span class="security-item__title">Автоматический выход</span>
            <span class="security-item__subtitle">20 минут</span>
          </span>
          <span class="security-item__arrow">
            <v-icon size="18" color="grey">mdi-chevron-right</v-icon>
          </span>
        </button>

        <!-- Сменить пароль -->
        <button class="security-item" @click="currentView = 'password'">
          <span class="security-item__icon">
            <v-icon size="20">mdi-key-outline</v-icon>
          </span>
          <span class="security-item__body">
            <span class="security-item__title">Сменить пароль</span>
            <span class="security-item__subtitle">Обновите пароль для входа</span>
          </span>
          <span class="security-item__arrow">
            <v-icon size="18">mdi-chevron-right</v-icon>
          </span>
        </button>

        <!-- Изменить PIN-код -->
        <button class="security-item" @click="currentView = 'pin'">
          <span class="security-item__icon">
            <v-icon size="20">mdi-dialpad</v-icon>
          </span>
          <span class="security-item__body">
            <span class="security-item__title">Изменить PIN-код</span>
            <span class="security-item__subtitle">{{ pinSubtitle }}</span>
          </span>
          <span class="security-item__arrow">
            <v-icon size="18">mdi-chevron-right</v-icon>
          </span>
        </button>

        <!-- Аккаунты для входа (Госуслуги) — заглушка -->
        <button class="security-item security-item--disabled" @click="showGosuslugiStub">
          <span class="security-item__icon">
            <v-icon size="20">mdi-account-key-outline</v-icon>
          </span>
          <span class="security-item__body">
            <span class="security-item__title">
              Аккаунты для входа
              <span class="badge-later">Позже</span>
            </span>
            <span class="security-item__subtitle">Госуслуги</span>
          </span>
          <span class="security-item__arrow">
            <v-icon size="18" color="grey">mdi-chevron-right</v-icon>
          </span>
        </button>
      </div>
    </template>

    <!-- ======================== CHANGE PASSWORD VIEW ======================== -->
    <template v-if="currentView === 'password'">
      <div class="sub-header">
        <button class="back-btn" @click="goBack">
          <v-icon size="20">mdi-arrow-left</v-icon>
        </button>
        <h3 class="sub-header__title">Сменить пароль</h3>
      </div>

      <form @submit.prevent="submitPassword" class="settings-form">
        <div class="form-group">
          <label class="form-label">Текущий пароль</label>
          <div class="input-wrapper">
            <input
              v-model="pwForm.current"
              :type="pwShowCurrent ? 'text' : 'password'"
              class="form-input"
              placeholder="Введите текущий пароль"
              autocomplete="current-password"
            />
            <button type="button" class="input-toggle" @click="pwShowCurrent = !pwShowCurrent">
              <v-icon size="18">{{ pwShowCurrent ? 'mdi-eye-off' : 'mdi-eye' }}</v-icon>
            </button>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Новый пароль</label>
          <div class="input-wrapper">
            <input
              v-model="pwForm.newPw"
              :type="pwShowNew ? 'text' : 'password'"
              class="form-input"
              placeholder="Минимум 8 символов"
              autocomplete="new-password"
            />
            <button type="button" class="input-toggle" @click="pwShowNew = !pwShowNew">
              <v-icon size="18">{{ pwShowNew ? 'mdi-eye-off' : 'mdi-eye' }}</v-icon>
            </button>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Подтвердите новый пароль</label>
          <div class="input-wrapper">
            <input
              v-model="pwForm.confirm"
              :type="pwShowConfirm ? 'text' : 'password'"
              class="form-input"
              placeholder="Повторите новый пароль"
              autocomplete="new-password"
            />
            <button type="button" class="input-toggle" @click="pwShowConfirm = !pwShowConfirm">
              <v-icon size="18">{{ pwShowConfirm ? 'mdi-eye-off' : 'mdi-eye' }}</v-icon>
            </button>
          </div>
        </div>

        <div v-if="pwError" class="form-message form-message--error">{{ pwError }}</div>
        <div v-if="pwSuccess" class="form-message form-message--success">{{ pwSuccess }}</div>

        <div class="form-actions">
          <button
            type="button"
            class="btn btn--secondary"
            @click="goBack"
          >
            Отмена
          </button>
          <button
            type="submit"
            class="btn btn--primary"
            :disabled="pwSaving || !pwFormValid"
          >
            {{ pwSaving ? 'Сохранение...' : 'Сохранить' }}
          </button>
        </div>
      </form>
    </template>

    <!-- ======================== CHANGE PIN VIEW ======================== -->
    <template v-if="currentView === 'pin'">
      <div class="sub-header">
        <button class="back-btn" @click="goBack">
          <v-icon size="20">mdi-arrow-left</v-icon>
        </button>
        <h3 class="sub-header__title">Изменить PIN-код</h3>
      </div>

      <div class="pin-change-form">
        <p class="pin-step-hint">{{ pinStepHint }}</p>

        <v-alert
          v-if="pinError"
          type="error"
          variant="tonal"
          class="mb-4"
          density="compact"
          closable
          @click:close="pinError = ''"
        >
          {{ pinError }}
        </v-alert>

        <v-alert
          v-if="pinSuccess"
          type="success"
          variant="tonal"
          class="mb-4"
          density="compact"
        >
          {{ pinSuccess }}
        </v-alert>

        <!-- Step 1: Enter new PIN -->
        <template v-if="pinStep === 'enter'">
          <div class="pin-center">
            <PinInput
              ref="pinRef1"
              v-model="pinForm.pin1"
              autofocus
              @complete="onPin1Complete"
            />
          </div>
        </template>

        <!-- Step 2: Confirm PIN -->
        <template v-if="pinStep === 'confirm'">
          <div class="pin-center">
            <PinInput
              ref="pinRef2"
              v-model="pinForm.pin2"
              autofocus
              @complete="onPin2Complete"
            />
          </div>
        </template>

        <!-- Step 3: Enter password -->
        <template v-if="pinStep === 'password'">
          <div class="form-group">
            <label class="form-label">Пароль для подтверждения</label>
            <div class="input-wrapper">
              <input
                ref="pinPasswordRef"
                v-model="pinForm.password"
                :type="pinShowPassword ? 'text' : 'password'"
                class="form-input"
                placeholder="Введите текущий пароль"
                autocomplete="current-password"
                @keydown.enter="submitPin"
              />
              <button type="button" class="input-toggle" @click="pinShowPassword = !pinShowPassword">
                <v-icon size="18">{{ pinShowPassword ? 'mdi-eye-off' : 'mdi-eye' }}</v-icon>
              </button>
            </div>
          </div>

          <div class="trust-device-row">
            <label class="checkbox-label">
              <input type="checkbox" v-model="pinForm.trustDevice" />
              <span>Доверять этому устройству</span>
            </label>
          </div>
        </template>

        <div class="form-actions">
          <button
            type="button"
            class="btn btn--secondary"
            @click="goBack"
          >
            Отмена
          </button>
          <button
            v-if="pinStep === 'password'"
            type="button"
            class="btn btn--primary"
            :disabled="pinSaving || !pinForm.password"
            @click="submitPin"
          >
            {{ pinSaving ? 'Сохранение...' : 'Сохранить PIN' }}
          </button>
        </div>
      </div>
    </template>

    <!-- ======================== DEVICES VIEW ======================== -->
    <template v-if="currentView === 'devices'">
      <div class="sub-header">
        <button class="back-btn" @click="goBack">
          <v-icon size="20">mdi-arrow-left</v-icon>
        </button>
        <h3 class="sub-header__title">Устройства</h3>
      </div>

      <UserDevicesPanel />
    </template>

    <!-- ======================== CHROME TOKEN VIEW ======================== -->
    <template v-if="currentView === 'chrome-token'">
      <div class="sub-header">
        <button class="back-btn" @click="goBack">
          <v-icon size="20">mdi-arrow-left</v-icon>
        </button>
        <h3 class="sub-header__title">Расширение Chrome</h3>
      </div>

      <div class="chrome-token-section">
        <p class="chrome-token-desc">
          Для подключения расширения «Призма» к вашему аккаунту необходим токен доступа.
          Скопируйте токен и вставьте его в настройках расширения.
        </p>

        <!-- Token display (after generation) -->
        <div v-if="chromeToken" class="token-display">
          <div class="token-display__header">
            <v-icon size="18" color="success">mdi-check-circle</v-icon>
            <span>Новый токен создан</span>
          </div>
          <div class="token-display__box">
            <code class="token-display__value">{{ chromeTokenMasked }}</code>
            <button class="token-display__copy" @click="copyChromeToken" :title="tokenCopied ? 'Скопировано!' : 'Копировать'">
              <v-icon size="18">{{ tokenCopied ? 'mdi-check' : 'mdi-content-copy' }}</v-icon>
            </button>
          </div>
          <v-alert type="warning" variant="tonal" density="compact" class="mt-3">
            Сохраните токен сейчас — он больше не будет показан полностью.
          </v-alert>
        </div>

        <!-- Generate form -->
        <div v-if="!chromeToken" class="chrome-token-form">
          <p v-if="chromeHasToken" class="form-hint">
            Токен уже выпущен. Для безопасности его нельзя показать повторно. Нажмите «Создать новый токен», если нужно заменить текущий.
          </p>
          <p v-else class="form-hint">
            Токен ещё не создан. Нажмите «Создать новый токен» и вставьте его в настройках расширения.
          </p>

          <div v-if="chromeError" class="form-message form-message--error">{{ chromeError }}</div>

          <div class="form-actions">
            <button
              type="button"
              class="btn btn--secondary"
              @click="goBack"
            >
              Отмена
            </button>
            <button
              type="button"
              class="btn btn--primary"
              :disabled="chromeSaving"
              @click="generateChromeToken"
            >
              {{ chromeSaving ? 'Генерация...' : 'Создать токен' }}
            </button>
          </div>
        </div>

        <!-- Revoke section -->
        <div class="chrome-revoke-section">
          <hr class="chrome-divider" />
          <button
            class="btn btn--danger-text"
            :disabled="chromeRevoking"
            @click="revokeChromeToken"
          >
            <v-icon size="16" class="mr-1">mdi-close-circle-outline</v-icon>
            {{ chromeRevoking ? 'Отзыв...' : 'Отозвать все токены расширения' }}
          </button>
          <p class="chrome-revoke-hint">Расширение будет отключено и потребует повторную авторизацию.</p>
        </div>
      </div>
    </template>

    <!-- Snackbar -->
    <v-snackbar v-model="snackbar.show" :timeout="3000" :color="snackbar.color" location="bottom right">
      {{ snackbar.message }}
    </v-snackbar>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, nextTick, watch } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { authApi } from '@/api/auth'
import { pinApi } from '@/api/pin'
import PinInput from '@/components/auth/PinInput.vue'
import UserDevicesPanel from '@/components/settings/UserDevicesPanel.vue'

type ViewName = 'list' | 'password' | 'pin' | 'devices' | 'chrome-token'
type PinStep = 'enter' | 'confirm' | 'password'

const authStore = useAuthStore()
const user = computed(() => authStore.user)
const userEmail = computed(() => user.value?.email || '—')

// ─── View navigation ───
const currentView = ref<ViewName>('list')

function goBack() {
  pwReset()
  pinReset()
  chromeReset()
  currentView.value = 'list'
}

// ─── Devices / sessions info ───
const sessionsCount = ref(0)
const devicesSubtitle = computed(() => {
  if (sessionsCount.value <= 1) return '1 активная сессия'
  return `${sessionsCount.value} активных сессий`
})

// ─── PIN status ───
const pinEnabled = ref(false)
const pinSubtitle = computed(() =>
  pinEnabled.value ? 'PIN-код включён' : 'PIN-код не установлен'
)

// ─── Snackbar ───
const snackbar = ref({ show: false, message: '', color: 'info' })
function notify(message: string, color = 'info') {
  snackbar.value = { show: true, message, color }
}

// ─── Gosuslugi stub ───
function showGosuslugiStub() {
  notify('Будет добавлено позже', 'info')
}

// ==================== CHANGE PASSWORD ====================
const pwForm = ref({ current: '', newPw: '', confirm: '' })
const pwShowCurrent = ref(false)
const pwShowNew = ref(false)
const pwShowConfirm = ref(false)
const pwSaving = ref(false)
const pwError = ref('')
const pwSuccess = ref('')

const pwFormValid = computed(() => {
  return (
    pwForm.value.current.length > 0 &&
    pwForm.value.newPw.length >= 8 &&
    pwForm.value.confirm.length > 0 &&
    pwForm.value.newPw === pwForm.value.confirm
  )
})

function pwReset() {
  pwForm.value = { current: '', newPw: '', confirm: '' }
  pwShowCurrent.value = false
  pwShowNew.value = false
  pwShowConfirm.value = false
  pwError.value = ''
  pwSuccess.value = ''
}

async function submitPassword() {
  pwError.value = ''
  pwSuccess.value = ''

  if (pwForm.value.newPw !== pwForm.value.confirm) {
    pwError.value = 'Пароли не совпадают'
    return
  }

  if (pwForm.value.newPw.length < 8) {
    pwError.value = 'Пароль должен содержать минимум 8 символов'
    return
  }

  pwSaving.value = true

  try {
    const resp = await authApi.changePassword({
      current_password: pwForm.value.current,
      new_password: pwForm.value.newPw,
      new_password_confirmation: pwForm.value.confirm,
    })
    pwSuccess.value = resp.message || 'Пароль изменён'
    notify('Пароль успешно изменён', 'success')
    pwForm.value = { current: '', newPw: '', confirm: '' }
    // Return to list after short delay
    setTimeout(() => { currentView.value = 'list' }, 1500)
  } catch (error: any) {
    const status = error.response?.status
    const msg = error.response?.data?.message
    if (status === 401) {
      pwError.value = msg || 'Неверный текущий пароль'
    } else if (status === 422) {
      pwError.value = msg || 'Ошибка валидации'
    } else {
      pwError.value = msg || 'Ошибка смены пароля'
    }
  } finally {
    pwSaving.value = false
  }
}

// ==================== CHANGE PIN ====================
const pinStep = ref<PinStep>('enter')
const pinForm = ref({ pin1: '', pin2: '', password: '', trustDevice: true })
const pinShowPassword = ref(false)
const pinSaving = ref(false)
const pinError = ref('')
const pinSuccess = ref('')

const pinRef1 = ref<InstanceType<typeof PinInput> | null>(null)
const pinRef2 = ref<InstanceType<typeof PinInput> | null>(null)
const pinPasswordRef = ref<HTMLInputElement | null>(null)

const pinStepHint = computed(() => {
  switch (pinStep.value) {
    case 'enter': return 'Введите новый 4-значный PIN-код'
    case 'confirm': return 'Подтвердите PIN-код'
    case 'password': return 'Введите пароль для подтверждения'
    default: return ''
  }
})

function pinReset() {
  pinStep.value = 'enter'
  pinForm.value = { pin1: '', pin2: '', password: '', trustDevice: true }
  pinShowPassword.value = false
  pinError.value = ''
  pinSuccess.value = ''
}

function onPin1Complete() {
  pinError.value = ''
  pinStep.value = 'confirm'
  nextTick(() => pinRef2.value?.focus())
}

function onPin2Complete() {
  if (pinForm.value.pin1 !== pinForm.value.pin2) {
    pinError.value = 'PIN-коды не совпадают'
    pinForm.value.pin2 = ''
    nextTick(() => pinRef2.value?.clear())
    return
  }
  pinError.value = ''
  pinStep.value = 'password'
  nextTick(() => pinPasswordRef.value?.focus())
}

async function submitPin() {
  if (!pinForm.value.password || pinSaving.value) return

  pinError.value = ''
  pinSuccess.value = ''
  pinSaving.value = true

  try {
    await pinApi.setPin({
      pin: pinForm.value.pin1,
      pin_confirm: pinForm.value.pin2,
      password: pinForm.value.password,
      trust_device: pinForm.value.trustDevice,
    })
    pinEnabled.value = true
    pinSuccess.value = 'PIN-код успешно установлен'
    notify('PIN-код изменён', 'success')
    // Return to list after short delay
    setTimeout(() => { currentView.value = 'list' }, 1500)
  } catch (e: any) {
    const msg = e.response?.data?.message
    pinError.value = msg || 'Не удалось установить PIN'

    if (msg?.toLowerCase().includes('пароль')) {
      // Stay on password step
    } else {
      // PIN error — start over
      pinStep.value = 'enter'
      pinForm.value.pin1 = ''
      pinForm.value.pin2 = ''
      pinForm.value.password = ''
      nextTick(() => pinRef1.value?.focus())
    }
  } finally {
    pinSaving.value = false
  }
}

// ==================== CHROME EXTENSION TOKEN ====================
const chromeSaving = ref(false)
const chromeRevoking = ref(false)
const chromeError = ref('')
const chromeToken = ref('')
const tokenCopied = ref(false)
const chromeHasToken = ref(false)

const chromeTokenSubtitle = computed(() =>
  chromeHasToken.value ? 'Токен активен' : 'Настроить подключение'
)

const chromeTokenMasked = computed(() => {
  if (!chromeToken.value) return ''
  if (chromeToken.value.length <= 12) return chromeToken.value
  return chromeToken.value.substring(0, 8) + '••••••••' + chromeToken.value.substring(chromeToken.value.length - 4)
})

function chromeReset() {
  chromeError.value = ''
  chromeToken.value = ''
  tokenCopied.value = false
}

async function loadChromeTokenStatus() {
  try {
    const status = await authApi.getChromeTokenStatus()
    chromeHasToken.value = !!status.has_token
  } catch {
    // Non-critical: keep silent
  }
}

async function generateChromeToken() {
  if (chromeSaving.value) return

  chromeError.value = ''
  chromeSaving.value = true

  try {
    const result = await authApi.issueChromeTokenFromSession()
    chromeToken.value = result.token
    chromeHasToken.value = true
    notify('Токен для расширения создан', 'success')
  } catch (e: any) {
    const msg = e.response?.data?.message
    chromeError.value = msg || 'Не удалось создать токен'
  } finally {
    chromeSaving.value = false
  }
}

async function copyChromeToken() {
  if (!chromeToken.value) return
  try {
    await navigator.clipboard.writeText(chromeToken.value)
    tokenCopied.value = true
    notify('Токен скопирован', 'success')
    setTimeout(() => { tokenCopied.value = false }, 2000)
  } catch {
    notify('Не удалось скопировать', 'error')
  }
}

async function revokeChromeToken() {
  chromeRevoking.value = true
  try {
    await authApi.revokeChromeToken()
    chromeHasToken.value = false
    chromeToken.value = ''
    notify('Все токены расширения отозваны', 'info')
  } catch (e: any) {
    notify(e.response?.data?.message || 'Ошибка отзыва токена', 'error')
  } finally {
    chromeRevoking.value = false
  }
}

// ─── Reset state when switching views ───
watch(currentView, (view) => {
  if (view === 'password') pwReset(), nextTick(() => {})
  if (view === 'pin') pinReset()
  if (view === 'chrome-token') {
    chromeReset()
    void loadChromeTokenStatus()
  }
})

// ─── Initial data load ───
onMounted(async () => {
  try {
    const [status, sessions] = await Promise.all([
      pinApi.getStatus(),
      authApi.getSessions(),
    ])
    pinEnabled.value = status.pin_enabled
    // Current + others
    sessionsCount.value = 1 + (sessions.others?.length || 0)
    await loadChromeTokenStatus()
  } catch {
    // Silently fail — non-critical info
  }
})
</script>

<style scoped>
/* ─── List items ─── */
.security-list {
  display: flex;
  flex-direction: column;
  gap: 2px;
  margin-top: 8px;
}

.security-item {
  display: flex;
  align-items: center;
  gap: 12px;
  width: 100%;
  padding: 12px;
  background: transparent;
  border: none;
  border-radius: 6px;
  cursor: pointer;
  text-align: left;
  transition: background 0.15s ease;
}

.security-item:hover:not(:disabled):not(.security-item--disabled) {
  background: var(--security-hover, #f5f5f5);
}

.security-item:disabled,
.security-item--disabled {
  opacity: 0.6;
  cursor: default;
}

.security-item__icon {
  flex-shrink: 0;
  width: 36px;
  height: 36px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--security-icon-bg, #f0f0f0);
  border-radius: 8px;
}

.security-item__body {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.security-item__title {
  font-size: 13px;
  font-weight: 500;
  color: var(--security-title, #1a1a1a);
  display: flex;
  align-items: center;
  gap: 8px;
}

.security-item__subtitle {
  font-size: 12px;
  color: var(--security-subtitle, #888);
}

.security-item__arrow {
  flex-shrink: 0;
}

.badge-later {
  display: inline-block;
  padding: 1px 6px;
  font-size: 10px;
  font-weight: 600;
  color: #888;
  background: #eee;
  border-radius: 4px;
  text-transform: uppercase;
  letter-spacing: 0.02em;
}

/* ─── Sub-header (for password / PIN views) ─── */
.sub-header {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 20px;
}

.sub-header__title {
  font-size: 16px;
  font-weight: 600;
  color: var(--security-title, #1a1a1a);
  margin: 0;
}

.back-btn {
  width: 32px;
  height: 32px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: transparent;
  border: none;
  border-radius: 4px;
  cursor: pointer;
  transition: background 0.15s ease;
}

.back-btn:hover {
  background: var(--security-hover, #f0f0f0);
}

/* ─── Form ─── */
.settings-form {
  display: flex;
  flex-direction: column;
  gap: 16px;
  max-width: 400px;
}

.form-group {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.form-label {
  font-size: 13px;
  font-weight: 500;
  color: var(--security-title, #333);
}

.input-wrapper {
  position: relative;
}

.form-input {
  width: 100%;
  padding: 10px 36px 10px 12px;
  font-size: 14px;
  color: var(--security-title, #333);
  background: var(--security-input-bg, #fff);
  border: 1px solid var(--security-input-border, #ddd);
  border-radius: 4px;
  transition: border-color 0.15s ease;
  box-sizing: border-box;
}

.form-input:focus {
  outline: none;
  border-color: #888;
}

.input-toggle {
  position: absolute;
  right: 4px;
  top: 50%;
  transform: translateY(-50%);
  background: none;
  border: none;
  cursor: pointer;
  padding: 4px;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #888;
}

.input-toggle:hover {
  color: #555;
}

.form-actions {
  display: flex;
  gap: 12px;
  padding-top: 8px;
}

.btn {
  padding: 10px 20px;
  font-size: 13px;
  font-weight: 500;
  border: none;
  border-radius: 4px;
  cursor: pointer;
  transition: all 0.15s ease;
}

.btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.btn--primary {
  color: #fff;
  background: #1a1a1a;
}

.btn--primary:hover:not(:disabled) {
  background: #333;
}

.btn--secondary {
  color: #333;
  background: #e5e5e5;
}

.btn--secondary:hover:not(:disabled) {
  background: #ddd;
}

.form-message {
  padding: 10px 12px;
  font-size: 13px;
  border-radius: 4px;
}

.form-message--success {
  color: #155724;
  background: #d4edda;
}

.form-message--error {
  color: #721c24;
  background: #f8d7da;
}

/* ─── PIN specific ─── */
.pin-change-form {
  max-width: 400px;
}

.pin-step-hint {
  font-size: 14px;
  font-weight: 500;
  color: var(--security-title, #333);
  text-align: center;
  margin-bottom: 16px;
}

.pin-center {
  display: flex;
  justify-content: center;
  margin-bottom: 16px;
}

.trust-device-row {
  margin: 12px 0 4px;
}

.checkbox-label {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  color: var(--security-title, #333);
  cursor: pointer;
}

.checkbox-label input {
  width: 16px;
  height: 16px;
}

/* ─── Section title & desc (matching AccountSettingsDialog) ─── */
.section-title {
  font-size: 16px;
  font-weight: 600;
  color: var(--security-title, #1a1a1a);
  margin: 0 0 4px;
}

.section-desc {
  font-size: 13px;
  color: var(--security-subtitle, #666);
  margin: 0 0 8px;
}

/* ─── Dark mode support (via parent class) ─── */
:global(.dialog-overlay--dark) .section-title,
:global(.dialog-overlay--dark) .sub-header__title,
:global(.dialog-overlay--dark) .security-item__title,
:global(.dialog-overlay--dark) .form-label,
:global(.dialog-overlay--dark) .pin-step-hint,
:global(.dialog-overlay--dark) .checkbox-label {
  color: #f0f0f0;
}

:global(.dialog-overlay--dark) .section-desc,
:global(.dialog-overlay--dark) .security-item__subtitle {
  color: #808080;
}

:global(.dialog-overlay--dark) .security-item:hover:not(:disabled):not(.security-item--disabled) {
  background: #2a2a2c;
}

:global(.dialog-overlay--dark) .security-item__icon {
  background: #3c3c3e;
}

:global(.dialog-overlay--dark) .back-btn:hover {
  background: #2e2e30;
}

:global(.dialog-overlay--dark) .form-input {
  background: #2e2e30;
  border-color: #4c4c4e;
  color: #f0f0f0;
}

:global(.dialog-overlay--dark) .form-input:focus {
  border-color: #707070;
}

:global(.dialog-overlay--dark) .btn--primary {
  background: #f0f0f0;
  color: #1a1a1a;
}

:global(.dialog-overlay--dark) .btn--primary:hover:not(:disabled) {
  background: #e0e0e0;
}

:global(.dialog-overlay--dark) .btn--secondary {
  background: #3c3c3e;
  color: #c0c0c0;
}

:global(.dialog-overlay--dark) .badge-later {
  background: #3c3c3e;
  color: #a0a0a0;
}

:global(.dialog-overlay--dark) .input-toggle {
  color: #808080;
}

:global(.dialog-overlay--dark) .input-toggle:hover {
  color: #c0c0c0;
}

/* ─── Chrome Token section ─── */
.chrome-token-section {
  max-width: 460px;
}

.chrome-token-desc {
  font-size: 13px;
  color: var(--security-subtitle, #666);
  margin: 0 0 16px;
  line-height: 1.5;
}

.chrome-token-form {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.form-hint {
  font-size: 13px;
  color: var(--security-subtitle, #888);
  margin: 0;
}

.token-display {
  background: var(--security-token-bg, #F0FDF4);
  border: 1px solid var(--security-token-border, #BBF7D0);
  border-radius: 8px;
  padding: 14px;
}

.token-display__header {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
  font-weight: 600;
  color: #065F46;
  margin-bottom: 10px;
}

.token-display__box {
  display: flex;
  align-items: center;
  gap: 8px;
  background: var(--security-input-bg, #fff);
  border: 1px solid var(--security-input-border, #ddd);
  border-radius: 4px;
  padding: 8px 10px;
}

.token-display__value {
  flex: 1;
  font-size: 13px;
  font-family: 'Courier New', monospace;
  word-break: break-all;
  color: var(--security-title, #333);
}

.token-display__copy {
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 30px;
  height: 30px;
  background: none;
  border: 1px solid var(--security-input-border, #ddd);
  border-radius: 4px;
  cursor: pointer;
  color: #666;
  transition: all 0.15s ease;
}

.token-display__copy:hover {
  background: #f0f0f0;
  color: #333;
}

.chrome-revoke-section {
  margin-top: 8px;
}

.chrome-divider {
  border: none;
  border-top: 1px solid var(--security-input-border, #eee);
  margin: 16px 0;
}

.btn--danger-text {
  display: inline-flex;
  align-items: center;
  padding: 8px 12px;
  font-size: 13px;
  font-weight: 500;
  color: #DC2626;
  background: transparent;
  border: 1px solid #FECACA;
  border-radius: 4px;
  cursor: pointer;
  transition: all 0.15s ease;
}

.btn--danger-text:hover:not(:disabled) {
  background: #FEF2F2;
}

.btn--danger-text:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.chrome-revoke-hint {
  font-size: 11px;
  color: var(--security-subtitle, #999);
  margin-top: 6px;
}

/* Dark mode for Chrome token section */
:global(.dialog-overlay--dark) .chrome-token-desc {
  color: #808080;
}

:global(.dialog-overlay--dark) .token-display {
  background: #1a2e1a;
  border-color: #2d5a2d;
}

:global(.dialog-overlay--dark) .token-display__header {
  color: #86efac;
}

:global(.dialog-overlay--dark) .token-display__box {
  background: #2e2e30;
  border-color: #4c4c4e;
}

:global(.dialog-overlay--dark) .token-display__value {
  color: #f0f0f0;
}

:global(.dialog-overlay--dark) .token-display__copy {
  border-color: #4c4c4e;
  color: #a0a0a0;
}

:global(.dialog-overlay--dark) .token-display__copy:hover {
  background: #3c3c3e;
  color: #f0f0f0;
}

:global(.dialog-overlay--dark) .btn--danger-text {
  color: #f87171;
  border-color: #7f1d1d;
}

:global(.dialog-overlay--dark) .btn--danger-text:hover:not(:disabled) {
  background: #2a1a1a;
}

:global(.dialog-overlay--dark) .chrome-divider {
  border-color: #3c3c3e;
}

:global(.dialog-overlay--dark) .chrome-revoke-hint {
  color: #666;
}
</style>
