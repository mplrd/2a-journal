<script setup>
import { ref, onMounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Password from 'primevue/password'
import Card from 'primevue/card'
import BrandLogo from '@/components/common/BrandLogo.vue'

const router = useRouter()
const route = useRoute()
const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()

const email = ref('')
const password = ref('')
const ssoLoading = ref(false)

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

// Handoff from the user SPA: a `?code=xxx` query param means the user was
// already authenticated on the user SPA and clicked the cross-link. Exchange
// the code for tokens and skip the manual login. Strip the code from the URL
// after exchange so it doesn't linger in browser history.
onMounted(async () => {
  const code = route.query.code
  if (typeof code !== 'string' || code.length === 0) return

  ssoLoading.value = true
  // Drop the code from the URL immediately so it isn't re-used on reload.
  router.replace({ path: route.path, query: {} })

  try {
    await auth.loginWithSsoCode(code)
    router.push('/users')
  } catch (err) {
    const key = err.code === 'NOT_ADMIN'
      ? 'auth.error.admin_only'
      : (err.messageKey || 'auth.error.sso_code_invalid')
    toast.add({ severity: 'error', summary: t('error.internal'), detail: t(key), life: 5000 })
  } finally {
    ssoLoading.value = false
  }
})
</script>

<template>
  <div class="min-h-screen flex items-center justify-center bg-gray-50 dark:bg-gray-900 p-4">
    <Card class="w-full max-w-md">
      <template #header>
        <div class="flex justify-center pt-6">
          <BrandLogo :size="200" class="text-brand-navy-900 dark:text-brand-cream" />
        </div>
      </template>
      <template #title>{{ t('login.title') }}</template>
      <template #content>
        <div v-if="ssoLoading" class="flex flex-col items-center gap-3 py-8">
          <i class="pi pi-spin pi-spinner text-3xl text-brand-navy-900 dark:text-brand-cream"></i>
          <p class="text-sm text-gray-600 dark:text-gray-300">{{ t('login.sso_loading') }}</p>
        </div>
        <form v-else class="flex flex-col gap-4" @submit.prevent="submit">
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
