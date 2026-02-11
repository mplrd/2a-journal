<script setup>
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import Select from 'primevue/select'
import Button from 'primevue/button'

const { t } = useI18n()

const props = defineProps({
  visible: Boolean,
  account: { type: Object, default: null },
  loading: Boolean,
})

const emit = defineEmits(['update:visible', 'save'])

const form = ref(getDefaultForm())

const accountTypeOptions = [
  { label: t('accounts.types.BROKER'), value: 'BROKER' },
  { label: t('accounts.types.PROPFIRM'), value: 'PROPFIRM' },
]

const modeOptions = [
  { label: t('accounts.modes.DEMO'), value: 'DEMO' },
  { label: t('accounts.modes.LIVE'), value: 'LIVE' },
  { label: t('accounts.modes.CHALLENGE'), value: 'CHALLENGE' },
  { label: t('accounts.modes.VERIFICATION'), value: 'VERIFICATION' },
  { label: t('accounts.modes.FUNDED'), value: 'FUNDED' },
]

function getDefaultForm() {
  return {
    name: '',
    account_type: 'BROKER',
    mode: 'DEMO',
    currency: 'EUR',
    initial_capital: 0,
    broker: '',
    max_drawdown: null,
    daily_drawdown: null,
    profit_target: null,
    profit_split: null,
  }
}

watch(
  () => props.visible,
  (val) => {
    if (val) {
      form.value = props.account
        ? { ...props.account, broker: props.account.broker || '' }
        : getDefaultForm()
    }
  },
)

function handleSave() {
  emit('save', { ...form.value })
}

function handleClose() {
  emit('update:visible', false)
}
</script>

<template>
  <Dialog
    :visible="visible"
    :header="account ? t('accounts.edit') : t('accounts.create')"
    :modal="true"
    :closable="true"
    :style="{ width: '500px' }"
    @update:visible="handleClose"
  >
    <div class="flex flex-col gap-4">
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">{{ t('accounts.name') }} *</label>
        <InputText v-model="form.name" class="w-full" :maxlength="100" />
      </div>

      <div class="grid grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">{{ t('accounts.account_type') }} *</label>
          <Select v-model="form.account_type" :options="accountTypeOptions" optionLabel="label" optionValue="value" class="w-full" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">{{ t('accounts.mode') }} *</label>
          <Select v-model="form.mode" :options="modeOptions" optionLabel="label" optionValue="value" class="w-full" />
        </div>
      </div>

      <div class="grid grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">{{ t('accounts.currency') }}</label>
          <InputText v-model="form.currency" class="w-full" :maxlength="3" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">{{ t('accounts.initial_capital') }}</label>
          <InputNumber v-model="form.initial_capital" class="w-full" :min="0" mode="decimal" :maxFractionDigits="2" />
        </div>
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">{{ t('accounts.broker') }}</label>
        <InputText v-model="form.broker" class="w-full" :maxlength="100" />
      </div>

      <div class="grid grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">{{ t('accounts.max_drawdown') }}</label>
          <InputNumber v-model="form.max_drawdown" class="w-full" :min="0" mode="decimal" :maxFractionDigits="2" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">{{ t('accounts.daily_drawdown') }}</label>
          <InputNumber v-model="form.daily_drawdown" class="w-full" :min="0" mode="decimal" :maxFractionDigits="2" />
        </div>
      </div>

      <div class="grid grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">{{ t('accounts.profit_target') }}</label>
          <InputNumber v-model="form.profit_target" class="w-full" :min="0" mode="decimal" :maxFractionDigits="2" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">{{ t('accounts.profit_split') }}</label>
          <InputNumber v-model="form.profit_split" class="w-full" :min="0" :max="100" mode="decimal" :maxFractionDigits="2" />
        </div>
      </div>
    </div>

    <template #footer>
      <Button :label="t('common.cancel')" severity="secondary" @click="handleClose" />
      <Button :label="t('common.save')" :loading="loading" @click="handleSave" />
    </template>
  </Dialog>
</template>
