// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest'
import { notifyStorageUsageChanged, STORAGE_USAGE_CHANGED_EVENT } from './storageUsageEvents'

afterEach(() => vi.restoreAllMocks())

describe('storage usage refresh event', () => {
  it('notifies open storage and billing screens after a successful mutation', () => {
    const listener = vi.fn()
    window.addEventListener(STORAGE_USAGE_CHANGED_EVENT, listener)

    notifyStorageUsageChanged()

    expect(listener).toHaveBeenCalledTimes(1)
    window.removeEventListener(STORAGE_USAGE_CHANGED_EVENT, listener)
  })
})
