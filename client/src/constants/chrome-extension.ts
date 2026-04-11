/**
 * Chrome Extension constants.
 * Single source of truth for the Prismcore Chrome Extension store URL.
 */

export const CHROME_EXTENSION_STORE_URL =
  'https://chromewebstore.google.com/detail/ciglfohallampekohacnicpdidhelbje'

/**
 * Open the Chrome Extension page in a new tab safely.
 */
export function openChromeExtensionStore(): void {
  window.open(CHROME_EXTENSION_STORE_URL, '_blank', 'noopener,noreferrer')
}
