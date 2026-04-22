<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useBillingStore } from '@/stores/billing'
import Button from 'primevue/button'

const { t } = useI18n()
const router = useRouter()
const billing = useBillingStore()

const state = ref('polling') // polling | success | timeout
const MAX_ATTEMPTS = 10
const INTERVAL_MS = 1000

onMounted(async () => {
  for (let i = 0; i < MAX_ATTEMPTS; i++) {
    await billing.fetchStatus()
    if (billing.hasAccess && billing.reason === 'subscription_active') {
      state.value = 'success'
      setTimeout(() => router.push({ name: 'dashboard' }), 1500)
      return
    }
    await new Promise((r) => setTimeout(r, INTERVAL_MS))
  }
  state.value = 'timeout'
})

function goToDashboard() {
  router.push({ name: 'dashboard' })
}
</script>

<template>
  <div class="min-h-screen flex items-center justify-center p-4 bg-gray-50 dark:bg-gray-900">
    <div class="max-w-md w-full bg-white dark:bg-gray-800 rounded-lg shadow p-8 text-center" data-testid="subscribe-success-view">
      <template v-if="state === 'polling'">
        <i class="pi pi-spin pi-spinner text-4xl text-blue-600 mb-4"></i>
        <h1 class="text-xl font-semibold text-gray-800 dark:text-gray-100 mb-2" data-testid="polling-title">
          {{ t('billing.success.polling_title') }}
        </h1>
        <p class="text-gray-600 dark:text-gray-400">{{ t('billing.success.polling_description') }}</p>
      </template>

      <template v-else-if="state === 'success'">
        <i class="pi pi-check-circle text-4xl text-green-600 mb-4"></i>
        <h1 class="text-xl font-semibold text-gray-800 dark:text-gray-100 mb-2" data-testid="success-title">
          {{ t('billing.success.confirmed_title') }}
        </h1>
        <p class="text-gray-600 dark:text-gray-400">{{ t('billing.success.confirmed_description') }}</p>
      </template>

      <template v-else>
        <i class="pi pi-exclamation-triangle text-4xl text-amber-500 mb-4"></i>
        <h1 class="text-xl font-semibold text-gray-800 dark:text-gray-100 mb-2" data-testid="timeout-title">
          {{ t('billing.success.timeout_title') }}
        </h1>
        <p class="text-gray-600 dark:text-gray-400 mb-4">{{ t('billing.success.timeout_description') }}</p>
        <Button :label="t('billing.success.go_dashboard')" data-testid="go-dashboard" @click="goToDashboard" />
      </template>
    </div>
  </div>
</template>
