// @vitest-environment jsdom
import { createApp, nextTick } from 'vue'
import { afterEach, describe, expect, it, vi } from 'vitest'
import AdminExpertAiRunsView from './AdminExpertAiRunsView.vue'

const apiMocks = vi.hoisted(() => ({ list: vi.fn(), show: vi.fn() }))

vi.mock('@/api/adminExpertAiRuns', () => ({ adminExpertAiRunsApi: apiMocks }))
vi.mock('@/components/layout/PageContainer.vue', () => ({ default: { template: '<div><slot /></div>' } }))
vi.mock('@/components/layout/PageHeader.vue', () => ({ default: { template: '<header><h1>{{ title }}</h1><p>{{ subtitle }}</p></header>', props: ['title', 'subtitle'] } }))
vi.mock('vuetify/components/VAlert', () => ({ VAlert: { template: '<div><slot /></div>' } }))
vi.mock('vuetify/components/VBtn', () => ({ VBtn: { emits: ['click'], template: '<button v-bind="$attrs" @click="$emit(\'click\')"><slot /></button>' } }))
vi.mock('vuetify/components/VCard', () => ({
  VCard: { template: '<div><slot /></div>' },
  VCardText: { template: '<div><slot /></div>' },
  VCardTitle: { template: '<div><slot /></div>' },
}))
vi.mock('vuetify/components/VChip', () => ({ VChip: { template: '<span><slot /></span>' } }))
vi.mock('vuetify/components/VCol', () => ({ VCol: { template: '<div><slot /></div>' } }))
vi.mock('vuetify/components/VDialog', () => ({ VDialog: { props: ['modelValue'], template: '<section v-if="modelValue" data-testid="detail-dialog"><slot /></section>' } }))
vi.mock('vuetify/components/VDivider', () => ({ VDivider: { template: '<hr />' } }))
vi.mock('vuetify/components/VGrid', () => ({
  VCol: { template: '<div><slot /></div>' },
  VRow: { template: '<div><slot /></div>' },
  VSpacer: { template: '<span />' },
}))
vi.mock('vuetify/components/VIcon', () => ({ VIcon: { template: '<i><slot /></i>' } }))
vi.mock('vuetify/components/VPagination', () => ({ VPagination: { props: ['modelValue', 'length'], template: '<div data-testid="pagination" :data-page="modelValue" :data-length="length" />' } }))
vi.mock('vuetify/components/VProgressLinear', () => ({ VProgressLinear: { template: '<div />' } }))
vi.mock('vuetify/components/VRow', () => ({ VRow: { template: '<div><slot /></div>' } }))
vi.mock('vuetify/components/VSnackbar', () => ({ VSnackbar: { props: ['modelValue'], template: '<div v-if="modelValue"><slot /></div>' } }))
vi.mock('vuetify/components/VSpacer', () => ({ VSpacer: { template: '<span />' } }))
vi.mock('vuetify/components/VTable', () => ({ VTable: { template: '<table><slot /></table>' } }))
vi.mock('vuetify/components/VTextField', () => ({
  VTextField: {
    props: ['modelValue', 'label', 'type'],
    emits: ['update:modelValue'],
    template: '<label>{{ label }}<input :aria-label="label" :type="type || \'text\'" :value="modelValue || \'\'" @input="$emit(\'update:modelValue\', $event.target.value)" /></label>',
  },
}))
const at = '2026-10-04T09:31:04.021Z'
const failedRun = {
  run_id: '11111111-1111-4111-8111-111111111111', started_at: at, first_token_at: null, finished_at: at,
  duration_ms: 50, status: 'failed', stage: 'provider', user: { id: 7, name: 'Тестовый пользователь' },
  project: { id: 8, name: 'Проверка сметы' }, conversation: { id: 9 },
  selected_material_count: 2, persisted_material_count: 2, resolved_material_count: 1, active_material_count: 1,
  requested_mode: 'auto', resolved_mode: 'fast', provider: 'routerai', model: 'openai/test-model',
  error_code: 'provider_unavailable', retryable: true, finish_reason: 'provider_error',
}
const completedRun = {
  ...failedRun, run_id: '22222222-2222-4222-8222-222222222222', status: 'completed', stage: 'persistence',
  error_code: null, retryable: false, finish_reason: 'completed',
}

async function settle(): Promise<void> {
  await new Promise(resolve => setTimeout(resolve, 0))
  await nextTick()
}

function mountView() {
  const root = document.createElement('div')
  document.body.append(root)
  const app = createApp(AdminExpertAiRunsView)
  app.mount(root)
  return { app, root }
}

afterEach(() => {
  vi.clearAllMocks()
  document.body.innerHTML = ''
})

describe('Admin Expert run diagnostics view', () => {
  it('loads and displays completed and failed runs, sends filters to the server, and opens a failed run timeline', async () => {
    apiMocks.list.mockResolvedValue({ data: [failedRun, completedRun], meta: { current_page: 1, per_page: 25, total: 2, last_page: 1 } })
    apiMocks.show.mockResolvedValue({
      run: failedRun,
      events: [
        { seq: 1, created_at: at, source: 'lifecycle', level: 'info', event_code: 'run.created', stage: 'created', status: 'created', payload: null },
        { seq: 2, created_at: at, source: 'lifecycle', level: 'error', event_code: 'run.failed', stage: 'provider', status: 'failed', payload: { error_code: 'provider_unavailable', retryable: true } },
      ],
    })

    const { app, root } = mountView()
    await settle()

    expect(apiMocks.list).toHaveBeenCalledTimes(1)
    expect(root.textContent).toContain('Ошибка')
    expect(root.textContent).toContain('Завершён')

    const runIdInput = root.querySelector('input[aria-label="Run ID"]') as HTMLInputElement
    runIdInput.value = '11111111'
    runIdInput.dispatchEvent(new Event('input', { bubbles: true }))
    await nextTick()
    Array.from(root.querySelectorAll('button')).find(button => button.textContent?.trim() === 'Применить')?.click()
    await settle()
    expect(apiMocks.list).toHaveBeenLastCalledWith(expect.objectContaining({ run_id: '11111111', page: 1, status: '' }))

    root.querySelector('tr.run-row')?.dispatchEvent(new MouseEvent('click', { bubbles: true }))
    await settle()
    expect(apiMocks.show).toHaveBeenCalledWith(failedRun.run_id)
    expect(root.textContent).toContain('run.created')
    expect(root.textContent).toContain('run.failed')
    expect(root.querySelector('.timeline-event--failed')).toBeTruthy()
    const payloadDisclosure = root.querySelector('details.payload-disclosure') as HTMLDetailsElement
    expect(payloadDisclosure.open).toBe(false)

    root.querySelector('summary')?.click()
    await nextTick()
    expect(payloadDisclosure.open).toBe(true)
    expect(root.querySelector('.safe-payload')?.textContent).toContain('provider_unavailable')
    app.unmount()
  })

  it('applies the error preset as a server-side status filter', async () => {
    apiMocks.list.mockResolvedValue({ data: [], meta: { current_page: 1, per_page: 25, total: 0, last_page: 1 } })
    const { app, root } = mountView()
    await settle()
    Array.from(root.querySelectorAll('button')).find(button => button.textContent?.trim() === 'Ошибки')?.click()
    await settle()
    expect(apiMocks.list).toHaveBeenLastCalledWith(expect.objectContaining({ status: 'failed', page: 1 }))
    app.unmount()
  })
})
