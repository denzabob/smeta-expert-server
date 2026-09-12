<template>
  <v-navigation-drawer v-model="open" temporary location="right" width="460" class="expert-material-drawer">
    <template v-if="material">
      <div class="expert-drawer__header"><div><span class="expert-drawer__eyebrow">Материал проекта</span><h2>{{ material.name }}</h2></div><v-btn icon="mdi-close" variant="text" @click="open = false" /></div>
      <div class="expert-material-drawer__preview"><v-icon :icon="material.icon" size="58" /><span>Предпросмотр {{ material.format }}</span><small>Будет подключён на этапе обработки документов</small></div>
      <div class="expert-drawer__content">
        <dl><template v-for="item in details" :key="item.label"><dt>{{ item.label }}</dt><dd>{{ item.value }}</dd></template></dl>
        <v-switch :model-value="material.useInAi" label="Использовать в AI" color="primary" hide-details inset readonly />
      </div>
      <div class="expert-drawer__actions"><v-btn variant="tonal" prepend-icon="mdi-open-in-new" @click="$emit('action', 'Открыть')">Открыть</v-btn><v-btn variant="tonal" prepend-icon="mdi-message-plus-outline" @click="$emit('action', 'Добавить в чат')">В чат</v-btn><v-btn color="primary" variant="flat" prepend-icon="mdi-chat-question-outline" @click="$emit('action', 'Спросить по материалу')">Спросить</v-btn></div>
    </template>
  </v-navigation-drawer>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import type { ExpertProjectMaterial } from '../../types'
const props = defineProps<{ material: ExpertProjectMaterial | null }>()
const open = defineModel<boolean>({ required: true })
defineEmits<{ (event: 'action', action: string): void }>()
const details = computed(() => props.material ? [
  { label: 'Тип', value: props.material.format }, { label: 'Размер', value: props.material.size }, { label: 'Количество страниц', value: props.material.pages ?? '—' },
  { label: 'Категория', value: props.material.category }, { label: 'Статус обработки', value: props.material.status },
] : [])
</script>

<style scoped>
.expert-material-drawer :deep(.v-navigation-drawer__content) { display: flex; flex-direction: column; }
.expert-drawer__header { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; padding: 20px; }
.expert-drawer__eyebrow { color: rgb(var(--v-theme-primary)); font-size: .67rem; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }
.expert-drawer__header h2 { margin: 5px 0 0; font-size: 1.05rem; }
.expert-material-drawer__preview { display: grid; justify-items: center; gap: 7px; margin: 0 20px; padding: 40px 16px; border-radius: var(--md-sys-shape-corner-large); color: rgba(var(--v-theme-on-surface-variant), .78); background: rgb(var(--v-theme-surface-container-low)); }
.expert-material-drawer__preview span { margin-top: 8px; color: rgb(var(--v-theme-on-surface)); font-size: .82rem; font-weight: 750; }
.expert-material-drawer__preview small { font-size: .69rem; text-align: center; }
.expert-drawer__content { padding: 22px 20px; }
.expert-drawer__content dl { display: grid; grid-template-columns: 1fr 1fr; gap: 10px 16px; }
.expert-drawer__content dt { color: rgba(var(--v-theme-on-surface-variant), .7); font-size: .72rem; }
.expert-drawer__content dd { margin: 0; font-size: .78rem; font-weight: 650; }
.expert-drawer__actions { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 8px; padding: 14px 20px; border-top: 1px solid rgba(var(--v-theme-outline-variant), .55); }
@media (max-width: 500px) { .expert-material-drawer { width: 100% !important; } }
</style>
