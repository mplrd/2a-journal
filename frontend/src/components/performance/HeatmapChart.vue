<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'

const { t } = useI18n()
const authStore = useAuthStore()

const props = defineProps({
  data: { type: Array, default: () => [] },
})

const dayLabels = computed(() => [
  t('performance.heatmap_sun'),
  t('performance.heatmap_mon'),
  t('performance.heatmap_tue'),
  t('performance.heatmap_wed'),
  t('performance.heatmap_thu'),
  t('performance.heatmap_fri'),
  t('performance.heatmap_sat'),
])

const hours = Array.from({ length: 24 }, (_, i) => i)

// Get user's UTC offset in hours
const userOffsetHours = computed(() => {
  const tz = authStore.user?.timezone || 'UTC'
  try {
    const now = new Date()
    const utcStr = now.toLocaleString('en-US', { timeZone: 'UTC' })
    const localStr = now.toLocaleString('en-US', { timeZone: tz })
    return Math.round((new Date(localStr) - new Date(utcStr)) / 3600000)
  } catch {
    return 0
  }
})

// Session hours in UTC: Asia 0-6 (Tokyo 9-15), Europe 7-14 (Paris 8-14), US 14-22 (NY 9:30-16)
// Shifted to user's local time for display
const sessionsUtc = [
  { name: 'ASIA', start: 0, end: 6 },
  { name: 'EUROPE', start: 7, end: 14 },
  { name: 'US', start: 14, end: 22 },
]
const sessionStyles = {
  ASIA: 'bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300',
  EUROPE: 'bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300',
  US: 'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300',
}

const sessions = computed(() => {
  const off = userOffsetHours.value
  function shift(h) { return ((h + off) % 24 + 24) % 24 }

  const bands = []

  function addBand(name, startUtc, endUtc) {
    const color = sessionStyles[name]
    // Expand UTC range to list of hours, then shift each
    const utcHours = []
    if (startUtc < endUtc) {
      for (let h = startUtc; h < endUtc; h++) utcHours.push(h)
    } else {
      // wraps midnight
      for (let h = startUtc; h < 24; h++) utcHours.push(h)
      for (let h = 0; h < endUtc; h++) utcHours.push(h)
    }
    const localHours = utcHours.map(shift).sort((a, b) => a - b)

    // Group consecutive hours into bands
    let bandStart = localHours[0]
    let prev = localHours[0]
    for (let i = 1; i <= localHours.length; i++) {
      if (i < localHours.length && localHours[i] === prev + 1) {
        prev = localHours[i]
      } else {
        bands.push({ name, start: bandStart, end: prev + 1, color, noLabel: bandStart !== localHours[0] })
        if (i < localHours.length) {
          bandStart = localHours[i]
          prev = localHours[i]
        }
      }
    }
  }

  for (const s of sessionsUtc) {
    addBand(s.name, s.start, s.end)
  }

  // Fill gaps with off-session
  const covered = new Array(24).fill(false)
  for (const b of bands) {
    for (let h = b.start; h < b.end; h++) covered[h] = true
  }
  let gapStart = null
  for (let h = 0; h <= 24; h++) {
    if (h < 24 && !covered[h]) {
      if (gapStart === null) gapStart = h
    } else {
      if (gapStart !== null) {
        bands.push({ start: gapStart, end: h, off: true })
        gapStart = null
      }
    }
  }

  bands.sort((a, b) => a.start - b.start)
  return bands
})

const grid = computed(() => {
  const map = {}
  for (const row of props.data) {
    map[`${row.day}-${row.hour}`] = row
  }
  return map
})

const maxCount = computed(() => {
  if (props.data.length === 0) return 1
  return Math.max(...props.data.map((d) => Number(d.trade_count)), 1)
})

function cellColor(day, hour) {
  const cell = grid.value[`${day}-${hour}`]
  if (!cell) return 'background-color: var(--heatmap-empty)'
  const intensity = Number(cell.trade_count) / maxCount.value
  const pnl = Number(cell.total_pnl)
  if (pnl >= 0) {
    return `background-color: rgba(34, 197, 94, ${0.15 + intensity * 0.85})`
  }
  return `background-color: rgba(239, 68, 68, ${0.15 + intensity * 0.85})`
}

function cellTooltip(day, hour) {
  const cell = grid.value[`${day}-${hour}`]
  if (!cell) return ''
  return `${cell.trade_count} trade(s) | P&L: ${Number(cell.total_pnl).toFixed(2)}`
}
</script>

<template>
  <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 p-4">
    <h3 class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-3">{{ t('performance.heatmap_title') }}</h3>
    <div v-if="data.length > 0" class="overflow-x-auto">
      <div class="heatmap-grid">
        <!-- Session bands row -->
        <div class="heatmap-corner"></div>
        <div
          v-for="(s, idx) in sessions"
          :key="'s-' + idx"
          :class="s.off ? '' : ['heatmap-session-band', s.color]"
          :style="{ gridColumn: `span ${s.end - s.start}` }"
        >
          <template v-if="!s.off && !s.noLabel">{{ t(`performance.sessions.${s.name}`) }}</template>
        </div>
        <!-- Header row: hours -->
        <div class="heatmap-corner"></div>
        <div v-for="h in hours" :key="'h-' + h" class="heatmap-hour-label">{{ h }}h</div>
        <!-- Data rows -->
        <template v-for="(label, day) in dayLabels" :key="'d-' + day">
          <div class="heatmap-day-label">{{ label }}</div>
          <div
            v-for="h in hours"
            :key="'c-' + day + '-' + h"
            class="heatmap-cell"
            :style="cellColor(day, h)"
            :title="cellTooltip(day, h)"
          ></div>
        </template>
      </div>
    </div>
    <p v-else class="text-gray-400 text-sm py-8 text-center">{{ t('performance.no_data') }}</p>
  </div>
</template>

<style scoped>
.heatmap-grid {
  display: grid;
  grid-template-columns: 40px repeat(24, 1fr);
  gap: 2px;
  min-width: 600px;
}
.heatmap-corner {
  /* empty top-left cell */
}
.heatmap-session-band {
  font-size: 10px;
  font-weight: 600;
  text-align: center;
  border-radius: 4px;
  padding: 2px 0;
  display: flex;
  align-items: center;
  justify-content: center;
}
.heatmap-hour-label {
  font-size: 10px;
  text-align: center;
  color: #9ca3af;
}
.heatmap-day-label {
  font-size: 11px;
  display: flex;
  align-items: center;
  color: #9ca3af;
}
.heatmap-cell {
  aspect-ratio: 1;
  border-radius: 3px;
  min-height: 16px;
  cursor: default;
  --heatmap-empty: #f3f4f6;
}
:root.dark-mode .heatmap-cell {
  --heatmap-empty: #374151;
}
</style>
