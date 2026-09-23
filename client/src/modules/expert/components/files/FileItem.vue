<template>
  <article
    class="expert-file-item"
    :class="[
      `expert-file-item--${viewMode}`,
      `expert-file-item--${accent}`,
      { 'expert-file-item--selected': selected, 'expert-file-item--disabled': disabled },
    ]"
    :data-file-item="item.id"
    :data-file-kind="item.kind"
    @contextmenu.prevent.stop="$emit('contextmenu', item, $event)"
  >
    <button
      class="expert-file-item__main"
      type="button"
      role="option"
      :aria-selected="selected"
      :aria-label="`${selected ? 'Выбрано' : 'Выбрать'}: ${item.name}`"
      :aria-disabled="disabled || undefined"
      @click="$emit('select', item, $event)"
      @dblclick.prevent.stop="$emit('open', item)"
      @keydown.space.prevent="$emit('select', item, $event)"
      @keydown.enter.prevent.stop="$emit('open', item)"
    >
      <span class="expert-file-item__visual" :class="`expert-file-item__visual--${accent}`" aria-hidden="true">
        <img
          v-if="item.kind === 'image' && thumbnail?.status === 'ready' && thumbnail.url"
          class="expert-file-item__thumbnail"
          :src="thumbnail.url"
          :alt="''"
          draggable="false"
          @error="$emit('thumbnail-error', item)"
        />
        <span v-else-if="item.kind === 'image' && thumbnail?.status === 'loading'" class="expert-file-item__skeleton" />
        <span v-else class="expert-file-item__file-icon">
          <span class="expert-file-item__mdi-icon mdi" :class="item.icon" aria-hidden="true" />
          <span v-if="item.kind !== 'image'" class="expert-file-item__extension">{{ displayExtension }}</span>
        </span>
      </span>
      <span class="expert-file-item__name" :title="item.name">{{ item.name }}</span>
      <span class="expert-file-item__meta">{{ item.format }}<template v-if="item.size"> · {{ item.size }}</template></span>
      <span v-if="viewMode === 'list'" class="expert-file-item__list-type">{{ item.format || 'Файл' }}</span>
      <span v-if="viewMode === 'list'" class="expert-file-item__list-size">{{ item.size || '—' }}</span>
      <span v-if="viewMode === 'list'" class="expert-file-item__list-date">{{ updatedAtLabel }}</span>
    </button>

    <span v-if="selected" class="expert-file-item__check" aria-hidden="true">
      <svg viewBox="0 0 20 20" focusable="false"><path d="m4.5 10.2 3.4 3.4 7.6-7.7" /></svg>
    </span>

    <button
      v-if="showActions"
      class="expert-file-item__actions"
      type="button"
      :aria-label="`Действия файла: ${item.name}`"
      :title="`Действия файла: ${item.name}`"
      @click.stop="$emit('item-menu', item, $event)"
      @keydown.space.stop
      @keydown.enter.stop
    >
      <svg viewBox="0 0 20 20" aria-hidden="true" focusable="false"><circle cx="10" cy="4" r="1.25" /><circle cx="10" cy="10" r="1.25" /><circle cx="10" cy="16" r="1.25" /></svg>
    </button>
  </article>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { describeProjectMaterial } from '../../materialPresentation'
import type { ExpertProjectMaterial } from '../../types'
import type { ExpertFileViewMode } from '../../fileBrowser'

type ThumbnailState = { status: 'idle' | 'loading' | 'ready' | 'error'; url?: string }

const props = withDefaults(defineProps<{
  item: ExpertProjectMaterial
  viewMode: ExpertFileViewMode
  selected: boolean
  thumbnail?: ThumbnailState
  showActions?: boolean
  disabled?: boolean
}>(), {
  showActions: false,
  disabled: false,
})

defineEmits<{
  (event: 'select', item: ExpertProjectMaterial, source: MouseEvent | KeyboardEvent): void
  (event: 'open', item: ExpertProjectMaterial): void
  (event: 'contextmenu', item: ExpertProjectMaterial, source: MouseEvent): void
  (event: 'item-menu', item: ExpertProjectMaterial, source: MouseEvent): void
  (event: 'thumbnail-error', item: ExpertProjectMaterial): void
}>()

const accent = computed(() => describeProjectMaterial(props.item).accent)
const displayExtension = computed(() => props.item.format || 'ФАЙЛ')
const updatedAtLabel = computed(() => {
  const date = props.item.updatedAt ?? props.item.createdAt
  if (!date) return '—'
  const parsed = new Date(date)
  return Number.isNaN(parsed.getTime()) ? '—' : new Intl.DateTimeFormat('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' }).format(parsed)
})
</script>

<style scoped>
.expert-file-item { position: relative; min-width: 0; color: rgb(var(--v-theme-on-surface)); }
.expert-file-item__main { display: flex; position: relative; width: 100%; min-width: 0; height: 100%; align-items: center; flex-direction: column; gap: 6px; padding: 8px 7px; border: 0; border-radius: var(--md-sys-shape-corner-medium); color: inherit; background: transparent; cursor: pointer; font: inherit; text-align: center; transition: background-color 120ms ease; }
.expert-file-item__main:hover { background: rgba(var(--v-theme-on-surface), .045); }
.expert-file-item__main:focus-visible { outline: 2px solid rgb(var(--v-theme-primary)); outline-offset: 1px; background: rgb(var(--v-theme-surface-container)); }
.expert-file-item--selected .expert-file-item__main { background: rgb(var(--v-theme-secondary-container)); }
.expert-file-item--selected .expert-file-item__main:hover { background: rgb(var(--v-theme-secondary-container)); }
.expert-file-item--disabled .expert-file-item__main { cursor: not-allowed; opacity: .62; }
.expert-file-item__visual { position: relative; display: grid; flex: 0 0 auto; width: 72px; height: 64px; place-items: center; overflow: hidden; border-radius: var(--md-sys-shape-corner-small); }
.expert-file-item--large_tile .expert-file-item__visual { width: 136px; height: 104px; }
.expert-file-item__visual--image { background: rgb(var(--v-theme-surface-container-low)); }
.expert-file-item__visual--pdf { color: rgb(var(--v-theme-error)); }
.expert-file-item__visual--word { color: rgb(var(--v-theme-primary)); }
.expert-file-item__visual--spreadsheet { color: rgb(var(--v-theme-success)); }
.expert-file-item__visual--archive { color: rgb(var(--v-theme-warning)); }
.expert-file-item__visual--neutral { color: rgb(var(--v-theme-on-surface-variant)); }
.expert-file-item__thumbnail { display: block; width: 100%; height: 100%; object-fit: cover; }
.expert-file-item__file-icon { display: grid; position: relative; width: 100%; height: 100%; place-items: center; }
.expert-file-item__mdi-icon { font-size: 38px; }
.expert-file-item--large_tile .expert-file-item__mdi-icon { font-size: 56px; }
.expert-file-item__extension { position: absolute; bottom: 3px; left: 50%; max-width: calc(100% - 10px); overflow: hidden; padding: 1px 4px; border-radius: 3px; color: rgb(var(--v-theme-on-surface-variant)); background: rgb(var(--v-theme-surface-container)); font-size: .52rem; font-weight: 800; line-height: 1.25; text-overflow: ellipsis; transform: translateX(-50%); }
.expert-file-item__skeleton { display: block; width: 100%; height: 100%; background: linear-gradient(100deg, rgba(var(--v-theme-on-surface), .035) 20%, rgba(var(--v-theme-on-surface), .09) 38%, rgba(var(--v-theme-on-surface), .035) 56%); background-size: 220% 100%; animation: expert-file-skeleton 1.25s ease-in-out infinite; }
.expert-file-item__name { display: -webkit-box; width: 100%; max-width: 180px; min-width: 0; overflow: hidden; -webkit-box-orient: vertical; -webkit-line-clamp: 2; overflow-wrap: anywhere; font-size: .75rem; line-height: 1.25; }
.expert-file-item__meta { max-width: 100%; overflow: hidden; color: rgb(var(--v-theme-on-surface-variant)); font-size: .66rem; text-overflow: ellipsis; white-space: nowrap; }
.expert-file-item__check { display: grid; position: absolute; z-index: 2; top: 5px; right: 5px; width: 20px; height: 20px; place-items: center; border-radius: 50%; color: rgb(var(--v-theme-on-secondary-container)); background: rgb(var(--v-theme-secondary-container)); pointer-events: none; }
.expert-file-item__check svg { width: 15px; height: 15px; fill: none; stroke: currentColor; stroke-linecap: round; stroke-linejoin: round; stroke-width: 2; }
.expert-file-item__actions { display: grid; position: absolute; z-index: 3; top: 3px; right: 3px; width: 30px; height: 30px; place-items: center; border: 0; border-radius: 50%; color: rgb(var(--v-theme-on-surface)); background: rgb(var(--v-theme-surface)); box-shadow: var(--ds-shadow-soft); cursor: pointer; opacity: 0; transition: opacity 120ms ease; }
.expert-file-item__actions svg { width: 17px; height: 17px; fill: currentColor; }
.expert-file-item:hover .expert-file-item__actions, .expert-file-item:focus-within .expert-file-item__actions, .expert-file-item--selected .expert-file-item__actions { opacity: 1; }
.expert-file-item--list { display: grid; grid-template-columns: minmax(260px, 1fr) minmax(70px, 120px) minmax(75px, 110px) minmax(90px, 130px); min-height: 48px; align-items: stretch; }
.expert-file-item--list .expert-file-item__main { display: grid; grid-column: 1 / -1; grid-template-columns: minmax(0, 1fr) minmax(70px, 120px) minmax(75px, 110px) minmax(90px, 130px); min-height: 48px; align-items: center; gap: 12px; padding: 5px 42px 5px 10px; border-radius: var(--md-sys-shape-corner-small); text-align: left; }
.expert-file-item--list .expert-file-item__visual { position: absolute; top: 8px; left: 10px; width: 32px; height: 32px; }
.expert-file-item--list .expert-file-item__main { padding-left: 52px; }
.expert-file-item--list .expert-file-item__meta { display: none; }
.expert-file-item--list .expert-file-item__mdi-icon { font-size: 24px; }
.expert-file-item--list .expert-file-item__extension { display: none; }
.expert-file-item--list .expert-file-item__name { display: block; max-width: none; font-size: .8rem; line-height: 1.25; text-overflow: ellipsis; white-space: nowrap; }
.expert-file-item__list-type, .expert-file-item__list-size, .expert-file-item__list-date { min-width: 0; overflow: hidden; color: rgb(var(--v-theme-on-surface-variant)); font-size: .73rem; text-overflow: ellipsis; white-space: nowrap; }
.expert-file-item__list-date { text-align: right; }
.expert-file-item--list .expert-file-item__check { top: 14px; left: 3px; right: auto; }
.expert-file-item--list .expert-file-item__actions { top: 9px; right: 6px; }
.expert-file-item--disabled .expert-file-item__actions { opacity: 0; }
@keyframes expert-file-skeleton { to { background-position-x: -220%; } }
@media (max-width: 760px) {
  .expert-file-item--list { min-height: 58px; }
  .expert-file-item--list .expert-file-item__main { grid-template-columns: minmax(0, 1fr) auto; min-height: 58px; row-gap: 1px; }
  .expert-file-item--list .expert-file-item__list-type, .expert-file-item--list .expert-file-item__list-size, .expert-file-item--list .expert-file-item__list-date { display: none; }
  .expert-file-item--list .expert-file-item__meta { grid-column: 1; grid-row: 2; }
  .expert-file-item--list .expert-file-item__meta { display: block; }
  .expert-file-item--list .expert-file-item__name { grid-column: 1; grid-row: 1; }
  .expert-file-item--list .expert-file-item__visual { top: 13px; }
  .expert-file-item--list .expert-file-item__check { top: 18px; }
}
@media (hover: none) { .expert-file-item__actions { opacity: 1; } }
@media (prefers-reduced-motion: reduce) { .expert-file-item__main, .expert-file-item__actions, .expert-file-item__skeleton { transition: none; animation: none; } }
</style>
