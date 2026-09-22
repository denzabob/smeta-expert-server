// @vitest-environment jsdom
import { createApp, nextTick } from 'vue'
import { afterEach, describe, expect, it, vi } from 'vitest'
import api from '@/api/axios'
import AdminLlmTaskProfiles from './AdminLlmTaskProfiles.vue'

vi.mock('@/api/axios', () => ({ default: { get: vi.fn(), put: vi.fn(), post: vi.fn() } }))
vi.mock('vuetify/components', () => ({
  VCard: { template: '<div><slot /></div>' }, VCardTitle: { template: '<div><slot /></div>' },
  VCardText: { template: '<div><slot /></div>' }, VAlert: { template: '<div><slot /></div>' },
  VRow: { template: '<div><slot /></div>' }, VCol: { template: '<div><slot /></div>' },
  VSelect: { props: ['modelValue', 'label'], emits: ['update:modelValue'], template: '<label>{{ label }}<select :value="modelValue" @change="$emit(\'update:modelValue\', $event.target.value)"><option value="catalog">Порядок</option><option value="typical_cost">Типовой</option></select></label>' }, VTextField: { props: ['modelValue', 'label'], emits: ['update:modelValue'], template: '<label>{{ label }}<input :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" /></label>' },
  VSwitch: { template: '<div />' }, VChip: { template: '<span><slot /></span>' },
  VBtn: { emits: ['click'], template: '<button @click="$emit(\'click\')"><slot /></button>' },
  VDivider: { template: '<hr />' }, VCheckboxBtn: { template: '<div />' },
  VProgressLinear: { template: '<div />' }, VList: { template: '<div><slot /></div>' },
  VListItem: { template: '<div @click="$emit(\'click\')"><slot /></div>' },
  VListItemTitle: { template: '<div><slot /></div>' }, VListItemSubtitle: { template: '<div><slot /></div>' },
  VSpacer: { template: '<div />' }, VSnackbar: { props: ['modelValue'], template: '<div v-if="modelValue"><slot /></div>' },
}))
vi.mock('vuetify/components/VCard', () => ({ VCard: { template: '<div><slot /></div>' }, VCardTitle: { template: '<div><slot /></div>' }, VCardText: { template: '<div><slot /></div>' } }))
vi.mock('vuetify/components/VAlert', () => ({ VAlert: { template: '<div><slot /></div>' } }))
vi.mock('vuetify/components/VGrid', () => ({ VRow: { template: '<div><slot /></div>' }, VCol: { template: '<div><slot /></div>' }, VSpacer: { template: '<div />' } }))
vi.mock('vuetify/components/VSelect', () => ({ VSelect: { props: ['modelValue', 'label'], emits: ['update:modelValue'], template: '<label>{{ label }}<select :value="modelValue" @change="$emit(\'update:modelValue\', $event.target.value)"><option value="catalog">Порядок</option><option value="typical_cost">Типовой</option></select></label>' } }))
vi.mock('vuetify/components/VTextField', () => ({ VTextField: { props: ['modelValue', 'label'], emits: ['update:modelValue'], template: '<label>{{ label }}<input :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" /></label>' } }))
vi.mock('vuetify/components/VSwitch', () => ({ VSwitch: { template: '<div />' } }))
vi.mock('vuetify/components/VChip', () => ({ VChip: { template: '<span><slot /></span>' } }))
vi.mock('vuetify/components/VBtn', () => ({ VBtn: { emits: ['click'], template: '<button @click="$emit(\'click\')"><slot /></button>' } }))
vi.mock('vuetify/components/VDivider', () => ({ VDivider: { template: '<hr />' } }))
vi.mock('vuetify/components/VCheckbox', () => ({ VCheckboxBtn: { template: '<div />' } }))
vi.mock('vuetify/components/VProgressLinear', () => ({ VProgressLinear: { template: '<div />' } }))
vi.mock('vuetify/components/VSnackbar', () => ({ VSnackbar: { props: ['modelValue'], template: '<div v-if="modelValue"><slot /></div>' } }))
vi.mock('vuetify/components/VList', () => ({ VList: { template: '<div><slot /></div>' }, VListItem: { template: '<div @click="$emit(\'click\')"><slot /></div>' }, VListItemTitle: { template: '<div><slot /></div>' }, VListItemSubtitle: { template: '<div><slot /></div>' } }))

const capabilities = {
  text_input: true, image_input: false, file_input: false, pdf_ocr: false,
  streaming: true, reasoning: false, tools: true, structured_output: false,
}
const profile = {
  configured: { provider: 'routerai', model: 'legacy/pinned', enabled: true, fallback_policy: 'none' },
  effective: { provider: 'routerai', model: 'legacy/pinned' }, source: 'PROFILE',
  provider_key_source: 'ENV', capabilities, catalog_status: 'fresh',
}

async function settle(): Promise<void> {
  await new Promise(resolve => setTimeout(resolve, 0))
  await nextTick()
}

async function mountProfile() {
  const root = document.createElement('div')
  document.body.append(root)
  const app = createApp(AdminLlmTaskProfiles, { providers: [{ value: 'routerai', title: 'RouterAI' }] })
  app.mount(root)
  await settle()
  await settle()
  return { root, app }
}

afterEach(() => {
  vi.restoreAllMocks()
  document.body.innerHTML = ''
})

describe('Expert task profile admin', () => {
  it('keeps an unknown pinned model, shows backend capabilities and tests the draft without saving', async () => {
    vi.mocked(api.get).mockImplementation(async (url) => {
      if (url.includes('/preview')) return { data: { capabilities, model_in_catalog: false, provider_configured: true } } as never
      if (url.includes('/llm-model-catalog/')) return { data: { models: [], total: 0, status: 'fresh', error_code: null } } as never
      return { data: profile } as never
    })
    vi.mocked(api.post).mockResolvedValue({ data: { status: 'PASS', checked_at: '2026-09-20T00:00:00Z', latency_ms: 10, ttft_ms: null, error_code: null } } as never)

    const { root, app } = await mountProfile()
    expect(root.textContent).toContain('Эксперт — Fast')
    expect(root.textContent).toContain('legacy/pinned')
    expect(root.textContent).toContain('PROFILE')
    Array.from(root.querySelectorAll('button')).find(button => button.textContent?.includes('Редактировать'))?.click()
    await settle()
    expect(root.textContent).toContain('Модель не найдена в текущем каталоге')
    expect(root.textContent).toContain('Эта модель не сможет анализировать изображения')
    expect(root.textContent).toContain('Скан PDF OCR')

    Array.from(root.querySelectorAll('button')).find(button => button.textContent?.trim() === 'Текст')?.click()
    await settle()
    expect(vi.mocked(api.post)).toHaveBeenCalledWith('/api/admin/llm-profiles/expert-chat/smoke', {
      provider: 'routerai', model: 'legacy/pinned', kind: 'text',
    })
    expect(vi.mocked(api.put)).not.toHaveBeenCalled()
    expect(root.textContent).toContain('PASS')
    app.unmount()
  })

  it('searches a bounded catalog with the Expert filter and saves only on the explicit action', async () => {
    vi.mocked(api.get).mockImplementation(async (url) => {
      if (url.includes('/preview')) return { data: { capabilities, model_in_catalog: true, provider_configured: true } } as never
      if (url.includes('/llm-model-catalog/')) return { data: {
        models: [{ id: 'new/model', display_name: 'New Model', context_length: 128000, capabilities,
          pricing: { prompt: '0.00000328', completion: '0.00001423', input_cache_read: '0.00000066', web_search: '2' },
          pricing_units: { prompt: 'token', completion: 'token', input_cache_read: 'token', web_search: 'request' },
        }],
        total: 1, status: 'fresh', error_code: null,
      } } as never
      return { data: profile } as never
    })
    vi.mocked(api.put).mockResolvedValue({ data: { ...profile, effective: { provider: 'routerai', model: 'new/model' } } } as never)
    const { root, app } = await mountProfile()
    Array.from(root.querySelectorAll('button')).find(button => button.textContent?.includes('Редактировать'))?.click()
    await settle()
    expect(vi.mocked(api.get)).toHaveBeenCalledWith('/api/admin/llm-model-catalog/routerai', {
      params: { q: undefined, filter: ['compatible'], sort: 'catalog', page: 1 },
    })

    const searchInput = Array.from(root.querySelectorAll('label')).find(label => label.textContent?.includes('Поиск модели'))?.querySelector('input')
    expect(searchInput).toBeTruthy()
    if (searchInput) {
      searchInput.value = 'new'
      searchInput.dispatchEvent(new Event('input', { bubbles: true }))
    }
    await new Promise(resolve => setTimeout(resolve, 300))
    expect(vi.mocked(api.get)).toHaveBeenCalledWith('/api/admin/llm-model-catalog/routerai', {
      params: { q: 'new', filter: ['compatible'], sort: 'catalog', page: 1 },
    })
    expect(root.textContent).toContain('3,28 ₽ / 1M токенов')
    expect(root.textContent).toContain('14,23 ₽ / 1M токенов')
    expect(root.textContent).toContain('0,66 ₽ / 1M токенов')
    expect(root.textContent).toContain('Web search: 2 ₽ / запрос')
    expect(root.textContent).toContain('20K вход + 2K выход')
    const sortInput = Array.from(root.querySelectorAll('label')).find(label => label.textContent?.includes('Сортировка по стоимости'))?.querySelector('select')
    if (sortInput) { sortInput.value = 'typical_cost'; sortInput.dispatchEvent(new Event('change', { bubbles: true })) }
    await new Promise(resolve => setTimeout(resolve, 300))
    expect(vi.mocked(api.get)).toHaveBeenCalledWith('/api/admin/llm-model-catalog/routerai', {
      params: { q: 'new', filter: ['compatible'], sort: 'typical_cost', page: 1 },
    })
    const modelInput = Array.from(root.querySelectorAll('label')).find(label => label.textContent?.includes('Основная модель'))?.querySelector('input')
    expect(modelInput).toBeTruthy()
    if (modelInput) {
      modelInput.value = 'new/model'
      modelInput.dispatchEvent(new Event('input', { bubbles: true }))
    }
    await settle()
    expect(root.textContent).toContain('Есть несохранённые изменения')
    expect(vi.mocked(api.put)).not.toHaveBeenCalled()
    Array.from(root.querySelectorAll('button')).find(button => button.textContent?.trim() === 'Сохранить')?.click()
    await settle()
    expect(vi.mocked(api.put)).toHaveBeenCalledWith('/api/admin/llm-profiles/expert-chat?task=expert_fast', {
      provider: 'routerai', model: 'new/model', enabled: true, fallback_policy: 'none', fallback_enabled: false,
      fallback_provider: null, fallback_model: null, reasoning_effort: null, max_output_tokens: null, temperature: null,
    })
    app.unmount()
  })
})
