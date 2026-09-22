// @vitest-environment jsdom
import { createApp, nextTick } from 'vue'
import { afterEach, describe, expect, it, vi } from 'vitest'

vi.mock('vuetify/components', () => ({
  VBtn: { inheritAttrs: false, template: '<button v-bind="$attrs"><slot /></button>' },
  VIcon: { props: ['icon'], template: '<i :data-icon="icon">{{ icon }}</i>' },
  VMenu: { props: { modelValue: { type: Boolean, default: undefined } }, emits: ['update:modelValue'], template: '<div><slot name="activator" :props="{ onClick: () => $emit(\'update:modelValue\', true) }" /><div v-if="modelValue !== false"><slot /></div></div>' },
  VList: { template: '<ul><slot /></ul>' },
  VListItem: { props: ['title', 'subtitle', 'active'], emits: ['click'], template: '<li :class="{ \'v-list-item--active\': active }" @click="$emit(\'click\')"><span class="v-list-item-title">{{ title }}</span><span class="v-list-item-subtitle">{{ subtitle }}</span><slot name="append" /></li>' },
  VChip: { template: '<span><slot /></span>' },
  VProgressCircular: { template: '<i />' },
  VSelect: { template: '<div />' },
}))
vi.mock('vuetify/components/VBtn', () => ({ VBtn: { inheritAttrs: false, template: '<button v-bind="$attrs"><slot /></button>' } }))
vi.mock('vuetify/components/VIcon', () => ({ VIcon: { props: ['icon'], template: '<i :data-icon="icon">{{ icon }}</i>' } }))
vi.mock('vuetify/components/VMenu', () => ({ VMenu: { props: { modelValue: { type: Boolean, default: undefined } }, emits: ['update:modelValue'], template: '<div><slot name="activator" :props="{ onClick: () => $emit(\'update:modelValue\', true) }" /><div v-if="modelValue !== false"><slot /></div></div>' } }))
vi.mock('vuetify/components/VList', () => ({ VList: { template: '<ul><slot /></ul>' }, VListItem: { template: '<li />' } }))
vi.mock('vuetify/components/VListItem', () => ({ VListItem: { props: ['title', 'subtitle', 'active'], emits: ['click'], template: '<li :class="{ \'v-list-item--active\': active }" @click="$emit(\'click\')"><span class="v-list-item-title">{{ title }}</span><span class="v-list-item-subtitle">{{ subtitle }}</span><slot name="append" /></li>' } }))
vi.mock('vuetify/components/VChip', () => ({ VChip: { template: '<span><slot /></span>' } }))
vi.mock('vuetify/components/VProgressCircular', () => ({ VProgressCircular: { template: '<i />' } }))
vi.mock('vuetify/components/VSelect', () => ({ VSelect: { template: '<div />' } }))

import ExpertChatComposer from './ExpertChatComposer.vue'
import type { ExpertChatMode } from '../../types'

const modeMenuStub = {
  props: { modelValue: { type: Boolean, default: undefined } },
  emits: ['update:modelValue'],
  template: '<div><slot name="activator" :props="{ onClick: () => $emit(\'update:modelValue\', true) }" /><div v-if="modelValue !== false"><slot /></div></div>',
}
const modeButtonStub = { inheritAttrs: false, template: '<button v-bind="$attrs"><slot /></button>' }
const modeIconStub = { props: ['icon'], template: '<i :data-icon="icon">{{ icon }}</i>' }
const modeListStub = { template: '<ul><slot /></ul>' }
const modeListItemStub = {
  props: ['title', 'subtitle', 'active'],
  emits: ['click'],
  template: '<li :class="{ \'v-list-item--active\': active }" @click="$emit(\'click\')"><span class="v-list-item-title">{{ title }}</span><span class="v-list-item-subtitle">{{ subtitle }}</span><slot name="append" /></li>',
}

function mountModeComposer(mode: ExpertChatMode = 'auto', onModeChange?: (mode: ExpertChatMode) => void) {
  const root = document.createElement('div')
  document.body.append(root)
  const app = createApp(ExpertChatComposer, { contextChips: [], mode, onModeChange })
  app.component('v-menu', modeMenuStub)
  app.component('VMenu', modeMenuStub)
  app.component('v-btn', modeButtonStub)
  app.component('VBtn', modeButtonStub)
  app.component('v-icon', modeIconStub)
  app.component('VIcon', modeIconStub)
  app.component('v-list', modeListStub)
  app.component('VList', modeListStub)
  app.component('v-list-item', modeListItemStub)
  app.component('VListItem', modeListItemStub)
  app.mount(root)
  return { root, app }
}

afterEach(() => { document.body.innerHTML = '' })

describe('Expert Chat composer local file drop', () => {
  it('uses a compact mode pill instead of a select', async () => {
    const root = document.createElement('div')
    document.body.append(root)
    const app = createApp(ExpertChatComposer, { contextChips: [], mode: 'auto' })
    app.mount(root)
    await nextTick()

    expect(root.querySelector('select')).toBeNull()
    expect(root.textContent).toContain('Auto')
    app.unmount()
  })

  it('opens the compact mode menu with all options and one active check', async () => {
    const { root, app } = mountModeComposer()

    expect(root.querySelector('.expert-composer__mode-menu')).toBeNull()
    ;(root.querySelector('.expert-composer__mode-trigger') as HTMLButtonElement).click()
    await nextTick()
    const menu = root.querySelector('.expert-composer__mode-menu') as HTMLElement
    expect(menu).not.toBeNull()
    const options = Array.from(menu.querySelectorAll('li[role="option"]'))
    expect(options.map((item) => item.getAttribute('title'))).toEqual(['Быстро', 'Auto', 'Глубокий'])
    expect(options.map((item) => item.getAttribute('subtitle'))).toEqual([
      'Для быстрых вопросов и поиска фактов',
      'Prism сама выберет подходящий режим',
      'Для сложного анализа и сопоставления материалов',
    ])
    expect(options.filter((item) => item.getAttribute('aria-selected') === 'true')).toHaveLength(1)
    expect(options.filter((item) => item.getAttribute('append-icon') === 'mdi-check')).toHaveLength(1)
    expect(options.find((item) => item.getAttribute('aria-selected') === 'true')?.getAttribute('title')).toBe('Auto')
    expect(menu.textContent).not.toContain('…')
    expect(menu.textContent).not.toContain('...')
    app.unmount()
  })

  it.each(['fast', 'deep'] as const)('emits explicit %s when selected from the mode menu', async (mode) => {
    const modeChange = vi.fn()
    const { root, app } = mountModeComposer('auto', modeChange)

    ;(root.querySelector('.expert-composer__mode-trigger') as HTMLButtonElement).click()
    await nextTick()
    const options = root.querySelectorAll('li[role="option"]')
    ;(options[mode === 'fast' ? 0 : 2] as HTMLElement).click()
    await nextTick()

    expect(modeChange).toHaveBeenCalledWith(mode)
    expect(root.querySelector('.expert-composer__mode-menu')).toBeNull()
    app.unmount()
  })

  it('sends a batch through the same attach-files event and clears the nested drag overlay', async () => {
    const attachFiles = vi.fn()
    const root = document.createElement('div')
    document.body.append(root)
    const app = createApp(ExpertChatComposer, { contextChips: [], allowFileUpload: true, onAttachFiles: attachFiles })
    app.mount(root)
    const box = root.querySelector('.expert-composer__box') as HTMLElement
    const files = [new File(['one'], 'one.pdf'), new File(['two'], 'two.jpg')]
    const dataTransfer = { types: ['Files'], items: files.map(() => ({ kind: 'file', webkitGetAsEntry: () => ({ isDirectory: false }) })), files, dropEffect: '' }
    const dispatch = (type: string) => {
      const event = new Event(type, { bubbles: true, cancelable: true })
      Object.defineProperty(event, 'dataTransfer', { value: dataTransfer })
      box.dispatchEvent(event)
    }

    dispatch('dragenter')
    dispatch('dragenter')
    dispatch('dragleave')
    await nextTick()
    expect(root.textContent).toContain('Перетащите файлы сюда')
    dispatch('drop')
    await nextTick()
    expect(attachFiles).toHaveBeenCalledWith(files)
    expect(root.textContent).not.toContain('Перетащите файлы сюда')
    app.unmount()
  })

  it('reports a dropped directory without passing it to upload', async () => {
    const attachFiles = vi.fn()
    const root = document.createElement('div')
    document.body.append(root)
    const app = createApp(ExpertChatComposer, { contextChips: [], allowFileUpload: true, onAttachFiles: attachFiles })
    app.mount(root)
    const box = root.querySelector('.expert-composer__box') as HTMLElement
    const dataTransfer = { types: ['Files'], items: [{ kind: 'file', webkitGetAsEntry: () => ({ isDirectory: true }) }], files: [new File(['x'], 'folder')], dropEffect: '' }
    const event = new Event('drop', { bubbles: true, cancelable: true })
    Object.defineProperty(event, 'dataTransfer', { value: dataTransfer })
    box.dispatchEvent(event)
    await nextTick()
    expect(root.textContent).toContain('Папки нельзя загрузить')
    expect(attachFiles).not.toHaveBeenCalled()
    app.unmount()
  })
})
