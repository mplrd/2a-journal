import { createI18n } from 'vue-i18n'
import fr from './fr.json'
import en from './en.json'

const savedLocale = typeof localStorage !== 'undefined' ? localStorage.getItem('locale') : null

export const i18n = createI18n({
  legacy: false,
  locale: savedLocale || import.meta.env.VITE_DEFAULT_LOCALE || 'fr',
  fallbackLocale: 'en',
  messages: { fr, en },
})
