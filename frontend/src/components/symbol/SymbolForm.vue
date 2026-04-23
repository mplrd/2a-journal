<script setup>
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import Button from 'primevue/button'
import { SymbolType } from '@/constants/enums'

const { t } = useI18n()

const props = defineProps({
  visible: Boolean,
  symbol: { type: Object, default: null },
  loading: Boolean,
})

const emit = defineEmits(['update:visible', 'save'])

const form = ref(getDefaultForm())

const typeOptions = Object.values(SymbolType).map((value) => ({
  label: t(`symbols.types.${value}`),
  value,
}))

function getDefaultForm() {
  return {
    code: '',
    name: '',
    type: SymbolType.INDEX,
  }
}

watch(
  () => props.visible,
  (val) => {
    if (val) {
      form.value = props.symbol
        ? {
            code: props.symbol.code,
            name: props.symbol.name,
            type: props.symbol.type,
          }
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
    :header="symbol ? t('symbols.edit') : t('symbols.create')"
    :modal="true"
    :closable="true"
    :style="{ width: '450px' }"
    @update:visible="handleClose"
  >
    <div class="flex flex-col gap-4">
      <div class="grid grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">{{ t('symbols.ticker') }} *</label>
          <InputText v-model="form.code" class="w-full" :maxlength="20" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">{{ t('symbols.type') }} *</label>
          <Select v-model="form.type" :options="typeOptions" optionLabel="label" optionValue="value" class="w-full" />
        </div>
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">{{ t('symbols.name') }} *</label>
        <InputText v-model="form.name" class="w-full" :maxlength="100" />
      </div>

      <p class="text-xs text-gray-500 mt-1">
        {{ t('symbols.point_value_per_account_hint') }}
      </p>
    </div>

    <template #footer>
      <Button :label="t('common.cancel')" severity="secondary" @click="handleClose" />
      <Button :label="t('common.save')" :loading="loading" @click="handleSave" />
    </template>
  </Dialog>
</template>
