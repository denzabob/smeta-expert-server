import type { ExpertMaterialKind, ExpertProjectMaterial } from './types'

export type ExpertMaterialAccent = 'pdf' | 'word' | 'spreadsheet' | 'archive' | 'image' | 'neutral'

export interface ExpertMaterialPresentation {
  kind: ExpertMaterialKind
  format: string
  icon: string
  accent: ExpertMaterialAccent
}

type MaterialPresentationSource = {
  extension?: string | null
  format?: string | null
  mimeType?: string | null
}

export function safeMaterialDisplayName(originalName: string | null | undefined, extension?: string | null): string {
  const normalizedName = originalName?.trim()
  if (normalizedName && !looksLikeStorageFilename(normalizedName)) return normalizedName

  const normalizedExtension = normalizeExtension(extension)
  return normalizedExtension ? `Материал без названия.${normalizedExtension}` : 'Материал без названия'
}

export function describeMaterialFile(file: Pick<File, 'name' | 'size' | 'type'>): ExpertMaterialPresentation {
  return describeMaterial({ extension: extensionFromName(file.name), mimeType: file.type })
}

export function describeProjectMaterial(material: Pick<ExpertProjectMaterial, 'format' | 'mimeType' | 'kind'>): ExpertMaterialPresentation {
  const presentation = describeMaterial({ extension: material.format, mimeType: material.mimeType })
  return material.kind === 'image' && presentation.kind !== 'image'
    ? { ...presentation, kind: 'image', icon: 'mdi-file-image-outline', accent: 'image' }
    : material.kind === 'other' && presentation.accent === 'neutral'
      ? { ...presentation, kind: 'other' }
      : presentation
}

export function formatMaterialSize(value: number): string {
  if (value < 1024) return `${value} Б`
  if (value < 1024 * 1024) return `${(value / 1024).toFixed(1)} КБ`
  return `${(value / 1024 / 1024).toFixed(1)} МБ`
}

function describeMaterial(source: MaterialPresentationSource): ExpertMaterialPresentation {
  const extension = normalizeExtension(source.extension)
  const mimeType = source.mimeType?.toLowerCase() ?? ''
  const format = extension ? extension.toUpperCase() : 'ФАЙЛ'

  if (mimeType.startsWith('image/') || ['jpg', 'jpeg', 'png', 'webp', 'gif', 'heic'].includes(extension)) {
    return { kind: 'image', format, icon: 'mdi-file-image-outline', accent: 'image' }
  }
  if (extension === 'pdf') return { kind: 'document', format, icon: 'mdi-file-pdf-box', accent: 'pdf' }
  if (['doc', 'docx', 'odt', 'rtf'].includes(extension)) return { kind: 'document', format, icon: 'mdi-file-word-outline', accent: 'word' }
  if (['xls', 'xlsx', 'csv', 'ods'].includes(extension)) return { kind: 'spreadsheet', format, icon: 'mdi-file-excel-outline', accent: 'spreadsheet' }
  if (['zip', 'rar', '7z', 'tar', 'gz'].includes(extension)) return { kind: 'other', format, icon: 'mdi-folder-zip-outline', accent: 'archive' }
  return { kind: 'document', format, icon: 'mdi-file-document-outline', accent: 'neutral' }
}

function extensionFromName(name: string): string {
  const suffix = name.trim().split('.').pop()
  return suffix && suffix !== name ? suffix : ''
}

function normalizeExtension(extension?: string | null): string {
  return extension?.trim().replace(/^\./, '').toLowerCase() ?? ''
}

function looksLikeStorageFilename(name: string): boolean {
  return /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}(?:\.[a-z0-9]{1,16})?$/i.test(name)
}
