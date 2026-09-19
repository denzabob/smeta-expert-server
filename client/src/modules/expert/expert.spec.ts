import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'
import { expertSidebarSections } from './navigation'
import { expertProjects, getExpertProject } from './mock/expertMockData'
import { expertResearchTabs, getExpertProjectReadiness } from './presentation'
import { expertRoutes } from './router/routes'

describe('Expert frontend prototype contracts', () => {
  it('declares the dashboard, project list and nested project routes', () => {
    const topLevelPaths = expertRoutes.map((route) => route.path)
    const projectRoute = expertRoutes.find((route) => route.path === 'expert/projects/:projectId')

    expect(topLevelPaths).toEqual([
      'expert',
      'expert/projects',
      'expert/projects/:projectId',
    ])
    expect(projectRoute?.children?.map((route) => route.path)).toEqual([
      '',
      'chat',
      'materials',
      'research',
      'normatives',
      'report',
      'versions',
    ])
  })

  it('keeps both demo profiles on the same project contract with conversations', () => {
    expect(expertProjects.map((project) => project.profile)).toEqual(['commodity', 'construction'])
    expect(expertProjects.every((project) => Array.isArray(project.conversations))).toBe(true)
    expect(getExpertProject('demo-commodity')?.conversations.length).toBeGreaterThan(1)
    expect(getExpertProject('demo-construction')?.conversations.length).toBeGreaterThan(1)
  })

  it('uses a universal Finding model with research objects, evidence and expert statuses', () => {
    const commodityProject = getExpertProject('demo-commodity')
    const constructionProject = getExpertProject('demo-construction')

    expect(expertProjects.every((project) => project.researchObjects.length > 0)).toBe(true)
    expect(expertProjects.every((project) => project.findings.every((finding) => project.researchObjects.some((item) => item.id === finding.researchObjectId)))).toBe(true)
    expect(expertProjects.every((project) => project.findings.every((finding) => (finding.materialIds ?? []).every((id) => project.materials.some((item) => item.id === id))))).toBe(true)
    expect(expertProjects.every((project) => project.findings.every((finding) => (finding.normativeReferenceIds ?? []).every((id) => project.normatives.some((item) => item.id === id))))).toBe(true)
    expect(commodityProject?.findings.map((finding) => finding.type)).toEqual(expect.arrayContaining(['defect', 'damage', 'observation']))
    expect(constructionProject?.findings.map((finding) => finding.type)).toEqual(expect.arrayContaining(['non_compliance', 'observation']))
    expect(commodityProject?.findings.find((finding) => finding.id === 'finding-1')?.materialIds).toContain('mat-4')
    expect(constructionProject?.findings.find((finding) => finding.id === 'c-finding-1')?.normativeReferenceIds).toContain('c-n-2')
    expect(new Set(expertProjects.flatMap((project) => project.findings.map((finding) => finding.status)))).toEqual(new Set(['Предложено AI', 'Подтверждено экспертом', 'Отклонено экспертом']))
  })

  it('labels the universal findings tab as research results and uses structural readiness', () => {
    const project = getExpertProject('demo-commodity')
    if (!project) throw new Error('Demo commodity project is required for Expert contract tests')
    const readiness = getExpertProjectReadiness(project)

    expect(expertResearchTabs.find((item) => item.value === 'all')?.label).toBe('Результаты исследования')
    expect(expertResearchTabs.map((item) => item.label)).not.toContain('Выявленные обстоятельства')
    expect(readiness).toEqual(expect.arrayContaining([
      expect.objectContaining({ label: 'Объекты исследования', value: 'Готово' }),
      expect.objectContaining({ label: 'Заключение', value: 'Черновик' }),
    ]))
    expect(JSON.stringify(readiness)).not.toContain('%')
  })

  it('keeps normative recommendations explainable and report sections linked to entities', () => {
    const recommendedNormatives = expertProjects.flatMap((project) => project.normatives.filter((normative) => normative.recommended))
    const reportReferences = expertProjects.flatMap((project) => project.reportSections.flatMap((section) => section.entityReferences ?? []))

    expect(recommendedNormatives.every((normative) => normative.recommendationReason && normative.status)).toBe(true)
    expect(reportReferences).toEqual(expect.arrayContaining([
      expect.objectContaining({ entityType: 'finding' }),
      expect.objectContaining({ entityType: 'research_object' }),
      expect.objectContaining({ entityType: 'material' }),
      expect.objectContaining({ entityType: 'normative' }),
    ]))
    expect(expertProjects.every((project) => project.reportSections.every((section) => (section.entityReferences ?? []).every((reference) => {
      if (reference.entityType === 'finding') return project.findings.some((item) => item.id === reference.entityId)
      if (reference.entityType === 'research_object') return project.researchObjects.some((item) => item.id === reference.entityId)
      if (reference.entityType === 'normative') return project.normatives.some((item) => item.id === reference.entityId)
      if (reference.entityType === 'material') return project.materials.some((item) => item.id === reference.entityId)
      return true
    })))).toBe(true)
  })

  it('does not retain a numerical 68 percent readiness display', () => {
    const overviewSource = readFileSync(new URL('./pages/ExpertOverview.vue', import.meta.url), 'utf8')
    const contextSource = readFileSync(new URL('./components/chat/ExpertContextPanel.vue', import.meta.url), 'utf8')

    expect(overviewSource).not.toContain('68%')
    expect(contextSource).not.toContain('68%')
    expect(overviewSource).not.toContain('model-value="68"')
    expect(contextSource).not.toContain('model-value="68"')
  })

  it('exposes only Expert-level navigation in the global sidebar', () => {
    expect(expertSidebarSections.flatMap((section) => section.items.map((item) => item.routeName))).toEqual([
      'expert-dashboard',
      'expert-projects',
    ])
  })

  it('keeps production dashboard independent from demo fixtures and guards async project loads', () => {
    const dashboardSource = readFileSync(new URL('./pages/ExpertDashboard.vue', import.meta.url), 'utf8')
    const projectSource = readFileSync(new URL('./pages/ExpertProject.vue', import.meta.url), 'utf8')
    const chatSource = readFileSync(new URL('./pages/ExpertChat.vue', import.meta.url), 'utf8')

    expect(dashboardSource).not.toContain("from '../mock/expertMockData'")
    expect(projectSource).toContain('sequence===loadSequence')
    expect(projectSource).toContain('isDemoProjectId(id)')
    expect(chatSource).toContain('targetConversationId')
    expect(chatSource).toContain('messagesSequence')
  })

  it('clears an optimistic chat draft after local acceptance and keeps keyboard semantics explicit', () => {
    const composerSource = readFileSync(new URL('./components/chat/ExpertChatComposer.vue', import.meta.url), 'utf8')
    const chatSource = readFileSync(new URL('./pages/ExpertChat.vue', import.meta.url), 'utf8')

    expect(composerSource).toContain("shouldSubmitExpertChatComposer(event)")
    expect(composerSource).toContain("normalizeExpertChatDraft(text.value)")
    expect(composerSource).toContain("if (normalizeExpertChatDraft(text.value) === value) text.value = ''")
    expect(chatSource).toContain('createOptimisticUserMessage')
    expect(chatSource).toContain('retryMessage')
    expect(chatSource).toContain('showScrollToBottom')
    expect(chatSource).not.toContain('AI-ответы подключаются на следующем этапе.')
  })

  it('uses the Expert Chat AI response envelope without a fake assistant placeholder', () => {
    const chatSource = readFileSync(new URL('./pages/ExpertChat.vue', import.meta.url), 'utf8')
    const timelineSource = readFileSync(new URL('./components/chat/ExpertChatActivityTimeline.vue', import.meta.url), 'utf8')

    expect(chatSource).toContain('reply.userMessage')
    expect(chatSource).toContain('reply.assistantMessage')
    expect(chatSource).toContain('appendServerAssistantMessage')
    expect(chatSource).toContain('handleMessageAdded(wasNearBottom, false)')
    expect(timelineSource).toContain('Формируется ответ…')
  })

  it('uses the shared Materials transfer flow and persisted message attachments', () => {
    const composerSource = readFileSync(new URL('./components/chat/ExpertChatComposer.vue', import.meta.url), 'utf8')
    const chatSource = readFileSync(new URL('./pages/ExpertChat.vue', import.meta.url), 'utf8')

    expect(composerSource).toContain("type=\"file\"")
    expect(composerSource).toContain("'attach-files'")
    expect(composerSource).toContain('sendBlockedReason')
    expect(chatSource).toContain('useExpertMaterialTransfers')
    expect(chatSource).toContain('transfers.queueUploads')
    expect(chatSource).toContain('snapshotExpertMessageMaterialContext')
    expect(chatSource).toContain('runtimeMaterialContext')
    expect(chatSource).toContain('message.attachments?.map((attachment) => attachment.id)')
    expect(chatSource).toContain('isExpertMaterialContextError(mapped.code)')
    expect(chatSource).toContain('mergeExpertMessageMaterialContexts')
    expect(chatSource).not.toContain('expertApi.deleteMaterial')
  })

  it('keeps run failures beside messages and removes the obsolete material-ID hint', () => {
    const composerSource = readFileSync(new URL('./components/chat/ExpertChatComposer.vue', import.meta.url), 'utf8')
    const messageSource = readFileSync(new URL('./components/chat/ExpertChatMessage.vue', import.meta.url), 'utf8')
    const chatSource = readFileSync(new URL('./pages/ExpertChat.vue', import.meta.url), 'utf8')

    expect(composerSource).not.toContain('API чата пока не получает их IDs')
    expect(messageSource).toContain("message.role === 'assistant' && message.deliveryState === 'error'")
    expect(chatSource).not.toContain('if (!useLegacyFallback) errorMessage.value = mapped.message')
    expect(chatSource).toContain("updateMessageDelivery(assistantMessage.id, 'error', mapped.message)")
  })

  it('keeps the Material drawer as a temporary overlay and replaces browser confirmation', () => {
    const materialsSource = readFileSync(new URL('./pages/ExpertMaterials.vue', import.meta.url), 'utf8')
    const drawerSource = readFileSync(new URL('./components/materials/ExpertMaterialDrawer.vue', import.meta.url), 'utf8')

    expect(drawerSource).toContain('v-if="open"')
    expect(drawerSource).toContain('temporary location="right"')
    expect(materialsSource).toContain('v-if="drawerOpen"')
    expect(materialsSource).not.toContain('window.confirm')
    expect(materialsSource).toContain('Материал используется в результатах исследования')
  })
})
