import type { ThemeDefinition } from 'vuetify'

const saasDarkTheme: ThemeDefinition = {
  dark: true,
  colors: {
    background: '#0f1720',
    surface: '#161f2c',
    'surface-bright': '#1b2535',
    'surface-variant': '#223044',
    'on-background': '#e5e7eb',
    'on-surface': '#e5e7eb',
    'on-surface-variant': '#94a3b8',
    primary: '#38bdf8',
    'primary-darken-1': '#0ea5e9',
    secondary: '#334155',
    success: '#22c55e',
    warning: '#f59e0b',
    error: '#ef4444',
    info: '#38bdf8',
  },
  variables: {
    'border-color': '#ffffff',
    'border-opacity': 0.08,
    'high-emphasis-opacity': 0.95,
    'medium-emphasis-opacity': 0.72,
    'disabled-opacity': 0.45,
    'hover-opacity': 0.08,
    'focus-opacity': 0.12,
    'selected-opacity': 0.1,
    'activated-opacity': 0.1,
    'pressed-opacity': 0.12,
    'dragged-opacity': 0.08,
    'theme-kbd': '#223044',
    'theme-on-kbd': '#e5e7eb',
    'theme-code': '#1b2535',
    'theme-on-code': '#e5e7eb',
  },
}

const saasLightTheme: ThemeDefinition = {
  dark: false,
  colors: {
    background: '#f4f8fd',
    surface: '#ffffff',
    'surface-bright': '#f8fbff',
    'surface-variant': '#e7edf5',
    'on-background': '#0f1720',
    'on-surface': '#1e293b',
    'on-surface-variant': '#64748b',
    primary: '#0284c7',
    'primary-darken-1': '#0369a1',
    secondary: '#64748b',
    success: '#16a34a',
    warning: '#d97706',
    error: '#dc2626',
    info: '#0284c7',
  },
}

export const saasDesignSystem = {
  themes: {
    saasDark: saasDarkTheme,
    saasLight: saasLightTheme,
    // Aliases for legacy toggles used in older layouts.
    myTheme: saasLightTheme,
    myThemeDark: saasDarkTheme,
    expertLight: saasLightTheme,
    expertDark: saasDarkTheme,
  },
  defaults: {
    global: {
      ripple: true,
    },
    VBtn: {
      rounded: 'md',
      elevation: 1,
      density: 'compact',
      style: 'text-transform:none;letter-spacing:0;font-weight:600;',
    },
    VCard: {
      rounded: 'lg',
      elevation: 2,
    },
    VTextField: {
      density: 'compact',
      rounded: 'md',
      variant: 'outlined',
      hideDetails: 'auto',
    },
    VSelect: {
      density: 'compact',
      rounded: 'md',
      variant: 'outlined',
      hideDetails: 'auto',
    },
    VTextarea: {
      density: 'compact',
      rounded: 'md',
      variant: 'outlined',
      hideDetails: 'auto',
    },
    VSwitch: {
      density: 'compact',
      inset: false,
      color: 'primary',
    },
    VDialog: {
      maxWidth: 720,
    },
    VSheet: {
      rounded: 'md',
    },
    VListItem: {
      rounded: 'sm',
    },
    VNavigationDrawer: {
      elevation: 0,
    },
    VChip: {
      rounded: 'md',
    },
    VDataTable: {
      density: 'comfortable',
    },
  },
}
