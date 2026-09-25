export const STORAGE_USAGE_CHANGED_EVENT = 'prism:storage-usage-changed'

export function notifyStorageUsageChanged(): void {
  if (typeof window !== 'undefined') {
    window.dispatchEvent(new Event(STORAGE_USAGE_CHANGED_EVENT))
  }
}
