<script setup>
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { authService } from '@/services/auth'
import InputText from 'primevue/inputtext'
import Button from 'primevue/button'

const { t } = useI18n()

const email = ref('')
const loading = ref(false)
const sent = ref(false)
const errorKey = ref(null)

async function handleSubmit() {
  errorKey.value = null
  loading.value = true
  try {
    await authService.forgotPassword(email.value)
    sent.value = true
  } catch (err) {
    errorKey.value = err.messageKey || 'error.internal'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="min-h-screen flex items-center justify-center bg-gray-50 dark:bg-gray-900">
    <div class="w-full max-w-md p-8 bg-white dark:bg-gray-800 rounded-lg shadow-md">
      <h1 class="text-2xl font-bold text-center mb-6 text-gray-900 dark:text-gray-100">{{ t('auth.forgot_password') }}</h1>

      <div v-if="sent" class="mb-4 p-3 bg-green-50 dark:bg-green-900/30 border border-green-200 dark:border-green-800 rounded text-green-700 dark:text-green-400 text-sm">
        {{ t('auth.forgot_password_sent') }}
      </div>

      <div v-if="errorKey" class="mb-4 p-3 bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 rounded text-red-700 dark:text-red-400 text-sm">
        {{ t(errorKey) }}
      </div>

      <form v-if="!sent" @submit.prevent="handleSubmit" class="flex flex-col gap-4">
        <p class="text-sm text-gray-600 dark:text-gray-400">{{ t('auth.forgot_password_description') }}</p>

        <div class="flex flex-col gap-1">
          <label for="email" class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ t('auth.email') }}</label>
          <InputText id="email" v-model="email" type="email" required class="w-full" />
        </div>

        <Button type="submit" :label="t('auth.send_reset_link')" :loading="loading" class="w-full mt-2" />
      </form>

      <p class="text-center mt-4 text-sm text-gray-600 dark:text-gray-400">
        <RouterLink to="/login" class="text-blue-600 dark:text-blue-400 hover:underline">{{ t('auth.back_to_login') }}</RouterLink>
      </p>
    </div>
  </div>
</template>
