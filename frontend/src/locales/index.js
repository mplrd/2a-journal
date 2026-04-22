import { createI18n } from 'vue-i18n'
import fr from './fr.json'
import en from './en.json'

const supportedLocales = ['fr', 'en']

function detectLocale() {
  const saved = typeof localStorage !== 'undefined' ? localStorage.getItem('locale') : null
  if (saved && supportedLocales.includes(saved)) return saved

  const browserLang = typeof navigator !== 'undefined' ? navigator.language.slice(0, 2) : null
  if (browserLang && supportedLocales.includes(browserLang)) return browserLang

  return 'en'
}

export const i18n = createI18n({
  legacy: false,
  locale: detectLocale(),
  fallbackLocale: 'en',
  messages: { fr, en },
})
