<script setup>
import { computed, watch } from 'vue'
import InputNumber from 'primevue/inputnumber'
import { Direction } from '@/constants/enums'
import { useNumberLocale } from '@/composables/useNumberLocale'

// Paired price ⇄ points input. The two fields stay in sync: editing one
// recomputes the other from the trade's entry price + direction. Extracted from
// the inline logic of CloseTradeDialog so it can be reused for the objectives
// (SL / BE / TP) on the trade form.
//
// Offset modes (the sign/bound differ per objective):
//   SL → loss direction,   magnitude ≥ 0  (BUY: entry − pts, SELL: entry + pts)
//   TP → profit direction, magnitude ≥ 0  (BUY: entry + pts, SELL: entry − pts)
//   BE → signed around entry, min = −entry (slippage either side of entry)
const props = defineProps({
  entryPrice: { type: Number, default: null },
  direction: {
    type: String,
    default: 'BUY',
    validator: (v) => ['BUY', 'SELL'].includes(v),
  },
  // Offset mode — how the points magnitude maps onto a price (see comment above).
  mode: {
    type: String,
    default: 'SL',
    validator: (v) => ['SL', 'TP', 'BE'].includes(v),
  },
  pointsLabel: { type: String, default: '' },
  priceLabel: { type: String, default: '' },
  pointsPlaceholder: { type: String, default: '' },
  pricePlaceholder: { type: String, default: '' },
  pointsName: { type: String, default: 'points' },
  priceName: { type: String, default: 'price' },
  priceFirst: { type: Boolean, default: false },
  pointsFractionDigits: { type: Number, default: 2 },
  priceFractionDigits: { type: Number, default: 5 },
  disabled: { type: Boolean, default: false },
})

const { numberLocale } = useNumberLocale()

const points = defineModel('points', { default: null })
const price = defineModel('price', { default: null })

const isSigned = computed(() => props.mode === 'BE')

const pointsMin = computed(() => {
  // Signed mode (BE): allow negatives down to "price = 0". PrimeVue InputNumber
  // filters the `-` keystroke unless min < 0 — hence the dynamic bound.
  if (!isSigned.value) return 0
  return props.entryPrice != null ? -Number(props.entryPrice) : 0
})

function signedDelta(magnitude) {
  const dirBuy = props.direction === Direction.BUY
  // SL points push the price against the position (a loss); TP/BE push it the
  // profit way (BE carries an already-signed magnitude).
  if (props.mode === 'SL') return dirBuy ? -magnitude : magnitude
  return dirBuy ? magnitude : -magnitude
}

function pointsToPrice(magnitude) {
  if (props.entryPrice == null || magnitude == null) return null
  const entry = Number(props.entryPrice)
  if (magnitude === 0) return entry
  return entry + signedDelta(magnitude)
}

function priceToPoints(value) {
  if (props.entryPrice == null || value == null) return null
  const delta = value - Number(props.entryPrice)
  if (isSigned.value) {
    // Preserve sign: BUY reads delta directly, SELL inverts it.
    return props.direction === Direction.BUY ? delta : -delta
  }
  return Math.abs(delta)
}

function setPoints(value) {
  points.value = value
  price.value = value == null ? null : pointsToPrice(value)
}

function setPrice(value) {
  price.value = value
  points.value = value == null ? null : priceToPoints(value)
}

// Seed the price from the incoming points, and keep it in sync when the
// surrounding context changes (entry / direction / mode). The points stay
// fixed — only the price is recomputed (entry can move while the form is open).
watch(
  () => [props.entryPrice, props.direction, props.mode],
  () => {
    if (points.value != null) price.value = pointsToPrice(points.value)
  },
  { immediate: true },
)
</script>

<template>
  <div class="grid grid-cols-2 gap-4">
    <div :class="priceFirst ? 'order-2' : 'order-1'">
      <label
        v-if="pointsLabel"
        class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"
      >{{ pointsLabel }}</label>
      <InputNumber
        :modelValue="points"
        :data-name="pointsName"
        class="w-full"
        :min="pointsMin"
        mode="decimal"
        :locale="numberLocale"
        :maxFractionDigits="pointsFractionDigits"
        :placeholder="pointsPlaceholder"
        :disabled="disabled"
        @update:modelValue="setPoints"
      />
    </div>
    <div :class="priceFirst ? 'order-1' : 'order-2'">
      <label
        v-if="priceLabel"
        class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"
      >{{ priceLabel }}</label>
      <InputNumber
        :modelValue="price"
        :data-name="priceName"
        class="w-full"
        :min="0"
        mode="decimal"
        :locale="numberLocale"
        :maxFractionDigits="priceFractionDigits"
        :placeholder="pricePlaceholder"
        :disabled="disabled"
        @update:modelValue="setPrice"
      />
    </div>
  </div>
</template>
