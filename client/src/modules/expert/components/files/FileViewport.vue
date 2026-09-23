<template>
  <section
    ref="viewport"
    class="expert-file-viewport"
    :class="`expert-file-viewport--${viewMode}`"
    role="listbox"
    aria-multiselectable="true"
    aria-label="Файлы проекта"
    tabindex="0"
    data-file-viewport
    @keydown="handleKeydown"
    @pointerdown="handlePointerDown"
    @pointermove="handlePointerMove"
    @pointerup="finishPointer"
    @pointercancel="finishPointer"
  >
    <div v-if="viewMode === 'list'" class="expert-file-viewport__list-header" aria-hidden="true">
      <span>Название</span><span>Тип</span><span>Размер</span><span>Дата изменения</span>
    </div>
    <div v-if="items.length" class="expert-file-viewport__items" :class="`expert-file-viewport__items--${viewMode}`">
      <FileItem
        v-for="item in items"
        :key="item.id"
        :item="item"
        :view-mode="viewMode"
        :selected="selectedSet.has(item.id)"
        :thumbnail="thumbnails?.[item.id]"
        :show-actions="mode === 'library'"
        :disabled="isSelectionDisabled(item)"
        @select="selectItem"
        @open="$emit('open', $event)"
        @contextmenu="showItemMenu"
        @item-menu="showItemMenu"
        @thumbnail-error="$emit('thumbnail-error', $event)"
      />
    </div>
    <slot v-else name="empty"><p class="expert-file-viewport__empty">Материалы не найдены.</p></slot>

    <div v-if="selectionRectangle" class="expert-file-viewport__selection-rectangle" :style="rectangleStyle" aria-hidden="true" />

    <div v-if="contextMenu" ref="menuElement" class="expert-file-viewport__context-menu" role="menu" :style="menuStyle" @pointerdown.stop @click.stop>
      <div v-if="contextSelectionIds.length > 1" class="expert-file-viewport__context-count">Выбрано: {{ contextSelectionIds.length }}</div>
      <template v-if="mode === 'library' && contextSelectionIds.length === 1">
        <button type="button" role="menuitem" @click="runContextAction('open')">Просмотреть</button>
        <button type="button" role="menuitem" @click="runContextAction('download')">Скачать</button>
        <button v-if="canDelete" class="expert-file-viewport__context-danger" type="button" role="menuitem" @click="runContextAction('delete')">Удалить</button>
      </template>
      <button v-else-if="mode === 'library'" type="button" role="menuitem" @click="clearSelection">Снять выделение</button>
      <template v-else>
        <button type="button" role="menuitem" :disabled="contextItemDisabled" @click="toggleContextItem">{{ contextSelectionIds.includes(contextMenu.item.id) ? 'Убрать из выбора' : 'Выбрать' }}</button>
        <button v-if="contextSelectionIds.length" type="button" role="menuitem" @click="clearSelection">Снять выделение</button>
      </template>
    </div>
  </section>
</template>

<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import FileItem from './FileItem.vue'
import { canAddMaterialToSelection, selectionWithinMaterialLimits, type ExpertFileBrowserMode, type ExpertFileViewMode, type ExpertMaterialSelectionLimits } from '../../fileBrowser'
import { idsIntersectingRectangle, selectExpertFiles } from '../../fileSelection'
import type { ExpertProjectMaterial } from '../../types'

type ThumbnailState = { status: 'idle' | 'loading' | 'ready' | 'error'; url?: string }
type ContextAction = 'open' | 'download' | 'delete'
type PointerStart = { x: number; y: number; id: number; additive: boolean; initialIds: string[]; started: boolean }

const props = withDefaults(defineProps<{
  items: ExpertProjectMaterial[]
  selectionItems: ExpertProjectMaterial[]
  selectedIds: string[]
  viewMode: ExpertFileViewMode
  mode: ExpertFileBrowserMode
  thumbnails?: Record<string, ThumbnailState>
  selectionLimits?: ExpertMaterialSelectionLimits
  canDelete?: boolean
}>(), { canDelete: false })

const emit = defineEmits<{
  (event: 'update:selectedIds', ids: string[]): void
  (event: 'open', item: ExpertProjectMaterial): void
  (event: 'download', item: ExpertProjectMaterial): void
  (event: 'delete', item: ExpertProjectMaterial): void
  (event: 'thumbnail-needed', item: ExpertProjectMaterial): void
  (event: 'thumbnail-error', item: ExpertProjectMaterial): void
  (event: 'selection-blocked', message: string): void
}>()

const viewport = ref<HTMLElement | null>(null)
const menuElement = ref<HTMLElement | null>(null)
const selectionRectangle = ref<{ left: number; top: number; width: number; height: number } | null>(null)
const contextMenu = ref<{ item: ExpertProjectMaterial; x: number; y: number } | null>(null)
const contextSelectionIds = ref<string[]>([])
const contextItemDisabled = ref(false)
const menuPosition = ref({ left: 0, top: 0 })
const anchorId = ref<string | null>(null)
const pointerStart = ref<PointerStart | null>(null)
const selectedSet = computed(() => new Set(props.selectedIds))
const itemById = computed(() => new Map(props.selectionItems.map((item) => [item.id, item])))
const rectangleStyle = computed(() => selectionRectangle.value ? {
  left: `${selectionRectangle.value.left}px`, top: `${selectionRectangle.value.top}px`,
  width: `${selectionRectangle.value.width}px`, height: `${selectionRectangle.value.height}px`,
} : undefined)
const menuStyle = computed(() => ({ left: `${menuPosition.value.left}px`, top: `${menuPosition.value.top}px` }))

let thumbnailObserver: IntersectionObserver | null = null

onMounted(() => {
  if (typeof IntersectionObserver !== 'undefined') {
    thumbnailObserver = new IntersectionObserver((entries) => {
      for (const entry of entries) {
        if (!entry.isIntersecting) continue
        const id = (entry.target as HTMLElement).dataset.fileItem
        const item = id ? props.items.find((candidate) => candidate.id === id) : undefined
        if (item?.kind === 'image') emit('thumbnail-needed', item)
        thumbnailObserver?.unobserve(entry.target)
      }
    }, { root: viewport.value, rootMargin: '140px' })
    observeThumbnails()
  } else {
    notifyVisibleImages()
  }
  document.addEventListener('pointerdown', dismissMenu)
})

watch(() => props.items.map((item) => item.id).join('|'), async () => {
  await nextTick()
  observeThumbnails()
  if (!thumbnailObserver) notifyVisibleImages()
})

onBeforeUnmount(() => {
  thumbnailObserver?.disconnect()
  document.removeEventListener('pointerdown', dismissMenu)
})

function observeThumbnails() {
  if (!thumbnailObserver || !viewport.value) return
  viewport.value.querySelectorAll<HTMLElement>('[data-file-kind="image"][data-file-item]').forEach((element) => thumbnailObserver?.observe(element))
}

function notifyVisibleImages() {
  for (const item of props.items) if (item.kind === 'image') emit('thumbnail-needed', item)
}

function selectItem(item: ExpertProjectMaterial, source: MouseEvent | KeyboardEvent) {
  if (props.mode === 'picker' && 'detail' in source && source.detail > 1) return
  const result = selectExpertFiles({
    selectedIds: props.selectedIds,
    visibleIds: props.items.map((candidate) => candidate.id),
    targetId: item.id,
    anchorId: anchorId.value,
    mode: props.mode,
    ctrlKey: source.ctrlKey,
    metaKey: source.metaKey,
    shiftKey: source.shiftKey,
  })
  if (!selectionAllowed(result.selectedIds)) return
  anchorId.value = result.anchorId
  emit('update:selectedIds', result.selectedIds)
  contextMenu.value = null
}

function isSelectionDisabled(item: ExpertProjectMaterial): boolean {
  return props.mode === 'picker'
    && Boolean(props.selectionLimits)
    && !props.selectedIds.includes(item.id)
    && !canAddMaterialToSelection(props.selectedIds, item, props.selectionLimits!, itemById.value)
}

function selectionAllowed(ids: string[]): boolean {
  if (props.mode !== 'picker' || !props.selectionLimits || selectionWithinMaterialLimits(ids, props.selectionLimits, itemById.value)) return true
  const selected = ids.map((id) => itemById.value.get(id)).filter((item): item is ExpertProjectMaterial => Boolean(item))
  emit('selection-blocked', props.selectionLimits.maxMaterials > 0 && selected.length > props.selectionLimits.maxMaterials
    ? `Нельзя добавить материал: максимум ${props.selectionLimits.maxMaterials}.`
    : `Нельзя добавить изображение: максимум ${props.selectionLimits.maxImages}.`)
  return false
}

function handleKeydown(event: KeyboardEvent) {
  if (isEditableTarget(event.target)) return
  if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'a') {
    event.preventDefault()
    const ids = [...new Set([...props.selectedIds, ...props.items.map((item) => item.id)])]
    if (selectionAllowed(ids)) emit('update:selectedIds', ids)
  } else if (event.key === 'Escape') {
    event.preventDefault()
    if (contextMenu.value) contextMenu.value = null
    else clearSelection()
  }
}

function handlePointerDown(event: PointerEvent) {
  if (event.button !== 0 || event.pointerType === 'touch' || !viewport.value || !isBlankTarget(event.target)) return
  event.preventDefault()
  pointerStart.value = {
    x: event.clientX, y: event.clientY, id: event.pointerId,
    additive: event.ctrlKey || event.metaKey,
    initialIds: [...props.selectedIds], started: false,
  }
  try { viewport.value.setPointerCapture(event.pointerId) } catch { /* Not available in all test DOMs. */ }
}

function handlePointerMove(event: PointerEvent) {
  const start = pointerStart.value
  const root = viewport.value
  if (!start || !root || start.id !== event.pointerId) return
  if (!start.started && Math.hypot(event.clientX - start.x, event.clientY - start.y) < 5) return
  start.started = true
  const left = Math.min(start.x, event.clientX)
  const right = Math.max(start.x, event.clientX)
  const top = Math.min(start.y, event.clientY)
  const bottom = Math.max(start.y, event.clientY)
  selectionRectangle.value = { left, top, width: right - left, height: bottom - top }
  const hits = idsIntersectingRectangle(
    [...root.querySelectorAll<HTMLElement>('[data-file-item]')].map((element) => ({
      id: element.dataset.fileItem ?? '', bounds: element.getBoundingClientRect(),
    })),
    { left, right, top, bottom },
  ).filter(Boolean)
  const ids = start.additive ? [...new Set([...start.initialIds, ...hits])] : hits
  if (selectionAllowed(ids)) emit('update:selectedIds', ids)
}

function finishPointer(event: PointerEvent) {
  const start = pointerStart.value
  if (!start || start.id !== event.pointerId) return
  if (!start.started && !start.additive) emit('update:selectedIds', [])
  try { viewport.value?.releasePointerCapture(event.pointerId) } catch { /* Not available in all test DOMs. */ }
  pointerStart.value = null
  selectionRectangle.value = null
}

async function showItemMenu(item: ExpertProjectMaterial, event: MouseEvent) {
  const selected = props.selectedIds.includes(item.id)
  let ids = selected ? [...props.selectedIds] : [item.id]
  if (!selected && selectionAllowed(ids)) emit('update:selectedIds', ids)
  else if (!selected) ids = [...props.selectedIds]
  contextSelectionIds.value = ids
  contextItemDisabled.value = props.mode === 'picker' && !ids.includes(item.id) && isSelectionDisabled(item)
  const bounds = (event.currentTarget as HTMLElement | null)?.getBoundingClientRect()
  const x = event.clientX || bounds?.left || 0
  const y = event.clientY || bounds?.bottom || 0
  contextMenu.value = { item, x, y }
  menuPosition.value = { left: x, top: y }
  await nextTick()
  const menu = menuElement.value
  if (!menu) return
  const margin = 8
  menuPosition.value = {
    left: x + menu.offsetWidth > window.innerWidth - margin ? Math.max(margin, x - menu.offsetWidth) : x,
    top: y + menu.offsetHeight > window.innerHeight - margin ? Math.max(margin, y - menu.offsetHeight) : y,
  }
}

function runContextAction(action: 'open' | 'download' | 'delete') {
  const item = contextMenu.value?.item
  if (!item) return
  contextMenu.value = null
  if (action === 'open') emit('open', item)
  else if (action === 'download') emit('download', item)
  else emit('delete', item)
}

function toggleContextItem() {
  const item = contextMenu.value?.item
  if (!item || contextItemDisabled.value) return
  const ids = new Set(props.selectedIds)
  ids.has(item.id) ? ids.delete(item.id) : ids.add(item.id)
  if (selectionAllowed([...ids])) emit('update:selectedIds', [...ids])
  contextMenu.value = null
}

function clearSelection() {
  anchorId.value = null
  emit('update:selectedIds', [])
  contextMenu.value = null
}

function dismissMenu(event: PointerEvent) {
  if (contextMenu.value && !menuElement.value?.contains(event.target as Node)) contextMenu.value = null
}

function isBlankTarget(target: EventTarget | null): boolean {
  if (!(target instanceof Element)) return false
  return !target.closest('[data-file-item], button, input, textarea, select, [contenteditable="true"], [data-file-control], .expert-file-viewport__list-header')
}

function isEditableTarget(target: EventTarget | null): boolean {
  return target instanceof HTMLElement && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName))
}
</script>

<style scoped>
.expert-file-viewport { position: relative; min-height: 72px; outline: none; user-select: none; }
.expert-file-viewport:focus-visible { outline: 2px solid rgba(var(--v-theme-primary), .55); outline-offset: 3px; border-radius: var(--md-sys-shape-corner-small); }
.expert-file-viewport__items { display: grid; grid-template-columns: repeat(auto-fill, minmax(112px, 1fr)); align-items: start; column-gap: 16px; row-gap: 22px; padding: 5px; }
.expert-file-viewport__items--tile :deep(.expert-file-item) { width: 100%; max-width: 132px; justify-self: center; }
.expert-file-viewport__items--large_tile { grid-template-columns: repeat(auto-fill, minmax(155px, 1fr)); column-gap: 20px; row-gap: 28px; }
.expert-file-viewport__items--large_tile :deep(.expert-file-item) { width: 100%; max-width: 180px; justify-self: center; }
.expert-file-viewport__items--list { grid-template-columns: minmax(0, 1fr); gap: 1px; padding: 0; }
.expert-file-viewport__list-header { display: grid; grid-template-columns: minmax(260px, 1fr) minmax(70px, 120px) minmax(75px, 110px) minmax(90px, 130px); gap: 12px; padding: 7px 14px 7px 52px; border-bottom: 1px solid rgba(var(--v-theme-outline-variant), .65); color: rgb(var(--v-theme-on-surface-variant)); font-size: .68rem; font-weight: 700; }
.expert-file-viewport__list-header span:last-child { text-align: right; }
.expert-file-viewport__empty { padding: 30px 14px; color: rgb(var(--v-theme-on-surface-variant)); text-align: center; }
.expert-file-viewport__selection-rectangle { position: fixed; z-index: 20; border: 1px solid rgb(var(--v-theme-primary)); background: rgba(var(--v-theme-primary), .1); pointer-events: none; }
.expert-file-viewport__context-menu { display: grid; position: fixed; z-index: 1000; min-width: 176px; max-width: min(260px, calc(100vw - 16px)); padding: 5px; border: 1px solid rgba(var(--v-theme-outline-variant), .8); border-radius: var(--md-sys-shape-corner-medium); background: rgb(var(--v-theme-surface-container)); box-shadow: var(--ds-shadow-soft); }
.expert-file-viewport__context-menu button { min-height: 36px; padding: 7px 10px; border: 0; border-radius: var(--md-sys-shape-corner-small); color: rgb(var(--v-theme-on-surface)); background: transparent; cursor: pointer; font: inherit; text-align: left; }
.expert-file-viewport__context-menu button:hover, .expert-file-viewport__context-menu button:focus-visible { outline: none; background: rgba(var(--v-theme-on-surface), .08); }
.expert-file-viewport__context-menu button:disabled { cursor: not-allowed; opacity: .55; }
.expert-file-viewport__context-menu .expert-file-viewport__context-danger { color: rgb(var(--v-theme-error)); }
.expert-file-viewport__context-count { padding: 7px 10px; color: rgb(var(--v-theme-on-surface-variant)); font-size: .72rem; font-weight: 700; }
@media (max-width: 760px) { .expert-file-viewport__list-header { display: none; } .expert-file-item--large_tile .expert-file-item__visual { width: min(136px, 100%); } }
</style>
