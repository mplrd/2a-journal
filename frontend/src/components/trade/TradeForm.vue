<script setup>
import { ref, watch, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import AutoComplete from 'primevue/autocomplete'
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import ToggleSwitch from 'primevue/toggleswitch'
import DatePicker from 'primevue/datepicker'
import Button from 'primevue/button'
import { Direction, CustomFieldType } from '@/constants/enums'
import { useSymbolsStore } from '@/stores/symbols'
import { useToast } from 'primevue/usetoast'
import SymbolForm from '@/components/symbol/SymbolForm.vue'
import { useSharePreview } from '@/composables/useSharePreview'

const { t } = useI18n()
const symbolsStore = useSymbolsStore()
const toast = useToast()

const props = defineProps({
  visible: Boolean,
  accounts: { type: Array, default: () => [] },
  symbols: { type: Array, default: () => [] },
  setups: { type: Array, default: () => [] },
  customFieldDefinitions: { type: Array, default: () => [] },
  loading: Boolean,
  trade: { type: Object, default: null },
})

const emit = defineEmits(['update:visible', 'save'])

const isEdit = computed(() => !!props.trade)
const isClosedTrade = computed(() => props.trade?.status === 'CLOSED')

const showSymbolForm = ref(false)
const filteredSetups = ref([])

function searchSetups(event) {
  const query = event.query.trim()
  const queryLower = query.toLowerCase()
  const matches = props.setups.filter((s) => s.toLowerCase().includes(queryLower))
  if (query && !matches.some((s) => s.toLowerCase() === queryLower)) {
    matches.unshift(query)
  }
  filteredSetups.value = matches
}

const form = ref(getDefaultForm())

const directionOptions = Object.values(Direction).map((value) => ({
  label: t(`positions.directions.${value}`),
  value,
}))

function getDefaultForm() {
  return {
    account_id: null,
    entry_price: 0,
    size: 1,
    sl_points: 0,
    be_points: null,
    be_size: null,
    direction: Direction.BUY,
    symbol: '',
    setup: [],
    notes: '',
    targets: [],
    opened_at: new Date(),
    closed_at: null,
    custom_fields: {},
  }
}

function parseSetup(setup) {
  if (Array.isArray(setup)) return setup
  if (!setup) return []
  try { return JSON.parse(setup) } catch { return [setup] }
}

function customFieldsToMap(list, definitions) {
  const map = {}
  for (const entry of list || []) {
    const def = definitions.find((d) => d.id === entry.field_id)
    if (!def) continue
    if (def.field_type === CustomFieldType.BOOLEAN) {
      map[entry.field_id] = entry.value === 'true' || entry.value === true
    } else if (def.field_type === CustomFieldType.NUMBER) {
      map[entry.field_id] = entry.value !== null && entry.value !== '' ? Number(entry.value) : null
    } else {
      map[entry.field_id] = entry.value
    }
  }
  return map
}

function populateFromTrade(trade) {
  const targets = trade.targets
    ? typeof trade.targets === 'string' ? JSON.parse(trade.targets) : trade.targets
    : []
  return {
    account_id: trade.account_id ?? null,
    entry_price: Number(trade.entry_price),
    size: Number(trade.size),
    sl_points: Number(trade.sl_points),
    be_points: trade.be_points != null ? Number(trade.be_points) : null,
    be_size: trade.be_size != null ? Number(trade.be_size) : null,
    direction: trade.direction,
    symbol: trade.symbol,
    setup: parseSetup(trade.setup),
    notes: trade.notes || '',
    targets: targets || [],
    opened_at: trade.opened_at ? new Date(trade.opened_at) : new Date(),
    closed_at: trade.closed_at ? new Date(trade.closed_at) : null,
    custom_fields: customFieldsToMap(trade.custom_fields, props.customFieldDefinitions),
  }
}

watch(
  () => props.visible,
  (val) => {
    if (val) {
      form.value = isEdit.value ? populateFromTrade(props.trade) : getDefaultForm()
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

const { sharePreviewText } = useSharePreview(form, calculatedTargets, calculatedSlPrice, calculatedBePrice)

async function copyPreview() {
  try {
    await navigator.clipboard.writeText(sharePreviewText.value)
    toast.add({ severity: 'success', summary: t('common.success'), detail: t('share.copied'), life: 2000 })
  } catch {
    // silent fail
  }
}

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

function formatDateTime(date) {
  if (!date) return null
  const d = new Date(date)
  const pad = (n) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`
}

function handleSave() {
  const data = { ...form.value }
  if (data.targets.length > 0) {
    data.targets = calculatedTargets.value
  } else {
    data.targets = null
  }
  data.opened_at = formatDateTime(data.opened_at)

  if (isEdit.value && isClosedTrade.value) {
    data.closed_at = data.closed_at ? formatDateTime(data.closed_at) : null
  } else {
    delete data.closed_at
  }

  // In edit mode the account is fixed; the parent uses the trade id, not
  // account_id, so don't ship a stale value from the form.
  if (isEdit.value) {
    delete data.account_id
  }

  // Build custom_fields array from the map
  const cfMap = data.custom_fields || {}
  data.custom_fields = Object.entries(cfMap)
    .filter(([, value]) => value !== null && value !== undefined && value !== '')
    .map(([fieldId, value]) => {
      // ToggleSwitch returns boolean, API expects "true"/"false"
      const strValue = typeof value === 'boolean' ? String(value) : String(value)
      return { field_id: parseInt(fieldId), value: strValue }
    })

  emit('save', data)
}

function handleClose() {
  emit('update:visible', false)
}

async function handleSymbolCreate(data) {
  try {
    const created = await symbolsStore.createSymbol(data)
    form.value.symbol = created.code
    showSymbolForm.value = false
    toast.add({ severity: 'success', summary: t('common.success'), detail: t('symbols.success.created'), life: 3000 })
  } catch {
    // error in store
  }
}
</script>

<template>
  <Dialog
    :visible="visible"
    :header="isEdit ? t('trades.edit') : t('trades.create')"
    :modal="true"
    :closable="true"
    :style="{ width: '600px' }"
    :contentStyle="{ overflowY: 'auto', maxHeight: '70vh' }"
    @update:visible="handleClose"
  >
    <div class="flex flex-col gap-4">
      <div v-if="!isEdit">
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ t('trades.account') }} *</label>
        <Select
          v-model="form.account_id"
          :options="accounts.map((a) => ({ label: a.name, value: a.id }))"
          optionLabel="label"
          optionValue="value"
          :placeholder="t('trades.select_account')"
          :emptyMessage="t('common.no_options')"
          class="w-full"
        />
      </div>

      <div class="grid grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ t('positions.symbol') }} *</label>
          <div class="flex gap-1">
            <Select
              v-model="form.symbol"
              :options="symbols"
              optionLabel="label"
              optionValue="value"
              :placeholder="t('positions.symbol')"
              :emptyMessage="t('common.no_options')"
              class="w-full"
            />
            <Button icon="pi pi-plus" severity="secondary" size="small" v-tooltip.top="t('symbols.add_symbol')" @click="showSymbolForm = true" />
          </div>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ t('positions.direction') }} *</label>
          <Select v-model="form.direction" :options="directionOptions" optionLabel="label" optionValue="value" class="w-full" />
        </div>
      </div>

      <div class="grid grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ t('positions.entry_price') }} *</label>
          <InputNumber v-model="form.entry_price" class="w-full" :min="0" mode="decimal" locale="en-US" :maxFractionDigits="5" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ t('positions.size') }} *</label>
          <InputNumber v-model="form.size" class="w-full" :min="0" mode="decimal" locale="en-US" :maxFractionDigits="5" />
        </div>
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ t('positions.setup') }} *</label>
        <AutoComplete
          v-model="form.setup"
          :suggestions="filteredSetups"
          multiple
          class="w-full"
          dropdown
          @complete="searchSetups"
        />
      </div>

      <div class="grid grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ t('positions.sl_points') }} *</label>
          <InputNumber v-model="form.sl_points" class="w-full" :min="0" mode="decimal" locale="en-US" :maxFractionDigits="2" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ t('positions.sl_price') }}</label>
          <div class="px-3 py-2 bg-gray-100 dark:bg-gray-700 rounded-md border border-gray-200 dark:border-gray-600 text-sm dark:text-gray-300 flex items-center min-h-[38px]">
            {{ calculatedSlPrice != null ? calculatedSlPrice.toLocaleString() : '-' }}
          </div>
        </div>
      </div>

      <div class="grid grid-cols-3 gap-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ t('positions.be_points') }}</label>
          <InputNumber v-model="form.be_points" class="w-full" :min="0" mode="decimal" locale="en-US" :maxFractionDigits="2" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ t('positions.be_price') }}</label>
          <div class="px-3 py-2 bg-gray-100 dark:bg-gray-700 rounded-md border border-gray-200 dark:border-gray-600 text-sm dark:text-gray-300 flex items-center min-h-[38px]">
            {{ calculatedBePrice != null ? calculatedBePrice.toLocaleString() : '-' }}
          </div>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ t('positions.be_size') }}</label>
          <InputNumber v-model="form.be_size" class="w-full" :min="0" mode="decimal" locale="en-US" :maxFractionDigits="5" />
        </div>
      </div>

      <div>
        <div class="flex items-center justify-between mb-2">
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">{{ t('positions.targets') }}</label>
          <Button :label="t('positions.add_target')" icon="pi pi-plus" size="small" severity="secondary" text @click="addTarget" />
        </div>
        <div v-for="(target, index) in form.targets" :key="index" class="grid grid-cols-[64px_1fr_1fr_80px_32px] gap-2 mb-2 items-center">
          <InputText v-model="target.label" class="w-full" :placeholder="t('positions.target_label')" />
          <InputNumber v-model="target.points" class="w-full" :min="0" mode="decimal" locale="en-US" :maxFractionDigits="2" :placeholder="t('positions.target_points')" />
          <InputNumber v-model="target.size" class="w-full" :min="0" mode="decimal" locale="en-US" :maxFractionDigits="5" :placeholder="t('positions.target_size')" />
          <span class="text-sm text-gray-500 dark:text-gray-400 text-right">
            {{ calculatedTargets[index]?.price != null ? calculatedTargets[index].price.toLocaleString() : '-' }}
          </span>
          <Button icon="pi pi-times" severity="danger" size="small" text @click="removeTarget(index)" />
        </div>
      </div>

      <div :class="isEdit && isClosedTrade ? 'grid grid-cols-2 gap-4' : ''">
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ t('trades.opened_at') }} *</label>
          <DatePicker v-model="form.opened_at" showTime hourFormat="24" class="w-full" />
        </div>
        <div v-if="isEdit && isClosedTrade">
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ t('trades.closed_at') }}</label>
          <DatePicker v-model="form.closed_at" showTime hourFormat="24" class="w-full" />
        </div>
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ t('positions.notes') }}</label>
        <Textarea v-model="form.notes" class="w-full" rows="3" :maxlength="10000" />
      </div>

      <!-- Custom fields -->
      <div v-if="customFieldDefinitions.length > 0">
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ t('custom_fields.title') }}</label>
        <div class="flex flex-col gap-3">
          <div v-for="def in customFieldDefinitions" :key="def.id">
            <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">{{ def.name }}</label>

            <ToggleSwitch
              v-if="def.field_type === CustomFieldType.BOOLEAN"
              v-model="form.custom_fields[def.id]"
            />

            <InputText
              v-else-if="def.field_type === CustomFieldType.TEXT"
              v-model="form.custom_fields[def.id]"
              class="w-full"
            />

            <InputNumber
              v-else-if="def.field_type === CustomFieldType.NUMBER"
              v-model="form.custom_fields[def.id]"
              class="w-full"
              mode="decimal"
              locale="en-US"
              :maxFractionDigits="5"
            />

            <Select
              v-else-if="def.field_type === CustomFieldType.SELECT"
              v-model="form.custom_fields[def.id]"
              :options="JSON.parse(def.options || '[]')"
              class="w-full"
            />
          </div>
        </div>
      </div>

      <div v-if="sharePreviewText && !isEdit" class="border border-gray-200 dark:border-gray-600 rounded-md p-3 bg-gray-50 dark:bg-gray-700">
        <div class="flex items-center justify-between mb-2">
          <label class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ t('share.preview') }}</label>
          <Button icon="pi pi-copy" :label="t('share.copy')" severity="secondary" size="small" text @click="copyPreview" />
        </div>
        <pre class="text-sm font-mono whitespace-pre-wrap text-gray-700 dark:text-gray-300" data-testid="share-preview">{{ sharePreviewText }}</pre>
      </div>
    </div>

    <template #footer>
      <Button :label="t('common.cancel')" severity="secondary" @click="handleClose" />
      <Button :label="isEdit ? t('common.save') : t('common.create')" :loading="loading" @click="handleSave" />
    </template>

    <SymbolForm
      v-model:visible="showSymbolForm"
      :loading="symbolsStore.loading"
      @save="handleSymbolCreate"
    />
  </Dialog>
</template>
