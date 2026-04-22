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

// Session definitions: real market hours in their local timezone
const sessionDefs = [
  { name: 'ASIA', timezone: 'Asia/Tokyo', startHour: 9, startMin: 0, endHour: 15, endMin: 0 },
  { name: 'EUROPE', timezone: 'Europe/Paris', startHour: 8, startMin: 0, endHour: 16, endMin: 30 },
  { name: 'US', timezone: 'America/New_York', startHour: 9, startMin: 30, endHour: 16, endMin: 0 },
]

const sessionColors = {
  ASIA: { bg: 'bg-amber-100 dark:bg-amber-900/30', text: 'text-amber-700 dark:text-amber-300' },
  EUROPE: { bg: 'bg-blue-100 dark:bg-blue-900/30', text: 'text-blue-700 dark:text-blue-300' },
  US: { bg: 'bg-red-100 dark:bg-red-900/30', text: 'text-red-700 dark:text-red-300' },
}

/**
 * Get the UTC offset (in hours) for a given IANA timezone at a given date.
 */
function getTimezoneOffsetHours(timezone, date = new Date()) {
  const utcStr = date.toLocaleString('en-US', { timeZone: 'UTC' })
  const tzStr = date.toLocaleString('en-US', { timeZone: timezone })
  return Math.round((new Date(tzStr) - new Date(utcStr)) / 3600000)
}

/**
 * Get the start/end user-local hours for a session definition.
 */
function getSessionRange(def, userTz) {
  const now = new Date()
  const sessionOffset = getTimezoneOffsetHours(def.timezone, now)
  const userOffset = getTimezoneOffsetHours(userTz, now)
  const diff = userOffset - sessionOffset

  const start = ((def.startHour + diff) % 24 + 24) % 24
  const endLocalHour = def.endMin > 0 ? def.endHour + 1 : def.endHour
  const end = ((endLocalHour + diff) % 24 + 24) % 24
  return { start, end }
}

/**
 * Two rows of session bands: row1 (ASIA + EUROPE), row2 (US).
 * Each row is a full 24-column grid. Sessions are positioned with grid-column.
 * Where EUROPE and US overlap, both bands are visible (one per row).
 */
const sessionRows = computed(() => {
  const userTz = authStore.user?.timezone || 'UTC'

  const ranges = {}
  for (const def of sessionDefs) {
    ranges[def.name] = getSessionRange(def, userTz)
  }

  function buildBands(sessionName) {
    const r = ranges[sessionName]
    const colors = sessionColors[sessionName]
    // gridColumn is 1-indexed, offset by 1 for the day-label column
    if (r.start < r.end) {
      return [{ name: sessionName, colStart: r.start + 2, colEnd: r.end + 2, ...colors }]
    }
    // Wraps midnight: two segments
    const bands = []
    if (r.start < 24) bands.push({ name: sessionName, colStart: r.start + 2, colEnd: 26, ...colors, noLabel: false })
    if (r.end > 0) bands.push({ name: sessionName, colStart: 2, colEnd: r.end + 2, ...colors, noLabel: true })
    return bands
  }

  return {
    row1: [...buildBands('ASIA'), ...buildBands('EUROPE')],
    row2: buildBands('US'),
  }
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
        <!-- Session band row 1: ASIA + EUROPE -->
        <div class="heatmap-corner"></div>
        <div class="heatmap-bands-row">
          <div
            v-for="(b, idx) in sessionRows.row1"
            :key="'r1-' + idx"
            class="heatmap-session-band"
            :class="[b.bg, b.text]"
            :style="{ gridColumn: `${b.colStart} / ${b.colEnd}` }"
          >
            <template v-if="!b.noLabel">{{ t(`performance.sessions.${b.name}`) }}</template>
          </div>
        </div>
        <!-- Session band row 2: US -->
        <div class="heatmap-corner"></div>
        <div class="heatmap-bands-row">
          <div
            v-for="(b, idx) in sessionRows.row2"
            :key="'r2-' + idx"
            class="heatmap-session-band"
            :class="[b.bg, b.text]"
            :style="{ gridColumn: `${b.colStart} / ${b.colEnd}` }"
          >
            <template v-if="!b.noLabel">{{ t(`performance.sessions.${b.name}`) }}</template>
          </div>
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
.heatmap-bands-row {
  grid-column: 2 / -1;
  display: grid;
  grid-template-columns: repeat(24, 1fr);
  gap: 2px;
  min-height: 18px;
}
.heatmap-session-band {
  font-size: 10px;
  font-weight: 600;
  text-align: center;
  border-radius: 4px;
  padding: 1px 0;
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
