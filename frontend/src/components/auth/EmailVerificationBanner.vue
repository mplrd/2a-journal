<script setup>
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import Button from 'primevue/button'

const { t } = useI18n()
const authStore = useAuthStore()

const sending = ref(false)
const sent = ref(false)

async function resend() {
  sending.value = true
  try {
    await authStore.resendVerification()
    sent.value = true
  } catch {
    // Non-blocking
  } finally {
    sending.value = false
  }
}
</script>

<template>
  <div
    v-if="authStore.user && authStore.user.email_verified === false"
    class="mb-4 p-3 bg-amber-50 dark:bg-amber-900/30 border border-amber-200 dark:border-amber-800 rounded flex items-center justify-between gap-4"
  >
    <span class="text-amber-700 dark:text-amber-400 text-sm">
      {{ t('auth.email_not_verified') }}
    </span>
    <Button
      v-if="!sent"
      :label="t('auth.resend_verification')"
      :loading="sending"
      size="small"
      severity="warn"
      outlined
      @click="resend"
    />
    <span v-else class="text-green-600 dark:text-green-400 text-sm">
      {{ t('auth.success.verification_resent') }}
    </span>
  </div>
</template>
