// src/plugins/vuetify.ts
import 'vuetify/styles'
import { createVuetify } from 'vuetify'
import { ru } from 'vuetify/locale'
import { expertTheme } from './vuetify-expert'

export default createVuetify({
  locale: {
    locale: 'ru',
    messages: { ru },
  },
  theme: {
    defaultTheme: 'expertLight',
    themes: {
      // Legacy themes
      myTheme: {
        dark: false,
        colors: {
          primary: '#1976D2',
          background: '#FFFFFF',
          surface: '#FFFFFF',
        },
      },
      myThemeDark: {
        dark: true,
        colors: {
          primary: '#2196F3',
          background: '#121212',
          surface: '#1E1E1E',
        },
      },
      // Expert themes for parser module
      ...expertTheme.themes,
    },
  },
  defaults: expertTheme.defaults,
})