<template>
  <v-navigation-drawer v-if="open" v-model="open" temporary location="right" width="460" class="expert-material-drawer">
    <template v-if="material">
      <div class="expert-drawer__header"><div><span class="expert-drawer__eyebrow">Материал проекта</span><h2>{{ material.name }}</h2></div><v-btn icon="mdi-close" variant="text" aria-label="Закрыть свойства материала" @click="open = false" /></div>
      <div class="expert-material-drawer__preview">
        <template v-if="material.kind === 'image'">
          <v-progress-circular v-if="imagePreview?.status === 'loading'" indeterminate color="primary" size="42" width="4" aria-label="Загружается предпросмотр изображения" />
          <img v-else-if="imagePreview?.status === 'ready'" :src="imagePreview.url" :alt="`Предпросмотр: ${material.name}`" class="expert-material-drawer__image" @error="$emit('image-error')" />
          <div v-else class="expert-material-drawer__image-error"><v-icon icon="mdi-image-broken-variant" size="38" /><small>{{ imagePreview?.status === 'error' ? 'Не удалось загрузить предпросмотр изображения' : 'Предпросмотр изображения недоступен' }}</small></div>
        </template>
        <template v-else><v-icon :icon="material.icon" size="58" /><span>{{ material.format }}</span><small>{{ projectMode === 'demo' ? 'Демонстрационный предпросмотр' : 'Предпросмотр содержимого будет подключён на следующем этапе' }}</small></template>
      </div>
      <div class="expert-drawer__content">
        <dl><template v-for="item in details" :key="item.label"><dt>{{ item.label }}</dt><dd>{{ item.value }}</dd></template></dl>
        <v-switch v-if="projectMode === 'demo'" :model-value="material.useInAi" label="Использовать в AI" color="primary" hide-details inset readonly />
      </div>
      <div v-if="projectMode === 'real'" class="expert-drawer__actions"><v-btn variant="tonal" prepend-icon="mdi-download-outline" :loading="downloading" :disabled="downloading" @click="$emit('action', 'download')">{{ downloadLabel }}</v-btn><v-btn color="error" variant="tonal" prepend-icon="mdi-delete-outline" @click="$emit('action', 'delete')">Удалить</v-btn></div>
      <div v-else class="expert-drawer__actions"><v-btn variant="tonal" prepend-icon="mdi-open-in-new" @click="$emit('action', 'Открыть')">Открыть</v-btn><v-btn variant="tonal" prepend-icon="mdi-message-plus-outline" @click="$emit('action', 'Добавить в чат')">В чат</v-btn><v-btn color="primary" variant="flat" prepend-icon="mdi-chat-question-outline" @click="$emit('action', 'Спросить по материалу')">Спросить</v-btn></div>
    </template>
  </v-navigation-drawer>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import type { ExpertMaterialImagePreview } from '../../composables/useExpertMaterialTransfers'
import type { ExpertProjectMaterial, ExpertProjectMode } from '../../types'

const props = defineProps<{
  material: ExpertProjectMaterial | null
  projectMode: ExpertProjectMode
  imagePreview?: ExpertMaterialImagePreview
  downloading?: boolean
  downloadProgress?: number | null
}>()
const open = defineModel<boolean>({ required: true })
defineEmits<{ (event: 'action', action: string): void; (event: 'image-error'): void }>()
const details = computed(() => props.material ? [
  { label: 'Тип', value: props.material.format }, { label: 'Размер', value: props.material.size }, { label: 'Количество страниц', value: props.material.pages ?? '—' },
  { label: 'Категория', value: props.material.category }, { label: 'Статус обработки', value: props.material.status },
] : [])
const downloadLabel = computed(() => !props.downloading ? 'Скачать' : props.downloadProgress === null || props.downloadProgress === undefined ? 'Скачивание…' : `Скачивание ${props.downloadProgress}%`)
</script>

<style scoped>
.expert-material-drawer :deep(.v-navigation-drawer__content) { display: flex; flex-direction: column; }
.expert-drawer__header { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; padding: 20px; }
.expert-drawer__eyebrow { color: rgb(var(--v-theme-primary)); font-size: .67rem; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }
.expert-drawer__header h2 { margin: 5px 0 0; font-size: 1.05rem; }
.expert-material-drawer__preview { display: grid; justify-items: center; gap: 7px; min-height: 180px; margin: 0 20px; padding: 24px 16px; border-radius: var(--md-sys-shape-corner-large); color: rgba(var(--v-theme-on-surface-variant), .78); background: rgb(var(--v-theme-surface-container-low)); }
.expert-material-drawer__preview span { margin-top: 8px; color: rgb(var(--v-theme-on-surface)); font-size: .82rem; font-weight: 750; }
.expert-material-drawer__preview small { font-size: .69rem; text-align: center; }
.expert-material-drawer__image { display: block; width: 100%; max-width: 100%; max-height: 390px; object-fit: contain; border-radius: var(--md-sys-shape-corner-medium); }
.expert-material-drawer__image-error { display: grid; min-height: 132px; align-content: center; justify-items: center; gap: 9px; }
.expert-drawer__content { padding: 22px 20px; }
.expert-drawer__content dl { display: grid; grid-template-columns: 1fr 1fr; gap: 10px 16px; }
.expert-drawer__content dt { color: rgba(var(--v-theme-on-surface-variant), .7); font-size: .72rem; }
.expert-drawer__content dd { margin: 0; font-size: .78rem; font-weight: 650; }
.expert-drawer__actions { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 8px; padding: 14px 20px; border-top: 1px solid rgba(var(--v-theme-outline-variant), .55); }
@media (max-width: 500px) { .expert-material-drawer { width: 100% !important; } .expert-material-drawer__image { max-height: 52dvh; } }
</style>
