<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

// How much a win and a loss weigh, under the win / loss pie. `data` is the
// pie's own payload (`win_loss` of /stats/charts), so the amounts are taken on
// the trades the pie counts, breakeven left out of both.
//
// Laid out as a butterfly: losses left of zero, gains right of it, one row per
// metric, the amount riding the tip of its bar. Each row is scaled on its own
// larger side — an outlier largest win would otherwise flatten the average row
// into two slivers. Gain and loss are never told apart by colour alone (the
// brand red / green pair does not survive deuteranopia): side of the axis,
// arrow, label and sign all carry it.
const { t } = useI18n()

const props = defineProps({
  data: { type: Object, default: null },
})

const counts = computed(() => ({
  loss: props.data?.loss ?? 0,
  win: props.data?.win ?? 0,
}))

const rows = computed(() => [
  {
    key: 'average',
    label: t('performance.win_loss_amounts_average'),
    ...scaled(props.data?.avg_loss ?? null, props.data?.avg_win ?? null),
  },
  {
    key: 'largest',
    label: t('performance.win_loss_amounts_largest'),
    ...scaled(props.data?.max_loss ?? null, props.data?.max_win ?? null),
  },
])

function scaled(loss, win) {
  const scale = Math.max(Math.abs(loss ?? 0), Math.abs(win ?? 0))
  const width = (value) => (value == null || scale === 0 ? null : (Math.abs(value) / scale) * 100)
  return {
    loss: { value: loss, width: width(loss) },
    win: { value: win, width: width(win) },
  }
}

function formatPnl(value) {
  if (value == null) return '-'
  const num = Number(value)
  return (num >= 0 ? '+' : '') + num.toFixed(2)
}
</script>

<template>
  <section
    data-testid="win-loss-amounts-block"
    class="mt-6 rounded-lg border border-gray-200 px-4 pb-5 pt-4 sm:px-6 dark:border-white/10"
  >
    <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
      <h4 class="text-sm font-semibold text-gray-700 dark:text-brand-cream">
        {{ t('performance.win_loss_amounts_title') }}
      </h4>
      <span class="text-xs text-gray-500 dark:text-brand-navy-300">
        {{ t('performance.win_loss_amounts_be_excluded') }}
      </span>
    </div>

    <div class="amounts-chart relative mt-5">
      <!-- Zero axis, drawn once through the headers and every row -->
      <div class="amounts-axis" aria-hidden="true" />

      <!-- Side headers, mirrored around the axis -->
      <div class="amounts-row">
        <div class="amounts-label" aria-hidden="true" />
        <div class="amounts-halves">
          <div data-testid="win-loss-amounts-header-loss" class="flex items-center justify-end gap-2 pr-2 text-right">
            <div>
              <div class="text-sm font-medium text-gray-700 dark:text-brand-cream">{{ t('performance.losses') }}</div>
              <div class="text-xs text-gray-500 dark:text-brand-navy-300">
                {{ t('performance.win_loss_amounts_trades', counts.loss) }}
              </div>
            </div>
            <span
              class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-danger-bg text-danger dark:bg-danger/25 dark:text-danger-fg-dark"
              aria-hidden="true"
            >
              <i class="pi pi-arrow-down text-xs" />
            </span>
          </div>
          <div data-testid="win-loss-amounts-header-win" class="flex items-center gap-2 pl-2">
            <span
              class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-success-bg text-success dark:bg-brand-green-700/40 dark:text-brand-green-400"
              aria-hidden="true"
            >
              <i class="pi pi-arrow-up text-xs" />
            </span>
            <div>
              <div class="text-sm font-medium text-gray-700 dark:text-brand-cream">{{ t('performance.wins') }}</div>
              <div class="text-xs text-gray-500 dark:text-brand-navy-300">
                {{ t('performance.win_loss_amounts_trades', counts.win) }}
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- One row per metric -->
      <div v-for="row in rows" :key="row.key" class="amounts-row mt-5">
        <div class="amounts-label text-[11px] font-semibold uppercase tracking-wider text-gray-500 dark:text-brand-navy-300">
          {{ row.label }}
        </div>
        <div class="amounts-halves h-8 items-center">
          <div class="amounts-track-loss flex justify-end">
            <div
              class="relative h-3"
              :class="row.loss.width != null ? 'min-w-[2px] rounded-l bg-danger dark:bg-danger-fg-dark' : ''"
              :style="{ width: `${row.loss.width ?? 0}%` }"
              :data-testid="row.loss.width != null ? `win-loss-amounts-${row.key}-loss-bar` : null"
            >
              <span
                :data-testid="`win-loss-amounts-${row.key}-loss`"
                class="amounts-value right-full mr-2.5"
                :class="row.loss.value == null ? 'text-gray-400 dark:text-brand-navy-300' : 'text-danger dark:text-danger-fg-dark'"
              >{{ formatPnl(row.loss.value) }}</span>
            </div>
          </div>
          <div class="amounts-track-win flex">
            <div
              class="relative h-3"
              :class="row.win.width != null ? 'min-w-[2px] rounded-r bg-success dark:bg-brand-green-400' : ''"
              :style="{ width: `${row.win.width ?? 0}%` }"
              :data-testid="row.win.width != null ? `win-loss-amounts-${row.key}-win-bar` : null"
            >
              <span
                :data-testid="`win-loss-amounts-${row.key}-win`"
                class="amounts-value left-full ml-2.5"
                :class="row.win.value == null ? 'text-gray-400 dark:text-brand-navy-300' : 'text-success dark:text-brand-green-400'"
              >{{ formatPnl(row.win.value) }}</span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>
</template>

<style scoped>
/* Narrow screens: the row label sits above its bars. From 640px it takes a
   column on the left, and the axis moves to the centre of what is left. */
.amounts-chart {
  --label-col: 0px;
  --label-gap: 0px;
}

.amounts-row {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
}

.amounts-label:empty {
  display: none;
}

/* Loss half | win half. The 5px gap holds the 1px axis with a 2px surface gap
   on either side, so no bar ever touches it. */
.amounts-halves {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
  column-gap: 5px;
}

.amounts-axis {
  position: absolute;
  top: 0;
  bottom: 0;
  left: calc(var(--label-col) + var(--label-gap) + (100% - var(--label-col) - var(--label-gap)) / 2);
  width: 1px;
  transform: translateX(-50%);
  background-color: var(--color-gray-200);
}

:global(.dark-mode) .amounts-axis {
  background-color: rgba(255, 255, 255, 0.15);
}

/* The outer padding reserves room for the amount at the tip of a full bar. */
.amounts-track-loss {
  padding-left: 4.75rem;
}

.amounts-track-win {
  padding-right: 4.75rem;
}

.amounts-value {
  position: absolute;
  top: 50%;
  transform: translateY(-50%);
  white-space: nowrap;
  font-family: var(--font-mono);
  font-variant-numeric: tabular-nums;
  font-weight: 600;
  font-size: 0.875rem;
  line-height: 1.25rem;
}

@media (min-width: 640px) {
  .amounts-chart {
    --label-col: 5.5rem;
    --label-gap: 1rem;
  }

  .amounts-row {
    grid-template-columns: var(--label-col) minmax(0, 1fr);
    column-gap: var(--label-gap);
    align-items: center;
  }

  .amounts-label:empty {
    display: block;
  }

  .amounts-track-loss {
    padding-left: 7rem;
  }

  .amounts-track-win {
    padding-right: 7rem;
  }

  .amounts-value {
    font-size: 1.125rem;
    line-height: 1.75rem;
  }
}
</style>
