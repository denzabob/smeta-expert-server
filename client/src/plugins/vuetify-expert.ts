/**
 * Vuetify Expert Theme Configuration
 * Angular design with border-radius: 0
 * Status colors: green=success, blue=running, orange=stale, red=failed
 */

import { createVuetify } from 'vuetify'
import * as components from 'vuetify/components'
import * as directives from 'vuetify/directives'
import '@mdi/font/css/materialdesignicons.css'
import 'vuetify/styles'

// Expert dark theme
const expertDarkTheme = {
  dark: true,
  colors: {
    background: '#121212',
    surface: '#1e1e1e',
    'surface-bright': '#2a2a2a',
    'surface-variant': '#424242',
    'on-surface-variant': '#EEEEEE',
    primary: '#2196F3',       // Blue for running state
    'primary-darken-1': '#1976D2',
    secondary: '#424242',
    'secondary-darken-1': '#1F1F1F',
    error: '#F44336',         // Red for failed state
    info: '#2196F3',
    success: '#4CAF50',       // Green for completed state
    warning: '#FF9800',       // Orange for stale/timeout state
  },
  variables: {
    'border-color': '#000000',
    'border-opacity': 0.12,
    'high-emphasis-opacity': 0.87,
    'medium-emphasis-opacity': 0.60,
    'disabled-opacity': 0.38,
    'idle-opacity': 0.04,
    'hover-opacity': 0.08,
    'focus-opacity': 0.12,
    'selected-opacity': 0.12,
    'activated-opacity': 0.12,
    'pressed-opacity': 0.12,
    'dragged-opacity': 0.08,
    'theme-kbd': '#212529',
    'theme-on-kbd': '#FFFFFF',
    'theme-code': '#F5F5F5',
    'theme-on-code': '#000000',
  }
}

// Expert light theme
const expertLightTheme = {
  dark: false,
  colors: {
    background: '#FAFAFA',
    surface: '#FFFFFF',
    'surface-bright': '#FFFFFF',
    'surface-variant': '#F5F5F5',
    'on-surface-variant': '#424242',
    primary: '#2196F3',       // Blue for running state
    'primary-darken-1': '#1976D2',
    secondary: '#757575',
    'secondary-darken-1': '#424242',
    error: '#F44336',         // Red for failed state
    info: '#2196F3',
    success: '#4CAF50',       // Green for completed state
    warning: '#FF9800',       // Orange for stale/timeout state
  }
}

export const expertTheme = {
  themes: {
    expertLight: expertLightTheme,
    expertDark: expertDarkTheme,
  },
  defaults: {
    global: {
      // Angular design - no border radius
      borderRadius: 0,
    },
    VBtn: {
      style: 'text-transform: none;',
      borderRadius: 0,
    },
    VCard: {
      borderRadius: 0,
      elevation: 2,
    },
    VTextField: {
      borderRadius: 0,
    },
    VSelect: {
      borderRadius: 0,
    },
    VTextarea: {
      borderRadius: 0,
    },
    VChip: {
      borderRadius: 0,
    },
    VAlert: {
      borderRadius: 0,
    },
    VDialog: {
      borderRadius: 0,
    },
    VSheet: {
      borderRadius: 0,
    },
  },
}

export default createVuetify({
  components,
  directives,
  theme: {
    defaultTheme: 'expertLight',
    themes: expertTheme.themes,
  },
  defaults: expertTheme.defaults,
  icons: {
    defaultSet: 'mdi',
  },
})
