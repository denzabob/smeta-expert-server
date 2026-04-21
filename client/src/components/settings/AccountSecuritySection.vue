<template>
  <div class="acc-sec">
    <!-- ── Loading ─────────────────────────────────────────────────────────── -->
    <template v-if="store.loading && !store.authStatus">
      <v-skeleton-loader type="card" class="mb-3" />
      <v-skeleton-loader type="card" class="mb-3" />
      <v-skeleton-loader type="card" />
    </template>

    <!-- ── Error ──────────────────────────────────────────────────────────── -->
      <v-alert v-else-if="loadError && !store.authStatus" type="error" variant="tonal" class="acc-sec__stack-item">
      Не удалось загрузить данные безопасности.
      <template #append>
        <v-btn variant="text" size="small" @click="reload">Повторить</v-btn>
      </template>
    </v-alert>

    <!-- ── Content ────────────────────────────────────────────────────────── -->
    <template v-else-if="store.authStatus">
      <!-- Critical warning -->
      <v-alert
        v-if="!store.authStatus.can_self_recover"
        type="error"
        variant="flat"
        density="comfortable"
        prepend-icon="mdi-alert-circle"
        class="acc-sec__stack-item"
      >
        <div class="text-body-2 font-weight-medium">Аккаунт под угрозой</div>
        <div class="acc-sec__critical-text">
          У вас нет ни одного способа восстановить доступ к аккаунту самостоятельно.
        </div>
      </v-alert>

      <!-- 1. Рекомендации -->
      <RecommendedActionsCard
        v-if="store.authStatus.recommended_actions.length > 0 || store.authStatus.blocked_actions.length > 0"
        :status="store.authStatus"
        @action="handleRecommendedAction"
        class="acc-sec__stack-item"
      />

      <!-- 2. Способы входа -->
      <AuthMethodsCard
        :status="store.authStatus"
        @set-password="setPasswordDialog = true"
        @change-password="changePasswordDialog = true"
        @add-phone="bootstrapPhoneDialog = true"
        @verify-phone="bootstrapPhoneDialog = true"
        @change-phone="openPhoneDialog"
        @add-email="openEmailDialog"
        @resend-email-verification="doResendEmailVerification"
        @change-email="openEmailDialog"
        @link-yandex="doLinkYandex"
        @unlink-yandex="doUnlinkYandex"
        @enable-pin="openPinSetup"
        @disable-pin="openPinDisable"
        class="acc-sec__stack-item"
      />

      <!-- 3. Восстановление / Надёжность аккаунта -->
      <RecoveryReadinessCard :status="store.authStatus" class="acc-sec__stack-item" />

      <!-- 4. Активные сессии -->
      <SessionsCard
        :sessions="store.sessions"
        :loading="store.sessionsLoading"
        class="acc-sec__stack-item"
      />

      <!-- 5. Доверенные устройства -->
      <TrustedDevicesCard
        v-if="store.authStatus.can_manage_trusted_devices"
        :devices="store.devices"
        :loading="store.devicesLoading"
        class="acc-sec__stack-item"
      />

      <!-- 6. Расширение Chrome -->
      <div class="acc-sec__card acc-sec__card--chrome acc-sec__stack-item">
        <!-- Header -->
        <div class="acc-sec__card-header">
          <v-icon icon="mdi-google-chrome" size="20" class="acc-sec__card-icon acc-sec__card-icon--chrome" />
          <div>
            <div class="acc-sec__card-title">Расширение Chrome</div>
            <div class="acc-sec__card-desc">
              Автосбор материалов, фиксация цен и доказательства прямо со страниц поставщиков
            </div>
          </div>
        </div>

        <!-- Status badges -->
        <div class="acc-sec__status-row acc-sec__section-space">
          <div class="acc-sec__status-item">
            <span class="acc-sec__status-label">Расширение</span>
            <span class="acc-sec__status-badge acc-sec__status-badge--neutral">
              <v-icon size="11" class="mr-1">mdi-help-circle-outline</v-icon>
              Проверьте установку
            </span>
          </div>
          <div class="acc-sec__status-item">
            <span class="acc-sec__status-label">Токен</span>
            <span
              :class="[
                'acc-sec__status-badge',
                chromeHasToken ? 'acc-sec__status-badge--success' : 'acc-sec__status-badge--muted'
              ]"
            >
              <v-icon size="11" class="mr-1">{{ chromeHasToken ? 'mdi-check-circle-outline' : 'mdi-circle-off-outline' }}</v-icon>
              {{ chromeHasToken ? 'Активен' : 'Отсутствует' }}
            </span>
          </div>
        </div>

        <!-- Token value — shown immediately after generation -->
        <div v-if="chromeTokenJustCreated" class="acc-sec__token-box acc-sec__section-space">
          <div class="acc-sec__token-label">
            <v-icon icon="mdi-check-circle" size="15" color="success" class="mr-1" />
            Токен создан — скопируйте и вставьте в расширение
          </div>
          <div class="acc-sec__token-row">
            <code class="acc-sec__token-value">{{ chromeTokenMasked }}</code>
            <button
              class="acc-sec__token-copy"
              :title="tokenCopied ? 'Скопировано!' : 'Копировать'"
              @click="copyChromeToken"
            >
              <v-icon size="15">{{ tokenCopied ? 'mdi-check' : 'mdi-content-copy' }}</v-icon>
            </button>
          </div>
          <p class="acc-sec__token-hint">
            Сохраните токен — он не будет показан повторно после закрытия этой страницы.
          </p>
        </div>

        <!-- Primary actions -->
        <div class="acc-sec__card-actions acc-sec__section-space">
          <a
            :href="CHROME_EXTENSION_STORE_URL"
            target="_blank"
            rel="noopener noreferrer"
            class="acc-sec__btn acc-sec__btn--primary"
          >
            <v-icon size="15" class="mr-1">mdi-download-outline</v-icon>
            Установить расширение
          </a>
          <button
            v-if="chromeToken"
            class="acc-sec__btn acc-sec__btn--secondary"
            :title="tokenCopied ? 'Скопировано!' : 'Скопировать токен'"
            @click="copyChromeToken"
          >
            <v-icon size="15" class="mr-1">{{ tokenCopied ? 'mdi-check' : 'mdi-content-copy' }}</v-icon>
            {{ tokenCopied ? 'Скопировано' : 'Скопировать токен' }}
          </button>
        </div>

        <!-- Secondary / destructive actions -->
        <div class="acc-sec__card-actions acc-sec__card-actions--secondary">
          <button class="acc-sec__btn acc-sec__btn--ghost" :disabled="chromeSaving" @click="doGenerateChromeToken">
            <v-icon size="14" class="mr-1">mdi-refresh</v-icon>
            {{ chromeSaving ? 'Генерация...' : (chromeHasToken ? 'Пересоздать токен' : 'Создать токен') }}
          </button>
          <button
            v-if="chromeHasToken"
            class="acc-sec__btn acc-sec__btn--ghost-danger"
            :disabled="chromeRevoking"
            @click="doRevokeChromeToken"
          >
            <v-icon size="14" class="mr-1">mdi-close-circle-outline</v-icon>
            {{ chromeRevoking ? 'Отзыв...' : 'Отозвать доступ' }}
          </button>
        </div>

        <div v-if="chromeError" class="acc-sec__inline-error">{{ chromeError }}</div>

        <!-- Step-by-step guide -->
        <div class="acc-sec__chrome-steps acc-sec__section-space">
          <div class="acc-sec__chrome-steps-title">Как подключить расширение</div>
          <ol class="acc-sec__chrome-steps-list">
            <li>Установите расширение из Chrome Web Store</li>
            <li>Нажмите «Создать токен» и скопируйте его</li>
            <li>Вставьте токен в настройках расширения и подтвердите подключение</li>
          </ol>
        </div>
      </div>
    </template>
  </div>

  <!-- ══ Dialogs ══════════════════════════════════════════════════════════ -->

  <!-- Set password -->
  <SetPasswordDialog v-model="setPasswordDialog" mode="set" @completed="onActionCompleted" />
  <!-- Change password -->
  <SetPasswordDialog v-model="changePasswordDialog" mode="change" @completed="onActionCompleted" />
  <!-- Bootstrap phone (Yandex-only accounts) -->
  <BootstrapPhoneDialog v-model="bootstrapPhoneDialog" @completed="onActionCompleted" />

  <!-- ── PIN dialogs (канонический поток через step-up) ────────────────────────────── -->
  <SetPinDialog v-model="setPinDialogOpen" @completed="onPinEnabled" />

  <!-- Disable PIN: step-up only -->
  <StepUpDialog
    v-model="disablePinDialogOpen"
    scope="set_quick_pin"
    title="Подтверждение для отключения PIN"
    @completed="onDisablePinStepUp"
    @cancelled="disablePinDialogOpen = false"
  />

  <!-- ── Phone change dialog ─────────────────────────────────────────────── -->
  <v-dialog v-model="phoneDialogOpen" max-width="440" persistent :scrim="false">
    <v-card rounded="xl" class="acc-sec__dialog-card">
      <div class="acc-sec__dialog-title">Изменить номер телефона</div>
      <v-alert v-if="phoneError" type="error" variant="tonal" density="compact" closable class="acc-sec__dialog-alert" @click:close="phoneError = ''">{{ phoneError }}</v-alert>
      <v-alert v-if="phoneSuccess" type="success" variant="tonal" density="compact" closable class="acc-sec__dialog-alert" @click:close="phoneSuccess = ''">{{ phoneSuccess }}</v-alert>

      <template v-if="phoneStep === 'form'">
        <div class="acc-sec__dialog-form">
          <div class="acc-sec__field">
          <label class="acc-sec__label">Новый номер телефона</label>
          <input v-model="phoneForm.phone" type="text" class="acc-sec__input" placeholder="+7 (999) 123-45-67" inputmode="tel" autocomplete="tel" @input="onPhoneInput" />
        </div>
        <div v-if="phoneForm.needPassword" class="acc-sec__field">
          <label class="acc-sec__label">Текущий пароль</label>
          <input v-model="phoneForm.password" type="password" class="acc-sec__input" placeholder="Введите пароль" autocomplete="current-password" />
        </div>
        <div class="acc-sec__dialog-actions">
          <button class="acc-sec__btn acc-sec__btn--secondary" @click="phoneDialogOpen = false">Отмена</button>
          <button class="acc-sec__btn acc-sec__btn--primary" :disabled="phoneForm.requesting" @click="doRequestPhoneChange">
            {{ phoneForm.requesting ? 'Отправка...' : 'Подтвердить номер' }}
          </button>
        </div>
        </div>
      </template>

      <template v-else-if="phoneStep === 'verify'">
        <div class="acc-sec__dialog-form">
        <p class="acc-sec__dialog-body-text">
          <template v-if="phoneForm.verificationMethod === 'code'">Введите код, отправленный на новый номер.</template>
          <template v-else>Позвоните на {{ phoneForm.callPhonePretty || phoneForm.callPhone }} и нажмите «Проверить звонок».</template>
        </p>
        <div v-if="phoneForm.verificationMethod === 'code'" class="acc-sec__field">
          <label class="acc-sec__label">Код подтверждения</label>
          <input v-model="phoneForm.code" type="text" class="acc-sec__input" placeholder="6 цифр" maxlength="6" />
        </div>
        <div class="acc-sec__dialog-actions">
          <button
            class="acc-sec__btn acc-sec__btn--secondary"
            :disabled="phoneResendCountdown > 0 || phoneForm.resending"
            @click="doResendPhoneChange"
          >
            {{ phoneForm.resending ? 'Отправка...' : phoneResendCountdown > 0 ? `Повторно через ${phoneResendCountdown}с` : 'Отправить повторно' }}
          </button>
          <button class="acc-sec__btn acc-sec__btn--primary" :disabled="phoneVerifyDisabled" @click="doConfirmPhoneChange">
            {{ phoneForm.verifying ? 'Проверка...' : phoneForm.verificationMethod === 'call' ? 'Проверить звонок' : 'Подтвердить' }}
          </button>
        </div>
        </div>
      </template>
    </v-card>
  </v-dialog>

  <!-- ── Email change dialog ─────────────────────────────────────────────── -->
  <v-dialog v-model="emailDialogOpen" max-width="440" persistent :scrim="false">
    <v-card rounded="xl" class="acc-sec__dialog-card">
      <div class="acc-sec__dialog-title">Изменить email</div>
      <v-alert v-if="emailError" type="error" variant="tonal" density="compact" closable class="acc-sec__dialog-alert" @click:close="emailError = ''">{{ emailError }}</v-alert>
      <v-alert v-if="emailSuccess" type="success" variant="tonal" density="compact" class="acc-sec__dialog-alert">{{ emailSuccess }}</v-alert>
      <div class="acc-sec__dialog-form">
      <div class="acc-sec__field">
        <label class="acc-sec__label">Новый email</label>
        <input v-model="emailForm.email" type="email" class="acc-sec__input" placeholder="name@example.com" autocomplete="email" />
      </div>
      <div v-if="emailForm.needPassword" class="acc-sec__field">
        <label class="acc-sec__label">Текущий пароль</label>
        <input v-model="emailForm.password" type="password" class="acc-sec__input" placeholder="Введите пароль" autocomplete="current-password" />
      </div>
      <div class="acc-sec__dialog-actions">
        <button class="acc-sec__btn acc-sec__btn--secondary" @click="emailDialogOpen = false">Отмена</button>
        <button class="acc-sec__btn acc-sec__btn--primary" :disabled="emailForm.saving" @click="doSubmitEmailChange">
          {{ emailForm.saving ? 'Сохранение...' : 'Изменить email' }}
        </button>
      </div>
      </div>
    </v-card>
  </v-dialog>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted, nextTick } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { useSecurityStore } from '@/stores/security'
import { authApi } from '@/api/auth'
import { formatRuPhoneMask } from '@/components/auth/phoneCallFlow'
import { CHROME_EXTENSION_STORE_URL } from '@/constants/chrome-extension'

import AuthMethodsCard from '@/components/security/AuthMethodsCard.vue'
import RecoveryReadinessCard from '@/components/security/RecoveryReadinessCard.vue'
import RecommendedActionsCard from '@/components/security/RecommendedActionsCard.vue'
import SessionsCard from '@/components/security/SessionsCard.vue'
import TrustedDevicesCard from '@/components/security/TrustedDevicesCard.vue'
import SetPasswordDialog from '@/components/security/SetPasswordDialog.vue'
import SetPinDialog from '@/components/security/SetPinDialog.vue'
import StepUpDialog from '@/components/security/StepUpDialog.vue'
import BootstrapPhoneDialog from '@/components/security/BootstrapPhoneDialog.vue'

const authStore = useAuthStore()
const store = useSecurityStore()

// ── Load ──────────────────────────────────────────────────────────────────

const loadError = ref(false)

async function reload() {
  loadError.value = false
  try {
    await Promise.all([
      store.fetchAuthStatus(),
      store.fetchSessions(),
      store.fetchDevices(),
    ])
    // Sync chrome token status
    await loadChromeTokenStatus()
  } catch {
    loadError.value = true
  }
}

onMounted(reload)

// Reload when OAuth link result is present in URL
function consumeOauthLinkResult() {
  const url = new URL(window.location.href)
  const result = url.searchParams.get('oauth_link')
  const provider = url.searchParams.get('provider')
  if (!result) return

  const label = provider === 'yandex' ? 'Яндекс' : 'внешний аккаунт'
  if (result === 'success') {
    store.fetchAuthStatus()
  }

  url.searchParams.delete('oauth_link')
  url.searchParams.delete('provider')
  window.history.replaceState({}, '', url.toString())
}

onMounted(consumeOauthLinkResult)

// After any action completes, refresh store
async function onActionCompleted() {
  await store.fetchAuthStatus()
  await store.fetchSessions()
  await store.fetchDevices()
}

// ── Recommended actions dispatcher ───────────────────────────────────────

function handleRecommendedAction(action: string) {
  switch (action) {
    case 'set_password':
      setPasswordDialog.value = true
      break
    case 'add_phone':
    case 'bootstrap_add_phone':
      bootstrapPhoneDialog.value = true
      break
    case 'enable_quick_pin':
      openPinSetup()
      break
    default:
      break
  }
}

// ── Standard dialogs ──────────────────────────────────────────────────────

const setPasswordDialog = ref(false)
const changePasswordDialog = ref(false)
const bootstrapPhoneDialog = ref(false)

// ── Provider link/unlink ──────────────────────────────────────────────────

const providerBusy = ref<string | null>(null)

async function doLinkYandex() {
  providerBusy.value = 'yandex'
  try {
    const result = await authApi.getProviderLinkRedirect('yandex')
    window.location.href = result.redirect_url
  } catch {
    // noop
  } finally {
    providerBusy.value = null
  }
}

async function doUnlinkYandex() {
  const confirmed = window.confirm('Отвязать аккаунт Яндекс от входа?')
  if (!confirmed) return
  providerBusy.value = 'yandex'
  try {
    await authApi.unlinkProvider('yandex')
    await store.fetchAuthStatus()
  } catch {
    // noop
  } finally {
    providerBusy.value = null
  }
}

// ── Resend email verification ─────────────────────────────────────────────

async function doResendEmailVerification() {
  try {
    await authApi.resendEmailVerification()
  } catch {
    // noop
  }
}

// ── PIN ─────────────────────────────────────────────────────────────────
// All PIN operations now go through the canonical step-up flow (SetPinDialog / StepUpDialog).
// The old inline form that submitted password directly is removed.

const setPinDialogOpen = ref(false)
const disablePinDialogOpen = ref(false)

function openPinSetup() {
  setPinDialogOpen.value = true
}

function openPinDisable() {
  disablePinDialogOpen.value = true
}

async function onPinEnabled() {
  await onActionCompleted()
}

async function onDisablePinStepUp(token: string) {
  disablePinDialogOpen.value = false
  try {
    await store.disablePin(token)
  } catch {
    // store.disablePin errors bubble up; silent here, user can retry
  }
  await onActionCompleted()
}

// ── Phone change ──────────────────────────────────────────────────────────

const phoneDialogOpen = ref(false)
const phoneStep = ref<'form' | 'verify'>('form')
const phoneForm = ref({
  phone: '',
  password: '',
  needPassword: false,
  challengeId: '',
  verificationMethod: 'code' as 'code' | 'call',
  callPhone: '',
  callPhonePretty: '',
  code: '',
  requesting: false,
  resending: false,
  verifying: false,
})
const phoneError = ref('')
const phoneSuccess = ref('')
const phoneResendCountdown = ref(0)
let phoneResendTimer: ReturnType<typeof setInterval> | null = null

const phoneVerifyDisabled = computed(() => {
  if (phoneForm.value.verifying) return true
  if (phoneForm.value.verificationMethod === 'code') return phoneForm.value.code.length < 6
  return false
})

function openPhoneDialog() {
  phoneStep.value = 'form'
  phoneError.value = ''
  phoneSuccess.value = ''
  phoneForm.value.phone = ''
  phoneForm.value.password = ''
  phoneForm.value.needPassword = !!store.authStatus?.password_enabled
  phoneForm.value.code = ''
  phoneDialogOpen.value = true
}

function onPhoneInput() {
  phoneForm.value.phone = formatRuPhoneMask(phoneForm.value.phone)
}

function startPhoneResendTimer(availableAt: string) {
  if (phoneResendTimer) clearInterval(phoneResendTimer)
  const target = new Date(availableAt)
  const tick = () => {
    const diff = Math.max(0, Math.ceil((target.getTime() - Date.now()) / 1000))
    phoneResendCountdown.value = diff
    if (diff <= 0 && phoneResendTimer) clearInterval(phoneResendTimer)
  }
  tick()
  phoneResendTimer = setInterval(tick, 1000)
}

async function doRequestPhoneChange() {
  phoneError.value = ''
  phoneSuccess.value = ''
  phoneForm.value.requesting = true
  try {
    const res = await authApi.requestPhoneChange({
      phone: phoneForm.value.phone,
      current_password: phoneForm.value.needPassword ? phoneForm.value.password : undefined,
    })
    phoneForm.value.challengeId = res.challenge_id
    phoneForm.value.verificationMethod = res.verification_method
    phoneForm.value.callPhone = res.call_phone || ''
    phoneForm.value.callPhonePretty = res.call_phone_pretty || ''
    phoneForm.value.code = ''
    phoneStep.value = 'verify'
    startPhoneResendTimer(res.resend_available_at)
  } catch (e: any) {
    phoneError.value = e.response?.data?.message || 'Не удалось отправить подтверждение.'
  } finally {
    phoneForm.value.requesting = false
  }
}

async function doResendPhoneChange() {
  if (phoneResendCountdown.value > 0 || phoneForm.value.resending || !phoneForm.value.challengeId) return
  phoneError.value = ''
  phoneForm.value.resending = true
  try {
    const res = await authApi.resendPhoneChange(phoneForm.value.challengeId)
    phoneForm.value.verificationMethod = res.verification_method
    phoneForm.value.callPhone = res.call_phone || ''
    phoneForm.value.callPhonePretty = res.call_phone_pretty || ''
    startPhoneResendTimer(res.resend_available_at)
  } catch (e: any) {
    phoneError.value = e.response?.data?.message || 'Не удалось отправить повторно.'
  } finally {
    phoneForm.value.resending = false
  }
}

async function doConfirmPhoneChange() {
  if (phoneVerifyDisabled.value || !phoneForm.value.challengeId) return
  phoneError.value = ''
  phoneForm.value.verifying = true
  try {
    const res = await authApi.confirmPhoneChange({
      challenge_id: phoneForm.value.challengeId,
      code: phoneForm.value.verificationMethod === 'code' ? phoneForm.value.code : undefined,
    })
    if (authStore.user) {
      authStore.user.phone = res.phone
    }
    await store.fetchAuthStatus()
    phoneDialogOpen.value = false
  } catch (e: any) {
    phoneError.value = e.response?.data?.message || 'Не удалось подтвердить номер.'
  } finally {
    phoneForm.value.verifying = false
  }
}

onUnmounted(() => {
  if (phoneResendTimer) clearInterval(phoneResendTimer)
})

// ── Email change ──────────────────────────────────────────────────────────

const emailDialogOpen = ref(false)
const emailError = ref('')
const emailSuccess = ref('')
const emailForm = ref({
  email: '',
  password: '',
  needPassword: false,
  saving: false,
})

function openEmailDialog() {
  emailError.value = ''
  emailSuccess.value = ''
  emailForm.value.email = authStore.user?.email || ''
  emailForm.value.password = ''
  emailForm.value.needPassword = !!store.authStatus?.password_enabled
  emailForm.value.saving = false
  emailDialogOpen.value = true
}

async function doSubmitEmailChange() {
  if (emailForm.value.saving) return
  emailError.value = ''
  emailSuccess.value = ''
  emailForm.value.saving = true
  try {
    const res = await authApi.changeEmail({
      email: emailForm.value.email,
      current_password: emailForm.value.needPassword ? emailForm.value.password : undefined,
    })
    if (authStore.user) {
      authStore.user.email = res.email
    }
    await store.fetchAuthStatus()
    emailDialogOpen.value = false
  } catch (e: any) {
    emailError.value = e.response?.data?.message || 'Не удалось изменить email.'
  } finally {
    emailForm.value.saving = false
  }
}

// ── Chrome extension token ────────────────────────────────────────────────

const chromeHasToken = ref(false)
const chromeToken = ref('')
const chromeTokenJustCreated = ref(false)
const chromeSaving = ref(false)
const chromeRevoking = ref(false)
const chromeError = ref('')
const tokenCopied = ref(false)

const chromeTokenMasked = computed(() => {
  if (!chromeToken.value) return ''
  return chromeToken.value.slice(0, 8) + '••••••••••••••••••••' + chromeToken.value.slice(-6)
})

async function loadChromeTokenStatus() {
  try {
    const status = await authApi.getChromeTokenStatus()
    chromeHasToken.value = status.has_token
  } catch {
    // Non-critical, ignore
  }
}

async function doGenerateChromeToken() {
  chromeError.value = ''
  chromeSaving.value = true
  try {
    const res = await authApi.issueChromeTokenFromSession()
    chromeToken.value = res.token
    chromeTokenJustCreated.value = true
    chromeHasToken.value = true
  } catch (e: any) {
    chromeError.value = e.response?.data?.message || 'Не удалось создать токен.'
  } finally {
    chromeSaving.value = false
  }
}

async function doRevokeChromeToken() {
  const confirmed = window.confirm('Отозвать токен расширения? Расширение потребует повторную авторизацию.')
  if (!confirmed) return
  chromeError.value = ''
  chromeRevoking.value = true
  try {
    await authApi.revokeChromeToken()
    chromeHasToken.value = false
    chromeToken.value = ''
    chromeTokenJustCreated.value = false
  } catch (e: any) {
    chromeError.value = e.response?.data?.message || 'Не удалось отозвать токен.'
  } finally {
    chromeRevoking.value = false
  }
}

function copyChromeToken() {
  if (!chromeToken.value) return
  navigator.clipboard.writeText(chromeToken.value).then(() => {
    tokenCopied.value = true
    setTimeout(() => { tokenCopied.value = false }, 2000)
  })
}
</script>

<style scoped>
.acc-sec {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

/* ── Static card for Chrome token ─────────────────────────────────────── */
.acc-sec__card {
  border: 1px solid rgba(var(--v-theme-outline-variant), 0.72);
  border-radius: var(--md-sys-shape-corner-extra-large);
  padding: 18px;
  background:
    linear-gradient(180deg, rgba(var(--v-theme-primary), 0.04), transparent 140px),
    rgba(var(--v-theme-surface-container-low), 0.96);
}

.acc-sec__stack-item {
  margin: 0 !important;
}

.acc-sec__critical-text {
  margin-top: 6px;
}

.acc-sec__section-space {
  margin-top: 16px;
}

.acc-sec__card-header {
  display: flex;
  align-items: flex-start;
  gap: 12px;
}

.acc-sec__card-icon {
  margin-top: 2px;
  flex-shrink: 0;
  opacity: 0.7;
}

.acc-sec__card-title {
  font-size: 0.96rem;
  font-weight: 700;
  color: rgb(var(--v-theme-on-surface));
  line-height: 1.4;
}

.acc-sec__card-desc {
  font-size: 0.82rem;
  color: rgba(var(--v-theme-on-surface), 0.68);
  margin-top: 4px;
  line-height: 1.55;
}

.acc-sec__card-actions {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

/* ── Shared button styles (scoped, no global leakage) ─────────────────── */
.acc-sec__btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-height: 40px;
  padding: 10px 18px;
  font-size: 0.84rem;
  font-weight: 700;
  border: none;
  border-radius: var(--md-sys-shape-corner-full);
  cursor: pointer;
  transition: background 0.13s, color 0.13s, border-color 0.13s;
  white-space: nowrap;
}

.acc-sec__btn:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}

.acc-sec__btn--primary {
  background: rgb(var(--v-theme-primary));
  color: rgb(var(--v-theme-on-primary));
}

.acc-sec__btn--primary:hover:not(:disabled) {
  background: rgba(var(--v-theme-primary), 0.85);
}

.acc-sec__btn--secondary {
  background: rgba(var(--v-theme-secondary-container), 0.92);
  color: rgb(var(--v-theme-on-secondary-container));
}

.acc-sec__btn--secondary:hover:not(:disabled) {
  background: rgba(var(--v-theme-secondary), 0.24);
}

.acc-sec__btn--danger {
  background: rgb(var(--v-theme-error));
  color: rgb(var(--v-theme-on-error));
}

.acc-sec__btn--danger:hover:not(:disabled) {
  background: rgba(var(--v-theme-error), 0.85);
}

/* ── Token display ────────────────────────────────────────────────────── */
.acc-sec__token-box {
  background: rgba(var(--v-theme-surface-container-lowest), 0.92);
  border: 1px solid rgba(var(--v-theme-outline-variant), 0.72);
  border-radius: var(--md-sys-shape-corner-large);
  padding: 14px;
}

.acc-sec__token-label {
  display: flex;
  align-items: center;
  font-size: 12px;
  font-weight: 500;
  color: rgb(var(--v-theme-on-surface));
  margin-bottom: 8px;
}

.acc-sec__token-row {
  display: flex;
  align-items: center;
  gap: 8px;
}

.acc-sec__token-value {
  flex: 1;
  font-size: 12px;
  color: rgb(var(--v-theme-on-surface));
  word-break: break-all;
  background: rgba(var(--v-theme-surface-container-highest), 0.9);
  padding: 8px 10px;
  border-radius: var(--md-sys-shape-corner-medium);
}

.acc-sec__token-copy {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 28px;
  height: 28px;
  border: none;
  border-radius: 6px;
  background: transparent;
  color: rgba(var(--v-theme-on-surface), 0.7);
  cursor: pointer;
  transition: background 0.13s;
}

.acc-sec__token-copy:hover {
  background: rgba(var(--v-theme-on-surface), 0.1);
}

/* ── Form elements inside dialogs ─────────────────────────────────────── */
.acc-sec__field {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.acc-sec__label {
  font-size: 0.8rem;
  font-weight: 700;
  color: rgb(var(--v-theme-on-surface));
}

.acc-sec__input {
  min-height: 48px;
  padding: 12px 14px;
  font-size: 0.92rem;
  color: rgb(var(--v-theme-on-surface));
  background: rgba(var(--v-theme-surface-container-highest), 0.94);
  border: 1px solid rgba(var(--v-theme-outline), 0.72);
  border-radius: var(--md-sys-shape-corner-large);
  transition: border-color 0.13s, box-shadow 0.13s;
  width: 100%;
}

.acc-sec__input:focus {
  outline: none;
  border-color: rgba(var(--v-theme-primary), 0.9);
  box-shadow: 0 0 0 3px rgba(var(--v-theme-primary), 0.14);
}

/* ── PIN center ───────────────────────────────────────────────────────── */
.acc-sec__pin-center {
  display: flex;
  justify-content: center;
}

/* ── Error inline ─────────────────────────────────────────────────────── */
.acc-sec__inline-error {
  margin-top: 12px;
  font-size: 12px;
  color: rgb(var(--v-theme-error));
}

/* ── Back button ──────────────────────────────────────────────────────── */
.acc-sec__back-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 30px;
  height: 30px;
  border: none;
  border-radius: 8px;
  background: transparent;
  color: rgba(var(--v-theme-on-surface), 0.6);
  cursor: pointer;
  transition: background 0.13s;
  flex-shrink: 0;
}

.acc-sec__back-btn:hover {
  background: rgba(var(--v-theme-on-surface), 0.08);
}

/* ── Chrome extension card ────────────────────────────────────────────── */
.acc-sec__card-icon--chrome {
  color: rgb(var(--v-theme-primary));
  opacity: 1;
}

.acc-sec__status-row {
  display: flex;
  align-items: center;
  gap: 16px;
  flex-wrap: wrap;
}

.acc-sec__status-item {
  display: flex;
  align-items: center;
  gap: 6px;
}

.acc-sec__status-label {
  font-size: 12px;
  color: rgba(var(--v-theme-on-surface), 0.55);
  white-space: nowrap;
}

.acc-sec__status-badge {
  display: inline-flex;
  align-items: center;
  font-size: 11px;
  font-weight: 500;
  padding: 2px 8px;
  border-radius: 20px;
  white-space: nowrap;
}

.acc-sec__status-badge--success {
  background: rgba(var(--v-theme-success), 0.12);
  color: rgb(var(--v-theme-success));
}

.acc-sec__status-badge--neutral {
  background: rgba(var(--v-theme-on-surface), 0.08);
  color: rgba(var(--v-theme-on-surface), 0.65);
}

.acc-sec__status-badge--muted {
  background: rgba(var(--v-theme-on-surface), 0.06);
  color: rgba(var(--v-theme-on-surface), 0.45);
}

.acc-sec__token-hint {
  font-size: 11px;
  color: rgba(var(--v-theme-on-surface), 0.55);
  margin: 6px 0 0;
  line-height: 1.5;
}

/* Ghost (text-like) buttons for secondary/danger actions */
.acc-sec__btn--ghost {
  background: transparent;
  color: rgba(var(--v-theme-on-surface), 0.6);
  border: 1px solid rgba(var(--v-theme-outline-variant), 0.92);
}

.acc-sec__btn--ghost:hover:not(:disabled) {
  background: rgba(var(--v-theme-on-surface), 0.06);
}

.acc-sec__btn--ghost-danger {
  background: transparent;
  color: rgb(var(--v-theme-error));
  border: 1px solid rgba(var(--v-theme-error), 0.25);
}

.acc-sec__btn--ghost-danger:hover:not(:disabled) {
  background: rgba(var(--v-theme-error), 0.06);
}

.acc-sec__card-actions--secondary {
  margin-top: 12px;
  opacity: 0.9;
}

/* Step-by-step mini guide */
.acc-sec__chrome-steps {
  border-top: 1px solid rgba(var(--v-theme-on-surface), 0.08);
  padding-top: 12px;
}

.acc-sec__chrome-steps-title {
  font-size: 11px;
  font-weight: 600;
  color: rgba(var(--v-theme-on-surface), 0.5);
  text-transform: uppercase;
  letter-spacing: 0.04em;
  margin-bottom: 6px;
}

.acc-sec__chrome-steps-list {
  margin: 0;
  padding-left: 18px;
  font-size: 12px;
  color: rgba(var(--v-theme-on-surface), 0.65);
  line-height: 1.7;
}

.acc-sec__dialog-card {
  padding: 24px;
  border: 1px solid rgba(var(--v-theme-outline-variant), 0.72);
  background:
    linear-gradient(180deg, rgba(var(--v-theme-primary), 0.04), transparent 120px),
    rgba(var(--v-theme-surface-container-low), 0.98);
}

.acc-sec__dialog-title {
  font-size: 1rem;
  font-weight: 700;
  color: rgb(var(--v-theme-on-surface));
  margin-bottom: 16px;
}

.acc-sec__dialog-alert {
  margin-bottom: 12px;
}

.acc-sec__dialog-form {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.acc-sec__dialog-body-text {
  margin: 0;
  font-size: 0.9rem;
  line-height: 1.55;
  color: rgba(var(--v-theme-on-surface), 0.72);
}

.acc-sec__dialog-actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  flex-wrap: wrap;
}

@media (max-width: 760px) {
  .acc-sec__card {
    padding: 16px;
  }

  .acc-sec__dialog-card {
    padding: 18px 16px;
  }

  .acc-sec__dialog-actions > * {
    flex: 1 1 100%;
  }
}
</style>
