<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import InputText from 'primevue/inputtext'
import Password from 'primevue/password'
import Button from 'primevue/button'

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
  <div class="min-h-screen flex items-center justify-center bg-gray-50">
    <div class="w-full max-w-md p-8 bg-white rounded-lg shadow-md">
      <h1 class="text-2xl font-bold text-center mb-6">{{ t('auth.login') }}</h1>

      <div v-if="errorKey" class="mb-4 p-3 bg-red-50 border border-red-200 rounded text-red-700 text-sm">
        {{ t(errorKey) }}
      </div>

      <form @submit.prevent="handleLogin" class="flex flex-col gap-4">
        <div class="flex flex-col gap-1">
          <label for="email" class="text-sm font-medium">{{ t('auth.email') }}</label>
          <InputText id="email" v-model="form.email" type="email" required class="w-full" />
        </div>

        <div class="flex flex-col gap-1">
          <label for="password" class="text-sm font-medium">{{ t('auth.password') }}</label>
          <Password id="password" v-model="form.password" :feedback="false" toggle-mask required
            input-class="w-full" class="w-full" />
        </div>

        <Button type="submit" :label="t('auth.login_button')" :loading="authStore.loading" class="w-full mt-2" />
      </form>

      <p class="text-center mt-4 text-sm text-gray-600">
        {{ t('auth.no_account') }}
        <RouterLink to="/register" class="text-blue-600 hover:underline">{{ t('auth.register_button') }}</RouterLink>
      </p>
    </div>
  </div>
</template>
