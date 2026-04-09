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
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useSecurityStore } from '@/stores/security'

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
    default:
      // Other actions (email, etc.) are handled via external navigation or future dialogs
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
  // Phone verification is not yet implemented (external flow)
}

function handleChangePhone() {
  // Phone change is not yet implemented (external flow)
}

// ── Email ─────────────────────────────────────────────────────────────────

function handleAddEmail() {
  // Delegate to UserSettingsView email tab (external flow)
}

function handleResendEmail() {
  // Resend verification — future implementation
}

function handleChangeEmail() {
  // Email change — future implementation
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
  // PIN setup delegated to PinSetupDialog (existing component)
  // For now opens settings
}

function handleDisablePin() {
  // PIN disable — future implementation
}

// ── Callbacks ─────────────────────────────────────────────────────────────

async function onActionCompleted() {
  await store.fetchAuthStatus()
}

async function onBootstrapPhoneCompleted(_recommendedActions: string[]) {
  await store.refreshAfterBootstrap()
}
</script>
