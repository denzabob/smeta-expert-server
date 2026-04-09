<template>
  <v-card
    v-if="hasItems"
    variant="outlined"
    class="mb-4"
  >
    <v-card-text>
      <div class="text-subtitle-2 font-weight-medium mb-1">Рекомендуемые действия</div>
      <div class="text-caption text-medium-emphasis mb-4">
        Выполните эти шаги, чтобы улучшить безопасность аккаунта.
      </div>

      <!-- Recommended actions -->
      <div
        v-for="action in status.recommended_actions"
        :key="action"
        class="action-row mb-3"
      >
        <div class="d-flex align-start gap-3">
          <v-icon size="20" :color="actionMeta(action).color" class="mt-0-5">
            {{ actionMeta(action).icon }}
          </v-icon>
          <div class="flex-1">
            <div class="text-body-2 font-weight-medium">{{ actionMeta(action).label }}</div>
            <div class="text-caption text-medium-emphasis mt-0-5">
              {{ actionMeta(action).description }}
            </div>
          </div>
          <v-btn
            size="small"
            :color="actionMeta(action).color"
            variant="tonal"
            @click="$emit('action', action)"
          >
            {{ actionMeta(action).buttonLabel }}
          </v-btn>
        </div>
      </div>

      <!-- Divider between recommended and blocked -->
      <v-divider
        v-if="status.recommended_actions.length > 0 && status.blocked_actions.length > 0"
        class="my-3"
      />

      <!-- Blocked actions (with prerequisite info) -->
      <div
        v-for="action in status.blocked_actions"
        :key="action"
        class="action-row mb-3"
      >
        <div class="d-flex align-start gap-3">
          <v-icon size="20" color="medium-emphasis" class="mt-0-5">
            mdi-lock-outline
          </v-icon>
          <div class="flex-1">
            <div class="text-body-2 text-medium-emphasis font-weight-medium">
              {{ actionMeta(action).label }}
            </div>
            <div class="text-caption text-medium-emphasis mt-0-5">
              Сначала: {{ prerequisiteLabel(action) }}
            </div>
          </div>
          <v-chip size="x-small" variant="tonal">Заблокировано</v-chip>
        </div>
      </div>
    </v-card-text>
  </v-card>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import type { AuthMethodProfile } from '@/api/security'
import {
  recommendedActionMeta,
  blockedActionPrerequisiteLabel,
} from './securityHelpers'

const props = defineProps<{
  status: AuthMethodProfile
}>()

defineEmits<{
  (e: 'action', action: string): void
}>()

const hasItems = computed(
  () => props.status.recommended_actions.length > 0 || props.status.blocked_actions.length > 0,
)

const actionMeta = recommendedActionMeta

function prerequisiteLabel(action: string): string {
  return blockedActionPrerequisiteLabel(action, props.status.prerequisite_actions)
}
</script>

<style scoped>
.action-row {
  border-radius: 8px;
}

.flex-1 {
  flex: 1;
}

.mt-0-5 {
  margin-top: 2px;
}
</style>
