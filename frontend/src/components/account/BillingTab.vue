<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useBillingStore } from '@/stores/billing'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'

const { t, locale } = useI18n()
const billing = useBillingStore()
const toast = useToast()

const opening = ref(false)
const starting = ref(false)
const cancelling = ref(false)
const reactivating = ref(false)
const cancelDialogVisible = ref(false)

onMounted(async () => {
  if (billing.status === null) {
    await billing.fetchStatus()
  }
  // Refresh status when the user comes back from the Stripe Billing Portal tab.
  document.addEventListener('visibilitychange', handleVisibilityChange)
})

onUnmounted(() => {
  document.removeEventListener('visibilitychange', handleVisibilityChange)
})

function handleVisibilityChange() {
  if (document.visibilityState === 'visible') {
    billing.fetchStatus()
  }
}

const dateFormatter = computed(() => new Intl.DateTimeFormat(locale.value, { dateStyle: 'long' }))

function formatDate(iso) {
  if (!iso) return ''
  try {
    return dateFormatter.value.format(new Date(iso.replace(' ', 'T') + 'Z'))
  } catch {
    return iso
  }
}

const reason = computed(() => billing.reason)
const subscription = computed(() => billing.subscription)
const isActive = computed(() => reason.value === 'subscription_active')
const isBypass = computed(() => reason.value === 'bypass')
const isGrace = computed(() => reason.value === 'grace_period')
const isNoAccess = computed(() => reason.value === 'no_access')
const cancelScheduled = computed(() => subscription.value?.cancel_at_period_end === true)

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
  } finally {
    opening.value = false
  }
}

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

function openCancelDialog() {
  cancelDialogVisible.value = true
}

async function confirmCancel() {
  cancelling.value = true
  try {
    await billing.cancel()
    cancelDialogVisible.value = false
    toast.add({
      severity: 'success',
      summary: t('common.success'),
      detail: t('account.billing.cancel_success'),
      life: 4000,
    })
  } catch (err) {
    toast.add({
      severity: 'error',
      summary: t('common.error'),
      detail: t(err.messageKey || 'error.internal'),
      life: 5000,
    })
  } finally {
    cancelling.value = false
  }
}

async function handleReactivate() {
  reactivating.value = true
  try {
    await billing.reactivate()
    toast.add({
      severity: 'success',
      summary: t('common.success'),
      detail: t('account.billing.reactivate_success'),
      life: 4000,
    })
  } catch (err) {
    toast.add({
      severity: 'error',
      summary: t('common.error'),
      detail: t(err.messageKey || 'error.internal'),
      life: 5000,
    })
  } finally {
    reactivating.value = false
  }
}
</script>

<template>
  <div class="max-w-lg" data-testid="billing-tab">
    <div v-if="billing.loading" class="text-center py-8">
      <i class="pi pi-spin pi-spinner text-2xl text-gray-400"></i>
    </div>

    <template v-else>
      <!-- Status -->
      <div class="mb-6">
        <h3 class="text-base font-semibold text-gray-800 dark:text-gray-100 mb-2">{{ t('account.billing.status_title') }}</h3>

        <div
          v-if="isBypass"
          class="p-3 rounded bg-gray-100 dark:bg-gray-700 text-gray-800 dark:text-gray-200 text-sm"
          data-testid="status-bypass"
        >
          {{ t('account.billing.status_bypass') }}
        </div>

        <div
          v-else-if="isActive && cancelScheduled"
          class="p-3 rounded bg-amber-50 dark:bg-amber-900/30 border border-amber-200 dark:border-amber-800 text-amber-800 dark:text-amber-200 text-sm"
          data-testid="status-cancel-scheduled"
        >
          {{ t('account.billing.status_cancel_scheduled', { date: formatDate(subscription?.current_period_end) }) }}
        </div>

        <div
          v-else-if="isActive"
          class="p-3 rounded bg-green-50 dark:bg-green-900/30 border border-green-200 dark:border-green-800 text-green-800 dark:text-green-200 text-sm"
          data-testid="status-active"
        >
          {{ t('account.billing.status_active', { date: formatDate(subscription?.current_period_end) }) }}
        </div>

        <div
          v-else-if="isGrace"
          class="p-3 rounded bg-info-bg dark:bg-info/20 border border-info/30 dark:border-info/40 text-info dark:text-info-bg text-sm"
          data-testid="status-grace"
        >
          {{ t('account.billing.status_grace', { date: formatDate(billing.gracePeriodEnd) }) }}
        </div>

        <div
          v-else-if="isNoAccess"
          class="p-3 rounded bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 text-red-800 dark:text-red-200 text-sm"
          data-testid="status-no-access"
        >
          {{ t('account.billing.status_no_access') }}
        </div>
      </div>

      <!-- Plan -->
      <div class="p-4 border border-gray-200 dark:border-gray-700 rounded-lg mb-6">
        <div class="flex items-baseline justify-between mb-2">
          <span class="font-medium text-gray-800 dark:text-gray-100">{{ t('account.billing.plan_label') }}</span>
          <span class="text-2xl font-bold text-gray-800 dark:text-gray-100">{{ t('billing.price') }}<span class="text-sm font-normal text-gray-500">{{ t('billing.per_month') }}</span></span>
        </div>
        <p class="text-sm text-gray-600 dark:text-gray-400">{{ t('account.billing.plan_description') }}</p>
      </div>

      <!-- Actions -->
      <div class="flex flex-col gap-2">
        <!-- No subscription & not bypass: subscribe -->
        <Button
          v-if="!isBypass && !subscription"
          :label="t('account.billing.subscribe_button')"
          severity="primary"
          icon="pi pi-credit-card"
          :loading="starting"
          data-testid="subscribe-button"
          @click="handleSubscribe"
        />

        <!-- Subscription exists: manage + cancel/reactivate -->
        <template v-if="subscription">
          <Button
            :label="t('account.billing.manage_button')"
            icon="pi pi-external-link"
            :loading="opening"
            data-testid="manage-button"
            @click="handleManage"
          />

          <Button
            v-if="!cancelScheduled"
            :label="t('account.billing.cancel_button')"
            severity="danger"
            outlined
            icon="pi pi-times"
            :loading="cancelling"
            data-testid="cancel-button"
            @click="openCancelDialog"
          />

          <Button
            v-else
            :label="t('account.billing.reactivate_button')"
            severity="success"
            icon="pi pi-refresh"
            :loading="reactivating"
            data-testid="reactivate-button"
            @click="handleReactivate"
          />

          <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">
            {{ t('account.billing.manage_hint') }}
          </p>
        </template>
      </div>
    </template>

    <!-- Cancellation confirmation dialog -->
    <Dialog
      v-model:visible="cancelDialogVisible"
      :header="t('account.billing.cancel_dialog_title')"
      :modal="true"
      :closable="true"
      :style="{ width: '450px' }"
    >
      <p class="mb-3 text-gray-700 dark:text-gray-300">{{ t('account.billing.cancel_dialog_line1') }}</p>
      <p v-if="subscription?.current_period_end" class="text-sm text-gray-600 dark:text-gray-400">
        {{ t('account.billing.cancel_dialog_line2', { date: formatDate(subscription.current_period_end) }) }}
      </p>

      <template #footer>
        <Button :label="t('common.cancel')" severity="secondary" @click="cancelDialogVisible = false" />
        <Button
          :label="t('account.billing.cancel_confirm_button')"
          severity="danger"
          :loading="cancelling"
          data-testid="confirm-cancel-button"
          @click="confirmCancel"
        />
      </template>
    </Dialog>
  </div>
</template>
