export type AppThemeMode = 'light' | 'dark' | 'auto'
export type AppThemeName = 'saasLight' | 'saasDark'

export const APP_THEME_MODE_STORAGE_KEY = 'app-theme-mode'
export const DEFAULT_APP_THEME_MODE: AppThemeMode = 'light'

const LEGACY_APP_THEME_STORAGE_KEY = 'app-theme'
const LEGACY_PARSER_THEME_STORAGE_KEY = 'theme'

function readLocalStorage(key: string): string | null {
  if (typeof window === 'undefined') return null

  try {
    return window.localStorage.getItem(key)
  } catch {
    return null
  }
}

export function isAppThemeMode(value: unknown): value is AppThemeMode {
  return value === 'light' || value === 'dark' || value === 'auto'
}

export function getSystemPrefersDark(): boolean {
  if (typeof window === 'undefined' || !('matchMedia' in window)) {
    return false
  }

  try {
    return window.matchMedia('(prefers-color-scheme: dark)').matches
  } catch {
    return false
  }
}

export function readStoredThemeMode(): AppThemeMode {
  const storedMode = readLocalStorage(APP_THEME_MODE_STORAGE_KEY)
  if (isAppThemeMode(storedMode)) return storedMode

  const legacyAppTheme = readLocalStorage(LEGACY_APP_THEME_STORAGE_KEY)
  if (legacyAppTheme === 'myThemeDark') return 'dark'
  if (legacyAppTheme === 'myTheme') return 'light'

  const legacyParserTheme = readLocalStorage(LEGACY_PARSER_THEME_STORAGE_KEY)
  if (legacyParserTheme === 'expertDark') return 'dark'
  if (legacyParserTheme === 'expertLight') return 'light'

  return DEFAULT_APP_THEME_MODE
}

export function writeStoredThemeMode(mode: AppThemeMode): void {
  if (typeof window === 'undefined') return

  try {
    window.localStorage.setItem(APP_THEME_MODE_STORAGE_KEY, mode)
  } catch {
    // Storage can be unavailable in private modes; keep runtime behavior stable.
  }
}

export function resolveThemeName(mode: AppThemeMode, systemPrefersDark = false): AppThemeName {
  return mode === 'dark' || (mode === 'auto' && systemPrefersDark)
    ? 'saasDark'
    : 'saasLight'
}

export function getInitialThemeName(): AppThemeName {
  return resolveThemeName(readStoredThemeMode(), getSystemPrefersDark())
}
