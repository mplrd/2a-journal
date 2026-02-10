import { createI18n } from 'vue-i18n'
import fr from './fr.json'
import en from './en.json'

export const i18n = createI18n({
  legacy: false,
  locale: import.meta.env.VITE_DEFAULT_LOCALE || 'fr',
  fallbackLocale: 'en',
  messages: { fr, en },
})
