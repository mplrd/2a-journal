import { createApp } from 'vue'
import PrimeVue from 'primevue/config'
import ToastService from 'primevue/toastservice'
import ConfirmationService from 'primevue/confirmationservice'
import Tooltip from 'primevue/tooltip'
import { definePreset } from '@primeuix/themes'
import Aura from '@primeuix/themes/aura'

import App from './App.vue'
import pinia from './stores'
import router from './router'
import { i18n } from './locales'

import 'primeicons/primeicons.css'
import './assets/main.css'

const Brand = definePreset(Aura, {
  semantic: {
    primary: {
      50: '#e7eaf0',
      100: '#c9d1de',
      200: '#9faab9',
      300: '#6d7c98',
      400: '#45587a',
      500: '#1f2a3c',
      600: '#1a2435',
      700: '#16181c',
      800: '#0d0e10',
      900: '#080809',
      950: '#040405',
    },
  },
})

if (localStorage.getItem('theme') === 'dark') {
  document.documentElement.classList.add('dark-mode')
}

const app = createApp(App)

app.use(pinia)
app.use(router)
app.use(i18n)
app.use(PrimeVue, {
  theme: {
    preset: Brand,
    options: {
      darkModeSelector: '.dark-mode',
    },
  },
})
app.use(ToastService)
app.use(ConfirmationService)
app.directive('tooltip', Tooltip)

app.mount('#app')
