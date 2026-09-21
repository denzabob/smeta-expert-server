// @vitest-environment jsdom
import { createApp, nextTick, ref } from 'vue'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { ExpertConversation, ExpertProject } from '../types'

const state = vi.hoisted(() => ({
  conversations: [] as ExpertConversation[],
  updateConversation: vi.fn(),
  deleteConversation: vi.fn(),
}))

vi.mock('vuetify', () => ({ useDisplay: () => ({ mdAndDown: ref(false) }) }))
vi.mock('vuetify/components', () => ({
  VBtn: { inheritAttrs: true, props: ['disabled', 'loading'], emits: ['click'], template: '<button v-bind="$attrs" :disabled="disabled || loading" @click="$emit(\'click\', $event)"><slot /></button>' },
  VIcon: { template: '<i />' },
  VMenu: { template: '<div><slot name="activator" :props="{}" /><slot /></div>' },
  VList: { template: '<div><slot /></div>' },
  VListItem: { props: ['title'], emits: ['click'], template: '<div v-bind="$attrs" :data-title="title" @click="$emit(\'click\', $event)"><slot name="title"><span>{{ title }}</span></slot><slot name="append" /></div>' },
  VListSubheader: { template: '<div><slot /></div>' },
  VDivider: { template: '<hr />' },
  VProgressLinear: { template: '<div />' },
  VAlert: { template: '<div><slot /><slot name="append" /></div>' },
  VDialog: { props: ['modelValue'], template: '<div v-if="modelValue"><slot /></div>' },
  VCard: { template: '<div><slot /></div>' },
  VCardTitle: { template: '<div><slot /></div>' },
  VCardText: { template: '<div><slot /></div>' },
  VCardActions: { template: '<div><slot /></div>' },
  VTextField: { props: ['modelValue', 'label'], emits: ['update:modelValue'], template: '<input :aria-label="label" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />' },
  VSpacer: { template: '<span />' },
  VTooltip: { template: '<div><slot name="activator" :props="{}" /></div>' },
  VNavigationDrawer: { template: '<div><slot /></div>' },
  VSnackbar: { template: '<div><slot /></div>' },
}))
vi.mock('vuetify/components/VBtn', () => ({ VBtn: { inheritAttrs: true, props: ['disabled', 'loading'], emits: ['click'], template: '<button v-bind="$attrs" :disabled="disabled || loading" @click="$emit(\'click\', $event)"><slot /></button>' } }))
vi.mock('vuetify/components/VIcon', () => ({ VIcon: { template: '<i />' } }))
vi.mock('vuetify/components/VMenu', () => ({ VMenu: { template: '<div><slot name="activator" :props="{}" /><slot /></div>' } }))
vi.mock('vuetify/components/VList', () => ({
  VList: { template: '<div><slot /></div>' },
  VListItem: { props: ['title'], emits: ['click'], template: '<div v-bind="$attrs" :data-title="title" @click="$emit(\'click\', $event)"><slot name="title"><span>{{ title }}</span></slot><slot name="append" /></div>' },
  VListSubheader: { template: '<div><slot /></div>' },
}))
vi.mock('vuetify/components/VListItem', () => ({ VListItem: { props: ['title'], emits: ['click'], template: '<div v-bind="$attrs" :data-title="title" @click="$emit(\'click\', $event)"><slot name="title"><span>{{ title }}</span></slot><slot name="append" /></div>' } }))
vi.mock('vuetify/components/VListSubheader', () => ({ VListSubheader: { template: '<div><slot /></div>' } }))
vi.mock('vuetify/components/VDivider', () => ({ VDivider: { template: '<hr />' } }))
vi.mock('vuetify/components/VProgressLinear', () => ({ VProgressLinear: { template: '<div />' } }))
vi.mock('vuetify/components/VAlert', () => ({ VAlert: { template: '<div><slot /><slot name="append" /></div>' } }))
vi.mock('vuetify/components/VDialog', () => ({ VDialog: { props: ['modelValue'], template: '<div v-if="modelValue"><slot /></div>' } }))
vi.mock('vuetify/components/VCard', () => ({
  VCard: { template: '<div><slot /></div>' },
  VCardTitle: { template: '<div><slot /></div>' },
  VCardText: { template: '<div><slot /></div>' },
  VCardActions: { template: '<div><slot /></div>' },
}))
vi.mock('vuetify/components/VCardTitle', () => ({ VCardTitle: { template: '<div><slot /></div>' } }))
vi.mock('vuetify/components/VCardText', () => ({ VCardText: { template: '<div><slot /></div>' } }))
vi.mock('vuetify/components/VCardActions', () => ({ VCardActions: { template: '<div><slot /></div>' } }))
vi.mock('vuetify/components/VTextField', () => ({ VTextField: { props: ['modelValue', 'label'], emits: ['update:modelValue'], template: '<input :aria-label="label" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />' } }))
vi.mock('vuetify/components/VSpacer', () => ({ VSpacer: { template: '<span />' } }))
vi.mock('vuetify/components/VGrid', () => ({ VSpacer: { template: '<span />' } }))
vi.mock('vuetify/components/VTooltip', () => ({ VTooltip: { template: '<div><slot name="activator" :props="{}" /></div>' } }))
vi.mock('vuetify/components/VNavigationDrawer', () => ({ VNavigationDrawer: { template: '<div><slot /></div>' } }))
vi.mock('vuetify/components/VSnackbar', () => ({ VSnackbar: { template: '<div><slot /></div>' } }))
vi.mock('../components/chat/ExpertChatComposer.vue', () => ({ default: { template: '<div />' } }))
vi.mock('../components/chat/ExpertChatMessage.vue', () => ({ default: { template: '<div />' } }))
vi.mock('../components/chat/ExpertProjectLibraryPicker.vue', () => ({ default: { template: '<div />' } }))
vi.mock('../components/materials/ExpertMaterialDrawer.vue', () => ({ default: { template: '<div />' } }))
vi.mock('../components/chat/ExpertContextPanel.vue', () => ({ default: { template: '<div />' } }))
vi.mock('../composables/useExpertMaterialTransfers', () => ({
  useExpertMaterialTransfers: () => ({
    uploads: ref([]), thumbnailPreviews: ref({}), imagePreviews: ref({}),
    queueUploads: vi.fn(), loadImageThumbnail: vi.fn(), loadImagePreview: vi.fn(),
    clearUploads: vi.fn(), syncImagePreviews: vi.fn(), dispose: vi.fn(),
    downloadMaterial: vi.fn(), isDownloading: () => false,
  }),
}))
vi.mock('../api', async (loadActual) => {
  const actual = await loadActual<typeof import('../api')>()
  return {
    ...actual,
    expertApi: {
      listConversations: vi.fn(async () => state.conversations.map((conversation) => ({ ...conversation, messages: [] }))),
      listMessages: vi.fn(async () => []),
      getConversationContext: vi.fn(async () => []),
      updateConversation: state.updateConversation,
      deleteConversation: state.deleteConversation,
    },
  }
})

import ExpertChat from './ExpertChat.vue'

function conversation(id: string, title: string): ExpertConversation {
  return { id, title, messages: [] }
}

function registerTestComponents(app: ReturnType<typeof createApp>) {
  app.component('v-btn', {
    inheritAttrs: true,
    props: ['disabled', 'loading'],
    emits: ['click'],
    template: '<button v-bind="$attrs" :disabled="disabled || loading" @click="$emit(\'click\', $event)"><slot /></button>',
  })
  app.component('v-menu', { props: ['modelValue'], template: '<div><slot name="activator" :props="{}" /><slot /></div>' })
  app.component('v-list', { template: '<div><slot /></div>' })
  app.component('v-list-subheader', { template: '<div><slot /></div>' })
  app.component('v-list-item', {
    props: ['title'],
    emits: ['click'],
    template: '<div :data-title="title" @click="$emit(\'click\', $event)"><slot name="title"><span>{{ title }}</span></slot><slot name="append" /></div>',
  })
  app.component('v-divider', { template: '<hr />' })
  app.component('v-icon', { template: '<i />' })
  app.component('v-tooltip', { template: '<div><slot name="activator" :props="{}" /></div>' })
  app.component('v-progress-linear', { template: '<div />' })
  app.component('v-alert', { template: '<div><slot /><slot name="append" /></div>' })
  app.component('v-dialog', { props: ['modelValue'], template: '<div v-if="modelValue"><slot /></div>' })
  app.component('v-card', { template: '<div><slot /></div>' })
  app.component('v-card-title', { template: '<div><slot /></div>' })
  app.component('v-card-text', { template: '<div><slot /></div>' })
  app.component('v-card-actions', { template: '<div><slot /></div>' })
  app.component('v-text-field', {
    props: ['modelValue', 'label'],
    emits: ['update:modelValue'],
    template: '<input :aria-label="label" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
  })
  app.component('v-spacer', { template: '<span />' })
  app.component('v-navigation-drawer', { template: '<div><slot /></div>' })
  app.component('v-snackbar', { template: '<div><slot /></div>' })
}

async function mountChat(items: ExpertConversation[]) {
  state.conversations = items
  const project = { id: 'project-1', conversations: items.map((item) => ({ ...item, messages: [] })), materials: [], counts: { conversations: items.length, materials: 0 } } as unknown as ExpertProject
  const root = document.createElement('div')
  document.body.append(root)
  const app = createApp(ExpertChat, { project, projectMode: 'real' })
  registerTestComponents(app)
  app.mount(root)
  await vi.waitFor(() => expect(root.querySelector('[aria-label^="Действия чата"]')).not.toBeNull())
  await vi.waitFor(() => expect(root.querySelector('.expert-chat__conversation')?.textContent).toContain(items[0]?.title ?? ''))
  return { root, app, project }
}

function buttonWithText(root: HTMLElement, text: string): HTMLButtonElement {
  return Array.from(root.querySelectorAll('button')).find((button) => button.textContent?.trim() === text) as HTMLButtonElement
}

async function openConversationMenu(root: HTMLElement, title: string) {
  const button = root.querySelector(`[aria-label="Действия чата ${title}"]`) as HTMLButtonElement
  expect(button).not.toBeNull()
  button.click()
  await nextTick()
}

async function clickConversationAction(root: HTMLElement, title: string, action: string) {
  const button = root.querySelector(`[aria-label="Действия чата ${title}"]`) as HTMLButtonElement
  const menuWrap = button.closest('.expert-chat__conversation-menu-wrap') as HTMLElement
  const item = menuWrap.querySelector(`[data-title="${action}"]`) as HTMLElement
  expect(item).not.toBeNull()
  item.click()
  await nextTick()
}

beforeEach(() => {
  state.conversations = []
  state.updateConversation.mockReset()
  state.deleteConversation.mockReset()
  state.updateConversation.mockImplementation(async (id: string, title: string) => ({ id, title, messages: [] }))
  state.deleteConversation.mockResolvedValue(undefined)
})

afterEach(() => {
  document.body.innerHTML = ''
})

describe('Expert chat conversation actions', () => {
  it('renames a conversation through PATCH and updates the list and active title', async () => {
    const { root, app } = await mountChat([conversation('conversation-1', 'Договор')])
    await openConversationMenu(root, 'Договор')
    await clickConversationAction(root, 'Договор', 'Переименовать')
    await nextTick()

    const input = root.querySelector('input[aria-label="Название"]') as HTMLInputElement
    input.value = 'Новый договор'
    input.dispatchEvent(new Event('input', { bubbles: true }))
    await nextTick()
    buttonWithText(root, 'Сохранить').click()

    await vi.waitFor(() => expect(state.updateConversation).toHaveBeenCalledWith('conversation-1', 'Новый договор'))
    await vi.waitFor(() => expect(root.querySelector('input[aria-label="Название"]')).toBeNull())
    expect(root.textContent).toContain('Новый договор')
    expect(root.textContent).not.toContain('Договор')
    app.unmount()
  })

  it('keeps the active chat when an inactive chat is deleted', async () => {
    const { root, app } = await mountChat([conversation('conversation-1', 'Активный'), conversation('conversation-2', 'Удаляемый')])
    await openConversationMenu(root, 'Удаляемый')
    await clickConversationAction(root, 'Удаляемый', 'Удалить')
    buttonWithText(root, 'Удалить').click()

    await vi.waitFor(() => expect(state.deleteConversation).toHaveBeenCalledWith('conversation-2'))
    await vi.waitFor(() => expect(root.textContent).not.toContain('Удалить чат «Удаляемый»?'))
    expect(root.querySelector('.expert-chat__conversation')?.textContent).toContain('Активный')
    expect(root.textContent).not.toContain('Удаляемый')
    app.unmount()
  })

  it('selects the next remaining chat when the active chat is deleted', async () => {
    const { root, app } = await mountChat([conversation('conversation-1', 'Удаляемый'), conversation('conversation-2', 'Следующий')])
    await openConversationMenu(root, 'Удаляемый')
    await clickConversationAction(root, 'Удаляемый', 'Удалить')
    buttonWithText(root, 'Удалить').click()

    await vi.waitFor(() => expect(root.querySelector('.expert-chat__conversation')?.textContent).toContain('Следующий'))
    expect(state.deleteConversation).toHaveBeenCalledWith('conversation-1')
    app.unmount()
  })

  it('shows the empty state after deleting the last chat', async () => {
    const { root, app } = await mountChat([conversation('conversation-1', 'Последний')])
    await openConversationMenu(root, 'Последний')
    await clickConversationAction(root, 'Последний', 'Удалить')
    buttonWithText(root, 'Удалить').click()

    await vi.waitFor(() => expect(root.textContent).toContain('Начните новый чат'))
    expect(root.querySelector('.expert-chat__conversation')?.textContent).toContain('Новый чат')
    app.unmount()
  })

  it('does not switch chats when the ellipsis is clicked', async () => {
    const { root, app } = await mountChat([conversation('conversation-1', 'Активный'), conversation('conversation-2', 'Другой')])
    await openConversationMenu(root, 'Другой')

    expect(root.querySelector('.expert-chat__conversation')?.textContent).toContain('Активный')
    app.unmount()
  })

  it('keeps the chat after delete failure and keeps the old title after rename failure', async () => {
    const { root, app } = await mountChat([conversation('conversation-1', 'Договор')])
    state.deleteConversation.mockRejectedValueOnce(new Error('delete failed'))
    await openConversationMenu(root, 'Договор')
    await clickConversationAction(root, 'Договор', 'Удалить')
    buttonWithText(root, 'Удалить').click()
    await vi.waitFor(() => expect(root.textContent).toContain('Не удалось выполнить запрос.'))
    expect(root.textContent).toContain('Договор')

    await clickConversationAction(root, 'Договор', 'Переименовать')
    const input = root.querySelector('input[aria-label="Название"]') as HTMLInputElement
    input.value = 'Новое название'
    input.dispatchEvent(new Event('input', { bubbles: true }))
    state.updateConversation.mockRejectedValueOnce(new Error('rename failed'))
    buttonWithText(root, 'Сохранить').click()
    await vi.waitFor(() => expect(root.textContent).toContain('Не удалось выполнить запрос.'))
    expect(root.textContent).toContain('Договор')
    app.unmount()
  })
})
