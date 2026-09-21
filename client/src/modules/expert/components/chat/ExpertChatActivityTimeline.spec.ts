// @vitest-environment jsdom
import { createApp, createSSRApp, h, nextTick } from 'vue'
import { renderToString } from '@vue/server-renderer'
import { describe, expect, it, vi } from 'vitest'

vi.mock('vuetify/components', () => ({
  VIcon: { template: '<i />' },
  VProgressCircular: { template: '<i />' },
}))
vi.mock('vuetify/components/VIcon', () => ({ VIcon: { template: '<i />' } }))
vi.mock('vuetify/components/VProgressCircular', () => ({ VProgressCircular: { template: '<i />' } }))
import { applyExpertTimelineActivity, createExpertTimelineRun, type ExpertTimelineRun } from '../../chatTimeline'
import ExpertChatActivityTimeline from './ExpertChatActivityTimeline.vue'

async function renderTimeline(runs: ExpertTimelineRun[], showSlowWaiting = false): Promise<string> {
  const app = createSSRApp({ render: () => h(ExpertChatActivityTimeline, { runs, showSlowWaiting }) })
  app.component('v-icon', { template: '<i />' })
  app.component('v-progress-circular', { template: '<i />' })
  return renderToString(app)
}

describe('Expert activity timeline presentation', () => {
  it('advances elapsed time without receiving another SSE event', async () => {
    vi.useFakeTimers()
    try {
      const root = document.createElement('div')
      const app = createApp({ render: () => h(ExpertChatActivityTimeline, { runs: [] }) })
      app.component('v-icon', { template: '<i />' })
      app.mount(root)
      expect(root.textContent).toContain('Подготавливаю запрос…')
      vi.advanceTimersByTime(5000)
      await nextTick()
      expect(root.textContent).toContain('5 с')
      app.unmount()
    } finally {
      vi.useRealTimers()
    }
  })

  it('shows a live placeholder before the first SSE event', async () => {
    await expect(renderTimeline([])).resolves.toContain('Подготавливаю запрос…')
  })

  it('shows one operation without the technical history', async () => {
    let runs = [createExpertTimelineRun('run-1')]
    runs = applyExpertTimelineActivity(runs, { runId: 'run-1', seq: 1, activityId: 'request', code: 'request.accepted', status: 'completed', category: 'request' })
    runs = applyExpertTimelineActivity(runs, { runId: 'run-1', seq: 2, activityId: 'model', code: 'model.first_token', status: 'completed', category: 'model' })

    const html = await renderTimeline(runs)
    expect(html).toContain('Формирую ответ…')
    expect(html).not.toContain('Запрос принят')
    expect(html).not.toContain('Ход обработки')
  })

  it('renders significant material activities sequentially before a terminal event', async () => {
    let runs: ExpertTimelineRun[] = [{ ...createExpertTimelineRun('run-1'), significant: true }]
    runs = applyExpertTimelineActivity(runs, { runId: 'run-1', seq: 1, activityId: 'image', code: 'material.image_prepare.started', status: 'started', category: 'material', detail: 'photo.jpg' })
    const first = await renderTimeline(runs)

    runs = applyExpertTimelineActivity(runs, { runId: 'run-1', seq: 2, activityId: 'image', code: 'material.image_prepare.completed', status: 'completed', category: 'material', detail: 'photo.jpg' })
    const second = await renderTimeline(runs)

    runs = applyExpertTimelineActivity(runs, { runId: 'run-1', seq: 3, activityId: 'ocr', code: 'pdf.ocr.started', status: 'started', category: 'material', detail: 'scan.pdf' })
    const third = await renderTimeline(runs)

    expect(first).toContain('Подготавливаю материалы…')
    expect(second).toContain('Подготавливаю материалы…')
    expect(third).not.toContain('Изображение подготовлено')
    expect(third).toContain('Обрабатываю scan.pdf…')
  })

  it('omits the full timeline after a fast single image preparation', async () => {
    let runs = [createExpertTimelineRun('run-fast')]
    runs = applyExpertTimelineActivity(runs, { runId: 'run-fast', seq: 1, activityId: 'image', code: 'material.image_prepare.started', status: 'started', category: 'material' })
    runs = applyExpertTimelineActivity(runs, { runId: 'run-fast', seq: 2, activityId: 'image', code: 'material.image_prepare.completed', status: 'completed', category: 'material' })
    await expect(renderTimeline(runs)).resolves.not.toContain('Ход обработки')
  })
})
