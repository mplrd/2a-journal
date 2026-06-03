<script setup>
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import Button from 'primevue/button'
import BalanceAdjustmentInput from '@/components/common/BalanceAdjustmentInput.vue'

const { t } = useI18n()

const props = defineProps({
  visible: Boolean,
  account: { type: Object, default: null },
  adjustments: { type: Array, default: () => [] },
  loading: Boolean,
})

const emit = defineEmits(['update:visible', 'submit', 'delete-adjustment'])

const amount = ref(null)
const reason = ref('')

const base = computed(() => (props.account ? Number(props.account.current_capital) : 0))
const canSubmit = computed(() => amount.value != null && Number(amount.value) !== 0)

// Reset the form each time the dialog opens (the history is driven by the
// `adjustments` prop, refreshed by the parent).
watch(
  () => props.visible,
  (val) => {
    if (val) {
      amount.value = null
      reason.value = ''
    }
  },
  { immediate: true },
)

function formatAmount(value) {
  const n = Number(value)
  const sign = n > 0 ? '+' : ''
  return `${sign}${n.toLocaleString()}`
}

function formatDate(value) {
  return value ? String(value).slice(0, 10) : ''
}

function handleSubmit() {
  if (!canSubmit.value) return
  emit('submit', { amount: Number(amount.value), reason: reason.value?.trim() || null })
}

function handleClose() {
  emit('update:visible', false)
}
</script>

<template>
  <Dialog
    :visible="visible"
    :header="t('accounts.adjust_balance')"
    :modal="true"
    :closable="true"
    :style="{ width: '480px' }"
    @update:visible="handleClose"
  >
    <div v-if="account" class="flex flex-col gap-4">
      <p class="text-sm text-gray-600 dark:text-gray-300">{{ t('accounts.adjust_balance_hint') }}</p>

      <div class="text-sm text-gray-600 dark:text-gray-300">
        {{ account.name }} —
        {{ t('accounts.balance') }}:
        <span class="font-mono tabular-nums">{{ base.toLocaleString() }} {{ account.currency }}</span>
      </div>

      <BalanceAdjustmentInput
        v-model:amount="amount"
        :base="base"
        :target-label="t('accounts.real_balance')"
        :amount-label="t('accounts.adjustment_amount')"
      />

      <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ t('accounts.adjustment_reason') }}</label>
        <InputText v-model="reason" data-name="reason" class="w-full" :maxlength="255" :placeholder="t('accounts.adjustment_reason_placeholder')" />
      </div>

      <div v-if="adjustments.length" class="border-t border-gray-200 dark:border-gray-700 pt-3">
        <div class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ t('accounts.adjustment_history') }}</div>
        <ul class="flex flex-col gap-1 max-h-48 overflow-y-auto">
          <li
            v-for="adj in adjustments"
            :key="adj.id"
            data-testid="adjustment-row"
            class="flex items-center justify-between gap-2 text-sm py-1"
          >
            <span class="text-gray-500 tabular-nums">{{ formatDate(adj.adjusted_at) }}</span>
            <span class="font-mono tabular-nums" :class="Number(adj.amount) >= 0 ? 'text-success' : 'text-danger'">
              {{ formatAmount(adj.amount) }}
            </span>
            <span class="flex-1 truncate text-gray-600 dark:text-gray-300">{{ adj.reason }}</span>
            <Button
              icon="pi pi-trash"
              severity="danger"
              size="small"
              text
              rounded
              data-testid="delete-adjustment"
              :aria-label="t('common.delete')"
              @click="emit('delete-adjustment', adj.id)"
            />
          </li>
        </ul>
      </div>
    </div>

    <template #footer>
      <Button :label="t('common.cancel')" severity="secondary" @click="handleClose" />
      <Button :label="t('common.confirm')" :loading="loading" :disabled="!canSubmit" @click="handleSubmit" />
    </template>
  </Dialog>
</template>
