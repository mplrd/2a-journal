<script setup>
import { ref, watch, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Dialog from 'primevue/dialog'
import InputNumber from 'primevue/inputnumber'
import Select from 'primevue/select'
import Button from 'primevue/button'
import { ExitType, Direction } from '@/constants/enums'

const { t } = useI18n()

const props = defineProps({
  visible: Boolean,
  trade: { type: Object, default: null },
  loading: Boolean,
})

const emit = defineEmits(['update:visible', 'close'])

const form = ref(getDefaultForm())

const exitTypeOptions = Object.values(ExitType).map((value) => ({
  label: t(`trades.exit_types.${value}`),
  value,
}))

function getDefaultForm() {
  return {
    exit_price: 0,
    exit_size: 0,
    exit_type: ExitType.TP,
  }
}

watch(
  () => props.visible,
  (val) => {
    if (val && props.trade) {
      form.value = {
        exit_price: 0,
        exit_size: Number(props.trade.remaining_size),
        exit_type: ExitType.TP,
      }
    }
  },
)

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
  emit('close', { ...form.value })
}

function handleClose() {
  emit('update:visible', false)
}
</script>

<template>
  <Dialog
    :visible="visible"
    :header="t('trades.close_trade')"
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

      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">{{ t('trades.exit_price') }} *</label>
        <InputNumber v-model="form.exit_price" class="w-full" :min="0" mode="decimal" :maxFractionDigits="5" />
      </div>

      <div>
        <div class="flex items-center justify-between mb-1">
          <label class="block text-sm font-medium text-gray-700">{{ t('trades.exit_size') }} *</label>
          <Button :label="t('trades.close_full')" size="small" severity="secondary" text @click="handleCloseFull" />
        </div>
        <InputNumber v-model="form.exit_size" class="w-full" :min="0" :max="Number(trade.remaining_size)" mode="decimal" :maxFractionDigits="4" />
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">{{ t('trades.exit_type') }} *</label>
        <Select v-model="form.exit_type" :options="exitTypeOptions" optionLabel="label" optionValue="value" class="w-full" />
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
