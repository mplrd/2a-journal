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
import PricePointsInput from '@/components/common/PricePointsInput.vue'
import { useSharePreview } from '@/composables/useSharePreview'
import { useNumberLocale } from '@/composables/useNumberLocale'

const { t } = useI18n()
const { numberLocale } = useNumberLocale()
const symbolsStore = useSymbolsStore()
const toast = useToast()

const props = defineProps({
  visible: Boolean,
  accounts: { type: Array, default: () => [] },
  symbols: { type: Array, default: () => [] },
  setups: { type: Array, default: () => [] },
  plans: { type: Array, default: () => [] },
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

// Optional plan tag: "no plan" + the user's active plans (docs/83). Empty when
// the plans feature is off / the user has none, so the field stays hidden.
const planOptions = computed(() => [
  { label: t('trades.no_plan'), value: null },
  ...props.plans.map((p) => ({ label: p.name, value: p.id })),
])

function getDefaultForm() {
  return {
    account_id: null,
    entry_price: 0,
    size: 1,
    sl_points: 0,
    sl_price: null,
    be_points: null,
    be_price: null,
    be_size: null,
    direction: Direction.BUY,
    symbol: '',
    setup: [],
    plan_id: null,
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
  // Seed the price companions from the stored points so the paired inputs show
  // a coherent price on open (the component keeps them in sync afterwards).
  const entry = Number(trade.entry_price)
  const isBuy = trade.direction === Direction.BUY
  const slPts = Number(trade.sl_points)
  const bePts = trade.be_points != null ? Number(trade.be_points) : null
  const hydratedTargets = (targets || []).map((tp) => {
    const pts = tp.points != null ? Number(tp.points) : null
    return {
      ...tp,
      price: pts != null ? (isBuy ? entry + pts : entry - pts) : (tp.price ?? null),
    }
  })
  return {
    account_id: trade.account_id ?? null,
    entry_price: entry,
    size: Number(trade.size),
    sl_points: slPts,
    sl_price: slPts ? (isBuy ? entry - slPts : entry + slPts) : null,
    be_points: bePts,
    be_price: bePts != null ? (isBuy ? entry + bePts : entry - bePts) : null,
    be_size: trade.be_size != null ? Number(trade.be_size) : null,
    direction: trade.direction,
    symbol: trade.symbol,
    setup: parseSetup(trade.setup),
    plan_id: trade.plan_id ?? null,
    notes: trade.notes || '',
    targets: hydratedTargets,
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
    points: null,
    price: null,
    size: null,
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
  // sl_price / be_price are UX companions of the points fields; the backend
  // reads points only.
  delete data.sl_price
  delete data.be_price
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
          <InputNumber v-model="form.entry_price" class="w-full" :min="0" mode="decimal" :locale="numberLocale" :maxFractionDigits="5" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ t('positions.size') }} *</label>
          <InputNumber v-model="form.size" class="w-full" :min="0" mode="decimal" :locale="numberLocale" :maxFractionDigits="5" />
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

      <div v-if="plans.length">
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ t('trades.plan') }}</label>
        <Select
          v-model="form.plan_id"
          :options="planOptions"
          option-label="label"
          option-value="value"
          class="w-full"
          data-testid="trade-plan-select"
        />
        <p class="text-xs text-gray-400 mt-1">{{ t('trades.plan_hint') }}</p>
      </div>

      <PricePointsInput
        v-model:points="form.sl_points"
        v-model:price="form.sl_price"
        :entry-price="form.entry_price"
        :direction="form.direction"
        mode="SL"
        points-name="sl_points"
        price-name="sl_price"
        :points-label="`${t('positions.sl_points')} *`"
        :price-label="t('positions.sl_price')"
      />

      <div class="grid grid-cols-3 gap-4">
        <PricePointsInput
          class="col-span-2"
          v-model:points="form.be_points"
          v-model:price="form.be_price"
          :entry-price="form.entry_price"
          :direction="form.direction"
          mode="TP"
          points-name="be_points"
          price-name="be_price"
          :points-label="t('positions.be_points')"
          :price-label="t('positions.be_price')"
        />
        <div>
          <label class="flex items-center gap-1 text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
            {{ t('positions.be_size') }}
            <i class="pi pi-info-circle text-gray-400 cursor-help" v-tooltip.top="t('positions.be_size_hint')" />
          </label>
          <InputNumber v-model="form.be_size" class="w-full" :min="0" mode="decimal" :locale="numberLocale" :maxFractionDigits="5" />
        </div>
      </div>

      <div>
        <div class="flex items-center justify-between mb-2">
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">{{ t('positions.targets') }}</label>
          <Button :label="t('positions.add_target')" icon="pi pi-plus" size="small" severity="secondary" text @click="addTarget" />
        </div>
        <div v-for="(target, index) in form.targets" :key="index" class="grid grid-cols-[64px_2fr_1fr_32px] gap-2 mb-2 items-center">
          <InputText v-model="target.label" class="w-full" :placeholder="t('positions.target_label')" />
          <PricePointsInput
            v-model:points="target.points"
            v-model:price="target.price"
            :entry-price="form.entry_price"
            :direction="form.direction"
            mode="TP"
            :points-name="`tp_points_${index}`"
            :price-name="`tp_price_${index}`"
            :points-placeholder="t('positions.target_points')"
            :price-placeholder="t('positions.target_price')"
          />
          <InputNumber v-model="target.size" class="w-full" :min="0" mode="decimal" :locale="numberLocale" :maxFractionDigits="5" :placeholder="t('positions.target_size')" />
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
              :locale="numberLocale"
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
