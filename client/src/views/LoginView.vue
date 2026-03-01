<template>
  <v-theme-provider theme="dark">
    <v-container class="fill-height login-page" fluid>
      <div class="login-prism-bg" aria-hidden="true">
        <PrismBackground
          animation-type="3drotate"
          :time-scale="0.4"
          :height="3.5"
          :base-width="5.5"
          :scale="2.2"
          :hue-shift="0"
          :color-frequency="0.25"
          :noise="0"
          :glow="0.5"
        />
      </div>

      <v-row class="auth-content ma-0" align="center" justify="center">
        <v-col cols="12" sm="auto" class="auth-col">
          <v-card class="login-card">
          <v-card-title class="text-h6">{{ cardTitle }}</v-card-title>

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

            <!-- Обычный логин -->
            <AuthLogin
              v-else-if="mode === 'login'"
              @forgot="switchMode('forgot')"
              @register="switchMode('register')"
              @login-success="onLoginSuccess"
            />

            <!-- Восстановление PIN (ввод пароля → новый PIN) -->
            <AuthLogin
              v-else-if="mode === 'forgot-pin'"
              @forgot="switchMode('forgot')"
              @register="switchMode('register')"
              @login-success="onLoginSuccess"
            />

            <AuthForgot v-else-if="mode === 'forgot'" @back="switchMode('login')" />
            <AuthRegister v-else @login="switchMode('login')" />
          </v-card-text>
        </v-card>
        </v-col>
      </v-row>

    <!-- PIN Setup dialog (after first login) -->
      <PinSetupDialog
        v-model="showPinSetup"
        @done="onPinSetupDone"
        @skip="onPinSetupSkip"
      />
    </v-container>
  </v-theme-provider>
</template>

<script setup lang="ts">
import { computed, ref, watch, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AuthLogin from '@/components/auth/AuthLogin.vue'
import AuthForgot from '@/components/auth/AuthForgot.vue'
import AuthRegister from '@/components/auth/AuthRegister.vue'
import AuthPinLogin from '@/components/auth/AuthPinLogin.vue'
import PinSetupDialog from '@/components/auth/PinSetupDialog.vue'
import PrismBackground from '@/components/effects/PrismBackground.vue'
import { pinApi } from '@/api/pin'

type AuthMode = 'login' | 'pin' | 'forgot' | 'forgot-pin' | 'register'

const router = useRouter()
const route = useRoute()

const mode = ref<AuthMode>('login')
const resolvingAuthMode = ref(true)
const topMessage = ref('')
const pinStatusChecked = ref(false)
const pinUserName = ref<string | null>(null)
const pinUserEmail = ref<string | null>(null)
const showPinSetup = ref(false)

// Данные от логина для решения о PIN setup
const loginResponseData = ref<any>(null)

const cardTitle = computed(() => {
  if (mode.value === 'pin') return 'Быстрый вход'
  if (mode.value === 'forgot') return 'Восстановление пароля'
  if (mode.value === 'forgot-pin') return 'Вход в систему'
  return 'Вход в систему'
})

// Проверить PIN-статус при загрузке страницы
onMounted(async () => {
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
    const queryMode = route.query.mode as string
    if (queryMode === 'forgot' || queryMode === 'register') {
      mode.value = queryMode
    } else {
      mode.value = 'login'
    }
  } catch {
    // Если ошибка — просто показываем обычный логин
    const queryMode = route.query.mode as string
    if (queryMode === 'forgot' || queryMode === 'register') {
      mode.value = queryMode
    } else {
      mode.value = 'login'
    }
  } finally {
    resolvingAuthMode.value = false
  }
})

watch(
  () => route.query.mode,
  (newMode) => {
    if (resolvingAuthMode.value) return
    if (mode.value === 'pin') return // Не переключать из PIN режима по query
    const m = typeof newMode === 'string' ? newMode : ''
    if (m === 'forgot' || m === 'login' || m === 'register') {
      mode.value = m
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

const clearTopMessage = async () => {
  topMessage.value = ''
  const query = { ...route.query }
  delete (query as any).message
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

  // Если PIN включён, но на этом устройстве нет доверия → предложить доверять
  if (data?.should_offer_pin_setup) {
    showPinSetup.value = true
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
.text-none {
  text-transform: none;
}

.auth-card-text {
  padding: 12px;
}

.login-page {
  position: relative;
  min-height: 100vh;
  overflow: hidden;
  background-color: #121212;
  padding: 16px 12px;
}

.login-prism-bg {
  position: absolute;
  inset: 0;
  z-index: 0;
}

.auth-content {
  position: relative;
  z-index: 1;
  width: 100%;
}

.auth-col {
  display: flex;
  justify-content: center;
}

.login-card {
  width: min(380px, calc(100vw - 24px));
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
