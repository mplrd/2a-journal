<script setup>
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useBillingStore } from '@/stores/billing'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'

const { t, locale } = useI18n()
const billing = useBillingStore()
const toast = useToast()

const starting = ref(false)
const opening = ref(false)

onMounted(async () => {
  await billing.fetchStatus()
})

const dateFormatter = computed(() => new Intl.DateTimeFormat(locale.value, { dateStyle: 'long' }))

function formatDate(iso) {
  if (!iso) return ''
  try {
    return dateFormatter.value.format(new Date(iso.replace(' ', 'T') + 'Z'))
  } catch {
    return iso
  }
}

const subStatus = computed(() => billing.subscription?.status)
const cancelAtPeriodEnd = computed(() => billing.subscription?.cancel_at_period_end === true)

async function handleSubscribe() {
  starting.value = true
  try {
    await billing.startCheckout()
  } catch (err) {
    toast.add({
      severity: 'error',
      summary: t('common.error'),
      detail: t(err.messageKey || 'error.internal'),
      life: 5000,
    })
    starting.value = false
  }
}

async function handleManage() {
  opening.value = true
  try {
    await billing.openPortal()
  } catch (err) {
    toast.add({
      severity: 'error',
      summary: t('common.error'),
      detail: t(err.messageKey || 'error.internal'),
      life: 5000,
    })
    opening.value = false
  }
}
</script>

<template>
  <div class="min-h-screen flex items-center justify-center p-4 bg-gray-50 dark:bg-gray-900">
    <div class="max-w-lg w-full bg-white dark:bg-gray-800 rounded-lg shadow p-8" data-testid="subscribe-view">
      <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100 mb-2">{{ t('billing.title') }}</h1>
      <p class="text-gray-600 dark:text-gray-400 mb-6">{{ t('billing.tagline') }}</p>

      <div v-if="billing.loading" class="text-center py-8" data-testid="billing-loading">
        <i class="pi pi-spin pi-spinner text-2xl text-gray-400"></i>
      </div>

      <template v-else>
        <!-- Grace period -->
        <div
          v-if="billing.reason === 'grace_period' && billing.gracePeriodEnd"
          class="mb-6 p-4 bg-blue-50 dark:bg-blue-900/30 border border-blue-200 dark:border-blue-800 rounded"
          data-testid="status-grace"
        >
          <p class="text-blue-800 dark:text-blue-200">
            {{ t('billing.grace_info', { date: formatDate(billing.gracePeriodEnd) }) }}
          </p>
        </div>

        <!-- Subscription active but cancellation scheduled -->
        <div
          v-else-if="subStatus === 'active' && cancelAtPeriodEnd"
          class="mb-6 p-4 bg-amber-50 dark:bg-amber-900/30 border border-amber-200 dark:border-amber-800 rounded"
          data-testid="status-cancel-scheduled"
        >
          <p class="text-amber-800 dark:text-amber-200">
            {{ t('billing.cancel_scheduled', { date: formatDate(billing.subscription?.current_period_end) }) }}
          </p>
        </div>

        <!-- Subscription active -->
        <div
          v-else-if="billing.reason === 'subscription_active'"
          class="mb-6 p-4 bg-green-50 dark:bg-green-900/30 border border-green-200 dark:border-green-800 rounded"
          data-testid="status-active"
        >
          <p class="text-green-800 dark:text-green-200">{{ t('billing.subscription_active') }}</p>
        </div>

        <!-- Bypass (admin) -->
        <div
          v-else-if="billing.reason === 'bypass'"
          class="mb-6 p-4 bg-gray-100 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded"
          data-testid="status-bypass"
        >
          <p class="text-gray-700 dark:text-gray-300">{{ t('billing.bypass_active') }}</p>
        </div>

        <!-- No access -->
        <div
          v-else-if="billing.reason === 'no_access'"
          class="mb-6 p-4 bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 rounded"
          data-testid="status-no-access"
        >
          <p class="text-red-800 dark:text-red-200">{{ t('billing.no_access') }}</p>
        </div>

        <!-- Plan box -->
        <div class="p-5 border border-gray-200 dark:border-gray-700 rounded-lg mb-6">
          <div class="flex items-baseline gap-2">
            <span class="text-3xl font-bold text-gray-800 dark:text-gray-100">{{ t('billing.price') }}</span>
            <span class="text-gray-500 dark:text-gray-400">{{ t('billing.per_month') }}</span>
          </div>
          <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">{{ t('billing.cancel_anytime') }}</p>
        </div>

        <!-- CTAs -->
        <Button
          v-if="billing.subscription && billing.hasAccess"
          :label="t('billing.manage_subscription')"
          class="w-full mb-2"
          :loading="opening"
          data-testid="manage-button"
          @click="handleManage"
        />
        <Button
          :label="t('billing.subscribe_cta')"
          severity="primary"
          class="w-full"
          :loading="starting"
          data-testid="subscribe-button"
          @click="handleSubscribe"
        />

        <p class="text-xs text-gray-500 dark:text-gray-400 mt-4 text-center">
          {{ t('billing.promo_hint') }}
        </p>
      </template>
    </div>
  </div>
</template>
