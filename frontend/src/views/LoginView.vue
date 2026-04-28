<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import InputText from 'primevue/inputtext'
import Password from 'primevue/password'
import Button from 'primevue/button'
import BrandLogo from '@/components/common/BrandLogo.vue'

const { t } = useI18n()
const router = useRouter()
const authStore = useAuthStore()

const form = ref({ email: '', password: '' })
const errorKey = ref(null)

async function handleLogin() {
  errorKey.value = null
  try {
    await authStore.login(form.value)
    router.push({ name: 'dashboard' })
  } catch (err) {
    errorKey.value = err.messageKey || 'error.internal'
  }
}
</script>

<template>
  <div class="min-h-screen flex items-center justify-center bg-gray-50 dark:bg-gray-900">
    <div class="w-full max-w-md p-8 bg-white dark:bg-gray-800 rounded-lg shadow-md">
      <div class="flex justify-center mb-4">
        <BrandLogo :size="200" class="text-brand-navy-900 dark:text-brand-cream" />
      </div>
      <h1 class="text-2xl font-bold text-center mb-6 text-gray-900 dark:text-gray-100">{{ t('auth.login') }}</h1>

      <div v-if="errorKey" class="mb-4 p-3 bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 rounded text-red-700 dark:text-red-400 text-sm">
        {{ t(errorKey) }}
      </div>

      <form @submit.prevent="handleLogin" class="flex flex-col gap-4">
        <div class="flex flex-col gap-1">
          <label for="email" class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ t('auth.email') }}</label>
          <InputText id="email" v-model="form.email" type="email" required class="w-full" />
        </div>

        <div class="flex flex-col gap-1">
          <label for="password" class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ t('auth.password') }}</label>
          <Password id="password" v-model="form.password" :feedback="false" toggle-mask required
            input-class="w-full" class="w-full" />
        </div>

        <Button type="submit" :label="t('auth.login_button')" :loading="authStore.loading" class="w-full mt-2" />
      </form>

      <p class="text-center mt-3 text-sm">
        <RouterLink to="/forgot-password" class="text-blue-600 dark:text-blue-400 hover:underline">{{ t('auth.forgot_password') }}</RouterLink>
      </p>

      <p class="text-center mt-2 text-sm text-gray-600 dark:text-gray-400">
        {{ t('auth.no_account') }}
        <RouterLink to="/register" class="text-blue-600 dark:text-blue-400 hover:underline">{{ t('auth.register_button') }}</RouterLink>
      </p>
    </div>
  </div>
</template>
