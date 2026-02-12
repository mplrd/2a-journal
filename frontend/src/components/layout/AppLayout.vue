<script setup>
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import Button from 'primevue/button'

const { t, locale } = useI18n()
const router = useRouter()
const authStore = useAuthStore()

const showLangMenu = ref(false)

const languages = [
  { code: 'fr', label: 'FR' },
  { code: 'en', label: 'EN' },
]

function switchLocale(code) {
  locale.value = code
  localStorage.setItem('locale', code)
  showLangMenu.value = false
}

async function handleLogout() {
  await authStore.logout()
  router.push({ name: 'login' })
}
</script>

<template>
  <div class="min-h-screen bg-gray-50">
    <header class="bg-white shadow-sm border-b border-gray-200">
      <div class="max-w-7xl mx-auto px-4 py-3 flex items-center justify-between">
        <h1 class="text-lg font-semibold text-gray-800">{{ t('app.title') }}</h1>
        <nav class="flex items-center gap-4">
          <RouterLink to="/" class="text-sm text-gray-600 hover:text-gray-900">
            {{ t('nav.dashboard') }}
          </RouterLink>
          <RouterLink to="/accounts" class="text-sm text-gray-600 hover:text-gray-900">
            {{ t('nav.accounts') }}
          </RouterLink>
          <RouterLink to="/positions" class="text-sm text-gray-600 hover:text-gray-900">
            {{ t('nav.positions') }}
          </RouterLink>
          <RouterLink to="/orders" class="text-sm text-gray-600 hover:text-gray-900">
            {{ t('nav.orders') }}
          </RouterLink>
          <RouterLink to="/trades" class="text-sm text-gray-600 hover:text-gray-900">
            {{ t('nav.trades') }}
          </RouterLink>
          <RouterLink to="/symbols" class="text-sm text-gray-600 hover:text-gray-900">
            {{ t('nav.symbols') }}
          </RouterLink>
          <div class="relative">
            <button
              data-testid="language-selector"
              class="text-sm text-gray-600 hover:text-gray-900 font-medium px-2 py-1 rounded border border-gray-300 cursor-pointer"
              @click="showLangMenu = !showLangMenu"
            >
              {{ locale.toUpperCase() }}
            </button>
            <div
              v-if="showLangMenu"
              class="absolute right-0 mt-1 bg-white border border-gray-200 rounded shadow-md z-10"
            >
              <button
                v-for="lang in languages"
                :key="lang.code"
                :data-testid="`lang-option-${lang.code}`"
                class="block w-full text-left px-3 py-1 text-sm hover:bg-gray-100 cursor-pointer"
                :class="{ 'font-bold': locale === lang.code }"
                @click="switchLocale(lang.code)"
              >
                {{ lang.label }}
              </button>
            </div>
          </div>
          <span v-if="authStore.fullName" class="text-sm text-gray-500">{{ authStore.fullName }}</span>
          <Button :label="t('nav.logout')" severity="secondary" size="small" @click="handleLogout" />
        </nav>
      </div>
    </header>
    <main class="max-w-7xl mx-auto px-4 py-6">
      <RouterView />
    </main>
  </div>
</template>
