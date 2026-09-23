// @vitest-environment jsdom
import { createApp, nextTick } from 'vue'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { ExpertProjectMaterial } from '../../types'

const card = vi.hoisted(() => ({ template: '<div><slot /></div>' }))
vi.mock('vuetify/components', () => ({
  VCard: card, VCardTitle: card, VCardText: card, VCardActions: card, VSpacer: card,
  VBtn: { props: ['disabled'], template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>' },
}))
vi.mock('vuetify/components/VCard', () => ({ VCard: card, VCardTitle: card, VCardText: card, VCardActions: card }))
vi.mock('vuetify/components/VCardTitle', () => ({ VCardTitle: card }))
vi.mock('vuetify/components/VCardText', () => ({ VCardText: card }))
vi.mock('vuetify/components/VCardActions', () => ({ VCardActions: card }))
vi.mock('vuetify/components/VSpacer', () => ({ VSpacer: card }))
vi.mock('vuetify/components/VBtn', () => ({ VBtn: { props: ['disabled'], template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>' } }))
vi.mock('vuetify/components/VGrid', () => ({ VSpacer: card }))

import ExpertProjectLibraryPicker from './ExpertProjectLibraryPicker.vue'

const limits = { maxMaterials: 5, maxImages: 2 }
const material = (id: string, name: string, kind: 'document' | 'image' = 'document'): ExpertProjectMaterial => ({
  id, name, kind, format: kind === 'image' ? 'JPG' : 'PDF', meta: kind === 'image' ? 'image/jpeg' : 'application/pdf',
  size: '1 КБ', category: kind, status: 'Загружен', useInAi: false, icon: 'mdi-file-outline',
})

afterEach(() => { document.body.innerHTML = ''; window.localStorage.clear() })

describe('Project library picker', () => {
  it('keeps selection across pages and filters and commits only on Add N', async () => {
    const materials = Array.from({ length: 27 }, (_, index) => material(`m-${index}`, `Документ ${index}.pdf`))
    materials.push(material('image-1', 'Особое фото.jpg', 'image'))
    const confirm = vi.fn()
    const cancel = vi.fn()
    const root = document.createElement('div')
    document.body.append(root)
    const app = createApp(ExpertProjectLibraryPicker, {
      materials, initiallySelected: ['m-0'], selectionLimits: limits, onConfirm: confirm, onCancel: cancel,
    })
    app.mount(root)

    expect(root.querySelectorAll('[data-file-item]')).toHaveLength(25)
    expect(root.querySelector('[aria-label="Выбрано: Документ 0.pdf"]')).not.toBeNull()
    ;(root.querySelector('[aria-label="Следующая страница"]') as HTMLButtonElement).click()
    await nextTick()
    expect(root.querySelectorAll('[data-file-item]')).toHaveLength(3)
    ;(root.querySelector('[aria-label="Выбрать: Документ 25.pdf"]') as HTMLButtonElement).click()
    await nextTick()

    const search = root.querySelector('input[aria-label="Поиск по материалам"]') as HTMLInputElement
    search.value = 'Особое фото'
    search.dispatchEvent(new Event('input', { bubbles: true }))
    await nextTick()
    expect(root.querySelectorAll('[data-file-item]')).toHaveLength(1)
    ;(root.querySelector('[aria-label="Выбрать: Особое фото.jpg"]') as HTMLButtonElement).click()
    await nextTick()
    expect(root.querySelector('[data-file-item="m-0"]')).toBeNull()
    expect(root.querySelector('.expert-file-browser__selection-bar')?.textContent).toContain('Выбрано: 3')
    expect(root.textContent).not.toContain('Удалить')
    expect(root.textContent).not.toContain('Скачать')
    expect(confirm).not.toHaveBeenCalled()

    ;(Array.from(root.querySelectorAll('button')).find((button) => button.textContent?.includes('Добавить 3')) as HTMLButtonElement).click()
    expect(confirm).toHaveBeenCalledWith(['m-0', 'm-25', 'image-1'])
    expect(cancel).not.toHaveBeenCalled()
    app.unmount()
  })

  it('uses the same filters and prevents a selection beyond configured limits', async () => {
    const confirm = vi.fn()
    const root = document.createElement('div')
    document.body.append(root)
    const app = createApp(ExpertProjectLibraryPicker, {
      materials: [material('doc-1', 'Акт.pdf'), material('image-1', 'Фото.jpg', 'image')],
      initiallySelected: ['doc-1'], selectionLimits: { maxMaterials: 1, maxImages: 1 }, onConfirm: confirm,
    })
    app.mount(root)
    ;(Array.from(root.querySelectorAll('button')).find((button) => button.textContent?.trim() === 'Изображения') as HTMLButtonElement).click()
    await nextTick()
    expect(root.querySelectorAll('[data-file-item]')).toHaveLength(1)
    ;(root.querySelector('[aria-label="Выбрать: Фото.jpg"]') as HTMLButtonElement).click()
    await nextTick()
    expect(root.querySelector('[role="status"]')?.textContent).toContain('не более 1 материалов')
    expect(root.textContent).toContain('Добавить 1')
    expect(confirm).not.toHaveBeenCalled()
    app.unmount()
  })
})
