import { describe, expect, it } from 'vitest'
import { addExpertMessageMaterialContext, createExpertMessageMaterialContext, getExpertChatAttachmentSendBlockReason, isExpertMaterialSupportedForAiContext, mergeExpertMessageMaterialContexts, snapshotExpertMessageMaterialContext } from './chatAttachments'
import type { ExpertProjectMaterial } from './types'

function material(id: string, name: string, format = 'PDF'): ExpertProjectMaterial {
  return {
    id,
    name,
    kind: 'document',
    format,
    meta: 'application/pdf',
    size: '1 КБ',
    category: 'document',
    status: 'Загружен',
    useInAi: false,
    icon: 'mdi-file-pdf-box',
  }
}

describe('chat material attachment context', () => {
  it('turns a completed material into one unique composer chip', () => {
    const uploaded = material('m1', 'Заключение.pdf')
    const first = addExpertMessageMaterialContext([], uploaded)
    const repeated = addExpertMessageMaterialContext(first, uploaded)

    expect(createExpertMessageMaterialContext(uploaded)).toMatchObject({ id: 'm1', name: 'Заключение.pdf', format: 'PDF' })
    expect(repeated).toHaveLength(1)
    expect(repeated[0]).toMatchObject({ id: 'm1', icon: 'mdi-file-pdf-box' })
  })

  it('blocks sending for active or failed transfers and allows completed-only context', () => {
    expect(getExpertChatAttachmentSendBlockReason([{ state: 'queued' }])).toBe('Дождитесь окончания загрузки файлов.')
    expect(getExpertChatAttachmentSendBlockReason([{ state: 'uploading' }])).toBe('Дождитесь окончания загрузки файлов.')
    expect(getExpertChatAttachmentSendBlockReason([{ state: 'processing' }])).toBe('Дождитесь окончания загрузки файлов.')
    expect(getExpertChatAttachmentSendBlockReason([{ state: 'error' }])).toContain('Не удалось загрузить файл')
    expect(getExpertChatAttachmentSendBlockReason([])).toBeUndefined()
  })

  it('keeps the first message snapshot independent from later composer attachments', () => {
    const firstContext = addExpertMessageMaterialContext([], material('m1', 'Первый.pdf'))
    const firstSnapshot = snapshotExpertMessageMaterialContext(firstContext)
    const nextComposerContext = addExpertMessageMaterialContext(firstContext, material('m2', 'Второй.pdf'))

    expect(firstSnapshot.map((context) => context.id)).toEqual(['m1'])
    expect(nextComposerContext.map((context) => context.id)).toEqual(['m1', 'm2'])
    expect(firstSnapshot).not.toBe(firstContext)
  })

  it('blocks image context while allowing XLSX context', () => {
    const image = {
      ...material('image-1', 'Фото.png'),
      kind: 'image' as const,
      format: 'PNG',
    }
    const first = [createExpertMessageMaterialContext(material('m1', 'Первый.pdf'))]
    const restored = mergeExpertMessageMaterialContexts(
      [createExpertMessageMaterialContext(material('m2', 'Новый.docx'))],
      first,
    )

    expect(isExpertMaterialSupportedForAiContext(createExpertMessageMaterialContext(image))).toBe(false)
    expect(getExpertChatAttachmentSendBlockReason([], [createExpertMessageMaterialContext(image)])).toContain('можно хранить в проекте')
    expect(isExpertMaterialSupportedForAiContext(createExpertMessageMaterialContext(material('xlsx-1', 'Расчёт.xlsx', 'XLSX')))).toBe(true)
    expect(restored.map((context) => context.id)).toEqual(['m2', 'm1'])
  })
})
