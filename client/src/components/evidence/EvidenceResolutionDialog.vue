<template>
  <v-dialog :model-value="modelValue" @update:model-value="$emit('update:modelValue', $event)" max-width="520" persistent>
    <v-card>
      <v-card-title class="d-flex align-center">
        <span>{{ isResolve ? 'Подтвердить позицию' : 'Пропустить позицию' }}</span>
        <v-spacer />
        <v-btn icon variant="text" size="small" @click="close">
          <v-icon>mdi-close</v-icon>
        </v-btn>
      </v-card-title>

      <v-card-text>
        <!-- Item context -->
        <v-alert variant="tonal" density="compact" type="info" class="mb-4">
          <strong>{{ item?.label || '—' }}</strong>
          <span v-if="item?.cost_component" class="ml-2 text-caption">
            ({{ componentLabel(item.cost_component) }})
          </span>
        </v-alert>

        <!-- Resolve mode: evidence_record_id input -->
        <template v-if="isResolve">
          <v-text-field
            v-model.number="recordId"
            label="ID записи обоснования (evidence_record_id)"
            type="number"
            :rules="[rules.required, rules.positive]"
            hint="Введите ID существующей записи обоснования для привязки к данной позиции"
            persistent-hint
            variant="outlined"
            density="compact"
          />

          <v-alert variant="tonal" density="compact" type="warning" class="mt-3" icon="mdi-google-chrome">
            Если записи обоснования ещё нет — используйте Chrome-расширение для захвата скриншота на странице поставщика.
            После захвата запись будет создана автоматически, и вы сможете указать её ID здесь.
          </v-alert>
        </template>

        <!-- Skip mode: reason input -->
        <template v-else>
          <v-textarea
            v-model="skipReason"
            label="Причина пропуска (необязательно)"
            rows="3"
            variant="outlined"
            density="compact"
            counter="500"
            :maxlength="500"
          />
        </template>

        <!-- Error feedback -->
        <v-alert v-if="errorMessage" type="error" variant="tonal" density="compact" class="mt-3">
          {{ errorMessage }}
        </v-alert>
      </v-card-text>

      <v-card-actions>
        <v-spacer />
        <v-btn @click="close">Отмена</v-btn>
        <v-btn
          :color="isResolve ? 'primary' : 'warning'"
          :loading="loading"
          :disabled="isResolve && !recordId"
          @click="submit"
        >
          {{ isResolve ? 'Подтвердить' : 'Пропустить' }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<script setup lang="ts">
import { ref, watch, computed } from 'vue'
import type { EvidenceItem } from '@/api/evidenceRun'
import { COST_COMPONENT_LABELS } from '@/composables/useEvidenceRun'

const props = defineProps<{
  modelValue: boolean
  item: EvidenceItem | null
  mode: 'resolve' | 'skip'
  loading?: boolean
  errorMessage?: string | null
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  resolve: [itemId: number, evidenceRecordId: number]
  skip: [itemId: number, reason: string]
}>()

const recordId = ref<number | null>(null)
const skipReason = ref('')

const isResolve = computed(() => props.mode === 'resolve')

const rules = {
  required: (v: number | null | undefined) => (v != null && v > 0) || 'Обязательное поле',
  positive: (v: number | null | undefined) => (v != null && v > 0) || 'Должно быть положительным',
}

watch(
  () => props.modelValue,
  (open) => {
    if (open) {
      recordId.value = null
      skipReason.value = ''
    }
  },
)

function componentLabel(comp: string): string {
  return COST_COMPONENT_LABELS[comp] ?? comp
}

function close() {
  emit('update:modelValue', false)
}

function submit() {
  if (!props.item) return
  if (isResolve.value) {
    if (recordId.value && recordId.value > 0) {
      emit('resolve', props.item.id, recordId.value)
    }
  } else {
    emit('skip', props.item.id, skipReason.value)
  }
}
</script>
