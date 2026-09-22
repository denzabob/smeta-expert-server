// @vitest-environment jsdom
import { createApp, nextTick } from 'vue'
import { afterEach, describe, expect, it, vi } from 'vitest'

vi.mock('vuetify/components', () => ({
  VBtn: { template: '<button @click="$emit(\'click\')"><slot /></button>' },
  VIcon: { template: '<i />' },
  VMenu: { template: '<div><slot name="activator" :props="{}" /><slot /></div>' },
  VList: { template: '<ul><slot /></ul>' },
  VListItem: { props: ['title', 'subtitle'], emits: ['click'], template: '<li @click="$emit(\'click\')"><span>{{ title }}</span><span>{{ subtitle }}</span><slot name="append" /></li>' },
  VChip: { template: '<span><slot /></span>' },
  VProgressCircular: { template: '<i />' },
  VSelect: { template: '<div />' },
}))
vi.mock('vuetify/components/VBtn', () => ({ VBtn: { template: '<button @click="$emit(\'click\')"><slot /></button>' } }))
vi.mock('vuetify/components/VIcon', () => ({ VIcon: { template: '<i />' } }))
vi.mock('vuetify/components/VMenu', () => ({ VMenu: { template: '<div><slot name="activator" :props="{}" /><slot /></div>' } }))
vi.mock('vuetify/components/VList', () => ({ VList: { template: '<ul><slot /></ul>' }, VListItem: { template: '<li />' } }))
vi.mock('vuetify/components/VListItem', () => ({ VListItem: { props: ['title', 'subtitle'], emits: ['click'], template: '<li @click="$emit(\'click\')"><span>{{ title }}</span><span>{{ subtitle }}</span><slot name="append" /></li>' } }))
vi.mock('vuetify/components/VChip', () => ({ VChip: { template: '<span><slot /></span>' } }))
vi.mock('vuetify/components/VProgressCircular', () => ({ VProgressCircular: { template: '<i />' } }))
vi.mock('vuetify/components/VSelect', () => ({ VSelect: { template: '<div />' } }))

import ExpertChatComposer from './ExpertChatComposer.vue'

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
