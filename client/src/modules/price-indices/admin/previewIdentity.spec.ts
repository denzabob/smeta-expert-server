import { describe, expect, it } from 'vitest'
import { isCurrentPreviewIdentity } from './previewIdentity'

describe('preview identity', () => {
  it('rejects a late response for A after the selection switched to B', () => {
    let displayedPreview = 'preview-b'
    const selectedPreview = { publicId: 'preview-b', sourceFilePublicId: 'file-b' }
    const acceptResponse = (publicId: string, sourceFilePublicId: string) => {
      if (isCurrentPreviewIdentity(publicId, sourceFilePublicId, selectedPreview.publicId, selectedPreview.sourceFilePublicId)) {
        displayedPreview = publicId
      }
    }

    acceptResponse('preview-a', 'file-a')
    expect(displayedPreview).toBe('preview-b')

    acceptResponse('preview-b', 'file-b')
    expect(displayedPreview).toBe('preview-b')
  })
})
