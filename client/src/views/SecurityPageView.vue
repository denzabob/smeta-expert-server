<template>
  <v-container max-width="760" class="py-6">
    <!-- Header -->
    <div class="d-flex align-center mb-6">
      <v-btn icon variant="text" size="small" :to="{ name: 'settings' }" class="mr-2">
        <v-icon>mdi-arrow-left</v-icon>
      </v-btn>
      <div>
        <div class="text-h6 font-weight-medium">Безопасность аккаунта</div>
        <div class="text-caption text-medium-emphasis">Способы входа, восстановление и устройства</div>
      </div>
    </div>

    <!-- Loading state -->
    <div v-if="store.loading && !store.authStatus" class="mb-4">
      <v-skeleton-loader type="card" class="mb-4" />
      <v-skeleton-loader type="card" class="mb-4" />
      <v-skeleton-loader type="card" />
    </div>

    <template v-else-if="store.authStatus">
      <!-- Critical warning: cannot self-recover -->
      <v-alert
        v-if="!store.authStatus.can_self_recover"
        type="error"
        variant="flat"
        density="comfortable"
        class="mb-6"
        prepend-icon="mdi-alert-circle"
      >
        <div class="text-body-2 font-weight-medium">Нет способа восстановить доступ</div>
        <div class="text-caption mt-1">
          Если вы потеряете доступ к аккаунту, вернуться будет невозможно.
          Добавьте телефон или установите пароль — это займёт меньше минуты.
        </div>
      </v-alert>

      <!-- Recommended actions card -->
      <RecommendedActionsCard
        v-if="store.authStatus.recommended_actions.length > 0 || store.authStatus.blocked_actions.length > 0"
        :status="store.authStatus"
        @action="handleRecommendedAction"
      />

      <!-- Auth methods card -->
      <AuthMethodsCard
        :status="store.authStatus"
        @set-password="openSetPassword"
        @change-password="openChangePassword"
        @add-phone="bootstrapPhoneDialog = true"
        @verify-phone="handleVerifyPhone"
        @change-phone="handleChangePhone"
        @add-email="handleAddEmail"
        @resend-email-verification="handleResendEmail"
        @change-email="handleChangeEmail"
        @link-yandex="handleLinkYandex"
        @unlink-yandex="handleUnlinkYandex"
        @link-provider="handleLinkProvider"
        @unlink-provider="handleUnlinkProvider"
        @enable-pin="handleEnablePin"
        @disable-pin="handleDisablePin"
        @action="handleRecommendedAction"
      />

      <!-- Recovery readiness -->
      <RecoveryReadinessCard :status="store.authStatus" />

      <!-- Sessions -->
      <SessionsCard
        :sessions="store.sessions"
        :loading="store.sessionsLoading"
        @notify="notify"
      />

      <!-- Trusted devices (only shown if can_manage_trusted_devices) -->
      <TrustedDevicesCard
        v-if="store.authStatus.can_manage_trusted_devices"
        :devices="store.devices"
        :loading="store.devicesLoading"
        @notify="notify"
      />
    </template>

    <!-- Error state -->
    <v-alert
      v-else-if="loadError"
      type="error"
      variant="tonal"
      class="mb-4"
    >
      Не удалось загрузить данные безопасности. Попробуйте обновить страницу.
      <template #append>
        <v-btn variant="text" @click="reload">Повторить</v-btn>
      </template>
    </v-alert>
  </v-container>

  <!-- ── Dialogs ─────────────────────────────────────────────────────────── -->

  <!-- Set password -->
  <SetPasswordDialog
    v-model="setPasswordDialog"
    mode="set"
    @completed="onActionCompleted"
  />

  <!-- Change password -->
  <SetPasswordDialog
    v-model="changePasswordDialog"
    mode="change"
    @completed="onActionCompleted"
  />

  <!-- Bootstrap phone (for Yandex-only accounts) -->
  <BootstrapPhoneDialog
    v-model="bootstrapPhoneDialog"
    @completed="onBootstrapPhoneCompleted"
  />

  <!-- Enable PIN -->
  <SetPinDialog
    v-model="setPinDialog"
    @completed="onPinEnabled"
  />

  <!-- Disable PIN (step-up only) -->
  <StepUpDialog
    v-model="disablePinDialog"
    scope="set_quick_pin"
    title="Подтверждение для отключения PIN"
    @completed="onDisablePinStepUp"
    @cancelled="disablePinDialog = false"
  />

  <!-- Global feedback snackbar -->
  <v-snackbar
    v-model="snack.show"
    :color="snack.color"
    :timeout="snack.timeout"
    location="bottom right"
    variant="tonal"
  >
    {{ snack.message }}
    <template v-if="snack.action" #actions>
      <v-btn variant="text" size="small" @click="snack.show = false">OK</v-btn>
    </template>
  </v-snackbar>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useSecurityStore } from '@/stores/security'
import { authApi } from '@/api/auth'

import AuthMethodsCard from '@/components/security/AuthMethodsCard.vue'
import RecoveryReadinessCard from '@/components/security/RecoveryReadinessCard.vue'
import RecommendedActionsCard from '@/components/security/RecommendedActionsCard.vue'
import SessionsCard from '@/components/security/SessionsCard.vue'
import TrustedDevicesCard from '@/components/security/TrustedDevicesCard.vue'
import SetPasswordDialog from '@/components/security/SetPasswordDialog.vue'
import SetPinDialog from '@/components/security/SetPinDialog.vue'
import BootstrapPhoneDialog from '@/components/security/BootstrapPhoneDialog.vue'
import StepUpDialog from '@/components/security/StepUpDialog.vue'

const store = useSecurityStore()
const router = useRouter()

const loadError = ref(false)
const setPasswordDialog = ref(false)
const changePasswordDialog = ref(false)
const bootstrapPhoneDialog = ref(false)
const setPinDialog = ref(false)
const disablePinDialog = ref(false)

// ── Snackbar ──────────────────────────────────────────────────────────────

const snack = ref({
  show: false,
  message: '',
  color: 'success' as 'success' | 'error' | 'warning' | 'info',
  timeout: 4000,
  action: false,
})

function notify(message: string, color: typeof snack.value.color = 'success', timeout = 4000) {
  snack.value = { show: true, message, color, timeout, action: false }
}

// ── Load ──────────────────────────────────────────────────────────────────

async function reload() {
  loadError.value = false
  try {
    await Promise.all([
      store.fetchAuthStatus(),
      store.fetchSessions(),
      store.fetchDevices(),
    ])
  } catch {
    loadError.value = true
  }
}

onMounted(reload)

// ── Recommended action dispatcher ────────────────────────────────────────

function handleRecommendedAction(action: string) {
  switch (action) {
    case 'set_password':
      openSetPassword()
      break
    case 'change_password':
      openChangePassword()
      break
    case 'add_phone':
    case 'bootstrap_add_phone':
      bootstrapPhoneDialog.value = true
      break
    case 'verify_email':
      handleResendEmail()
      break
    case 'add_email':
      handleAddEmail()
      break
    case 'verify_phone':
      handleVerifyPhone()
      break
    case 'enable_quick_pin':
      handleEnablePin()
      break
    default:
      break
  }
}

// ── Password ──────────────────────────────────────────────────────────────

function openSetPassword() {
  setPasswordDialog.value = true
}

function openChangePassword() {
  changePasswordDialog.value = true
}

// ── Phone ─────────────────────────────────────────────────────────────────

function handleVerifyPhone() {
  notify('Подтверждение телефона: отправьте код повторно через настройки профиля.', 'info', 6000)
}

function handleChangePhone() {
  notify('Изменение телефона доступно в разделе «Настройки профиля».', 'info', 5000)
}

// ── Email ─────────────────────────────────────────────────────────────────

function handleAddEmail() {
  router.push({ name: 'settings' })
}

async function handleResendEmail() {
  const maskedEmail = store.authStatus?.email?.masked
  try {
    await authApi.resendEmailVerification()
    const target = maskedEmail ? ` на ${maskedEmail}` : ''
    notify(`Письмо подтверждения отправлено${target}. Проверьте почту.`, 'success', 6000)
  } catch (e: any) {
    const status = e?.response?.status
    if (status === 429) {
      const retryAfter = e?.response?.data?.retry_after
      const seconds = retryAfter ? ` через ${retryAfter} сек.` : ''
      notify(`Слишком много попыток. Повторите${seconds}.`, 'warning', 6000)
    } else if (status === 422) {
      notify(e?.response?.data?.message || 'Email не указан в профиле.', 'error')
    } else {
      notify('Не удалось отправить письмо. Попробуйте позже.', 'error')
    }
  }
}

function handleChangeEmail() {
  notify('Изменение email доступно в разделе «Настройки профиля».', 'info', 5000)
}

// ── OAuth providers ───────────────────────────────────────────────────────

function handleLinkYandex() {
  void handleLinkProvider('yandex')
}

function handleUnlinkYandex() {
  void handleUnlinkProvider('yandex')
}

async function handleLinkProvider(provider: string) {
  try {
    const result = await authApi.getProviderLinkRedirect(provider)
    window.location.href = result.redirect_url
  } catch (e: any) {
    notify(e?.response?.data?.message || `Не удалось начать подключение ${oauthProviderLabel(provider)}.`, 'error')
  }
}

async function handleUnlinkProvider(provider: string) {
  const confirmed = window.confirm(`Отвязать аккаунт ${oauthProviderLabel(provider)} от входа?`)
  if (!confirmed) return

  try {
    await authApi.unlinkProvider(provider)
    await store.fetchAuthStatus()
    notify(`Аккаунт ${oauthProviderLabel(provider)} отвязан.`, 'success')
  } catch (e: any) {
    notify(e?.response?.data?.message || `Не удалось отвязать ${oauthProviderLabel(provider)}.`, 'error')
  }
}

function oauthProviderLabel(provider: string): string {
  if (provider === 'yandex') return 'Яндекс'
  if (provider === 'vk') return 'VK ID'
  return 'внешний аккаунт'
}

// ── PIN ───────────────────────────────────────────────────────────────────

function handleEnablePin() {
  setPinDialog.value = true
}

function handleDisablePin() {
  disablePinDialog.value = true
}

async function onDisablePinStepUp(token: string) {
  disablePinDialog.value = false
  try {
    await store.disablePin(token)
    notify('Быстрый PIN отключён. Все доверенные устройства отозваны.', 'success')
    await store.fetchDevices()
  } catch (e: any) {
    notify(e?.response?.data?.message || 'Не удалось отключить PIN. Попробуйте снова.', 'error')
  }
}

// ── Callbacks ─────────────────────────────────────────────────────────────

async function onActionCompleted() {
  await store.fetchAuthStatus()
  notify('Изменения сохранены.')
}

async function onPinEnabled() {
  await store.fetchAuthStatus()
  await store.fetchDevices()
  notify('Быстрый PIN установлен. Это устройство теперь доверенное.', 'success')
}

async function onBootstrapPhoneCompleted(_recommendedActions: string[]) {
  await store.refreshAfterBootstrap()
  notify('Телефон добавлен. Теперь вы можете установить пароль и включить быстрый PIN.', 'success', 6000)
}
</script>
