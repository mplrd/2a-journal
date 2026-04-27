<script setup>
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import Button from 'primevue/button'

const router = useRouter()
const { t } = useI18n()
const auth = useAuthStore()

async function logout() {
  await auth.logout()
  router.push('/login')
}
</script>

<template>
  <div class="min-h-screen bg-gray-50 dark:bg-gray-900">
    <header class="bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 px-6 py-3 flex items-center justify-between">
      <div class="flex items-center gap-6">
        <h1 class="font-bold text-lg">{{ t('app.title') }}</h1>
        <nav class="flex gap-4">
          <router-link to="/users" class="text-sm hover:underline" active-class="font-semibold text-blue-600">
            {{ t('nav.users') }}
          </router-link>
          <router-link to="/settings" class="text-sm hover:underline" active-class="font-semibold text-blue-600">
            {{ t('nav.settings') }}
          </router-link>
        </nav>
      </div>
      <div class="flex items-center gap-3 text-sm">
        <span v-if="auth.user" class="text-gray-600 dark:text-gray-300">
          {{ auth.user.email }}
        </span>
        <Button :label="t('common.logout')" icon="pi pi-sign-out" size="small" severity="secondary" @click="logout" />
      </div>
    </header>
    <main class="p-6 w-full">
      <slot />
    </main>
  </div>
</template>
