<template>
  <v-card class="expert-library-picker">
    <v-card-title>Библиотека проекта</v-card-title>
    <v-card-text class="expert-library-picker__body">
      <ExpertFileBrowser
        v-model:selected-ids="selectedIds"
        :items="materials"
        :thumbnails="thumbnails"
        :selection-limits="selectionLimits"
        mode="picker"
        storage-key="expert.materialPicker"
        @thumbnail-needed="$emit('thumbnail-needed', $event)"
        @thumbnail-error="$emit('thumbnail-error', $event)"
      >
        <template #empty><p class="expert-library-picker__empty">Материалы не найдены.</p></template>
      </ExpertFileBrowser>
    </v-card-text>
    <v-card-actions class="expert-library-picker__footer">
      <v-spacer />
      <v-btn @click="$emit('cancel')">Отмена</v-btn>
      <v-btn color="primary" variant="flat" :disabled="!canConfirm" @click="$emit('confirm', [...selectedIds])">Добавить {{ selectedIds.length }}</v-btn>
    </v-card-actions>
  </v-card>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import ExpertFileBrowser from '../files/ExpertFileBrowser.vue'
import { selectionWithinMaterialLimits, type ExpertMaterialSelectionLimits } from '../../fileBrowser'
import type { ExpertProjectMaterial } from '../../types'

const props = withDefaults(defineProps<{
  materials: ExpertProjectMaterial[]
  initiallySelected: string[]
  selectionLimits: ExpertMaterialSelectionLimits
  thumbnails?: Record<string, { status: 'idle' | 'loading' | 'ready' | 'error'; url?: string }>
}>(), { thumbnails: () => ({}) })

defineEmits<{
  (event: 'cancel'): void
  (event: 'confirm', ids: string[]): void
  (event: 'thumbnail-needed', item: ExpertProjectMaterial): void
  (event: 'thumbnail-error', item: ExpertProjectMaterial): void
}>()

const selectedIds = ref([...props.initiallySelected])
const materialById = computed(() => new Map(props.materials.map((item) => [item.id, item])))
const canConfirm = computed(() => selectedIds.value.length > 0 && selectionWithinMaterialLimits(selectedIds.value, props.selectionLimits, materialById.value))
</script>

<style scoped>
.expert-library-picker { display: flex; max-height: min(88vh, 860px); flex-direction: column; }
.expert-library-picker__body { min-height: 0; overflow-y: auto; padding-bottom: 16px; }
.expert-library-picker__footer { position: sticky; z-index: 2; bottom: 0; flex: 0 0 auto; padding: 12px 16px; border-top: 1px solid rgba(var(--v-theme-outline-variant), .6); background: rgb(var(--v-theme-surface)); }
.expert-library-picker__empty { padding: 24px 12px; color: rgb(var(--v-theme-on-surface-variant)); text-align: center; }
</style>
