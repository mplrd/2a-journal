<script setup>
import { ref, watch } from 'vue'
import InputNumber from 'primevue/inputnumber'

// Paired "real balance" ⇄ "adjustment" input, same idea as PricePointsInput.
// The model is the signed delta (`amount`); the target balance is derived from
// the account's current computed balance (`base`):
//   target = base + amount      amount = target − base
// Editing either field keeps the other in sync.
const props = defineProps({
  base: { type: Number, default: 0 },
  targetLabel: { type: String, default: '' },
  amountLabel: { type: String, default: '' },
  locale: { type: String, default: 'en-US' },
  disabled: { type: Boolean, default: false },
})

const amount = defineModel('amount', { default: null })
const target = ref(null)

function round2(value) {
  return Math.round(value * 100) / 100
}

function setTarget(value) {
  target.value = value
  amount.value = value == null ? null : round2(value - Number(props.base))
}

function setAmount(value) {
  amount.value = value
}

// Keep the target balance derived from base + amount. Runs immediately so the
// field opens seeded with the current balance (amount null → target = base).
watch(
  () => [props.base, amount.value],
  () => {
    target.value = amount.value == null ? Number(props.base) : round2(Number(props.base) + Number(amount.value))
  },
  { immediate: true },
)
</script>

<template>
  <div class="grid grid-cols-2 gap-4">
    <div>
      <label
        v-if="targetLabel"
        class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"
      >{{ targetLabel }}</label>
      <InputNumber
        :modelValue="target"
        data-name="target"
        class="w-full"
        :min="0"
        mode="decimal"
        :locale="locale"
        :maxFractionDigits="2"
        :disabled="disabled"
        @update:modelValue="setTarget"
      />
    </div>
    <div>
      <label
        v-if="amountLabel"
        class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"
      >{{ amountLabel }}</label>
      <InputNumber
        :modelValue="amount"
        data-name="adjustment"
        class="w-full"
        :min="base != null ? -Number(base) : 0"
        mode="decimal"
        :locale="locale"
        :maxFractionDigits="2"
        :disabled="disabled"
        @update:modelValue="setAmount"
      />
    </div>
  </div>
</template>
