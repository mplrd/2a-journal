<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Password from 'primevue/password'
import Card from 'primevue/card'

const router = useRouter()
const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()

const email = ref('')
const password = ref('')

async function submit() {
  if (!email.value || !password.value) return
  try {
    await auth.login({ email: email.value, password: password.value })
    router.push('/users')
  } catch (err) {
    const key = err.code === 'NOT_ADMIN'
      ? 'auth.error.admin_only'
      : (err.messageKey || 'auth.error.invalid_credentials')
    toast.add({ severity: 'error', summary: t('error.internal'), detail: t(key), life: 5000 })
  }
}
</script>

<template>
  <div class="min-h-screen flex items-center justify-center bg-gray-50 dark:bg-gray-900 p-4">
    <Card class="w-full max-w-md">
      <template #title>{{ t('login.title') }}</template>
      <template #content>
        <form class="flex flex-col gap-4" @submit.prevent="submit">
          <div class="flex flex-col gap-2">
            <label for="email" class="text-sm font-medium">{{ t('login.email') }}</label>
            <InputText id="email" v-model="email" type="email" required autofocus />
          </div>
          <div class="flex flex-col gap-2">
            <label for="password" class="text-sm font-medium">{{ t('login.password') }}</label>
            <Password id="password" v-model="password" toggleMask :feedback="false" required />
          </div>
          <Button type="submit" :label="auth.loading ? t('login.submitting') : t('login.submit')" :loading="auth.loading" />
        </form>
      </template>
    </Card>
  </div>
</template>
