<template>
  <v-card variant="outlined" class="mb-4">
    <v-card-text>
      <div class="text-subtitle-2 font-weight-medium mb-1">Надёжность аккаунта</div>
      <div class="text-caption text-medium-emphasis mb-4">Как вы сможете восстановить доступ к аккаунту</div>

      <!-- Critical: no recovery path -->
      <v-alert
        v-if="!status.can_self_recover"
        type="error"
        variant="tonal"
        density="comfortable"
        class="mb-3"
        icon="mdi-alert-circle-outline"
      >
        <div class="text-body-2 font-weight-medium">Аккаунт не защищён</div>
        <div class="text-caption mt-0-5">
          У вас нет способа восстановить доступ к аккаунту. Добавьте телефон или пароль, чтобы исправить это.
        </div>
      </v-alert>

      <!-- Weak: only one recovery method -->
      <v-alert
        v-else-if="status.recovery_methods.length === 1"
        type="warning"
        variant="tonal"
        density="comfortable"
        class="mb-3"
        icon="mdi-shield-alert-outline"
      >
        <div class="text-body-2 font-weight-medium">Один способ восстановления</div>
        <div class="text-caption mt-0-5">
          Если вы потеряете к нему доступ — войти в аккаунт будет невозможно. Добавьте второй способ для надёжности.
        </div>
      </v-alert>

      <!-- Strong: multiple methods -->
      <v-alert
        v-else
        type="success"
        variant="tonal"
        density="comfortable"
        class="mb-3"
        icon="mdi-shield-check-outline"
      >
        <div class="text-body-2 font-weight-medium">Аккаунт надёжно защищён</div>
        <div class="text-caption mt-0-5">
          {{ status.recovery_methods.length }} способа восстановления доступа.
        </div>
      </v-alert>

      <!-- Recovery methods list -->
      <div v-if="status.recovery_methods.length > 0" class="recovery-methods">
        <div
          v-for="method in status.recovery_methods"
          :key="method"
          class="d-flex align-center gap-2 mb-1"
        >
          <v-icon size="16" color="success">mdi-check-circle-outline</v-icon>
          <span class="text-body-2">{{ methodLabel(method) }}</span>
        </div>
      </div>

      <div v-if="status.recovery_methods.length === 0" class="text-caption text-medium-emphasis">
        Нет доступных способов восстановления.
      </div>
    </v-card-text>
  </v-card>
</template>

<script setup lang="ts">
import type { AuthMethodProfile } from '@/api/security'
import { recoveryMethodLabel } from './securityHelpers'

defineProps<{
  status: AuthMethodProfile
}>()

const methodLabel = recoveryMethodLabel
</script>

<style scoped>
.recovery-methods {
  padding: 4px 0;
}

.mt-0-5 {
  margin-top: 2px;
}
</style>
