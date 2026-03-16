<script setup>
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useStatsStore } from '@/stores/stats'
import { useAccountsStore } from '@/stores/accounts'
import Select from 'primevue/select'
import EmailVerificationBanner from '@/components/auth/EmailVerificationBanner.vue'
import KpiCards from '@/components/dashboard/KpiCards.vue'
import CumulativePnlChart from '@/components/dashboard/CumulativePnlChart.vue'
import WinLossChart from '@/components/dashboard/WinLossChart.vue'
import PnlBySymbolChart from '@/components/dashboard/PnlBySymbolChart.vue'
import RecentTrades from '@/components/dashboard/RecentTrades.vue'
import PnlCalendar from '@/components/dashboard/PnlCalendar.vue'

const { t } = useI18n()
const statsStore = useStatsStore()
const accountsStore = useAccountsStore()

const filterAccountId = ref(null)

onMounted(async () => {
  await accountsStore.fetchAccounts()
  await Promise.all([
    statsStore.fetchDashboard(),
    statsStore.fetchCharts(),
    statsStore.fetchOpenTrades(),
    statsStore.fetchDailyPnl(),
  ])
})

async function applyFilters() {
  const filters = {}
  if (filterAccountId.value) filters.account_id = filterAccountId.value
  statsStore.setFilters(filters)
  await Promise.all([
    statsStore.fetchDashboard(),
    statsStore.fetchCharts(),
    statsStore.fetchOpenTrades(),
    statsStore.fetchDailyPnl(),
  ])
}
</script>

<template>
  <div>
    <EmailVerificationBanner />
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

      <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
        <CumulativePnlChart :data="statsStore.charts?.cumulative_pnl" />
        <WinLossChart :data="statsStore.charts?.win_loss" />
        <PnlBySymbolChart :data="statsStore.charts?.pnl_by_symbol" />
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <div class="lg:col-span-2">
          <RecentTrades :trades="statsStore.recentTrades" :openTrades="statsStore.openTrades" />
        </div>
        <PnlCalendar :dailyPnl="statsStore.dailyPnl" />
      </div>
    </template>
  </div>
</template>
