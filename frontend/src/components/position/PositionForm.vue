<script setup>
import { ref, watch, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import Button from 'primevue/button'
import { Direction } from '@/constants/enums'

const { t } = useI18n()

const props = defineProps({
  visible: Boolean,
  position: { type: Object, default: null },
  loading: Boolean,
})

const emit = defineEmits(['update:visible', 'save'])

const form = ref(getDefaultForm())

const directionOptions = Object.values(Direction).map((value) => ({
  label: t(`positions.directions.${value}`),
  value,
}))

function getDefaultForm() {
  return {
    entry_price: 0,
    size: 1,
    sl_points: 0,
    be_points: null,
    be_size: null,
    direction: Direction.BUY,
    symbol: '',
    setup: '',
    notes: '',
    targets: [],
  }
}

watch(
  () => props.visible,
  (val) => {
    if (val && props.position) {
      const targets = props.position.targets
        ? typeof props.position.targets === 'string'
          ? JSON.parse(props.position.targets)
          : props.position.targets
        : []
      form.value = {
        entry_price: Number(props.position.entry_price),
        size: Number(props.position.size),
        sl_points: Number(props.position.sl_points),
        be_points: props.position.be_points ? Number(props.position.be_points) : null,
        be_size: props.position.be_size ? Number(props.position.be_size) : null,
        direction: props.position.direction,
        symbol: props.position.symbol,
        setup: props.position.setup,
        notes: props.position.notes || '',
        targets: targets || [],
      }
    }
  },
)

const calculatedSlPrice = computed(() => {
  if (!form.value.entry_price || !form.value.sl_points) return null
  if (form.value.direction === Direction.BUY) {
    return form.value.entry_price - form.value.sl_points
  }
  return form.value.entry_price + form.value.sl_points
})

const calculatedBePrice = computed(() => {
  if (!form.value.entry_price || !form.value.be_points) return null
  if (form.value.direction === Direction.BUY) {
    return form.value.entry_price + form.value.be_points
  }
  return form.value.entry_price - form.value.be_points
})

const calculatedTargets = computed(() => {
  if (!form.value.entry_price || !form.value.targets?.length) return []
  return form.value.targets.map((target) => ({
    ...target,
    price:
      form.value.direction === Direction.BUY
        ? form.value.entry_price + (target.points || 0)
        : form.value.entry_price - (target.points || 0),
  }))
})

function addTarget() {
  form.value.targets.push({
    id: `tp${form.value.targets.length + 1}`,
    label: `TP${form.value.targets.length + 1}`,
    points: 0,
    size: 0,
  })
}

function removeTarget(index) {
  form.value.targets.splice(index, 1)
}

function handleSave() {
  const data = { ...form.value }
  if (data.targets.length > 0) {
    data.targets = calculatedTargets.value
  } else {
    data.targets = null
  }
  emit('save', data)
}

function handleClose() {
  emit('update:visible', false)
}
</script>

<template>
  <Dialog
    :visible="visible"
    :header="t('positions.edit')"
    :modal="true"
    :closable="true"
    :style="{ width: '600px' }"
    @update:visible="handleClose"
  >
    <div class="flex flex-col gap-4">
      <div class="grid grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">{{ t('positions.symbol') }} *</label>
          <InputText v-model="form.symbol" class="w-full" :maxlength="50" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">{{ t('positions.direction') }} *</label>
          <Select v-model="form.direction" :options="directionOptions" optionLabel="label" optionValue="value" class="w-full" />
        </div>
      </div>

      <div class="grid grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">{{ t('positions.entry_price') }} *</label>
          <InputNumber v-model="form.entry_price" class="w-full" :min="0" mode="decimal" :maxFractionDigits="5" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">{{ t('positions.size') }} *</label>
          <InputNumber v-model="form.size" class="w-full" :min="0" mode="decimal" :maxFractionDigits="4" />
        </div>
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">{{ t('positions.setup') }} *</label>
        <InputText v-model="form.setup" class="w-full" :maxlength="255" />
      </div>

      <div class="grid grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">{{ t('positions.sl_points') }} *</label>
          <InputNumber v-model="form.sl_points" class="w-full" :min="0" mode="decimal" :maxFractionDigits="2" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">{{ t('positions.sl_price') }}</label>
          <div class="p-2 bg-gray-100 rounded text-sm">
            {{ calculatedSlPrice != null ? calculatedSlPrice.toLocaleString() : '-' }}
          </div>
        </div>
      </div>

      <div class="grid grid-cols-3 gap-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">{{ t('positions.be_points') }}</label>
          <InputNumber v-model="form.be_points" class="w-full" :min="0" mode="decimal" :maxFractionDigits="2" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">{{ t('positions.be_price') }}</label>
          <div class="p-2 bg-gray-100 rounded text-sm">
            {{ calculatedBePrice != null ? calculatedBePrice.toLocaleString() : '-' }}
          </div>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">{{ t('positions.be_size') }}</label>
          <InputNumber v-model="form.be_size" class="w-full" :min="0" mode="decimal" :maxFractionDigits="4" />
        </div>
      </div>

      <div>
        <div class="flex items-center justify-between mb-2">
          <label class="block text-sm font-medium text-gray-700">{{ t('positions.targets') }}</label>
          <Button :label="t('positions.add_target')" icon="pi pi-plus" size="small" severity="secondary" text @click="addTarget" />
        </div>
        <div v-for="(target, index) in form.targets" :key="index" class="flex items-center gap-2 mb-2">
          <InputText v-model="target.label" class="w-20" :placeholder="t('positions.target_label')" />
          <InputNumber v-model="target.points" class="w-28" :min="0" mode="decimal" :maxFractionDigits="2" :placeholder="t('positions.target_points')" />
          <InputNumber v-model="target.size" class="w-28" :min="0" mode="decimal" :maxFractionDigits="4" :placeholder="t('positions.target_size')" />
          <span class="text-sm text-gray-500 w-28">
            {{ calculatedTargets[index]?.price != null ? calculatedTargets[index].price.toLocaleString() : '-' }}
          </span>
          <Button icon="pi pi-times" severity="danger" size="small" text @click="removeTarget(index)" />
        </div>
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">{{ t('positions.notes') }}</label>
        <Textarea v-model="form.notes" class="w-full" rows="3" :maxlength="10000" />
      </div>
    </div>

    <template #footer>
      <Button :label="t('common.cancel')" severity="secondary" @click="handleClose" />
      <Button :label="t('common.save')" :loading="loading" @click="handleSave" />
    </template>
  </Dialog>
</template>
