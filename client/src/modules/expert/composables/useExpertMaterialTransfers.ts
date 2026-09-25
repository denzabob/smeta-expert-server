import { computed, ref } from 'vue'
import { expertApi, type ExpertDownloadOptions, type ExpertUploadOptions } from '../api'
import { describeMaterialFile, formatMaterialSize, type ExpertMaterialAccent } from '../materialPresentation'
import type { ExpertMaterialKind, ExpertProjectMaterial } from '../types'

export type MaterialUploadState = 'queued' | 'uploading' | 'processing' | 'completed' | 'error'

export interface ExpertMaterialUploadItem {
  id: string
  fingerprint: string
  file: File
  name: string
  kind: ExpertMaterialKind
  format: string
  size: string
  icon: string
  accent: ExpertMaterialAccent
  state: MaterialUploadState
  progress: number
  error?: unknown
}

export interface ExpertMaterialImagePreview {
  status: 'loading' | 'ready' | 'error'
  url?: string
  error?: unknown
}

export interface ExpertMaterialTransferApi {
  uploadMaterial(projectId: string, file: File, options?: ExpertUploadOptions): Promise<ExpertProjectMaterial>
  downloadMaterial(id: string, options?: ExpertDownloadOptions): Promise<Blob>
  getMaterialImageContent(id: string): Promise<Blob>
  getMaterialThumbnail?(id: string): Promise<Blob>
}

export type MaterialDownloadResult =
  | { ok: true }
  | { ok: false; ignored: true }
  | { ok: false; ignored?: false; error: unknown }

export function useExpertMaterialTransfers(
  api: ExpertMaterialTransferApi = expertApi,
  saveBlob: (blob: Blob, filename: string) => void = saveBlobAsDownload,
) {
  const uploads = ref<ExpertMaterialUploadItem[]>([])
  const downloadStates = ref<Record<string, { progress: number | null }>>({})
  const thumbnailPreviews = ref<Record<string, ExpertMaterialImagePreview>>({})
  const imagePreviews = ref<Record<string, ExpertMaterialImagePreview>>({})
  const uploadCallbacks = new Map<string, (material: ExpertProjectMaterial) => void>()
  const queuedUploadIds: string[] = []
  const previewTokens = new Map<string, number>()
  const thumbnailTokens = new Map<string, number>()
  const thumbnailAccessOrder: string[] = []
  const maxThumbnailCache = 80
  // Preserve deterministic partial-success semantics in the order selected.
  // The server-side usage row lock still protects other tabs and clients.
  const maxConcurrentUploads = 1
  let activeUploads = 0
  let itemSequence = 0
  let previewSequence = 0
  let disposed = false

  const uploadingCount = computed(() => uploads.value.filter((item) => item.state === 'queued' || item.state === 'uploading' || item.state === 'processing').length)

  function queueUploads(projectId: string, files: File[], onCompleted: (material: ExpertProjectMaterial) => void): ExpertMaterialUploadItem[] {
    const activeFingerprints = new Set(
      uploads.value.map((item) => item.fingerprint),
    )
    const queued = files.flatMap((file) => {
      const fingerprint = [projectId, file.name, file.size, file.lastModified].join(':')
      if (activeFingerprints.has(fingerprint)) return []

      activeFingerprints.add(fingerprint)
      const presentation = describeMaterialFile(file)
      const item: ExpertMaterialUploadItem = {
        id: `material-upload-${++itemSequence}`,
        fingerprint,
        file,
        name: file.name || 'Материал без названия',
        kind: presentation.kind,
        format: presentation.format,
        size: formatMaterialSize(file.size),
        icon: presentation.icon,
        accent: presentation.accent,
        state: 'queued',
        progress: 0,
      }
      uploadCallbacks.set(item.id, onCompleted)
      queuedUploadIds.push(item.id)
      return [item]
    })

    if (queued.length) {
      uploads.value = [...uploads.value, ...queued]
      processQueue(projectId)
    }
    return queued
  }

  function retryUpload(projectId: string, id: string) {
    const item = uploads.value.find((candidate) => candidate.id === id)
    if (!item || item.state !== 'error') return

    updateUpload(id, { state: 'queued', progress: 0, error: undefined })
    queuedUploadIds.push(id)
    processQueue(projectId)
  }

  function removeUpload(id: string) {
    const item = uploads.value.find((candidate) => candidate.id === id)
    if (!item || item.state !== 'error') return

    uploads.value = uploads.value.filter((candidate) => candidate.id !== id)
    uploadCallbacks.delete(id)
    const queuedIndex = queuedUploadIds.indexOf(id)
    if (queuedIndex >= 0) queuedUploadIds.splice(queuedIndex, 1)
  }

  function clearUploads() {
    uploads.value = []
    queuedUploadIds.splice(0)
    uploadCallbacks.clear()
  }

  async function downloadMaterial(material: Pick<ExpertProjectMaterial, 'id' | 'name'>): Promise<MaterialDownloadResult> {
    if (downloadStates.value[material.id]) return { ok: false, ignored: true }

    updateDownload(material.id, { progress: null })
    try {
      const blob = await api.downloadMaterial(material.id, {
        onProgress: (progress) => updateDownload(material.id, { progress }),
      })
      updateDownload(material.id, { progress: 100 })
      saveBlob(blob, material.name)
      return { ok: true }
    } catch (error) {
      return { ok: false, error }
    } finally {
      removeDownload(material.id)
    }
  }

  function isDownloading(id: string): boolean {
    return Boolean(downloadStates.value[id])
  }

  function downloadProgress(id: string): number | null | undefined {
    return downloadStates.value[id]?.progress
  }

  async function loadImagePreview(material: Pick<ExpertProjectMaterial, 'id' | 'kind'>): Promise<void> {
    if (disposed || material.kind !== 'image') return

    const existing = imagePreviews.value[material.id]
    if (existing?.status === 'ready' || existing?.status === 'loading') return

    const token = ++previewSequence
    previewTokens.set(material.id, token)
    updateImagePreview(material.id, { status: 'loading' })
    try {
      const blob = await api.getMaterialImageContent(material.id)
      const url = URL.createObjectURL(blob)
      if (disposed || previewTokens.get(material.id) !== token) {
        URL.revokeObjectURL(url)
        return
      }
      updateImagePreview(material.id, { status: 'ready', url })
    } catch (error) {
      if (!disposed && previewTokens.get(material.id) === token) updateImagePreview(material.id, { status: 'error', error })
    }
  }

  async function loadImageThumbnail(material: Pick<ExpertProjectMaterial, 'id' | 'kind'>): Promise<void> {
    if (disposed || material.kind !== 'image') return

    const existing = thumbnailPreviews.value[material.id]
    if (existing?.status === 'ready' || existing?.status === 'loading') return

    const token = (thumbnailTokens.get(material.id) ?? 0) + 1
    thumbnailTokens.set(material.id, token)
    updateThumbnailPreview(material.id, { status: 'loading' })
    try {
      const blob = await (api.getMaterialThumbnail?.(material.id) ?? api.getMaterialImageContent(material.id))
      const url = URL.createObjectURL(blob)
      if (disposed || thumbnailTokens.get(material.id) !== token) {
        URL.revokeObjectURL(url)
        return
      }
      updateThumbnailPreview(material.id, { status: 'ready', url })
      thumbnailAccessOrder.push(material.id)
      trimThumbnailCache()
    } catch (error) {
      if (!disposed && thumbnailTokens.get(material.id) === token) updateThumbnailPreview(material.id, { status: 'error', error })
    }
  }

  function markImagePreviewError(id: string) {
    releaseImagePreview(id)
    updateImagePreview(id, { status: 'error' })
  }

  function markThumbnailError(id: string) {
    releaseThumbnail(id)
    updateThumbnailPreview(id, { status: 'error' })
  }

  function syncImagePreviews(materials: ExpertProjectMaterial[]) {
    const allowed = new Set(materials.filter((material) => material.kind === 'image').map((material) => material.id))
    Object.keys(imagePreviews.value).forEach((id) => {
      if (!allowed.has(id)) releaseImagePreview(id)
    })
    Object.keys(thumbnailPreviews.value).forEach((id) => {
      if (!allowed.has(id)) releaseThumbnail(id)
    })
  }

  function releaseThumbnail(id: string) {
    thumbnailTokens.set(id, (thumbnailTokens.get(id) ?? 0) + 1)
    const preview = thumbnailPreviews.value[id]
    if (preview?.url) URL.revokeObjectURL(preview.url)
    const { [id]: _removed, ...remaining } = thumbnailPreviews.value
    thumbnailPreviews.value = remaining
    let index = thumbnailAccessOrder.indexOf(id)
    while (index >= 0) {
      thumbnailAccessOrder.splice(index, 1)
      index = thumbnailAccessOrder.indexOf(id)
    }
  }

  function releaseImagePreview(id: string) {
    previewTokens.set(id, ++previewSequence)
    const preview = imagePreviews.value[id]
    if (preview?.url) URL.revokeObjectURL(preview.url)
    const { [id]: _removed, ...remaining } = imagePreviews.value
    imagePreviews.value = remaining
  }

  function dispose() {
    disposed = true
    Object.values(imagePreviews.value).forEach((preview) => {
      if (preview.url) URL.revokeObjectURL(preview.url)
    })
    imagePreviews.value = {}
    Object.values(thumbnailPreviews.value).forEach((preview) => {
      if (preview.url) URL.revokeObjectURL(preview.url)
    })
    thumbnailPreviews.value = {}
    thumbnailAccessOrder.splice(0)
  }

  function processQueue(projectId: string) {
    while (activeUploads < maxConcurrentUploads && queuedUploadIds.length) {
      const id = queuedUploadIds.shift()
      const item = uploads.value.find((candidate) => candidate.id === id)
      if (!item || item.state !== 'queued') continue

      activeUploads += 1
      void uploadItem(projectId, item).finally(() => {
        activeUploads -= 1
        processQueue(projectId)
      })
    }
  }

  async function uploadItem(projectId: string, item: ExpertMaterialUploadItem) {
    updateUpload(item.id, { state: 'uploading', progress: 0, error: undefined })
    try {
      const material = await api.uploadMaterial(projectId, item.file, {
        onProgress: (progress) => updateUpload(item.id, { progress }),
      })
      updateUpload(item.id, { state: 'completed', progress: 100 })
      const onCompleted = uploadCallbacks.get(item.id)
      uploadCallbacks.delete(item.id)
      uploads.value = uploads.value.filter((candidate) => candidate.id !== item.id)
      onCompleted?.(material)
    } catch (error) {
      updateUpload(item.id, { state: 'error', error })
    }
  }

  function updateUpload(id: string, patch: Partial<ExpertMaterialUploadItem>) {
    uploads.value = uploads.value.map((item) => item.id === id ? { ...item, ...patch } : item)
  }

  function updateDownload(id: string, state: { progress: number | null }) {
    downloadStates.value = { ...downloadStates.value, [id]: state }
  }

  function removeDownload(id: string) {
    const { [id]: _removed, ...remaining } = downloadStates.value
    downloadStates.value = remaining
  }

  function updateImagePreview(id: string, preview: ExpertMaterialImagePreview) {
    const previous = imagePreviews.value[id]
    if (previous?.url && previous.url !== preview.url) URL.revokeObjectURL(previous.url)
    imagePreviews.value = { ...imagePreviews.value, [id]: preview }
  }

  function updateThumbnailPreview(id: string, preview: ExpertMaterialImagePreview) {
    const previous = thumbnailPreviews.value[id]
    if (previous?.url && previous.url !== preview.url) URL.revokeObjectURL(previous.url)
    thumbnailPreviews.value = { ...thumbnailPreviews.value, [id]: preview }
  }

  function trimThumbnailCache() {
    while (thumbnailAccessOrder.length > maxThumbnailCache) {
      const candidate = thumbnailAccessOrder.shift()
      if (candidate) releaseThumbnail(candidate)
    }
  }

  return {
    uploads,
    uploadingCount,
    imagePreviews,
    thumbnailPreviews,
    queueUploads,
    retryUpload,
    removeUpload,
    clearUploads,
    downloadMaterial,
    isDownloading,
    downloadProgress,
    loadImagePreview,
    loadImageThumbnail,
    markImagePreviewError,
    markThumbnailError,
    syncImagePreviews,
    releaseImagePreview,
    releaseThumbnail,
    dispose,
  }
}

function saveBlobAsDownload(blob: Blob, filename: string) {
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  document.body.appendChild(link)
  try {
    link.click()
  } finally {
    link.remove()
    window.setTimeout(() => URL.revokeObjectURL(url), 0)
  }
}
