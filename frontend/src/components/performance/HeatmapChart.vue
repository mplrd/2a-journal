<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

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
