<template>
  <div class="expert-file-browser" :class="`expert-file-browser--${mode}`">
    <div class="expert-file-browser__toolbar">
      <label class="expert-file-browser__search">
        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.8" cy="10.8" r="6.7" /><path d="m16 16 4.2 4.2" /></svg>
        <input v-model="search" type="search" :placeholder="mode === 'picker' ? 'Поиск по имени файла' : 'Поиск по материалам'" aria-label="Поиск по материалам" />
      </label>
      <div class="expert-file-browser__filters" role="group" aria-label="Фильтр типов файлов">
        <button v-for="item in filters" :key="item.value" type="button" :aria-pressed="filter === item.value" :class="{ 'is-active': filter === item.value }" @click="filter = item.value">{{ item.label }}</button>
      </div>
      <div class="expert-file-browser__controls">
        <div class="expert-file-browser__menu-wrap">
          <button class="expert-file-browser__control" type="button" aria-haspopup="menu" :aria-expanded="sortMenuOpen" @click="toggleSortMenu">
            <span class="expert-file-browser__sort-arrow" aria-hidden="true">{{ sortDirection === 'asc' ? '↑' : '↓' }}</span>
            {{ sortButtonLabel }}
            <svg class="expert-file-browser__chevron" viewBox="0 0 20 20" aria-hidden="true"><path d="m5 7.5 5 5 5-5" /></svg>
          </button>
          <div v-if="sortMenuOpen" class="expert-file-browser__menu" role="menu" aria-label="Сортировка">
            <button v-for="option in sortOptions" :key="option.value" type="button" role="menuitemradio" :aria-checked="sortBy === option.value" @click="chooseSort(option.value)">
              <span>{{ option.label }}</span><span v-if="sortBy === option.value" class="expert-file-browser__check" aria-hidden="true">✓</span>
            </button>
            <hr />
            <button v-for="option in directionOptions" :key="option.value" type="button" role="menuitemradio" :aria-checked="sortDirection === option.value" @click="chooseDirection(option.value)">
              <span>{{ option.label }}</span><span v-if="sortDirection === option.value" class="expert-file-browser__check" aria-hidden="true">✓</span>
            </button>
          </div>
        </div>
        <div class="expert-file-browser__menu-wrap">
          <button class="expert-file-browser__control expert-file-browser__view-trigger" type="button" aria-haspopup="menu" :aria-expanded="viewMenuOpen" aria-label="Вид" @click="toggleViewMenu">
            <ViewIcon :mode="viewMode" />
            <span class="expert-file-browser__view-label">{{ viewLabel }}</span>
            <svg class="expert-file-browser__chevron" viewBox="0 0 20 20" aria-hidden="true"><path d="m5 7.5 5 5 5-5" /></svg>
          </button>
          <div v-if="viewMenuOpen" class="expert-file-browser__menu" role="menu" aria-label="Вид">
            <button v-for="option in viewOptions" :key="option.value" type="button" role="menuitemradio" :aria-checked="viewMode === option.value" :class="{ 'is-active': viewMode === option.value }" @click="chooseView(option.value)">
              <ViewIcon :mode="option.value" /><span>{{ option.label }}</span><span v-if="viewMode === option.value" class="expert-file-browser__check" aria-hidden="true">✓</span>
            </button>
          </div>
        </div>
        <label v-if="mode === 'library'" class="expert-file-browser__page-size">
          <span class="sr-only">Количество материалов на странице</span>
          <select v-model.number="pageSize" aria-label="Количество материалов на странице"><option v-for="size in pageSizeOptions" :key="size" :value="size">{{ size }}</option></select>
        </label>
      </div>
    </div>

    <div v-if="selectedIds.length" class="expert-file-browser__selection-bar" aria-live="polite">
      <span>Выбрано: {{ selectedIds.length }}</span>
      <span v-if="mode === 'picker' && selectionLimits?.maxImages">Изображения: {{ selectedImageCount }} из {{ selectionLimits.maxImages }}</span>
      <button type="button" @click="clearSelection">Снять выделение</button>
    </div>
    <p v-if="selectionNotice" class="expert-file-browser__notice" role="status">{{ selectionNotice }}</p>

    <FileViewport
      v-model:selected-ids="selectedIds"
      :items="materialPage.items"
      :selection-items="items"
      :view-mode="viewMode"
      :mode="mode"
      :thumbnails="thumbnails"
      :selection-limits="selectionLimits"
      :can-delete="canDelete"
      @open="$emit('open', $event)"
      @download="$emit('download', $event)"
      @delete="$emit('delete', $event)"
      @thumbnail-needed="$emit('thumbnail-needed', $event)"
      @thumbnail-error="$emit('thumbnail-error', $event)"
      @selection-blocked="selectionNotice = $event"
    >
      <template #empty><slot name="empty"><p class="expert-file-browser__empty">Материалы не найдены.</p></slot></template>
    </FileViewport>

    <div v-if="pageCount > 1" class="expert-file-browser__pagination" aria-label="Страницы материалов">
      <button type="button" aria-label="Предыдущая страница" :disabled="page <= 1" @click="page--">‹</button>
      <span>Страница {{ page }} из {{ pageCount }}</span>
      <button type="button" aria-label="Следующая страница" :disabled="page >= pageCount" @click="page++">›</button>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, defineComponent, h, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import FileViewport from './FileViewport.vue'
import { filterSortAndPaginateMaterials, readExpertFileBrowserPreferences, writeExpertFileBrowserPreferences, type ExpertFileBrowserMode, type ExpertFileSortBy, type ExpertFileSortDirection, type ExpertFileViewMode, type ExpertMaterialSelectionLimits } from '../../fileBrowser'
import type { ExpertMaterialKind, ExpertProjectMaterial } from '../../types'

const props = withDefaults(defineProps<{
  items: ExpertProjectMaterial[]
  mode: ExpertFileBrowserMode
  storageKey: string
  selectedIds: string[]
  thumbnails?: Record<string, { status: 'idle' | 'loading' | 'ready' | 'error'; url?: string }>
  selectionLimits?: ExpertMaterialSelectionLimits
  canDelete?: boolean
  pageSizeOptions?: number[]
}>(), {
  canDelete: false,
  pageSizeOptions: () => [25, 50, 100],
})

const emit = defineEmits<{
  (event: 'update:selectedIds', ids: string[]): void
  (event: 'open', item: ExpertProjectMaterial): void
  (event: 'download', item: ExpertProjectMaterial): void
  (event: 'delete', item: ExpertProjectMaterial): void
  (event: 'thumbnail-needed', item: ExpertProjectMaterial): void
  (event: 'thumbnail-error', item: ExpertProjectMaterial): void
}>()

const filters: { label: string; value: 'all' | ExpertMaterialKind }[] = [
  { label: 'Все', value: 'all' }, { label: 'Документы', value: 'document' },
  { label: 'Изображения', value: 'image' }, { label: 'Таблицы', value: 'spreadsheet' }, { label: 'Прочее', value: 'other' },
]
const sortOptions: { label: string; value: ExpertFileSortBy }[] = [
  { label: 'Название', value: 'name' }, { label: 'Размер', value: 'size' }, { label: 'Тип', value: 'type' }, { label: 'Дата изменения', value: 'updated_at' },
]
const directionOptions: { label: string; value: ExpertFileSortDirection }[] = [
  { label: 'По возрастанию', value: 'asc' }, { label: 'По убыванию', value: 'desc' },
]
const viewOptions: { label: string; value: ExpertFileViewMode }[] = [
  { label: 'Крупная плитка', value: 'large_tile' }, { label: 'Плитка', value: 'tile' }, { label: 'Список', value: 'list' },
]
const storage = browserStorage()
const initialPreferences = readExpertFileBrowserPreferences(storage, props.storageKey, props.storageKey === 'expert.materials' ? 'expert.materials.view' : undefined)
const search = ref('')
const filter = ref<'all' | ExpertMaterialKind>('all')
const viewMode = ref<ExpertFileViewMode>(initialPreferences.viewMode)
const sortBy = ref<ExpertFileSortBy>(initialPreferences.sortBy)
const sortDirection = ref<ExpertFileSortDirection>(initialPreferences.sortDirection)
const pageSize = ref(readPageSize(props.storageKey, props.pageSizeOptions))
const page = ref(1)
const selectedIds = computed({
  get: () => props.selectedIds,
  set: (ids: string[]) => emit('update:selectedIds', ids),
})
const viewMenuOpen = ref(false)
const sortMenuOpen = ref(false)
const selectionNotice = ref('')
const materialPage = computed(() => filterSortAndPaginateMaterials(props.items, search.value, filter.value, sortBy.value, sortDirection.value, page.value, pageSize.value))
const pageCount = computed(() => materialPage.value.pageCount)
const selectedImageCount = computed(() => props.items.filter((item) => item.kind === 'image' && props.selectedIds.includes(item.id)).length)
const sortButtonLabel = computed(() => sortOptions.find((item) => item.value === sortBy.value)?.label ?? 'Название')
const viewLabel = computed(() => viewOptions.find((item) => item.value === viewMode.value)?.label ?? 'Плитка')

const ViewIcon = defineComponent({
  props: { mode: { type: String, required: true } },
  setup(iconProps) {
    return () => h('svg', { viewBox: '0 0 24 24', 'aria-hidden': 'true', class: 'expert-file-view-icon' }, iconProps.mode === 'large_tile'
      ? [h('rect', { x: 3, y: 3, width: 8, height: 8, rx: 1 }), h('rect', { x: 13, y: 3, width: 8, height: 8, rx: 1 }), h('rect', { x: 3, y: 13, width: 8, height: 8, rx: 1 }), h('rect', { x: 13, y: 13, width: 8, height: 8, rx: 1 })]
      : iconProps.mode === 'tile'
        ? [3, 10, 17].flatMap((y) => [3, 10, 17].map((x) => h('rect', { x, y, width: 4, height: 4, rx: .6 })))
        : [4, 10, 16].flatMap((y) => [h('path', { d: `M8 ${y}h13` }), h('circle', { cx: 4, cy: y, r: 1 })]))
  },
})

watch([search, filter, pageSize, sortBy, sortDirection], () => { page.value = 1 })
watch(() => props.selectedIds.join('|'), () => { selectionNotice.value = '' })
watch(() => props.items.map((item) => item.id).join('|'), (newIds, oldIds) => {
  const valid = new Set(props.items.map((item) => item.id))
  const retained = props.selectedIds.filter((id) => valid.has(id))
  if (retained.length !== props.selectedIds.length) emit('update:selectedIds', retained)
  if (oldIds !== undefined && props.items.some((item) => !oldIds.split('|').includes(item.id))) page.value = 1
  else page.value = Math.min(page.value, pageCount.value)
})
watch([viewMode, sortBy, sortDirection], () => {
  writeExpertFileBrowserPreferences(storage, props.storageKey, { viewMode: viewMode.value, sortBy: sortBy.value, sortDirection: sortDirection.value })
})
watch(pageSize, (value) => {
  try { storage.setItem(`${props.storageKey}.pageSize`, String(value)) } catch { /* Optional preference. */ }
})
onMounted(() => document.addEventListener('pointerdown', dismissMenus))
onBeforeUnmount(() => document.removeEventListener('pointerdown', dismissMenus))

function browserStorage(): Pick<Storage, 'getItem' | 'setItem'> {
  try { return window.localStorage } catch { return { getItem: () => null, setItem: () => undefined } }
}

function readPageSize(prefix: string, options: number[]): number {
  try {
    const value = Number(storage.getItem(`${prefix}.pageSize`))
    return options.includes(value) ? value : 25
  } catch { return 25 }
}

function chooseView(value: ExpertFileViewMode) { viewMode.value = value; viewMenuOpen.value = false }
function chooseSort(value: ExpertFileSortBy) { sortBy.value = value; sortMenuOpen.value = false; selectionNotice.value = '' }
function chooseDirection(value: ExpertFileSortDirection) { sortDirection.value = value; sortMenuOpen.value = false; selectionNotice.value = '' }
function toggleViewMenu() { viewMenuOpen.value = !viewMenuOpen.value; sortMenuOpen.value = false }
function toggleSortMenu() { sortMenuOpen.value = !sortMenuOpen.value; viewMenuOpen.value = false }
function clearSelection() { emit('update:selectedIds', []); selectionNotice.value = '' }
function dismissMenus(event: PointerEvent) {
  if (!(event.target instanceof Element) || !event.target.closest('.expert-file-browser__menu-wrap')) {
    viewMenuOpen.value = false
    sortMenuOpen.value = false
  }
}
</script>

<style>
.expert-file-view-icon { width: 20px; height: 20px; fill: currentColor; stroke: currentColor; stroke-width: 1.5; }
.expert-file-browser { min-width: 0; }
.expert-file-browser__toolbar { display: grid; grid-template-columns: minmax(230px, 1fr) auto; align-items: center; gap: 10px 14px; margin-bottom: 12px; }
.expert-file-browser__search { display: flex; min-width: 180px; height: 38px; align-items: center; gap: 9px; padding: 0 11px; border: 1px solid rgba(var(--v-theme-outline-variant), .8); border-radius: var(--md-sys-shape-corner-medium); color: rgb(var(--v-theme-on-surface-variant)); background: rgb(var(--v-theme-surface)); }
.expert-file-browser__search:focus-within { border-color: rgb(var(--v-theme-primary)); outline: 1px solid rgb(var(--v-theme-primary)); }
.expert-file-browser__search svg { width: 18px; height: 18px; fill: none; stroke: currentColor; stroke-width: 1.7; }
.expert-file-browser__search input { width: 100%; min-width: 0; border: 0; outline: 0; color: rgb(var(--v-theme-on-surface)); background: transparent; font: inherit; font-size: .82rem; }
.expert-file-browser__filters { display: flex; grid-column: 1; flex-wrap: wrap; gap: 5px; }
.expert-file-browser__filters button, .expert-file-browser__control, .expert-file-browser__page-size select { min-height: 34px; border: 1px solid rgba(var(--v-theme-outline-variant), .72); border-radius: var(--md-sys-shape-corner-small); color: rgb(var(--v-theme-on-surface)); background: rgb(var(--v-theme-surface)); cursor: pointer; font: inherit; font-size: .74rem; }
.expert-file-browser__filters button { padding: 5px 10px; }
.expert-file-browser__filters button:hover, .expert-file-browser__control:hover { background: rgb(var(--v-theme-surface-container-low)); }
.expert-file-browser__filters button.is-active { border-color: rgba(var(--v-theme-primary), .28); color: rgb(var(--v-theme-primary)); background: rgb(var(--v-theme-primary-container)); }
.expert-file-browser__controls { display: flex; grid-column: 2; grid-row: 1 / span 2; align-items: center; justify-content: flex-end; gap: 7px; }
.expert-file-browser__menu-wrap { position: relative; }
.expert-file-browser__control { display: inline-flex; min-width: 42px; align-items: center; justify-content: center; gap: 7px; padding: 5px 9px; white-space: nowrap; }
.expert-file-browser__sort-arrow { color: rgb(var(--v-theme-primary)); font-size: 1rem; font-weight: 800; }
.expert-file-browser__chevron { width: 16px; height: 16px; fill: none; stroke: currentColor; stroke-linecap: round; stroke-linejoin: round; stroke-width: 1.7; }
.expert-file-browser__view-trigger { min-width: 46px; }
.expert-file-browser__view-trigger .expert-file-view-icon { width: 20px; height: 20px; }
.expert-file-browser__page-size select { padding: 4px 7px; }
.expert-file-browser__menu { display: grid; position: absolute; z-index: 30; top: calc(100% + 5px); right: 0; min-width: 194px; padding: 5px; border: 1px solid rgba(var(--v-theme-outline-variant), .8); border-radius: var(--md-sys-shape-corner-medium); background: rgb(var(--v-theme-surface-container)); box-shadow: var(--ds-shadow-soft); }
.expert-file-browser__menu button { display: flex; min-height: 36px; align-items: center; gap: 9px; padding: 6px 9px; border: 0; border-radius: var(--md-sys-shape-corner-small); color: rgb(var(--v-theme-on-surface)); background: transparent; cursor: pointer; font: inherit; font-size: .78rem; text-align: left; }
.expert-file-browser__menu button:hover, .expert-file-browser__menu button:focus-visible, .expert-file-browser__menu button.is-active { outline: none; background: rgb(var(--v-theme-surface-container-high)); }
.expert-file-browser__menu hr { width: 100%; margin: 4px 0; border: 0; border-top: 1px solid rgba(var(--v-theme-outline-variant), .6); }
.expert-file-browser__check { margin-left: auto; color: rgb(var(--v-theme-primary)); font-weight: 800; }
.expert-file-browser__selection-bar { display: flex; align-items: center; gap: 14px; margin: 0 0 8px; color: rgb(var(--v-theme-on-surface-variant)); font-size: .74rem; }
.expert-file-browser__selection-bar button { margin-left: auto; padding: 4px 7px; border: 0; border-radius: var(--md-sys-shape-corner-small); color: rgb(var(--v-theme-primary)); background: transparent; cursor: pointer; font: inherit; }
.expert-file-browser__notice { margin: 0 0 7px; color: rgb(var(--v-theme-error)); font-size: .75rem; }
.expert-file-browser__empty { padding: 30px 14px; color: rgb(var(--v-theme-on-surface-variant)); text-align: center; }
.expert-file-browser__pagination { display: flex; align-items: center; justify-content: center; gap: 13px; margin-top: 10px; color: rgb(var(--v-theme-on-surface-variant)); font-size: .75rem; }
.expert-file-browser__pagination button { width: 34px; height: 32px; border: 1px solid rgba(var(--v-theme-outline-variant), .7); border-radius: var(--md-sys-shape-corner-small); color: rgb(var(--v-theme-on-surface)); background: rgb(var(--v-theme-surface)); cursor: pointer; font-size: 1.1rem; }
.expert-file-browser__pagination button:disabled { cursor: default; opacity: .45; }
.sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; }
@media (max-width: 800px) { .expert-file-browser__toolbar { grid-template-columns: 1fr; } .expert-file-browser__controls { grid-column: 1; grid-row: auto; justify-content: flex-start; flex-wrap: wrap; } }
@media (max-width: 540px) { .expert-file-browser__filters { flex-wrap: nowrap; overflow-x: auto; padding-bottom: 3px; } .expert-file-browser__filters button { white-space: nowrap; } .expert-file-browser__selection-bar { flex-wrap: wrap; } .expert-file-browser__selection-bar button { margin-left: 0; } .expert-file-browser__view-label { display: none; } }
</style>
