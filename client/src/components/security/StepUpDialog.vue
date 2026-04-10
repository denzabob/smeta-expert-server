<template>
  <v-dialog
    :model-value="modelValue"
    @update:model-value="onDialogUpdate"
    max-width="420"
    persistent
  >
    <v-card>
      <v-card-title class="d-flex align-center gap-2 pt-5 pb-2 px-6">
        <v-icon color="primary" size="22">mdi-shield-lock-outline</v-icon>
        <span class="text-subtitle-1 font-weight-medium">{{ title || 'Подтвердите, что это вы' }}</span>
      </v-card-title>

      <!-- Loading / Initiating -->
      <v-card-text v-if="phase === 'initiating'" class="text-center py-8">
        <v-progress-circular indeterminate color="primary" />
        <div class="text-body-2 text-medium-emphasis mt-3">Подготовка...</div>
      </v-card-text>

      <!-- Method choice (when multiple methods are available) -->
      <v-card-text v-else-if="phase === 'choice'">
        <div class="text-body-2 text-medium-emphasis mb-4">
          Для продолжения выберите способ подтверждения личности.
        </div>
        <div class="d-flex flex-column gap-2">
          <v-btn
            v-if="allowedMethods.includes('password')"
            variant="outlined"
            @click="selectMethod('password')"
            prepend-icon="mdi-key-outline"
            class="justify-start"
          >
            Подтвердить паролем
          </v-btn>
          <v-btn
            v-if="allowedMethods.includes('email_otp')"
            variant="outlined"
            @click="selectMethod('email_otp')"
            prepend-icon="mdi-email-outline"
            class="justify-start"
          >
            Код на email {{ emailMasked ? `(${emailMasked})` : '' }}
          </v-btn>
          <v-btn
            v-if="allowedMethods.includes('phone_otp')"
            variant="outlined"
            @click="selectMethod('phone_otp')"
            prepend-icon="mdi-cellphone-message"
            class="justify-start"
          >
            Код на телефон {{ phoneMasked ? `(${phoneMasked})` : '' }}
          </v-btn>
        </div>
      </v-card-text>

      <!-- Password form -->
      <v-card-text v-else-if="phase === 'password'">
        <div class="text-body-2 text-medium-emphasis mb-4">
          Введите пароль от аккаунта для подтверждения.
        </div>
        <v-alert v-if="error" type="error" variant="tonal" density="compact" class="mb-4">
          {{ error }}
        </v-alert>
        <v-text-field
          v-model="password"
          label="Пароль"
          :type="showPassword ? 'text' : 'password'"
          :append-inner-icon="showPassword ? 'mdi-eye-off' : 'mdi-eye'"
          @click:append-inner="showPassword = !showPassword"
          autocomplete="current-password"
          @keydown.enter="submitPassword"
          autofocus
          hide-details="auto"
        />
      </v-card-text>

      <!-- Phone OTP: send code step -->
      <v-card-text v-else-if="phase === 'otp_send'">
        <div class="text-body-2 text-medium-emphasis mb-4">
          Мы отправим код подтверждения на ваш телефон
          <strong v-if="phoneMasked">{{ phoneMasked }}</strong>.
        </div>
        <v-alert v-if="error" type="error" variant="tonal" density="compact" class="mb-4">
          {{ error }}
        </v-alert>
      </v-card-text>

      <!-- Phone OTP: enter code step -->
      <v-card-text v-else-if="phase === 'otp_code'">
        <div class="text-body-2 text-medium-emphasis mb-1">
          Код отправлен на <strong>{{ phoneMasked }}</strong>.
        </div>
        <div class="text-caption text-medium-emphasis mb-4">
          Не получили? <button class="text-primary" style="background:none;border:none;cursor:pointer;padding:0;" @click="resendOtp">Отправить повторно</button>
        </div>
        <v-alert v-if="error" type="error" variant="tonal" density="compact" class="mb-4">
          {{ error }}
        </v-alert>
        <v-text-field
          v-model="otpCode"
          label="Код из SMS"
          inputmode="numeric"
          maxlength="6"
          @keydown.enter="submitOtp"
          autofocus
          hide-details="auto"
        />
      </v-card-text>

      <!-- Email OTP: send code step -->
      <v-card-text v-else-if="phase === 'email_otp_send'">
        <div class="text-body-2 text-medium-emphasis mb-4">
          Мы отправим код подтверждения на ваш email
          <strong v-if="emailMasked">{{ emailMasked }}</strong>.
        </div>
        <v-alert v-if="error" type="error" variant="tonal" density="compact" class="mb-4">
          {{ error }}
        </v-alert>
      </v-card-text>

      <!-- Email OTP: enter code step -->
      <v-card-text v-else-if="phase === 'email_otp_code'">
        <div class="text-body-2 text-medium-emphasis mb-1">
          Код подтверждения отправлен на <strong>{{ emailMasked }}</strong>.
        </div>
        <div class="text-caption text-medium-emphasis mb-4">
          Не получили? <button class="text-primary" style="background:none;border:none;cursor:pointer;padding:0;" @click="resendEmailOtp">Отправить повторно</button>
        </div>
        <v-alert v-if="error" type="error" variant="tonal" density="compact" class="mb-4">
          {{ error }}
        </v-alert>
        <v-text-field
          v-model="emailOtpCode"
          label="Код из письма"
          inputmode="numeric"
          maxlength="6"
          @keydown.enter="submitEmailOtp"
          autofocus
          hide-details="auto"
        />
      </v-card-text>

      <!-- Success -->
      <v-card-text v-else-if="phase === 'success'" class="text-center py-8">
        <v-icon color="success" size="48">mdi-check-circle-outline</v-icon>
        <div class="text-body-1 mt-3">Личность подтверждена</div>
      </v-card-text>

      <!-- Error state -->
      <v-card-text v-else-if="phase === 'error'" class="text-center py-8">
        <v-icon color="error" size="48">mdi-alert-circle-outline</v-icon>
        <div class="text-body-1 mt-3 mb-2">{{ error }}</div>
        <v-btn variant="text" size="small" @click="retry">Попробовать снова</v-btn>
      </v-card-text>

      <v-card-actions class="px-6 pb-5">
        <v-btn variant="text" @click="cancel" :disabled="verifying">Отмена</v-btn>
        <v-spacer />

        <!-- Password submit -->
        <v-btn
          v-if="phase === 'password'"
          color="primary"
          variant="flat"
          :loading="verifying"
          :disabled="!password"
          @click="submitPassword"
        >
          Подтвердить
        </v-btn>

        <!-- Send OTP button -->
        <v-btn
          v-if="phase === 'otp_send'"
          color="primary"
          variant="flat"
          :loading="verifying"
          @click="requestOtp"
        >
          Отправить код
        </v-btn>

        <!-- Verify OTP button -->
        <v-btn
          v-if="phase === 'otp_code'"
          color="primary"
          variant="flat"
          :loading="verifying"
          :disabled="!otpCode"
          @click="submitOtp"
        >
          Подтвердить
        </v-btn>

        <!-- Send email OTP button -->
        <v-btn
          v-if="phase === 'email_otp_send'"
          color="primary"
          variant="flat"
          :loading="verifying"
          @click="requestEmailOtp"
        >
          Отправить код на email
        </v-btn>

        <!-- Verify email OTP button -->
        <v-btn
          v-if="phase === 'email_otp_code'"
          color="primary"
          variant="flat"
          :loading="verifying"
          :disabled="!emailOtpCode"
          @click="submitEmailOtp"
        >
          Подтвердить
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue'
import { securityApi } from '@/api/security'

const props = defineProps<{
  modelValue: boolean
  scope: string
  title?: string
}>()

const emit = defineEmits<{
  (e: 'update:modelValue', val: boolean): void
  (e: 'completed', token: string): void
  (e: 'cancelled'): void
}>()

// ── State ────────────────────────────────────────────────────────────────────

type Phase =
  | 'initiating'
  | 'choice'
  | 'password'
  | 'otp_send'
  | 'otp_code'
  | 'email_otp_send'
  | 'email_otp_code'
  | 'success'
  | 'error'

const phase = ref<Phase>('initiating')
const challengeId = ref<string | null>(null)
const phoneChallengeId = ref<string | null>(null)
const emailChallengeId = ref<string | null>(null)
const allowedMethods = ref<string[]>([])
const phoneMasked = ref<string | null>(null)
const emailMasked = ref<string | null>(null)
const verifying = ref(false)
const error = ref<string | null>(null)

// Form values
const password = ref('')
const showPassword = ref(false)
const otpCode = ref('')
const emailOtpCode = ref('')

// ── Lifecycle ────────────────────────────────────────────────────────────────

watch(
  () => props.modelValue,
  async (open) => {
    if (open) await initiate()
    else reset()
  },
)

async function initiate() {
  reset()
  phase.value = 'initiating'
  try {
    const res = await securityApi.stepUpInitiate(props.scope)
    challengeId.value = res.challenge_id
    allowedMethods.value = res.allowed_methods
    phoneMasked.value = res.phone_masked
    emailMasked.value = res.email_masked

    // Auto-select method if only one is available
    if (allowedMethods.value.length === 1) {
      selectMethod(allowedMethods.value[0] as 'password' | 'phone_otp' | 'email_otp')
    } else {
      phase.value = 'choice'
    }
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Не удалось начать проверку. Попробуйте снова.'
    phase.value = 'error'
  }
}

function selectMethod(method: 'password' | 'phone_otp' | 'email_otp') {
  if (method === 'password') {
    phase.value = 'password'
  } else if (method === 'email_otp') {
    phase.value = 'email_otp_send'
  } else {
    phase.value = 'otp_send'
  }
}

// ── Password flow ────────────────────────────────────────────────────────────

async function submitPassword() {
  if (!password.value || !challengeId.value) return
  verifying.value = true
  error.value = null
  try {
    const res = await securityApi.stepUpVerifyPassword(challengeId.value, password.value)
    onSuccess(res.step_up_token)
  } catch (e: any) {
    const status = e?.response?.status
    if (status === 401) {
      error.value = 'Неверный пароль.'
    } else if (status === 410) {
      error.value = 'Время сессии истекло. Начните заново.'
      phase.value = 'error'
    } else {
      error.value = e?.response?.data?.message || 'Ошибка. Попробуйте снова.'
    }
  } finally {
    verifying.value = false
  }
}

// ── Phone OTP flow ───────────────────────────────────────────────────────────

async function requestOtp() {
  if (!challengeId.value) return
  verifying.value = true
  error.value = null
  try {
    const res = await securityApi.stepUpRequestPhoneOtp(challengeId.value)
    phoneChallengeId.value = res.phone_challenge_id
    if (res.phone_masked) phoneMasked.value = res.phone_masked
    phase.value = 'otp_code'
  } catch (e: any) {
    const status = e?.response?.status
    if (status === 429) {
      error.value = 'Слишком много попыток. Подождите и попробуйте снова.'
    } else {
      error.value = e?.response?.data?.message || 'Не удалось отправить код.'
    }
  } finally {
    verifying.value = false
  }
}

async function resendOtp() {
  phase.value = 'otp_send'
  otpCode.value = ''
  await requestOtp()
}

// ── Email OTP flow ──────────────────────────────────────────────────────────────────

async function requestEmailOtp() {
  if (!challengeId.value) return
  verifying.value = true
  error.value = null
  try {
    const res = await securityApi.stepUpRequestEmailOtp(challengeId.value)
    emailChallengeId.value = res.email_challenge_id
    if (res.email_masked) emailMasked.value = res.email_masked
    phase.value = 'email_otp_code'
  } catch (e: any) {
    const status = e?.response?.status
    if (status === 429) {
      error.value = 'Слишком много попыток. Подождите перед повторной отправкой.'
    } else {
      error.value = e?.response?.data?.message || 'Не удалось отправить код.'
    }
  } finally {
    verifying.value = false
  }
}

async function resendEmailOtp() {
  phase.value = 'email_otp_send'
  emailOtpCode.value = ''
  await requestEmailOtp()
}

async function submitEmailOtp() {
  if (!emailOtpCode.value || !challengeId.value || !emailChallengeId.value) return
  verifying.value = true
  error.value = null
  try {
    const res = await securityApi.stepUpVerifyEmailOtp(
      challengeId.value,
      emailChallengeId.value,
      emailOtpCode.value,
    )
    onSuccess(res.step_up_token)
  } catch (e: any) {
    const status = e?.response?.status
    if (status === 410) {
      error.value = 'Время сессии истекло. Начните заново.'
      phase.value = 'error'
    } else {
      error.value = e?.response?.data?.message || 'Неверный код. Попробуйте снова.'
    }
  } finally {
    verifying.value = false
  }
}

async function submitOtp() {
  if (!otpCode.value || !challengeId.value || !phoneChallengeId.value) return
  verifying.value = true
  error.value = null
  try {
    const res = await securityApi.stepUpVerifyPhoneOtp(
      challengeId.value,
      phoneChallengeId.value,
      otpCode.value,
    )
    onSuccess(res.step_up_token)
  } catch (e: any) {
    const status = e?.response?.status
    if (status === 410) {
      error.value = 'Время сессии истекло. Начните заново.'
      phase.value = 'error'
    } else {
      error.value = e?.response?.data?.message || 'Неверный код. Попробуйте снова.'
    }
  } finally {
    verifying.value = false
  }
}

// ── Result handlers ──────────────────────────────────────────────────────────

function onSuccess(token: string) {
  phase.value = 'success'
  // Close after brief success state
  setTimeout(() => {
    emit('update:modelValue', false)
    emit('completed', token)
    reset()
  }, 600)
}

function cancel() {
  emit('update:modelValue', false)
  emit('cancelled')
  reset()
}

function retry() {
  initiate()
}

function onDialogUpdate(val: boolean) {
  if (!val) cancel()
}

function reset() {
  phase.value = 'initiating'
  challengeId.value = null
  phoneChallengeId.value = null
  emailChallengeId.value = null
  allowedMethods.value = []
  phoneMasked.value = null
  emailMasked.value = null
  password.value = ''
  showPassword.value = false
  otpCode.value = ''
  emailOtpCode.value = ''
  error.value = null
  verifying.value = false
}
</script>
