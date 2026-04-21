<template>
  <v-container class="fill-height login-page md3-auth-page" fluid>
      <v-row class="auth-content md3-auth-shell ma-0" align="center" justify="center">
        <v-col cols="12" md="6" class="d-none d-md-flex">
          <section class="md3-auth-hero">
            <div>
              <div class="md3-auth-hero__eyebrow">Material 3 Foundation</div>
              <h1 class="md3-auth-hero__title">СметаЭксперт для рабочих процессов.</h1>
              <p class="md3-auth-hero__body">
                Пилотный экран новой глобальной дизайн-системы. Основа строится на общих
                semantic tokens, tonal surfaces и предсказуемой форме компонентов, чтобы
                затем поэтапно перевести на неё весь сервис.
              </p>
            </div>

            <div class="md3-auth-hero__highlights">
              <div class="md3-auth-hero__metric">
                <div class="md3-auth-hero__metric-label">Основа</div>
                <div class="md3-auth-hero__metric-value">Глобальные MD3 tokens и совместимая тема Vuetify</div>
              </div>
              <div class="md3-auth-hero__metric">
                <div class="md3-auth-hero__metric-label">Приоритет</div>
                <div class="md3-auth-hero__metric-value">Безопасная миграция без поломки текущих экранов</div>
              </div>
            </div>
          </section>
        </v-col>

        <v-col cols="12" md="6" lg="5" class="auth-col">
          <v-card class="login-card md3-auth-card">
          <v-card-title class="text-h5">{{ cardTitle }}</v-card-title>

          <v-card-text class="auth-card-text">
            <v-alert
              v-if="topMessage"
              type="success"
              variant="tonal"
              closable
              class="mb-4"
              @click:close="clearTopMessage"
            >
              {{ topMessage }}
            </v-alert>

            <v-alert
              v-if="topError"
              type="error"
              variant="tonal"
              closable
              class="mb-4"
              @click:close="clearTopError"
            >
              {{ topError }}
            </v-alert>

            <div v-if="resolvingAuthMode" class="auth-resolving">
              <v-progress-circular indeterminate size="26" color="primary" class="mb-3" />
              <div class="text-body-2 text-medium-emphasis">Проверяем способ входа...</div>
            </div>

            <!-- PIN-вход (доверенное устройство) -->
            <AuthPinLogin
              v-else-if="mode === 'pin'"
              :user-name="pinUserName"
              :user-email="pinUserEmail"
              @forgot-pin="switchMode('forgot-pin')"
              @switch-account="onSwitchAccount"
            />

            <!-- Телефонная авторизация -->
            <template v-else-if="mode === 'phone'">
              <AuthPhoneLogin @verified="onPhoneVerified" />

              <div class="auth-divider md3-auth-separator my-4">
                <span class="md3-auth-separator__label">или</span>
              </div>

              <YandexLoginButton class="mb-3" />

              <div class="text-center">
                <v-btn variant="text" size="small" @click="switchMode('login')">
                  Войти по email
                </v-btn>
              </div>
            </template>

            <!-- Onboarding: заполнение профиля -->
            <OnboardingCompletion
              v-else-if="mode === 'onboarding'"
              @completed="onOnboardingCompleted"
              @switch-account="onOnboardingSwitchAccount"
            />

            <!-- Обычный логин -->
            <template v-else-if="mode === 'login'">
              <AuthLogin
                @forgot="switchMode('forgot')"
                @register="switchMode('phone')"
                @login-success="onLoginSuccess"
              />

              <div class="auth-divider md3-auth-separator my-4">
                <span class="md3-auth-separator__label">или</span>
              </div>

              <v-btn
                block
                variant="outlined"
                size="large"
                class="text-none mb-3"
                prepend-icon="mdi-phone"
                @click="switchMode('phone')"
              >
                Войти по телефону
              </v-btn>

              <YandexLoginButton />
            </template>

            <!-- Восстановление PIN (ввод пароля → новый PIN) -->
            <AuthLogin
              v-else-if="mode === 'forgot-pin'"
              @forgot="switchMode('forgot')"
              @register="switchMode('phone')"
              @login-success="onLoginSuccess"
            />

            <AuthForgot v-else-if="mode === 'forgot'" @back="switchMode('login')" />
            <AuthRegister v-else @login="switchMode('login')" />
          </v-card-text>
        </v-card>
        </v-col>
      </v-row>

    <!-- PIN Setup dialog (after first login): canonical shared flow -->
      <SetPinDialog
        v-model="showPinSetup"
        :skipable="true"
        :show-trust-device="true"
        @completed="onPinSetupDone"
        @skipped="onPinSetupSkip"
      />

      <v-dialog v-model="showPinTrustDialog" max-width="420" persistent>
        <v-card>
          <v-card-title class="text-h6">
            <v-icon class="mr-2" color="primary">mdi-cellphone-lock</v-icon>
            Доверить это устройство
          </v-card-title>

          <v-card-text>
            <div class="text-body-2 text-medium-emphasis mb-3">
              PIN уже настроен для этого аккаунта. Добавить текущее устройство в доверенные,
              чтобы в следующий раз входить по PIN без логина и пароля?
            </div>

            <v-alert v-if="pinTrustError" type="error" variant="tonal" density="compact">
              {{ pinTrustError }}
            </v-alert>
          </v-card-text>

          <v-card-actions>
            <v-btn variant="text" :disabled="pinTrustLoading" @click="onSkipTrustDevice">
              Не сейчас
            </v-btn>
            <v-spacer />
            <v-btn color="primary" :loading="pinTrustLoading" @click="onTrustDeviceForPin">
              Доверять устройство
            </v-btn>
          </v-card-actions>
        </v-card>
      </v-dialog>
  </v-container>
</template>

<script setup lang="ts">
import { computed, ref, watch, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AuthLogin from '@/components/auth/AuthLogin.vue'
import AuthForgot from '@/components/auth/AuthForgot.vue'
import AuthRegister from '@/components/auth/AuthRegister.vue'
import AuthPinLogin from '@/components/auth/AuthPinLogin.vue'
import AuthPhoneLogin from '@/components/auth/AuthPhoneLogin.vue'
import OnboardingCompletion from '@/components/auth/OnboardingCompletion.vue'
import YandexLoginButton from '@/components/auth/YandexLoginButton.vue'
import SetPinDialog from '@/components/security/SetPinDialog.vue'
import { pinApi } from '@/api/pin'
import { useAuthStore } from '@/stores/auth'
import type { VerifyCodeResponse } from '@/api/phoneAuth'

type AuthMode = 'login' | 'pin' | 'forgot' | 'forgot-pin' | 'register' | 'phone' | 'onboarding'

const router = useRouter()
const route = useRoute()

const mode = ref<AuthMode>('login')
const resolvingAuthMode = ref(true)
const topMessage = ref('')
const topError = ref('')
const pinStatusChecked = ref(false)
const pinUserName = ref<string | null>(null)
const pinUserEmail = ref<string | null>(null)
const showPinSetup = ref(false)
const showPinTrustDialog = ref(false)
const pinTrustLoading = ref(false)
const pinTrustError = ref('')
const authStore = useAuthStore()

// Данные от логина для решения о PIN setup
const loginResponseData = ref<any>(null)

const cardTitle = computed(() => {
  if (mode.value === 'pin') return 'Быстрый вход'
  if (mode.value === 'forgot') return 'Восстановление пароля'
  if (mode.value === 'forgot-pin') return 'Вход в систему'
  if (mode.value === 'phone') return 'Вход по телефону'
  if (mode.value === 'onboarding') return 'Завершение регистрации'
  return 'Вход в систему'
})

// Проверить PIN-статус при загрузке страницы
onMounted(async () => {
  const queryMode = typeof route.query.mode === 'string' ? route.query.mode : ''

  // Keep onboarding route stable and prevent switching back to PIN on trusted devices.
  if (queryMode === 'onboarding') {
    mode.value = 'onboarding'
    resolvingAuthMode.value = false
    return
  }

  try {
    const status = await pinApi.getStatus()
    pinStatusChecked.value = true

    if (status.trusted_device_present && status.pin_enabled) {
      mode.value = 'pin'
      pinUserName.value = status.user_name
      pinUserEmail.value = status.user_email
      return
    }

    // Если нет PIN — показываем режим из query
    if (queryMode === 'onboarding') {
      mode.value = 'onboarding'
    } else if (queryMode === 'forgot') {
      mode.value = 'forgot'
    } else if (queryMode === 'login') {
      mode.value = 'login'
    } else {
      // По умолчанию — телефонная авторизация
      mode.value = 'phone'
    }
  } catch {
    if (queryMode === 'onboarding') {
      mode.value = 'onboarding'
    } else if (queryMode === 'forgot') {
      mode.value = 'forgot'
    } else if (queryMode === 'login') {
      mode.value = 'login'
    } else {
      mode.value = 'phone'
    }
  } finally {
    resolvingAuthMode.value = false
  }
})

watch(
  () => route.query.mode,
  (newMode) => {
    if (resolvingAuthMode.value) return
    const m = typeof newMode === 'string' ? newMode : ''
    if (mode.value === 'pin' && m !== 'onboarding') return // Keep PIN mode unless onboarding is explicitly requested
    if (['forgot', 'login', 'register', 'phone', 'onboarding'].includes(m)) {
      mode.value = m as AuthMode
    }
  }
)

watch(
  () => route.query.message,
  (val) => {
    if (val === 'password-reset') {
      topMessage.value = 'Пароль изменён'
    } else if (val === 'session-terminated') {
      topMessage.value = 'Сеанс завершён: выполнен вход на другом устройстве'
    }
  },
  { immediate: true }
)

watch(
  () => [route.query.error, route.query.provider],
  ([errorRaw, providerRaw]) => {
    const error = typeof errorRaw === 'string' ? errorRaw : ''
    const provider = typeof providerRaw === 'string' ? providerRaw : ''

    if (!error) {
      topError.value = ''
      return
    }

    const providerLabel = provider === 'yandex' ? 'Яндекса' : 'провайдера'

    const messages: Record<string, string> = {
      oauth_state_mismatch: 'Сессия авторизации устарела. Попробуйте вход через Яндекс ещё раз.',
      oauth_no_code: 'Яндекс не передал код авторизации. Повторите попытку.',
      oauth_token_failed: 'Не удалось подтвердить вход через Яндекс. Попробуйте позже.',
      oauth_profile_failed: 'Не удалось получить профиль Яндекса. Попробуйте позже.',
      oauth_link_required: `Этот аккаунт ${providerLabel} не подключен. Войдите по телефону или email и подключите его в настройках.`,
      already_linked_to_other_user: 'Этот аккаунт Яндекса уже подключён к другому пользователю.',
      provider_already_connected: 'К вашему профилю уже подключён другой аккаунт Яндекса. Сначала отключите текущий.',
      account_deleted: 'Ваша учетная запись удалена. Обратитесь к администратору для восстановления.',
      account_blocked: 'Ваша учетная запись заблокирована. Обратитесь к администратору.',
    }

    topError.value = messages[error] || 'Не удалось выполнить вход через внешний аккаунт.'
  },
  { immediate: true }
)

const clearTopMessage = async () => {
  topMessage.value = ''
  const query = { ...route.query }
  delete (query as any).message
  await router.replace({ query })
}

const clearTopError = async () => {
  topError.value = ''
  const query = { ...route.query }
  delete (query as any).error
  delete (query as any).provider
  await router.replace({ query })
}

const switchMode = async (newMode: AuthMode) => {
  mode.value = newMode
  await router.replace({ query: { ...route.query, mode: newMode } })
}

const onSwitchAccount = async () => {
  try {
    await pinApi.forgetDevice()
  } catch {
    // ignore
  }
  mode.value = 'login'
}

/**
 * Вызывается после успешного логина по паролю.
 * Проверяет, нужно ли предложить настройку PIN.
 */
const onLoginSuccess = (data: any) => {
  loginResponseData.value = data

  // Если PIN ещё не настроен → предложить
  if (data?.should_offer_pin_enable) {
    showPinSetup.value = true
    return
  }

  // PIN уже есть, но устройство не доверено → предложить доверить устройство.
  if (data?.should_offer_pin_setup) {
    pinTrustError.value = ''
    showPinTrustDialog.value = true
    return
  }

  // Иначе — сразу на главную
  navigateAfterLogin()
}

const onPinSetupDone = () => {
  showPinSetup.value = false
  navigateAfterLogin()
}

const onPinSetupSkip = () => {
  showPinSetup.value = false
  navigateAfterLogin()
}

/**
 * Вызывается после успешной верификации кода по телефону.
 */
const onPhoneVerified = async (data: VerifyCodeResponse) => {
  await authStore.checkAuth(true) // Обновляем состояние после серверного логина

  if (data.status === 'needs_onboarding' || data.need_profile_completion) {
    mode.value = 'onboarding'
    await router.replace({ query: { ...route.query, mode: 'onboarding' } })
    return
  }

  loginResponseData.value = data
  if (data.should_offer_pin_enable) {
    showPinSetup.value = true
    return
  }
  if (data.should_offer_pin_setup) {
    pinTrustError.value = ''
    showPinTrustDialog.value = true
    return
  }
  navigateAfterLogin()
}

/**
 * Вызывается после завершения onboarding (заполнение профиля).
 */
const onOnboardingCompleted = async (data: any) => {
  await authStore.checkAuth(true)

  loginResponseData.value = data
  if (data.should_offer_pin_enable) {
    showPinSetup.value = true
    return
  }
  if (data.should_offer_pin_setup) {
    pinTrustError.value = ''
    showPinTrustDialog.value = true
    return
  }
  navigateAfterLogin()
}

const onTrustDeviceForPin = async () => {
  if (pinTrustLoading.value) return

  pinTrustLoading.value = true
  pinTrustError.value = ''
  try {
    await pinApi.trustCurrentDevice()
    showPinTrustDialog.value = false
    navigateAfterLogin()
  } catch (e: any) {
    pinTrustError.value = e?.response?.data?.message || 'Не удалось доверить устройство.'
  } finally {
    pinTrustLoading.value = false
  }
}

const onSkipTrustDevice = () => {
  showPinTrustDialog.value = false
  navigateAfterLogin()
}

const onOnboardingSwitchAccount = async () => {
  await authStore.logout()
  mode.value = 'login'
  const query = { ...route.query }
  delete (query as any).mode
  await router.replace({ query })
}

const navigateAfterLogin = async () => {
  const intendedRaw = route.query.intended
  const intended = typeof intendedRaw === 'string' ? intendedRaw : ''

  if (intended && intended.startsWith('/')) {
    await router.replace(intended)
  } else {
      await router.push({ name: 'projects' })
  }
}
</script>

<style scoped>
.auth-card-text {
  padding: 12px;
}

.login-page {
  position: relative;
  min-height: 100vh;
  overflow: hidden;
  padding: 16px 12px;
}

.auth-content {
  position: relative;
  z-index: 1;
  width: 100%;
  row-gap: 20px;
}

.auth-col {
  display: flex;
  justify-content: center;
}

.login-card {
  width: min(430px, calc(100vw - 24px));
  max-width: 100%;
}

.auth-resolving {
  min-height: 220px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  text-align: center;
}

.auth-divider {
  display: flex;
  align-items: center;
}

@media (max-width: 600px) {
  .login-page {
    padding: 12px 8px;
  }

  .auth-card-text {
    padding: 8px;
  }

  .login-card {
    width: min(100%, calc(100vw - 16px));
  }
}
</style>
