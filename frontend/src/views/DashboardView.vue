<script setup>
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useStatsStore } from '@/stores/stats'
import { useAccountsStore } from '@/stores/accounts'
import Select from 'primevue/select'
import KpiCards from '@/components/dashboard/KpiCards.vue'
import CumulativePnlChart from '@/components/dashboard/CumulativePnlChart.vue'
import WinLossChart from '@/components/dashboard/WinLossChart.vue'
import RecentTrades from '@/components/dashboard/RecentTrades.vue'

const { t } = useI18n()
const statsStore = useStatsStore()
const accountsStore = useAccountsStore()

const filterAccountId = ref(null)

onMounted(async () => {
  await accountsStore.fetchAccounts()
  await statsStore.fetchDashboard()
  statsStore.fetchCharts()
})

async function applyFilters() {
  const filters = {}
  if (filterAccountId.value) filters.account_id = filterAccountId.value
  statsStore.setFilters(filters)
  await statsStore.fetchDashboard()
  statsStore.fetchCharts()
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <h1 class="text-2xl font-bold dark:text-gray-100">{{ t('dashboard.title') }}</h1>
      <Select
        v-model="filterAccountId"
        :options="[{ label: t('dashboard.all_accounts'), value: null }, ...accountsStore.accounts.map((a) => ({ label: a.name, value: a.id }))]"
        optionLabel="label"
        optionValue="value"
        :placeholder="t('dashboard.filter_account')"
        class="w-48"
        @change="applyFilters"
      />
    </div>

    <div v-if="statsStore.loading" class="text-gray-500 py-8 text-center">
      <i class="pi pi-spin pi-spinner text-2xl"></i>
    </div>

    <template v-else>
      <KpiCards :overview="statsStore.overview" class="mb-6" />

      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6">
        <CumulativePnlChart :data="statsStore.charts?.cumulative_pnl" />
        <WinLossChart :data="statsStore.charts?.win_loss" />
      </div>

      <RecentTrades :trades="statsStore.recentTrades" />
    </template>
  </div>
</template>
