import type { ExpertMaterialUploadItem } from './composables/useExpertMaterialTransfers'
import type { ExpertMessageMaterialContext, ExpertProjectMaterial } from './types'

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

export function getExpertChatAttachmentSendBlockReason(
  uploads: Pick<ExpertMaterialUploadItem, 'state'>[],
): string | undefined {
  if (uploads.some((item) => item.state === 'error')) {
    return 'Не удалось загрузить файл. Повторите загрузку или удалите файл из composer.'
  }
  if (uploads.some((item) => item.state === 'queued' || item.state === 'uploading' || item.state === 'processing')) {
    return 'Дождитесь окончания загрузки файлов.'
  }
  return undefined
}
