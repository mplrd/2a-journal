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

// Monday = 0 … Sunday = 6
function mondayIndex(date) {
  return (date.getDay() + 6) % 7
}

function dayCell(date, outside) {
  const dateStr = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`
  const data = pnlMap.value[dateStr]
  return {
    day: date.getDate(),
    date: dateStr,
    outside,
    pnl: data?.pnl ?? null,
    count: data?.count ?? 0,
  }
}

// Whole Monday-to-Sunday weeks: the first row opens with the last days of the
// previous month and the last row closes with the first days of the next, so a
// week reads whole. The daily P&L covers the full history, so those days carry
// their figures too; they are only dimmed. Date arithmetic goes through the
// Date constructor, which rolls day 0 or day 32 over to the adjacent month.
const calendarDays = computed(() => {
  const year = currentYear.value
  const month = currentMonth.value
  const firstDay = new Date(year, month, 1)
  const lastDay = new Date(year, month + 1, 0)

  const days = []

  for (let before = mondayIndex(firstDay); before > 0; before--) {
    days.push(dayCell(new Date(year, month, 1 - before), true))
  }

  for (let d = 1; d <= lastDay.getDate(); d++) {
    days.push(dayCell(new Date(year, month, d), false))
  }

  for (let after = 1; after <= 6 - mondayIndex(lastDay); after++) {
    days.push(dayCell(new Date(year, month + 1, after), true))
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
  if (day.pnl > 0) return 'bg-green-500/80 text-white'
  if (day.pnl < 0) return 'bg-red-500/80 text-white'
  return 'bg-amber-500/80 text-white'
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
      <div class="flex items-center gap-1.5">
        <h3 class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ t('dashboard.daily_calendar') }}</h3>
        <i
          data-testid="daily-pnl-help"
          class="pi pi-info-circle text-gray-400 cursor-help text-xs"
          role="img"
          :aria-label="t('dashboard.daily_calendar_help')"
          v-tooltip.top="t('dashboard.daily_calendar_help')"
        />
      </div>
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
        v-for="cell in calendarDays"
        :key="cell.date"
        data-testid="calendar-day"
        :data-date="cell.date"
        :data-outside="cell.outside"
        class="aspect-square flex flex-col items-center justify-center rounded text-xs relative"
        :class="[cellClass(cell), { 'opacity-40': cell.outside }]"
        :title="cell.count ? `${t('dashboard.trade_count', { count: cell.count })} : ${formatDayPnl(cell.pnl)}` : ''"
      >
        <span class="font-medium" :class="cell.pnl == null ? 'text-gray-400 dark:text-gray-600' : ''">
          {{ cell.day }}
        </span>
        <span v-if="cell.pnl != null" class="text-[10px] leading-tight font-medium">
          {{ formatDayPnl(cell.pnl) }}
        </span>
      </div>
    </div>
  </div>
</template>
