<template>
  <div class="acc-sec">
    <!-- ── Loading ─────────────────────────────────────────────────────────── -->
    <template v-if="store.loading && !store.authStatus">
      <v-skeleton-loader type="card" class="mb-3" />
      <v-skeleton-loader type="card" class="mb-3" />
      <v-skeleton-loader type="card" />
    </template>

    <!-- ── Error ──────────────────────────────────────────────────────────── -->
    <v-alert v-else-if="loadError && !store.authStatus" type="error" variant="tonal" class="mb-4">
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
        class="mb-4"
      >
        <div class="text-body-2 font-weight-medium">Аккаунт под угрозой</div>
        <div class="text-caption mt-1">
          У вас нет ни одного способа восстановить доступ к аккаунту самостоятельно.
        </div>
      </v-alert>

      <!-- 1. Рекомендации -->
      <RecommendedActionsCard
        v-if="store.authStatus.recommended_actions.length > 0 || store.authStatus.blocked_actions.length > 0"
        :status="store.authStatus"
        @action="handleRecommendedAction"
        class="mb-4"
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
        class="mb-4"
      />

      <!-- 3. Восстановление / Надёжность аккаунта -->
      <RecoveryReadinessCard :status="store.authStatus" class="mb-4" />

      <!-- 4. Активные сессии -->
      <SessionsCard
        :sessions="store.sessions"
        :loading="store.sessionsLoading"
        class="mb-4"
      />

      <!-- 5. Доверенные устройства -->
      <TrustedDevicesCard
        v-if="store.authStatus.can_manage_trusted_devices"
        :devices="store.devices"
        :loading="store.devicesLoading"
        class="mb-4"
      />

      <!-- 6. Расширение Chrome -->
      <div class="acc-sec__card mb-4">
        <div class="acc-sec__card-header">
          <v-icon icon="mdi-puzzle-outline" size="18" class="acc-sec__card-icon" />
          <div>
            <div class="acc-sec__card-title">Расширение Chrome</div>
            <div class="acc-sec__card-desc">Токен для подключения браузерного расширения «Призма»</div>
          </div>
        </div>

        <div v-if="chromeTokenJustCreated" class="acc-sec__token-box mt-3">
          <div class="acc-sec__token-label">
            <v-icon icon="mdi-check-circle" size="16" color="success" class="mr-1" />
            Токен создан — скопируйте его сейчас
          </div>
          <div class="acc-sec__token-row">
            <code class="acc-sec__token-value">{{ chromeTokenMasked }}</code>
            <button class="acc-sec__token-copy" :title="tokenCopied ? 'Скопировано!' : 'Копировать'" @click="copyChromeToken">
              <v-icon size="15">{{ tokenCopied ? 'mdi-check' : 'mdi-content-copy' }}</v-icon>
            </button>
          </div>
          <v-alert type="warning" variant="tonal" density="compact" class="mt-2" style="font-size:12px">
            Сохраните токен — он не будет показан повторно.
          </v-alert>
        </div>

        <div class="acc-sec__card-actions mt-3">
          <button class="acc-sec__btn acc-sec__btn--secondary" :disabled="chromeSaving" @click="doGenerateChromeToken">
            <v-icon size="15" class="mr-1">mdi-key-plus</v-icon>
            {{ chromeSaving ? 'Генерация...' : (chromeHasToken ? 'Пересоздать токен' : 'Создать токен') }}
          </button>
          <button
            v-if="chromeHasToken"
            class="acc-sec__btn acc-sec__btn--danger"
            :disabled="chromeRevoking"
            @click="doRevokeChromeToken"
          >
            <v-icon size="15" class="mr-1">mdi-close-circle-outline</v-icon>
            {{ chromeRevoking ? 'Отзыв...' : 'Отозвать токен' }}
          </button>
        </div>
        <div v-if="chromeError" class="acc-sec__inline-error mt-2">{{ chromeError }}</div>
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

  <!-- ── PIN dialog ──────────────────────────────────────────────────────── -->
  <v-dialog v-model="pinDialogOpen" max-width="360" persistent :scrim="false">
    <v-card rounded="xl" class="pa-6">
      <div class="d-flex align-center mb-4">
        <button class="acc-sec__back-btn mr-2" @click="closePinDialog">
          <v-icon size="18">mdi-arrow-left</v-icon>
        </button>
        <div class="text-subtitle-1 font-weight-semibold">
          {{ pinDisableMode ? 'Отключить PIN-код' : pinStepTitle }}
        </div>
      </div>

      <!-- Disable PIN mode -->
      <template v-if="pinDisableMode">
        <p class="text-body-2 text-medium-emphasis mb-4">
          Введите текущий пароль для отключения PIN-кода.
        </p>
        <div class="acc-sec__field mb-4">
          <input
            v-model="pinDisablePassword"
            type="password"
            class="acc-sec__input"
            placeholder="Текущий пароль"
          />
        </div>
        <div v-if="pinError" class="acc-sec__inline-error mb-3">{{ pinError }}</div>
        <div class="d-flex justify-end" style="gap:8px">
          <button class="acc-sec__btn acc-sec__btn--secondary" @click="closePinDialog">Отмена</button>
          <button
            class="acc-sec__btn acc-sec__btn--danger"
            :disabled="!pinDisablePassword || pinSaving"
            @click="submitDisablePin"
          >
            {{ pinSaving ? 'Отключение...' : 'Отключить PIN' }}
          </button>
        </div>
      </template>

      <!-- Setup PIN steps -->
      <template v-else>
        <p class="text-body-2 text-medium-emphasis mb-4">{{ pinStepHint }}</p>

        <v-alert v-if="pinError" type="error" variant="tonal" density="compact" closable class="mb-4" @click:close="pinError = ''">
          {{ pinError }}
        </v-alert>
        <v-alert v-if="pinSuccess" type="success" variant="tonal" density="compact" class="mb-4">
          {{ pinSuccess }}
        </v-alert>

        <div v-if="pinStep === 'enter'" class="acc-sec__pin-center mb-4">
          <PinInput ref="pinRef1" v-model="pinForm.pin1" autofocus @complete="onPin1Complete" />
        </div>

        <div v-else-if="pinStep === 'confirm'" class="acc-sec__pin-center mb-4">
          <PinInput ref="pinRef2" v-model="pinForm.pin2" autofocus @complete="onPin2Complete" />
        </div>

        <template v-else-if="pinStep === 'password'">
          <div class="acc-sec__field mb-3">
            <label class="acc-sec__label">Пароль для подтверждения</label>
            <input
              ref="pinPasswordRef"
              v-model="pinForm.password"
              type="password"
              class="acc-sec__input"
              placeholder="Введите текущий пароль"
              autocomplete="current-password"
              @keydown.enter="submitPin"
            />
          </div>
          <label class="d-flex align-center mb-4" style="gap:8px;cursor:pointer">
            <input type="checkbox" v-model="pinForm.trustDevice" />
            <span class="text-body-2">Доверять этому устройству</span>
          </label>
          <div class="d-flex justify-end" style="gap:8px">
            <button class="acc-sec__btn acc-sec__btn--secondary" @click="closePinDialog">Отмена</button>
            <button
              class="acc-sec__btn acc-sec__btn--primary"
              :disabled="pinSaving || !pinForm.password"
              @click="submitPin"
            >
              {{ pinSaving ? 'Сохранение...' : 'Сохранить PIN' }}
            </button>
          </div>
        </template>
      </template>
    </v-card>
  </v-dialog>

  <!-- ── Phone change dialog ─────────────────────────────────────────────── -->
  <v-dialog v-model="phoneDialogOpen" max-width="440" persistent :scrim="false">
    <v-card rounded="xl" class="pa-6">
      <div class="text-subtitle-1 font-weight-semibold mb-4">Изменить номер телефона</div>
      <v-alert v-if="phoneError" type="error" variant="tonal" density="compact" closable class="mb-3" @click:close="phoneError = ''">{{ phoneError }}</v-alert>
      <v-alert v-if="phoneSuccess" type="success" variant="tonal" density="compact" closable class="mb-3" @click:close="phoneSuccess = ''">{{ phoneSuccess }}</v-alert>

      <template v-if="phoneStep === 'form'">
        <div class="acc-sec__field mb-3">
          <label class="acc-sec__label">Новый номер телефона</label>
          <input v-model="phoneForm.phone" type="text" class="acc-sec__input" placeholder="+7 (999) 123-45-67" inputmode="tel" autocomplete="tel" @input="onPhoneInput" />
        </div>
        <div v-if="phoneForm.needPassword" class="acc-sec__field mb-3">
          <label class="acc-sec__label">Текущий пароль</label>
          <input v-model="phoneForm.password" type="password" class="acc-sec__input" placeholder="Введите пароль" autocomplete="current-password" />
        </div>
        <div class="d-flex justify-end" style="gap:8px">
          <button class="acc-sec__btn acc-sec__btn--secondary" @click="phoneDialogOpen = false">Отмена</button>
          <button class="acc-sec__btn acc-sec__btn--primary" :disabled="phoneForm.requesting" @click="doRequestPhoneChange">
            {{ phoneForm.requesting ? 'Отправка...' : 'Подтвердить номер' }}
          </button>
        </div>
      </template>

      <template v-else-if="phoneStep === 'verify'">
        <p class="text-body-2 text-medium-emphasis mb-3">
          <template v-if="phoneForm.verificationMethod === 'code'">Введите код, отправленный на новый номер.</template>
          <template v-else>Позвоните на {{ phoneForm.callPhonePretty || phoneForm.callPhone }} и нажмите «Проверить звонок».</template>
        </p>
        <div v-if="phoneForm.verificationMethod === 'code'" class="acc-sec__field mb-4">
          <label class="acc-sec__label">Код подтверждения</label>
          <input v-model="phoneForm.code" type="text" class="acc-sec__input" placeholder="6 цифр" maxlength="6" />
        </div>
        <div class="d-flex justify-end" style="gap:8px">
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
      </template>
    </v-card>
  </v-dialog>

  <!-- ── Email change dialog ─────────────────────────────────────────────── -->
  <v-dialog v-model="emailDialogOpen" max-width="440" persistent :scrim="false">
    <v-card rounded="xl" class="pa-6">
      <div class="text-subtitle-1 font-weight-semibold mb-4">Изменить email</div>
      <v-alert v-if="emailError" type="error" variant="tonal" density="compact" closable class="mb-3" @click:close="emailError = ''">{{ emailError }}</v-alert>
      <v-alert v-if="emailSuccess" type="success" variant="tonal" density="compact" class="mb-3">{{ emailSuccess }}</v-alert>
      <div class="acc-sec__field mb-3">
        <label class="acc-sec__label">Новый email</label>
        <input v-model="emailForm.email" type="email" class="acc-sec__input" placeholder="name@example.com" autocomplete="email" />
      </div>
      <div v-if="emailForm.needPassword" class="acc-sec__field mb-3">
        <label class="acc-sec__label">Текущий пароль</label>
        <input v-model="emailForm.password" type="password" class="acc-sec__input" placeholder="Введите пароль" autocomplete="current-password" />
      </div>
      <div class="d-flex justify-end" style="gap:8px">
        <button class="acc-sec__btn acc-sec__btn--secondary" @click="emailDialogOpen = false">Отмена</button>
        <button class="acc-sec__btn acc-sec__btn--primary" :disabled="emailForm.saving" @click="doSubmitEmailChange">
          {{ emailForm.saving ? 'Сохранение...' : 'Изменить email' }}
        </button>
      </div>
    </v-card>
  </v-dialog>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted, nextTick } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { useSecurityStore } from '@/stores/security'
import { authApi } from '@/api/auth'
import { pinApi } from '@/api/pin'
import { formatRuPhoneMask } from '@/components/auth/phoneCallFlow'

import AuthMethodsCard from '@/components/security/AuthMethodsCard.vue'
import RecoveryReadinessCard from '@/components/security/RecoveryReadinessCard.vue'
import RecommendedActionsCard from '@/components/security/RecommendedActionsCard.vue'
import SessionsCard from '@/components/security/SessionsCard.vue'
import TrustedDevicesCard from '@/components/security/TrustedDevicesCard.vue'
import SetPasswordDialog from '@/components/security/SetPasswordDialog.vue'
import BootstrapPhoneDialog from '@/components/security/BootstrapPhoneDialog.vue'
import PinInput from '@/components/auth/PinInput.vue'

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

// ── PIN ──────────────────────────────────────────────────────────────────

type PinStep = 'enter' | 'confirm' | 'password'

const pinDialogOpen = ref(false)
const pinDisableMode = ref(false)
const pinDisablePassword = ref('')
const pinStep = ref<PinStep>('enter')
const pinForm = ref({ pin1: '', pin2: '', password: '', trustDevice: true })
const pinSaving = ref(false)
const pinError = ref('')
const pinSuccess = ref('')

const pinRef1 = ref<InstanceType<typeof PinInput> | null>(null)
const pinRef2 = ref<InstanceType<typeof PinInput> | null>(null)
const pinPasswordRef = ref<HTMLInputElement | null>(null)

const pinStepTitle = computed(() => {
  if (pinStep.value === 'enter') return 'Установить PIN-код'
  if (pinStep.value === 'confirm') return 'Подтвердите PIN-код'
  return 'Подтверждение паролем'
})

const pinStepHint = computed(() => {
  if (pinStep.value === 'enter') return 'Введите новый 4-значный PIN-код'
  if (pinStep.value === 'confirm') return 'Введите PIN-код ещё раз для подтверждения'
  return 'Введите пароль от аккаунта для подтверждения'
})

function openPinSetup() {
  pinDisableMode.value = false
  pinStep.value = 'enter'
  pinForm.value = { pin1: '', pin2: '', password: '', trustDevice: true }
  pinError.value = ''
  pinSuccess.value = ''
  pinDialogOpen.value = true
}

function openPinDisable() {
  pinDisableMode.value = true
  pinDisablePassword.value = ''
  pinError.value = ''
  pinDialogOpen.value = true
}

function closePinDialog() {
  pinDialogOpen.value = false
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
    nextTick(() => (pinRef2.value as any)?.clear?.())
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
    pinSuccess.value = 'PIN-код успешно установлен'
    await store.fetchAuthStatus()
    setTimeout(() => { pinDialogOpen.value = false }, 1200)
  } catch (e: any) {
    const msg = e.response?.data?.message
    pinError.value = msg || 'Не удалось установить PIN'
    if (!msg?.toLowerCase().includes('пароль')) {
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

async function submitDisablePin() {
  if (!pinDisablePassword.value || pinSaving.value) return
  pinError.value = ''
  pinSaving.value = true
  try {
    await pinApi.disablePin(pinDisablePassword.value)
    await store.fetchAuthStatus()
    pinDialogOpen.value = false
  } catch (e: any) {
    pinError.value = e.response?.data?.message || 'Не удалось отключить PIN'
  } finally {
    pinSaving.value = false
  }
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
  /* Remove default max-width constraints from cards inside modal */
}

/* ── Static card for Chrome token ─────────────────────────────────────── */
.acc-sec__card {
  border: 1px solid rgba(var(--v-theme-on-surface), 0.12);
  border-radius: 12px;
  padding: 16px;
  background: rgb(var(--v-theme-surface-bright));
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
  font-size: 14px;
  font-weight: 600;
  color: rgb(var(--v-theme-on-surface));
  line-height: 1.4;
}

.acc-sec__card-desc {
  font-size: 12px;
  color: rgba(var(--v-theme-on-surface), 0.6);
  margin-top: 2px;
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
  padding: 7px 14px;
  font-size: 13px;
  font-weight: 500;
  border: none;
  border-radius: 10px;
  cursor: pointer;
  transition: background 0.13s;
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
  background: rgba(var(--v-theme-on-surface), 0.1);
  color: rgb(var(--v-theme-on-surface));
}

.acc-sec__btn--secondary:hover:not(:disabled) {
  background: rgba(var(--v-theme-on-surface), 0.18);
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
  background: rgba(var(--v-theme-on-surface), 0.04);
  border: 1px solid rgba(var(--v-theme-on-surface), 0.1);
  border-radius: 10px;
  padding: 12px;
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
  background: rgba(var(--v-theme-on-surface), 0.06);
  padding: 4px 8px;
  border-radius: 6px;
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
  gap: 5px;
}

.acc-sec__label {
  font-size: 13px;
  font-weight: 500;
  color: rgb(var(--v-theme-on-surface));
}

.acc-sec__input {
  padding: 9px 12px;
  font-size: 14px;
  color: rgb(var(--v-theme-on-surface));
  background: rgba(var(--v-theme-on-surface), 0.05);
  border: 1px solid rgba(var(--v-theme-on-surface), 0.2);
  border-radius: 10px;
  transition: border-color 0.13s;
  width: 100%;
}

.acc-sec__input:focus {
  outline: none;
  border-color: rgba(var(--v-theme-primary), 0.7);
}

/* ── PIN center ───────────────────────────────────────────────────────── */
.acc-sec__pin-center {
  display: flex;
  justify-content: center;
}

/* ── Error inline ─────────────────────────────────────────────────────── */
.acc-sec__inline-error {
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
</style>
