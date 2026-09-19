import { createSSRApp, h } from 'vue'
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
  it('hides fast text-only technical stages and shows only the slow transient status', async () => {
    let runs = [createExpertTimelineRun('run-1')]
    runs = applyExpertTimelineActivity(runs, { runId: 'run-1', seq: 1, activityId: 'request', code: 'request.accepted', status: 'completed', category: 'request' })
    runs = applyExpertTimelineActivity(runs, { runId: 'run-1', seq: 2, activityId: 'model', code: 'model.first_token', status: 'completed', category: 'model' })

    await expect(renderTimeline(runs)).resolves.not.toContain('Ход обработки')
    await expect(renderTimeline(runs, true)).resolves.toContain('Формируется ответ')
  })

  it('renders significant material activities sequentially before a terminal event', async () => {
    let runs: ExpertTimelineRun[] = [{ ...createExpertTimelineRun('run-1'), significant: true }]
    runs = applyExpertTimelineActivity(runs, { runId: 'run-1', seq: 1, activityId: 'image', code: 'material.image_prepare.started', status: 'started', category: 'material', detail: 'photo.jpg' })
    const first = await renderTimeline(runs)

    runs = applyExpertTimelineActivity(runs, { runId: 'run-1', seq: 2, activityId: 'image', code: 'material.image_prepare.completed', status: 'completed', category: 'material', detail: 'photo.jpg' })
    const second = await renderTimeline(runs)

    runs = applyExpertTimelineActivity(runs, { runId: 'run-1', seq: 3, activityId: 'ocr', code: 'pdf.ocr.started', status: 'started', category: 'material', detail: 'scan.pdf' })
    const third = await renderTimeline(runs)

    expect(first).toContain('Подготавливаю изображение')
    expect(second).toContain('Изображение подготовлено')
    expect(third).toContain('Изображение подготовлено')
    expect(third).toContain('Распознаю сканированный документ')
  })

  it('omits the full timeline after a fast single image preparation', async () => {
    let runs = [createExpertTimelineRun('run-fast')]
    runs = applyExpertTimelineActivity(runs, { runId: 'run-fast', seq: 1, activityId: 'image', code: 'material.image_prepare.started', status: 'started', category: 'material' })
    runs = applyExpertTimelineActivity(runs, { runId: 'run-fast', seq: 2, activityId: 'image', code: 'material.image_prepare.completed', status: 'completed', category: 'material' })
    await expect(renderTimeline(runs)).resolves.not.toContain('Ход обработки')
  })
})
