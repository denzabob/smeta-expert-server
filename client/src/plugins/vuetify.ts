// src/plugins/vuetify.ts
import 'vuetify/styles'
import { createVuetify } from 'vuetify'
import { ru } from 'vuetify/locale'
import { getInitialThemeName } from './appTheme'
import { saasDesignSystem } from './theme'

export default createVuetify({
  locale: {
    locale: 'ru',
    messages: { ru },
  },
  theme: {
    defaultTheme: getInitialThemeName(),
    themes: saasDesignSystem.themes,
  },
  defaults: saasDesignSystem.defaults,
  icons: {
    defaultSet: 'mdi',
  },
})
