<script setup>
import { ref, watch, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Dialog from 'primevue/dialog'
import InputNumber from 'primevue/inputnumber'
import Button from 'primevue/button'
import PricePointsInput from '@/components/common/PricePointsInput.vue'
import { ExitType, Direction } from '@/constants/enums'
import { useNumberLocale } from '@/composables/useNumberLocale'

const { t } = useI18n()
const { numberLocale } = useNumberLocale()

const props = defineProps({
  visible: Boolean,
  trade: { type: Object, default: null },
  loading: Boolean,
  prefill: { type: Object, default: null },
})

const emit = defineEmits(['update:visible', 'close', 'mark-be'])

const form = ref(getDefaultForm())

function getDefaultForm() {
  return {
    exit_price: 0,
    exit_points: 0,
    exit_size: 0,
    exit_type: ExitType.MANUAL,
    target_id: null,
  }
}

const headerKey = computed(() => {
  switch (form.value.exit_type) {
    case ExitType.SL:
      return 'trades.close_sl'
    case ExitType.BE:
      return 'trades.close_be'
    case ExitType.TP:
      return 'trades.close_tp'
    default:
      return 'trades.close_stop_win'
  }
})

// Maps the exit intent to the paired-input offset mode (cf. PricePointsInput):
//   SL → loss direction · BE → signed slippage around entry · TP/MANUAL → profit.
const priceMode = computed(() => {
  switch (form.value.exit_type) {
    case ExitType.SL:
      return 'SL'
    case ExitType.BE:
      return 'BE'
    default:
      return 'TP'
  }
})

watch(
  () => props.visible,
  (val) => {
    if (!val || !props.trade) return
    form.value = buildInitialForm(props.trade, props.prefill)
  },
  { immediate: true },
)

// Initial form values are prefilled based on what the system knows:
// - BE: exit_price from the prefill when the caller planned the move to BE
//   (lightening at the configured BE price), else entry price = stopped out at
//   break-even (see the BE branch below)
// - SL: exit_price/points from trade.sl_points (the SL planned at creation;
//   user adjusts on real slippage)
// - TP (from "next objective"): exit_price + size from the target spec
// - MANUAL / Stop Win: nothing prefilled — we don't know the realized exit
//   ahead of time, the user types it.
function buildInitialForm(trade, prefill) {
  const remaining = Number(trade.remaining_size)
  const entry = Number(trade.entry_price)
  const exitType = prefill?.exit_type ?? ExitType.MANUAL

  const base = {
    exit_price: null,
    exit_points: null,
    exit_size: prefill?.exit_size ?? remaining,
    exit_type: exitType,
    target_id: prefill?.target_id ?? null,
  }

  if (exitType === ExitType.BE) {
    // Two intents reach this dialog with exit_type = BE:
    // - Moving TO break-even (the "next objective" shortcut): the trader
    //   lightens the planned size at the BE price configured on the trade, so
    //   the caller passes that price in the prefill — honour it. (#23)
    // - Being stopped out AT break-even (the BE button): the stop sits at the
    //   entry price, so that's what we propose, for the whole remaining size.
    //   The configured BE price is only the lightening trigger, never the
    //   stop-out price. (#32)
    // Either way the fields stay editable — the trader books the real spread.
    if (prefill?.exit_price != null) {
      const price = Number(prefill.exit_price)
      // BE points are signed (slippage either side of entry), cf. PricePointsInput.
      const points = trade.direction === Direction.BUY ? price - entry : entry - price
      return { ...base, exit_price: price, exit_points: points }
    }
    return { ...base, exit_price: entry, exit_points: 0 }
  }

  if (exitType === ExitType.SL && trade.sl_points != null) {
    const magnitude = Number(trade.sl_points)
    const price = trade.direction === Direction.BUY ? entry - magnitude : entry + magnitude
    return { ...base, exit_price: price, exit_points: magnitude }
  }

  if (exitType === ExitType.TP && prefill?.exit_price != null) {
    const price = Number(prefill.exit_price)
    return { ...base, exit_price: price, exit_points: Math.abs(price - entry) }
  }

  return base
}

const pnlPreview = computed(() => {
  if (!props.trade || !form.value.exit_price || !form.value.exit_size) return null
  const entryPrice = Number(props.trade.entry_price)
  const exitPrice = form.value.exit_price
  const exitSize = form.value.exit_size
  const multiplier = props.trade.direction === Direction.BUY ? 1 : -1
  return ((exitPrice - entryPrice) * exitSize * multiplier).toFixed(2)
})

function handleCloseFull() {
  form.value.exit_size = Number(props.trade.remaining_size)
}

function handleSubmit() {
  // "Protect but don't lighten": a BE with a zero exit size is not a partial
  // fill, so route to the dedicated "mark BE reached" action instead of a
  // close (which the backend rejects with invalid_exit_size).
  if (form.value.exit_type === ExitType.BE && Number(form.value.exit_size) === 0) {
    emit('mark-be')
    return
  }
  // exit_points is UX-only; backend reads exit_price.
  const { exit_points, ...payload } = form.value
  emit('close', payload)
}

function handleClose() {
  emit('update:visible', false)
}
</script>

<template>
  <Dialog
    :visible="visible"
    :header="t(headerKey)"
    :modal="true"
    :closable="true"
    :style="{ width: '450px' }"
    @update:visible="handleClose"
  >
    <div v-if="trade" class="flex flex-col gap-4">
      <div class="text-sm text-gray-600">
        {{ trade.symbol }} — {{ t(`positions.directions.${trade.direction}`) }} —
        {{ t('positions.entry_price') }}: {{ Number(trade.entry_price).toLocaleString() }} —
        {{ t('trades.remaining_size') }}: {{ Number(trade.remaining_size) }}
      </div>

      <PricePointsInput
        v-model:points="form.exit_points"
        v-model:price="form.exit_price"
        :entry-price="Number(trade.entry_price)"
        :direction="trade.direction"
        :mode="priceMode"
        price-first
        :price-label="`${t('trades.exit_price')} *`"
        :points-label="t('trades.exit_points')"
        points-name="exit_points"
        price-name="exit_price"
      />

      <div>
        <div class="flex items-center justify-between mb-1">
          <label class="block text-sm font-medium text-gray-700">{{ t('trades.exit_size') }} *</label>
          <Button :label="t('trades.close_full')" size="small" severity="secondary" text @click="handleCloseFull" />
        </div>
        <InputNumber v-model="form.exit_size" data-name="exit_size" class="w-full" :min="0" :max="Number(trade.remaining_size)" mode="decimal" :locale="numberLocale" :maxFractionDigits="5" />
      </div>

      <div v-if="pnlPreview !== null" class="p-3 rounded text-sm font-medium" :class="Number(pnlPreview) >= 0 ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700'">
        {{ t('trades.pnl_preview') }}: {{ Number(pnlPreview) >= 0 ? '+' : '' }}{{ pnlPreview }}
      </div>
    </div>

    <template #footer>
      <Button :label="t('common.cancel')" severity="secondary" @click="handleClose" />
      <Button :label="t('common.confirm')" :loading="loading" @click="handleSubmit" />
    </template>
  </Dialog>
</template>
