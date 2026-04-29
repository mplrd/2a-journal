<script setup>
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps({
  dailyPnl: { type: Array, default: () => [] },
})

const today = new Date()
const currentMonth = ref(today.getMonth())
const currentYear = ref(today.getFullYear())

const monthLabel = computed(() => {
  const date = new Date(currentYear.value, currentMonth.value, 1)
  return date.toLocaleDateString(undefined, { month: 'long', year: 'numeric' })
})

const weekDays = computed(() => [
  t('performance.heatmap_mon'),
  t('performance.heatmap_tue'),
  t('performance.heatmap_wed'),
  t('performance.heatmap_thu'),
  t('performance.heatmap_fri'),
  t('performance.heatmap_sat'),
  t('performance.heatmap_sun'),
])

const pnlMap = computed(() => {
  const map = {}
  for (const row of props.dailyPnl) {
    map[row.date] = { pnl: Number(row.total_pnl), count: Number(row.trade_count) }
  }
  return map
})

const calendarDays = computed(() => {
  const year = currentYear.value
  const month = currentMonth.value
  const firstDay = new Date(year, month, 1)
  const lastDay = new Date(year, month + 1, 0)

  // Monday = 0, Sunday = 6
  let startDow = firstDay.getDay() - 1
  if (startDow < 0) startDow = 6

  const days = []

  // Padding before first day
  for (let i = 0; i < startDow; i++) {
    days.push({ day: null })
  }

  for (let d = 1; d <= lastDay.getDate(); d++) {
    const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`
    const data = pnlMap.value[dateStr]
    days.push({
      day: d,
      date: dateStr,
      pnl: data?.pnl ?? null,
      count: data?.count ?? 0,
    })
  }

  return days
})

function prevMonth() {
  if (currentMonth.value === 0) {
    currentMonth.value = 11
    currentYear.value--
  } else {
    currentMonth.value--
  }
}

function nextMonth() {
  if (currentMonth.value === 11) {
    currentMonth.value = 0
    currentYear.value++
  } else {
    currentMonth.value++
  }
}

function cellClass(day) {
  if (day.pnl == null) return ''
  if (day.pnl > 0) return 'bg-success-bg dark:bg-success/25 text-success-fg dark:text-brand-green-300'
  if (day.pnl < 0) return 'bg-danger-bg dark:bg-danger/25 text-danger dark:text-danger-fg-dark'
  return 'bg-warning-bg dark:bg-warning/25 text-warning dark:text-warning-bg'
}

function formatDayPnl(pnl) {
  if (pnl == null) return ''
  const num = Number(pnl)
  return (num >= 0 ? '+' : '') + num.toFixed(0)
}
</script>

<template>
  <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 p-4 h-full">
    <div class="flex items-center justify-between mb-3">
      <h3 class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ t('dashboard.daily_calendar') }}</h3>
      <div class="flex items-center gap-1">
        <button
          class="p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-700 text-gray-500"
          @click="prevMonth"
        >
          <i class="pi pi-chevron-left text-xs"></i>
        </button>
        <span class="text-sm font-medium text-gray-700 dark:text-gray-300 min-w-[120px] text-center capitalize">
          {{ monthLabel }}
        </span>
        <button
          class="p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-700 text-gray-500"
          @click="nextMonth"
        >
          <i class="pi pi-chevron-right text-xs"></i>
        </button>
      </div>
    </div>

    <div class="grid grid-cols-7 gap-px text-center text-xs">
      <!-- Header -->
      <div
        v-for="wd in weekDays"
        :key="wd"
        class="text-gray-400 dark:text-gray-500 font-medium py-1"
      >
        {{ wd }}
      </div>

      <!-- Days -->
      <div
        v-for="(cell, idx) in calendarDays"
        :key="idx"
        class="aspect-square flex flex-col items-center justify-center rounded text-xs relative"
        :class="cell.day ? cellClass(cell) : ''"
        :title="cell.count ? `${cell.count} trade(s) : ${formatDayPnl(cell.pnl)}` : ''"
      >
        <span v-if="cell.day" class="font-medium" :class="cell.pnl == null ? 'text-gray-400 dark:text-gray-600' : ''">
          {{ cell.day }}
        </span>
        <span v-if="cell.pnl != null" class="text-[10px] leading-tight font-medium">
          {{ formatDayPnl(cell.pnl) }}
        </span>
      </div>
    </div>
  </div>
</template>
