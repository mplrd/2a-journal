<script setup>
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useStatsStore } from '@/stores/stats'
import { useAccountsStore } from '@/stores/accounts'
import BadgeFilter from '@/components/common/BadgeFilter.vue'
import EmailVerificationBanner from '@/components/auth/EmailVerificationBanner.vue'
import DdAlertBanner from '@/components/dashboard/DdAlertBanner.vue'
import KpiCards from '@/components/dashboard/KpiCards.vue'
import CumulativePnlChart from '@/components/dashboard/CumulativePnlChart.vue'
import RecentTrades from '@/components/dashboard/RecentTrades.vue'
import PnlCalendar from '@/components/dashboard/PnlCalendar.vue'
import PinnedNotesCard from '@/components/dashboard/PinnedNotesCard.vue'

const { t } = useI18n()
const statsStore = useStatsStore()
const accountsStore = useAccountsStore()

const filterAccountIds = ref([])

onMounted(async () => {
  statsStore.setFilters({})
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
  if (filterAccountIds.value.length > 0) filters.account_ids = filterAccountIds.value
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
    <DdAlertBanner />
    <div class="flex items-center justify-end flex-wrap gap-3 mb-4">
      <BadgeFilter
        v-model="filterAccountIds"
        :options="accountsStore.accounts.map((a) => ({ label: a.name, value: a.id }))"
        multi
        @change="applyFilters"
      />
    </div>

    <div class="relative">
      <div v-if="statsStore.loading" class="absolute inset-0 bg-white/60 dark:bg-gray-900/60 z-10 flex items-center justify-center rounded-lg">
        <i class="pi pi-spin pi-spinner text-3xl text-gray-400"></i>
      </div>

      <!-- Row 1: compact overview tile (KPIs + win/loss) and a wide cumulative P&L -->
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
        <KpiCards :overview="statsStore.overview" :winLoss="statsStore.charts?.win_loss" />
        <CumulativePnlChart class="lg:col-span-2" :data="statsStore.charts?.cumulative_pnl" />
      </div>

      <!-- Row 2: pinned notebook reminders (self-hides when nothing is pinned) -->
      <PinnedNotesCard class="mb-6" />

      <!-- Row 3: recent trades + P&L calendar -->
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <div class="lg:col-span-2">
          <RecentTrades :trades="statsStore.recentTrades" :openTrades="statsStore.openTrades" />
        </div>
        <PnlCalendar :dailyPnl="statsStore.dailyPnl" />
      </div>
    </div>
  </div>
</template>
