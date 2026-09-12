<template>
  <div class="expert-section-page">
    <div class="expert-section-page__header"><div><span>Материалы</span><h1>Материалы проекта</h1><p>Единый источник документов, изображений и данных для чата и исследования.</p></div><v-btn color="primary" variant="flat" prepend-icon="mdi-plus" @click="notify('Добавление материалов')">Добавить материалы</v-btn></div>
    <div class="expert-materials__controls"><v-text-field v-model="search" prepend-inner-icon="mdi-magnify" placeholder="Поиск по материалам" variant="outlined" density="compact" hide-details clearable /><v-chip-group v-model="filter" mandatory selected-class="text-primary"><v-chip v-for="item in filters" :key="item.value" :value="item.value" filter variant="tonal" size="small">{{ item.label }}</v-chip></v-chip-group></div>
    <div v-if="filteredMaterials.length" class="expert-materials__grid">
      <button v-for="material in filteredMaterials" :key="material.id" class="expert-material-card" type="button" @click="openMaterial(material)">
        <div class="expert-material-card__icon"><v-icon :icon="material.icon" size="28" /></div><div class="expert-material-card__body"><strong>{{ material.name }}</strong><span>{{ material.format }} · {{ material.meta }}</span><small>{{ material.category }}</small></div>
        <v-chip size="x-small" variant="tonal" :color="statusColor(material.status)">{{ material.status }}</v-chip><v-icon icon="mdi-chevron-right" size="18" />
      </button>
    </div>
    <div v-else class="expert-empty"><v-icon icon="mdi-folder-open-outline" size="44" /><h2>Материалы пока не добавлены</h2><p>Загрузите документы, изображения, таблицы и другие материалы исследования.</p><v-btn color="primary" variant="tonal" @click="notify('Добавление материалов')">Добавить материалы</v-btn></div>
    <ExpertMaterialDrawer v-model="drawerOpen" :material="selectedMaterial" @action="notify" />
    <v-snackbar v-model="snackbarOpen">{{ snackbarText }}</v-snackbar>
  </div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import ExpertMaterialDrawer from '../components/materials/ExpertMaterialDrawer.vue'
import type { ExpertMaterialKind, ExpertMaterialStatus, ExpertProject, ExpertProjectMaterial } from '../types'
const props = defineProps<{ project: ExpertProject }>()
const search = ref(''); const filter = ref<'all' | ExpertMaterialKind>('all'); const drawerOpen = ref(false); const selectedMaterial = ref<ExpertProjectMaterial | null>(null); const snackbarOpen = ref(false); const snackbarText = ref('')
const filters: { label: string; value: 'all' | ExpertMaterialKind }[] = [{ label: 'Все', value: 'all' }, { label: 'Документы', value: 'document' }, { label: 'Изображения', value: 'image' }, { label: 'Таблицы', value: 'spreadsheet' }, { label: 'Видео', value: 'video' }, { label: 'Прочее', value: 'other' }]
const filteredMaterials = computed(() => props.project.materials.filter((item) => (filter.value === 'all' || item.kind === filter.value) && item.name.toLowerCase().includes(search.value.trim().toLowerCase())))
function openMaterial(material: ExpertProjectMaterial) { selectedMaterial.value = material; drawerOpen.value = true }
function notify(action: string) { snackbarText.value = `${action}: функция будет подключена на следующем этапе`; snackbarOpen.value = true }
function statusColor(status: ExpertMaterialStatus) { return status === 'Ошибка' ? 'error' : status === 'Обрабатывается' ? 'warning' : 'success' }
</script>

<style scoped>
.expert-section-page { padding: 26px; }
.expert-section-page__header { display: flex; align-items: flex-end; justify-content: space-between; gap: 20px; margin-bottom: 20px; }
.expert-section-page__header > div > span { color: rgb(var(--v-theme-primary)); font-size: .68rem; font-weight: 800; letter-spacing: .09em; text-transform: uppercase; }
.expert-section-page h1 { margin: 4px 0; font-size: 1.55rem; }
.expert-section-page__header p { margin: 0; color: rgba(var(--v-theme-on-surface-variant), .75); font-size: .8rem; }
.expert-materials__controls { display: grid; grid-template-columns: minmax(240px, 380px) minmax(0, 1fr); align-items: center; gap: 18px; margin-bottom: 16px; }
.expert-materials__grid { display: grid; gap: 9px; }
.expert-material-card { display: grid; grid-template-columns: auto minmax(0, 1fr) auto auto; align-items: center; gap: 13px; width: 100%; min-height: 72px; padding: 11px 14px; border: 1px solid rgba(var(--v-theme-outline-variant), .62); border-radius: var(--md-sys-shape-corner-large); color: rgb(var(--v-theme-on-surface)); background: rgb(var(--v-theme-surface)); cursor: pointer; text-align: left; font: inherit; }
.expert-material-card:hover { border-color: rgba(var(--v-theme-primary), .45); background: rgba(var(--v-theme-primary), .035); }
.expert-material-card__icon { display: grid; place-items: center; width: 46px; height: 46px; border-radius: var(--md-sys-shape-corner-medium); color: rgb(var(--v-theme-primary)); background: rgba(var(--v-theme-primary), .09); }
.expert-material-card__body strong, .expert-material-card__body span, .expert-material-card__body small { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.expert-material-card__body strong { font-size: .84rem; }.expert-material-card__body span { margin-top: 3px; color: rgba(var(--v-theme-on-surface-variant), .76); font-size: .71rem; }.expert-material-card__body small { margin-top: 2px; color: rgba(var(--v-theme-on-surface-variant), .58); font-size: .66rem; }
.expert-empty { display: grid; justify-items: center; padding: 64px 20px; text-align: center; color: rgba(var(--v-theme-on-surface-variant), .75); }.expert-empty h2 { margin: 14px 0 4px; color: rgb(var(--v-theme-on-surface)); font-size: 1.05rem; }.expert-empty p { max-width: 440px; margin: 0 0 18px; font-size: .8rem; }
@media (max-width: 760px) { .expert-section-page { padding: 18px 13px 76px; } .expert-section-page__header { align-items: stretch; flex-direction: column; } .expert-materials__controls { grid-template-columns: 1fr; gap: 8px; } .expert-materials__controls :deep(.v-slide-group) { max-width: calc(100vw - 60px); } .expert-material-card { grid-template-columns: auto minmax(0, 1fr) auto; } .expert-material-card > .v-chip { display: none; } }
</style>
