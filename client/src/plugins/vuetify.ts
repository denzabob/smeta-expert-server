// src/plugins/vuetify.ts
import 'vuetify/styles'
import { createVuetify } from 'vuetify'
import { ru } from 'vuetify/locale'

const myTheme = {
  dark: false,
  colors: {
    primary: '#1976D2',
  }
}

export default createVuetify({
  locale: {
    locale: 'ru',
    messages: { ru },
  },
  theme: {
    defaultTheme: 'myTheme',
    themes: {
      myTheme,
    }
  }
})
