import { describe, expect, it, vi } from 'vitest'
import { useExpertMaterialTransfers, type ExpertMaterialTransferApi } from './useExpertMaterialTransfers'
import type { ExpertProjectMaterial } from '../types'

const flush = async () => {
  await Promise.resolve()
  await Promise.resolve()
}

const file = (name: string, size = 100): File => ({ name, size, type: 'application/pdf', lastModified: 1 } as File)
const material = (id: string, name = 'Заключение.pdf'): ExpertProjectMaterial => ({
  id,
  name,
  kind: 'document',
  format: 'PDF',
  meta: 'application/pdf',
  size: '100 Б',
  category: 'document',
  status: 'Загружен',
  useInAi: false,
  icon: 'mdi-file-pdf-box',
})
const imageMaterial = (id: string): ExpertProjectMaterial => ({ ...material(id, 'Фото.jpg'), kind: 'image', format: 'JPG', meta: 'image/jpeg', category: 'image', icon: 'mdi-image-outline' })

function transferApi(overrides: Partial<ExpertMaterialTransferApi> = {}): ExpertMaterialTransferApi {
  return {
    uploadMaterial: vi.fn(),
    downloadMaterial: vi.fn(),
    getMaterialImageContent: vi.fn(),
    ...overrides,
  }
}

describe('Expert material transfers', () => {
  it('adds an upload item immediately and reports real per-file progress', async () => {
    let reportProgress: ((progress: number) => void) | undefined
    const api = transferApi({
      uploadMaterial: vi.fn((_projectId, _file, options) => {
        reportProgress = options?.onProgress
        return Promise.resolve(material('m1'))
      }),
    })
    const completed = vi.fn()
    const transfers = useExpertMaterialTransfers(api, vi.fn())

    transfers.queueUploads('p1', [file('Заключение.pdf')], completed)
    expect(transfers.uploads.value).toEqual([expect.objectContaining({ name: 'Заключение.pdf', state: 'uploading', progress: 0 })])

    reportProgress?.(43)
    expect(transfers.uploads.value[0]).toMatchObject({ state: 'uploading', progress: 43 })
    await flush()

    expect(completed).toHaveBeenCalledWith(expect.objectContaining({ id: 'm1', name: 'Заключение.pdf' }))
    expect(transfers.uploads.value).toEqual([])
  })

  it('keeps a failed file available for retry while successful files complete', async () => {
    const api = transferApi({
      uploadMaterial: vi.fn()
        .mockResolvedValueOnce(material('m1', 'Первый.pdf'))
        .mockRejectedValueOnce(new Error('network'))
        .mockResolvedValueOnce(material('m2', 'Второй.pdf')),
    })
    const completed = vi.fn()
    const transfers = useExpertMaterialTransfers(api, vi.fn())

    transfers.queueUploads('p1', [file('Первый.pdf'), file('Второй.pdf')], completed)
    await flush()

    expect(completed).toHaveBeenCalledTimes(1)
    expect(transfers.uploads.value).toEqual([expect.objectContaining({ name: 'Второй.pdf', state: 'error', progress: 0 })])

    const failedUpload = transfers.uploads.value[0]
    if (!failedUpload) throw new Error('Failed upload must remain retryable')
    transfers.retryUpload('p1', failedUpload.id)
    await flush()

    expect(completed).toHaveBeenCalledTimes(2)
    expect(transfers.uploads.value).toEqual([])
  })

  it('uploads a selected batch serially in selection order for partial quota success', async () => {
    let finishFirst: ((value: ExpertProjectMaterial) => void) | undefined
    const api = transferApi({
      uploadMaterial: vi.fn()
        .mockImplementationOnce(() => new Promise<ExpertProjectMaterial>((resolve) => { finishFirst = resolve }))
        .mockResolvedValueOnce(material('m2', 'Второй.pdf')),
    })
    const transfers = useExpertMaterialTransfers(api, vi.fn())
    const completed: string[] = []

    transfers.queueUploads('p1', [file('Первый.pdf'), file('Второй.pdf')], (item) => completed.push(item.id))
    expect(api.uploadMaterial).toHaveBeenCalledTimes(1)
    expect(api.uploadMaterial).toHaveBeenNthCalledWith(1, 'p1', expect.objectContaining({ name: 'Первый.pdf' }), expect.anything())

    finishFirst?.(material('m1', 'Первый.pdf'))
    await flush()

    expect(api.uploadMaterial).toHaveBeenCalledTimes(2)
    expect(api.uploadMaterial).toHaveBeenNthCalledWith(2, 'p1', expect.objectContaining({ name: 'Второй.pdf' }), expect.anything())
    expect(completed).toEqual(['m1', 'm2'])
  })

  it('does not queue a duplicate file and allows removing only its failed transfer item', async () => {
    const api = transferApi({ uploadMaterial: vi.fn().mockRejectedValue(new Error('network')) })
    const transfers = useExpertMaterialTransfers(api, vi.fn())

    const first = transfers.queueUploads('p1', [file('Повтор.pdf')], vi.fn())
    const duplicate = transfers.queueUploads('p1', [file('Повтор.pdf')], vi.fn())
    await flush()

    expect(first).toHaveLength(1)
    expect(duplicate).toEqual([])
    expect(transfers.uploads.value).toEqual([expect.objectContaining({ state: 'error', name: 'Повтор.pdf' })])

    const firstUpload = first[0]
    if (!firstUpload) throw new Error('First upload item must exist')
    transfers.removeUpload(firstUpload.id)
    expect(transfers.uploads.value).toEqual([])
  })

  it('clears composer-owned pending items without invoking their completion callback', async () => {
    let resolveUpload: ((value: ExpertProjectMaterial) => void) | undefined
    const api = transferApi({
      uploadMaterial: vi.fn(() => new Promise<ExpertProjectMaterial>((resolve) => { resolveUpload = resolve })),
    })
    const completed = vi.fn()
    const transfers = useExpertMaterialTransfers(api, vi.fn())

    transfers.queueUploads('p1', [file('Старый.pdf')], completed)
    transfers.clearUploads()
    resolveUpload?.(material('m1', 'Старый.pdf'))
    await flush()

    expect(transfers.uploads.value).toEqual([])
    expect(completed).not.toHaveBeenCalled()
  })

  it('prevents duplicate downloads and exposes download progress', async () => {
    let reportProgress: ((progress: number | null) => void) | undefined
    let resolveDownload: ((blob: Blob) => void) | undefined
    const api = transferApi({
      downloadMaterial: vi.fn((_id, options) => new Promise<Blob>((resolve) => {
        reportProgress = options?.onProgress
        resolveDownload = resolve
      })),
    })
    const saveBlob = vi.fn()
    const transfers = useExpertMaterialTransfers(api, saveBlob)
    const source = material('m1')

    const first = transfers.downloadMaterial(source)
    const second = await transfers.downloadMaterial(source)
    reportProgress?.(37)

    expect(second).toEqual({ ok: false, ignored: true })
    expect(api.downloadMaterial).toHaveBeenCalledTimes(1)
    expect(transfers.isDownloading('m1')).toBe(true)
    expect(transfers.downloadProgress('m1')).toBe(37)

    resolveDownload?.({} as Blob)
    await expect(first).resolves.toEqual({ ok: true })
    expect(saveBlob).toHaveBeenCalledWith(expect.anything(), 'Заключение.pdf')
    expect(transfers.isDownloading('m1')).toBe(false)
  })

  it('returns download errors without leaving a material busy', async () => {
    const api = transferApi({ downloadMaterial: vi.fn().mockRejectedValue(new Error('network')) })
    const transfers = useExpertMaterialTransfers(api, vi.fn())

    await expect(transfers.downloadMaterial(material('m1'))).resolves.toEqual(expect.objectContaining({ ok: false, error: expect.any(Error) }))
    expect(transfers.isDownloading('m1')).toBe(false)
  })

  it('loads a thumbnail from the dedicated endpoint and revokes it on release', async () => {
    const api = transferApi({ getMaterialThumbnail: vi.fn().mockResolvedValue({} as Blob) })
    const createObjectURL = vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:thumb')
    const revokeObjectURL = vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => undefined)
    const transfers = useExpertMaterialTransfers(api, vi.fn())

    await transfers.loadImageThumbnail(imageMaterial('m1'))

    expect(api.getMaterialThumbnail).toHaveBeenCalledWith('m1')
    expect(api.getMaterialImageContent).not.toHaveBeenCalled()
    expect(transfers.thumbnailPreviews.value.m1).toMatchObject({ status: 'ready', url: 'blob:thumb' })
    transfers.releaseThumbnail('m1')
    expect(revokeObjectURL).toHaveBeenCalledWith('blob:thumb')
    createObjectURL.mockRestore()
    revokeObjectURL.mockRestore()
  })

  it('does not fetch an original image while synchronising the material list', () => {
    const api = transferApi({ getMaterialThumbnail: vi.fn(), getMaterialImageContent: vi.fn() })
    const transfers = useExpertMaterialTransfers(api, vi.fn())

    transfers.syncImagePreviews([imageMaterial('m1')])

    expect(api.getMaterialThumbnail).not.toHaveBeenCalled()
    expect(api.getMaterialImageContent).not.toHaveBeenCalled()
  })
})
