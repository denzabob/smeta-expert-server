// @vitest-environment jsdom
import type { AxiosInstance } from 'axios'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { STORAGE_USAGE_CHANGED_EVENT } from '@/api/storageUsageEvents'
import { createExpertApi } from './api'

afterEach(() => { document.body.innerHTML = '' })

describe('Expert storage mutations', () => {
  it('announces successful upload, material delete and project delete for snapshot refresh', async () => {
    const post = vi.fn().mockResolvedValue({
      data: {
        public_id: 'material-1',
        original_name: 'Акт.pdf',
        mime_type: 'application/pdf',
        extension: 'pdf',
        size: 1024,
        category: 'document',
        status: 'uploaded',
        created_at: '2026-09-25T10:00:00Z',
      },
    })
    const remove = vi.fn().mockResolvedValue({ data: null })
    const listener = vi.fn()
    window.addEventListener(STORAGE_USAGE_CHANGED_EVENT, listener)
    const api = createExpertApi({ post, delete: remove } as unknown as AxiosInstance)

    await api.uploadMaterial('project-1', new File(['data'], 'Акт.pdf', { type: 'application/pdf' }))
    await api.deleteMaterial('material-1')
    await api.deleteProject('project-1')

    expect(listener).toHaveBeenCalledTimes(3)
    expect(remove).toHaveBeenNthCalledWith(1, '/api/expert/materials/material-1')
    expect(remove).toHaveBeenNthCalledWith(2, '/api/expert/projects/project-1')
    window.removeEventListener(STORAGE_USAGE_CHANGED_EVENT, listener)
  })

  it('does not refresh storage after a rejected upload', async () => {
    const post = vi.fn().mockRejectedValue(new Error('network'))
    const listener = vi.fn()
    window.addEventListener(STORAGE_USAGE_CHANGED_EVENT, listener)
    const api = createExpertApi({ post } as unknown as AxiosInstance)

    await expect(api.uploadMaterial('project-1', new File(['data'], 'Акт.pdf'))).rejects.toThrow('network')

    expect(listener).not.toHaveBeenCalled()
    window.removeEventListener(STORAGE_USAGE_CHANGED_EVENT, listener)
  })
})
