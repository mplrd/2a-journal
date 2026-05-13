<script setup>
import { ref, watch, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Dialog from 'primevue/dialog'
import InputNumber from 'primevue/inputnumber'
import Button from 'primevue/button'
import { ExitType, Direction } from '@/constants/enums'

const { t } = useI18n()

const props = defineProps({
  visible: Boolean,
  trade: { type: Object, default: null },
  loading: Boolean,
  prefill: { type: Object, default: null },
})

const emit = defineEmits(['update:visible', 'close'])

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

// BE is a SIGNED mode: slippage or spread can push exit a few points either
// side of entry. The user must be able to type both positive and negative
// magnitudes here. Other modes (SL / TP / MANUAL = Stop Win) carry an
// unambiguous sign from the bouton intent, so the input stays positive
// (magnitude only) and the sign is applied automatically.
const isSignedMode = computed(() => form.value.exit_type === ExitType.BE)

const pointsMin = computed(() => {
  if (!props.trade) return 0
  // For signed mode, allow negatives down to "exit_price = 0" on a BUY,
  // which is the largest meaningful loss in points. PrimeVue InputNumber
  // filters the `-` keystroke unless min < 0 — hence the dynamic bound.
  return isSignedMode.value ? -Number(props.trade.entry_price) : 0
})

// For unsigned modes, magnitude is positive and the sign comes from
// (direction × exit_type):
//   TP / MANUAL (Stop Win): profit ⇒ BUY price up, SELL price down
//   SL: loss               ⇒ BUY price down, SELL price up
function signedDelta(magnitude) {
  if (!props.trade) return 0
  const dirBuy = props.trade.direction === Direction.BUY
  switch (form.value.exit_type) {
    case ExitType.SL:
      return dirBuy ? -magnitude : magnitude
    case ExitType.BE:
      // Signed: caller already passed a signed magnitude, just apply direction.
      return dirBuy ? magnitude : -magnitude
    default:
      return dirBuy ? magnitude : -magnitude
  }
}

function pointsToPrice(magnitude) {
  if (!props.trade) return 0
  const entry = Number(props.trade.entry_price)
  if (magnitude == null || magnitude === 0) return entry
  return entry + signedDelta(magnitude)
}

function priceToPoints(price) {
  if (!props.trade || price == null) return 0
  const delta = price - Number(props.trade.entry_price)
  if (isSignedMode.value) {
    // Preserve sign: BUY direction reads delta directly, SELL inverts.
    return props.trade.direction === Direction.BUY ? delta : -delta
  }
  return Math.abs(delta)
}

function setExitPrice(value) {
  if (value == null) {
    form.value.exit_price = null
    form.value.exit_points = null
    return
  }
  form.value.exit_price = value
  form.value.exit_points = priceToPoints(value)
}

function setExitPoints(value) {
  if (value == null) {
    form.value.exit_points = null
    form.value.exit_price = null
    return
  }
  form.value.exit_points = value
  form.value.exit_price = pointsToPrice(value)
}

watch(
  () => props.visible,
  (val) => {
    if (!val || !props.trade) return
    form.value = buildInitialForm(props.trade, props.prefill)
  },
  { immediate: true },
)

// Initial form values are prefilled based on what the system knows:
// - BE: exit_price = entry, points = 0 (slippage = 0 by default; user adjusts)
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

      <div class="grid grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">{{ t('trades.exit_price') }} *</label>
          <InputNumber
            :modelValue="form.exit_price"
            data-name="exit_price"
            class="w-full"
            :min="0"
            mode="decimal"
            locale="en-US"
            :maxFractionDigits="5"
            @update:modelValue="setExitPrice"
          />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">{{ t('trades.exit_points') }}</label>
          <InputNumber
            :modelValue="form.exit_points"
            data-name="exit_points"
            class="w-full"
            :min="pointsMin"
            mode="decimal"
            locale="en-US"
            :maxFractionDigits="2"
            @update:modelValue="setExitPoints"
          />
        </div>
      </div>

      <div>
        <div class="flex items-center justify-between mb-1">
          <label class="block text-sm font-medium text-gray-700">{{ t('trades.exit_size') }} *</label>
          <Button :label="t('trades.close_full')" size="small" severity="secondary" text @click="handleCloseFull" />
        </div>
        <InputNumber v-model="form.exit_size" class="w-full" :min="0" :max="Number(trade.remaining_size)" mode="decimal" locale="en-US" :maxFractionDigits="5" />
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
