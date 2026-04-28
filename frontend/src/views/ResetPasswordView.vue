<script setup>
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { authService } from '@/services/auth'
import Password from 'primevue/password'
import Button from 'primevue/button'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()

const password = ref('')
const loading = ref(false)
const success = ref(false)
const errorKey = ref(null)

async function handleSubmit() {
  errorKey.value = null
  loading.value = true
  try {
    await authService.resetPassword(route.query.token, password.value)
    success.value = true
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
      <h1 class="text-2xl font-bold text-center mb-6 text-gray-900 dark:text-gray-100">{{ t('auth.reset_password') }}</h1>

      <div v-if="success" class="mb-4 p-3 bg-green-50 dark:bg-green-900/30 border border-green-200 dark:border-green-800 rounded text-green-700 dark:text-green-400 text-sm">
        {{ t('auth.success.password_reset') }}
        <RouterLink to="/login" class="block mt-2 text-brand-green-700 dark:text-brand-green-400 hover:underline">{{ t('auth.back_to_login') }}</RouterLink>
      </div>

      <div v-if="errorKey" class="mb-4 p-3 bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 rounded text-red-700 dark:text-red-400 text-sm">
        {{ t(errorKey) }}
      </div>

      <form v-if="!success" @submit.prevent="handleSubmit" class="flex flex-col gap-4">
        <div class="flex flex-col gap-1">
          <label for="password" class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ t('auth.new_password') }}</label>
          <Password id="password" v-model="password" toggle-mask required input-class="w-full" class="w-full" />
        </div>

        <Button type="submit" :label="t('auth.reset_password_button')" :loading="loading" class="w-full mt-2" />
      </form>

      <p v-if="!success" class="text-center mt-4 text-sm text-gray-600 dark:text-gray-400">
        <RouterLink to="/login" class="text-brand-green-700 dark:text-brand-green-400 hover:underline">{{ t('auth.back_to_login') }}</RouterLink>
      </p>
    </div>
  </div>
</template>
