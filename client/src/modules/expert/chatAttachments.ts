import type { ExpertMaterialUploadItem } from './composables/useExpertMaterialTransfers'
import type { ExpertMessageMaterialContext, ExpertProjectMaterial } from './types'

const supportedDocumentFormats = new Set(['txt', 'md', 'docx', 'pdf', 'xlsx'])
const supportedImageFormats = new Set(['jpg', 'jpeg', 'png', 'webp'])

export function createExpertMessageMaterialContext(material: ExpertProjectMaterial): ExpertMessageMaterialContext {
  return {
    id: material.id,
    name: material.name,
    kind: material.kind,
    format: material.format,
    icon: material.icon,
  }
}

export function addExpertMessageMaterialContext(
  contexts: ExpertMessageMaterialContext[],
  material: ExpertProjectMaterial,
): ExpertMessageMaterialContext[] {
  if (contexts.some((context) => context.id === material.id)) return contexts
  return [...contexts, createExpertMessageMaterialContext(material)]
}

export function snapshotExpertMessageMaterialContext(
  contexts: ExpertMessageMaterialContext[],
): ExpertMessageMaterialContext[] {
  return contexts.map((context) => ({ ...context }))
}

export function mergeExpertMessageMaterialContexts(
  current: ExpertMessageMaterialContext[],
  restored: ExpertMessageMaterialContext[],
): ExpertMessageMaterialContext[] {
  const currentIds = new Set(current.map((context) => context.id))

  return [
    ...current,
    ...restored
      .filter((context) => !currentIds.has(context.id))
      .map((context) => ({ ...context })),
  ]
}

export function isExpertMaterialSupportedForAiContext(
  context: Pick<ExpertMessageMaterialContext, 'kind' | 'format'>,
): boolean {
  const format = context.format.trim().toLowerCase()

  return context.kind === 'image'
    ? supportedImageFormats.has(format)
    : supportedDocumentFormats.has(format)
}

export function getExpertChatAttachmentSendBlockReason(
  uploads: Pick<ExpertMaterialUploadItem, 'state'>[],
  contexts: ExpertMessageMaterialContext[] = [],
): string | undefined {
  if (uploads.some((item) => item.state === 'error')) {
    return 'Не удалось загрузить файл. Повторите загрузку или удалите файл из composer.'
  }
  if (uploads.some((item) => item.state === 'queued' || item.state === 'uploading' || item.state === 'processing')) {
    return 'Дождитесь окончания загрузки файлов.'
  }
  if (contexts.some((context) => !isExpertMaterialSupportedForAiContext(context))) {
    return 'Этот тип файла можно хранить в проекте, но пока нельзя использовать как контекст AI.'
  }
  return undefined
}
