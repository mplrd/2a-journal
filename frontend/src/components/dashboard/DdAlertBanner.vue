<script setup>
import { ref, onMounted, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { accountsService } from '@/services/accounts'

const { t } = useI18n()

const statuses = ref([])

const alertedAccounts = computed(() => statuses.value.filter((s) => s.alert_max || s.alert_daily))

async function load() {
  try {
    const res = await accountsService.ddStatus()
    statuses.value = res?.data ?? []
  } catch (err) {
    statuses.value = []
  }
}

function fmtPct(value) {
  if (value == null) return '-'
  return Number(value).toFixed(2) + '%'
}

onMounted(load)

defineExpose({ load })
</script>

<template>
  <div
    v-if="alertedAccounts.length > 0"
    class="mb-4 p-4 border border-red-300 dark:border-red-700 rounded-lg bg-red-50 dark:bg-red-900/20"
    data-testid="dd-alert-banner"
  >
    <h3 class="text-base font-semibold text-red-700 dark:text-red-300 mb-2 flex items-center gap-2">
      <i class="pi pi-exclamation-triangle"></i>
      {{ t('dashboard.dd_alert.title') }}
    </h3>
    <ul class="space-y-1">
      <li
        v-for="account in alertedAccounts"
        :key="account.account_id"
        class="text-sm text-gray-800 dark:text-gray-200"
        :data-testid="`dd-alert-account-${account.account_id}`"
      >
        <strong>{{ account.account_name }}</strong> —
        <span v-if="account.alert_max">
          {{ t('dashboard.dd_alert.max_dd', { used: fmtPct(account.max_used_percent) }) }}
        </span>
        <span v-if="account.alert_max && account.alert_daily" class="mx-1">·</span>
        <span v-if="account.alert_daily">
          {{ t('dashboard.dd_alert.daily_dd', { used: fmtPct(account.daily_used_percent) }) }}
        </span>
      </li>
    </ul>
    <p class="text-xs text-red-700/80 dark:text-red-300/80 mt-2">{{ t('dashboard.dd_alert.hint') }}</p>
  </div>
</template>
