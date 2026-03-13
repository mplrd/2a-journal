<script setup>
import { ref, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { api } from '@/services/api'
import Button from 'primevue/button'

const { t } = useI18n()
const route = useRoute()

const loading = ref(true)
const success = ref(false)
const errorKey = ref(null)

onMounted(async () => {
  const token = route.query.token
  if (!token) {
    errorKey.value = 'auth.error.invalid_verification_token'
    loading.value = false
    return
  }

  try {
    await api.get(`/auth/verify-email?token=${encodeURIComponent(token)}`, { auth: false })
    success.value = true
  } catch (err) {
    errorKey.value = err.messageKey || 'error.internal'
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div class="min-h-screen flex items-center justify-center bg-gray-50 dark:bg-gray-900">
    <div class="w-full max-w-md p-8 bg-white dark:bg-gray-800 rounded-lg shadow-md text-center">
      <h1 class="text-2xl font-bold mb-6 text-gray-900 dark:text-gray-100">{{ t('auth.verify_email') }}</h1>

      <div v-if="loading" class="text-gray-500 py-4">
        <i class="pi pi-spin pi-spinner text-2xl"></i>
        <p class="mt-2">{{ t('common.loading') }}</p>
      </div>

      <div v-else-if="success" class="p-3 bg-green-50 dark:bg-green-900/30 border border-green-200 dark:border-green-800 rounded text-green-700 dark:text-green-400 text-sm">
        {{ t('auth.success.email_verified') }}
        <RouterLink to="/login" class="block mt-3">
          <Button :label="t('auth.login_button')" class="w-full" />
        </RouterLink>
      </div>

      <div v-else class="p-3 bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 rounded text-red-700 dark:text-red-400 text-sm">
        {{ t(errorKey) }}
        <RouterLink to="/login" class="block mt-3 text-blue-600 dark:text-blue-400 hover:underline">
          {{ t('auth.back_to_login') }}
        </RouterLink>
      </div>
    </div>
  </div>
</template>
