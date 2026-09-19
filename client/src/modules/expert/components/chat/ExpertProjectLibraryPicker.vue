<template>
  <v-card>
    <v-card-title>Библиотека проекта</v-card-title>
    <v-card-text>
      <v-text-field v-model="search" label="Поиск по имени файла" prepend-inner-icon="mdi-magnify" variant="outlined" density="compact" clearable hide-details class="mb-3" />
      <v-chip-group v-model="filter" mandatory selected-class="text-primary" class="mb-3">
        <v-chip v-for="item in filters" :key="item.value" :value="item.value" filter variant="tonal" size="small">{{ item.label }}</v-chip>
      </v-chip-group>
      <div v-if="pagedMaterials.length" class="expert-library__grid">
        <button v-for="material in pagedMaterials" :key="material.id" type="button" class="expert-library__item" :class="{ 'expert-library__item--selected': selectedIds.includes(material.id) }" :aria-pressed="selectedIds.includes(material.id)" :aria-label="`${selectedIds.includes(material.id) ? 'Убрать' : 'Выбрать'}: ${material.name}`" @click="toggle(material.id)">
          <img v-if="material.kind === 'image' && transfers.thumbnailPreviews.value[material.id]?.status === 'ready'" :src="transfers.thumbnailPreviews.value[material.id]?.url" :alt="`Миниатюра: ${material.name}`" />
          <v-icon v-else :icon="material.icon" size="24" />
          <span><strong>{{ material.name }}</strong><small>{{ material.format }} · {{ material.size }}</small></span>
          <v-icon :icon="selectedIds.includes(material.id) ? 'mdi-check-circle' : 'mdi-circle-outline'" size="18" />
        </button>
      </div>
      <p v-else class="expert-library__empty">Материалы не найдены.</p>
      <v-pagination v-if="pageCount > 1" v-model="page" :length="pageCount" density="compact" aria-label="Страницы библиотеки" />
    </v-card-text>
    <v-card-actions><v-spacer /><v-btn @click="$emit('cancel')">Отмена</v-btn><v-btn color="primary" variant="flat" @click="$emit('confirm', selectedIds)">Добавить</v-btn></v-card-actions>
  </v-card>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useExpertMaterialTransfers } from '../../composables/useExpertMaterialTransfers'
import { filterAndPaginateMaterials } from '../../materialPagination'
import type { ExpertMaterialKind, ExpertProjectMaterial } from '../../types'

const props = defineProps<{ materials: ExpertProjectMaterial[]; initiallySelected: string[] }>()
defineEmits<{ (event: 'cancel'): void; (event: 'confirm', ids: string[]): void }>()
const search = ref('')
const filter = ref<'all' | ExpertMaterialKind>('all')
const page = ref(1)
const selectedIds = ref([...props.initiallySelected])
const transfers = useExpertMaterialTransfers()
const filters: { label: string; value: 'all' | ExpertMaterialKind }[] = [
  { label: 'Все', value: 'all' },
  { label: 'Документы', value: 'document' },
  { label: 'Изображения', value: 'image' },
  { label: 'Таблицы', value: 'spreadsheet' },
  { label: 'Прочее', value: 'other' },
]
const materialPage = computed(() => filterAndPaginateMaterials(props.materials, search.value, filter.value, page.value, 25))
const pagedMaterials = computed(() => materialPage.value.items)
const pageCount = computed(() => materialPage.value.pageCount)
watch([search, filter], () => { page.value = 1 })
watch(pagedMaterials, (materials) => {
  for (const material of materials) {
    if (material.kind === 'image') void transfers.loadImageThumbnail(material)
  }
}, { immediate: true })
function toggle(id: string) {
  selectedIds.value = selectedIds.value.includes(id)
    ? selectedIds.value.filter((selected) => selected !== id)
    : [...selectedIds.value, id]
}
onBeforeUnmount(() => transfers.dispose())
</script>

<style scoped>
.expert-library__grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(210px, 1fr)); gap: 8px; max-height: min(55vh, 480px); overflow-y: auto; }
.expert-library__item { display: flex; align-items: center; gap: 8px; min-width: 0; padding: 8px; border: 1px solid rgba(var(--v-theme-outline-variant), .6); border-radius: var(--md-sys-shape-corner-medium); color: rgb(var(--v-theme-on-surface)); background: rgb(var(--v-theme-surface)); text-align: left; cursor: pointer; }
.expert-library__item--selected { border-color: rgb(var(--v-theme-primary)); background: rgb(var(--v-theme-primary-container)); }
.expert-library__item img { width: 38px; height: 38px; border-radius: 4px; object-fit: cover; }
.expert-library__item span { display: grid; min-width: 0; flex: 1; }
.expert-library__item strong { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: .75rem; }
.expert-library__item small { color: rgba(var(--v-theme-on-surface-variant), .75); font-size: .68rem; }
.expert-library__item:focus-visible { outline: 2px solid rgb(var(--v-theme-primary)); outline-offset: 2px; }
.expert-library__empty { padding: 22px; text-align: center; }
</style>
