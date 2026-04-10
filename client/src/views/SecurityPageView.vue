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
        <div class="text-body-2 font-weight-medium">Аккаунт под угрозой</div>
        <div class="text-caption mt-1">
          У вас нет ни одного способа восстановить доступ к аккаунту. Если вы потеряете доступ,
          это будет невозможно исправить самостоятельно.
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
        @enable-pin="handleEnablePin"
        @disable-pin="handleDisablePin"
      />

      <!-- Recovery readiness -->
      <RecoveryReadinessCard :status="store.authStatus" />

      <!-- Sessions -->
      <SessionsCard
        :sessions="store.sessions"
        :loading="store.sessionsLoading"
      />

      <!-- Trusted devices (only shown if can_manage_trusted_devices) -->
      <TrustedDevicesCard
        v-if="store.authStatus.can_manage_trusted_devices"
        :devices="store.devices"
        :loading="store.devicesLoading"
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
import { useSecurityStore } from '@/stores/security'
import { authApi } from '@/api/auth'

import AuthMethodsCard from '@/components/security/AuthMethodsCard.vue'
import RecoveryReadinessCard from '@/components/security/RecoveryReadinessCard.vue'
import RecommendedActionsCard from '@/components/security/RecommendedActionsCard.vue'
import SessionsCard from '@/components/security/SessionsCard.vue'
import TrustedDevicesCard from '@/components/security/TrustedDevicesCard.vue'
import SetPasswordDialog from '@/components/security/SetPasswordDialog.vue'
import BootstrapPhoneDialog from '@/components/security/BootstrapPhoneDialog.vue'

const store = useSecurityStore()

const loadError = ref(false)
const setPasswordDialog = ref(false)
const changePasswordDialog = ref(false)
const bootstrapPhoneDialog = ref(false)

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
    case 'add_phone':
    case 'bootstrap_add_phone':
      bootstrapPhoneDialog.value = true
      break
    case 'verify_email':
    case 'add_email':
      handleResendEmail()
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
  // Phone verification not yet implemented
}

function handleChangePhone() {
  // Phone change not yet implemented
}

// ── Email ─────────────────────────────────────────────────────────────────

function handleAddEmail() {
  // Delegate to email settings tab
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
  // Email change not yet implemented
}

// ── Yandex ────────────────────────────────────────────────────────────────

function handleLinkYandex() {
  // OAuth redirect — future implementation
}

function handleUnlinkYandex() {
  // Unlink — future implementation
}

// ── PIN ───────────────────────────────────────────────────────────────────

function handleEnablePin() {
  // PIN setup — future implementation
}

function handleDisablePin() {
  // PIN disable — future implementation
}

// ── Callbacks ─────────────────────────────────────────────────────────────

async function onActionCompleted() {
  await store.fetchAuthStatus()
  notify('Изменения сохранены.')
}

async function onBootstrapPhoneCompleted(_recommendedActions: string[]) {
  await store.refreshAfterBootstrap()
  notify('Телефон добавлен.')
}
</script>
