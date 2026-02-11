<script setup>
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { onMounted } from 'vue'
import Button from 'primevue/button'

const { t } = useI18n()
const router = useRouter()
const authStore = useAuthStore()

onMounted(() => {
  authStore.initFromStorage()
})

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
