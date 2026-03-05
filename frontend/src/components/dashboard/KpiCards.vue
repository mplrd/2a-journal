<script setup>
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps({
  overview: {
    type: Object,
    default: null,
  },
})

function formatPnl(value) {
  if (value == null) return '-'
  const num = Number(value)
  return (num >= 0 ? '+' : '') + num.toFixed(2)
}

function formatPercent(value) {
  if (value == null) return '-'
  return Number(value).toFixed(2) + '%'
}

function formatRatio(value) {
  if (value == null) return '-'
  return Number(value).toFixed(2)
}

function pnlClass(value) {
  if (value == null) return 'text-gray-500'
  return Number(value) >= 0 ? 'text-green-600' : 'text-red-600'
}
</script>

<template>
  <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
    <!-- Total Trades -->
    <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 p-4">
      <div class="text-sm text-gray-500 dark:text-gray-400 mb-1">{{ t('dashboard.total_trades') }}</div>
      <div class="text-2xl font-bold">{{ overview?.total_trades ?? 0 }}</div>
    </div>

    <!-- Win Rate -->
    <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 p-4">
      <div class="text-sm text-gray-500 dark:text-gray-400 mb-1">{{ t('dashboard.win_rate') }}</div>
      <div class="text-2xl font-bold" :class="pnlClass(overview?.win_rate)">
        {{ formatPercent(overview?.win_rate) }}
      </div>
    </div>

    <!-- Total P&L -->
    <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 p-4">
      <div class="text-sm text-gray-500 dark:text-gray-400 mb-1">{{ t('dashboard.total_pnl') }}</div>
      <div class="text-2xl font-bold" :class="pnlClass(overview?.total_pnl)">
        {{ formatPnl(overview?.total_pnl) }}
      </div>
    </div>

    <!-- Profit Factor -->
    <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 p-4">
      <div class="text-sm text-gray-500 dark:text-gray-400 mb-1">{{ t('dashboard.profit_factor') }}</div>
      <div class="text-2xl font-bold">{{ formatRatio(overview?.profit_factor) }}</div>
    </div>

    <!-- Avg RR -->
    <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 p-4">
      <div class="text-sm text-gray-500 dark:text-gray-400 mb-1">{{ t('dashboard.avg_rr') }}</div>
      <div class="text-2xl font-bold">{{ formatRatio(overview?.avg_rr) }}</div>
    </div>

    <!-- Best / Worst Trade -->
    <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 p-4">
      <div class="text-sm text-gray-500 dark:text-gray-400 mb-1">{{ t('dashboard.best_worst') }}</div>
      <div class="flex flex-col">
        <span class="text-sm font-bold text-green-600">{{ formatPnl(overview?.best_trade) }}</span>
        <span class="text-sm font-bold text-red-600">{{ formatPnl(overview?.worst_trade) }}</span>
      </div>
    </div>
  </div>
</template>
