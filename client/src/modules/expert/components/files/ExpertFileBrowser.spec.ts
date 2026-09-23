// @vitest-environment jsdom
import { createApp, h, nextTick, ref } from 'vue'
import { afterEach, describe, expect, it } from 'vitest'
import ExpertFileBrowser from './ExpertFileBrowser.vue'
import type { ExpertProjectMaterial } from '../../types'

function material(id: string, name: string): ExpertProjectMaterial {
  return {
    id, name, kind: 'document', format: 'PDF', meta: 'application/pdf', size: '1 КБ',
    category: 'document', status: 'Загружен', useInAi: false, icon: 'mdi-file-pdf-box', sizeBytes: 1024,
  }
}

afterEach(() => { document.body.innerHTML = ''; window.localStorage.clear() })

describe('Expert file browser', () => {
  it('switches among the three views and supports modifier and keyboard selection', async () => {
    const root = document.createElement('div')
    document.body.append(root)
    const items = [material('a', 'Alpha.pdf'), material('b', 'Bravo.pdf'), material('c', 'Charlie.pdf'), material('d', 'Delta.pdf')]
    const app = createApp({
      setup() {
        const selectedIds = ref<string[]>([])
        return () => h(ExpertFileBrowser, {
          items, mode: 'library', storageKey: 'test.materials', selectedIds: selectedIds.value,
          'onUpdate:selectedIds': (ids: string[]) => { selectedIds.value = ids },
        })
      },
    })
    app.mount(root)

    expect(root.querySelector('[data-file-item="a"]')?.classList.contains('expert-file-item--tile')).toBe(true)
    ;(root.querySelector('[aria-label="Вид"]') as HTMLButtonElement).click()
    await nextTick()
    expect(root.querySelectorAll('[role="menuitemradio"]')).toHaveLength(3)
    ;(Array.from(root.querySelectorAll('[role="menuitemradio"]')).find((item) => item.textContent?.includes('Список')) as HTMLButtonElement).click()
    await nextTick()
    expect(root.querySelector('[data-file-item="a"]')?.classList.contains('expert-file-item--list')).toBe(true)
    expect(window.localStorage.getItem('test.materials.viewMode')).toBe('list')

    ;(root.querySelector('[data-file-item="a"] .expert-file-item__main') as HTMLButtonElement).click()
    ;(root.querySelector('[data-file-item="c"] .expert-file-item__main') as HTMLButtonElement).dispatchEvent(new MouseEvent('click', { bubbles: true, ctrlKey: true }))
    await nextTick()
    expect(root.querySelector('[data-file-item="c"] .expert-file-item__main')?.getAttribute('aria-selected')).toBe('true')
    ;(root.querySelector('[data-file-item="b"] .expert-file-item__main') as HTMLButtonElement).dispatchEvent(new MouseEvent('click', { bubbles: true, shiftKey: true }))
    await nextTick()
    expect(root.querySelector('[data-file-item="a"] .expert-file-item__main')?.getAttribute('aria-selected')).toBe('false')
    expect(root.querySelector('[data-file-item="b"] .expert-file-item__main')?.getAttribute('aria-selected')).toBe('true')
    expect(root.querySelector('[data-file-item="c"] .expert-file-item__main')?.getAttribute('aria-selected')).toBe('true')

    const viewport = root.querySelector('[data-file-viewport]') as HTMLElement
    viewport.dispatchEvent(new KeyboardEvent('keydown', { bubbles: true, key: 'a', ctrlKey: true }))
    await nextTick()
    expect(root.querySelectorAll('[data-file-item] .expert-file-item__main[aria-selected="true"]')).toHaveLength(4)
    app.unmount()
  })

  it('selects files with a mouse marquee and exposes the RMB menu', async () => {
    const root = document.createElement('div')
    document.body.append(root)
    const items = [material('a', 'Alpha.pdf'), material('b', 'Bravo.pdf')]
    const app = createApp({
      setup() {
        const selectedIds = ref<string[]>([])
        return () => h(ExpertFileBrowser, {
          items, mode: 'library', storageKey: 'test.marquee', selectedIds: selectedIds.value, canDelete: true,
          'onUpdate:selectedIds': (ids: string[]) => { selectedIds.value = ids },
        })
      },
    })
    app.mount(root)

    const firstItem = root.querySelector('[data-file-item="a"]') as HTMLElement
    firstItem.getBoundingClientRect = () => new DOMRect(10, 10, 20, 20)
    const secondItem = root.querySelector('[data-file-item="b"]') as HTMLElement
    secondItem.getBoundingClientRect = () => new DOMRect(80, 80, 20, 20)
    const viewport = root.querySelector('[data-file-viewport]') as HTMLElement
    const dispatchPointer = (target: HTMLElement, type: string, x: number, y: number) => {
      const event = new MouseEvent(type, { bubbles: true, cancelable: true, button: 0, clientX: x, clientY: y })
      Object.defineProperties(event, { pointerId: { value: 7 }, pointerType: { value: 'mouse' } })
      target.dispatchEvent(event)
    }
    dispatchPointer(viewport, 'pointerdown', 1, 1)
    dispatchPointer(viewport, 'pointermove', 25, 25)
    dispatchPointer(viewport, 'pointerup', 25, 25)
    await nextTick()
    expect(root.querySelector('[data-file-item="a"] .expert-file-item__main')?.getAttribute('aria-selected')).toBe('true')
    expect(root.querySelector('[data-file-item="b"] .expert-file-item__main')?.getAttribute('aria-selected')).toBe('false')

    const itemButton = root.querySelector('[data-file-item="a"] .expert-file-item__main') as HTMLElement
    const contextEvent = new MouseEvent('contextmenu', { bubbles: true, cancelable: true, button: 2, clientX: 60, clientY: 60 })
    itemButton.dispatchEvent(contextEvent)
    await nextTick()
    expect(root.querySelector('[role="menu"]')?.textContent).toContain('Просмотреть')
    expect(root.querySelector('[role="menu"]')?.textContent).toContain('Удалить')
    app.unmount()
  })
})
