// @vitest-environment jsdom
import { createApp, nextTick } from 'vue'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { ExpertProjectMaterial } from '../../types'

const card = vi.hoisted(() => ({ template: '<div><slot /></div>' }))
vi.mock('vuetify/components', () => ({
  VCard: card, VCardTitle: card, VCardText: card, VCardActions: card, VSpacer: card,
  VTextField: { props: ['modelValue'], template: '<input aria-label="Поиск по имени файла" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />' },
  VChipGroup: { template: '<div @click="$emit(\'update:modelValue\', $event.target.value)"><slot /></div>' },
  VChip: { props: ['value'], template: '<button :value="value"><slot /></button>' },
  VIcon: { template: '<i />' },
  VPagination: { props: ['modelValue'], template: '<button aria-label="Следующая страница" @click="$emit(\'update:modelValue\', modelValue + 1)">Далее</button>' },
  VBtn: { template: '<button @click="$emit(\'click\')"><slot /></button>' },
}))
vi.mock('vuetify/components/VCard', () => ({ VCard: card, VCardTitle: card, VCardText: card, VCardActions: card }))
vi.mock('vuetify/components/VCardTitle', () => ({ VCardTitle: card }))
vi.mock('vuetify/components/VCardText', () => ({ VCardText: card }))
vi.mock('vuetify/components/VCardActions', () => ({ VCardActions: card }))
vi.mock('vuetify/components/VSpacer', () => ({ VSpacer: card }))
vi.mock('vuetify/components/VGrid', () => ({ VSpacer: card }))
vi.mock('vuetify/components/VTextField', () => ({ VTextField: { props: ['modelValue'], template: '<input aria-label="Поиск по имени файла" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />' } }))
vi.mock('vuetify/components/VChipGroup', () => ({ VChipGroup: { template: '<div @click="$emit(\'update:modelValue\', $event.target.value)"><slot /></div>' } }))
vi.mock('vuetify/components/VChip', () => ({ VChip: { props: ['value'], template: '<button :value="value"><slot /></button>' } }))
vi.mock('vuetify/components/VIcon', () => ({ VIcon: { template: '<i />' } }))
vi.mock('vuetify/components/VPagination', () => ({ VPagination: { props: ['modelValue'], template: '<button aria-label="Следующая страница" @click="$emit(\'update:modelValue\', modelValue + 1)">Далее</button>' } }))
vi.mock('vuetify/components/VBtn', () => ({ VBtn: { template: '<button @click="$emit(\'click\')"><slot /></button>' } }))

import ExpertProjectLibraryPicker from './ExpertProjectLibraryPicker.vue'

const material = (id: string, name: string, kind: 'document' | 'image' = 'document'): ExpertProjectMaterial => ({
  id, name, kind, format: kind === 'image' ? 'JPG' : 'PDF', meta: kind === 'image' ? 'image/jpeg' : 'application/pdf',
  size: '1 КБ', category: kind, status: 'Загружен', useInAi: false, icon: 'mdi-file-outline',
})

afterEach(() => { document.body.innerHTML = '' })

describe('Project library picker', () => {
  it('keeps initial selection, filters before pagination and commits only on Add', async () => {
    const materials = Array.from({ length: 27 }, (_, index) => material(`m-${index}`, `Документ ${index}.pdf`))
    materials.push(material('image-1', 'Особое фото.jpg', 'image'))
    const confirm = vi.fn()
    const cancel = vi.fn()
    const root = document.createElement('div')
    document.body.append(root)
    const app = createApp(ExpertProjectLibraryPicker, { materials, initiallySelected: ['m-0'], onConfirm: confirm, onCancel: cancel })
    app.mount(root)

    expect(root.querySelectorAll('.expert-library__item')).toHaveLength(25)
    expect(root.querySelector('[aria-label="Убрать: Документ 0.pdf"]')).not.toBeNull()
    ;(root.querySelector('[aria-label="Страницы библиотеки"]') as HTMLButtonElement).click()
    await nextTick()
    expect(root.querySelectorAll('.expert-library__item')).toHaveLength(3)

    const search = root.querySelector('input[aria-label="Поиск по имени файла"]') as HTMLInputElement
    search.value = 'Особое фото'
    search.dispatchEvent(new Event('input', { bubbles: true }))
    await nextTick()
    expect(root.querySelectorAll('.expert-library__item')).toHaveLength(1)
    ;(root.querySelector('[aria-label="Выбрать: Особое фото.jpg"]') as HTMLButtonElement).click()
    await nextTick()
    expect(confirm).not.toHaveBeenCalled()
    ;(Array.from(root.querySelectorAll('button')).find((button) => button.textContent === 'Добавить') as HTMLButtonElement).click()
    expect(confirm).toHaveBeenCalledWith(['m-0', 'image-1'])
    expect(cancel).not.toHaveBeenCalled()
    app.unmount()
  })

  it('filters by material kind and cancels without confirming changes', async () => {
    const confirm = vi.fn()
    const cancel = vi.fn()
    const root = document.createElement('div')
    document.body.append(root)
    const app = createApp(ExpertProjectLibraryPicker, {
      materials: [material('doc-1', 'Акт.pdf'), material('image-1', 'Фото.jpg', 'image')],
      initiallySelected: ['doc-1'], onConfirm: confirm, onCancel: cancel,
    })
    app.mount(root)
    ;(Array.from(root.querySelectorAll('button')).find((button) => button.textContent === 'Изображения') as HTMLButtonElement).click()
    await nextTick()
    expect(root.querySelectorAll('.expert-library__item')).toHaveLength(1)
    expect(root.textContent).toContain('Фото.jpg')
    ;(root.querySelector('[aria-label="Выбрать: Фото.jpg"]') as HTMLButtonElement).click()
    ;(Array.from(root.querySelectorAll('button')).find((button) => button.textContent === 'Отмена') as HTMLButtonElement).click()
    expect(cancel).toHaveBeenCalled()
    expect(confirm).not.toHaveBeenCalled()
    app.unmount()
  })
})
