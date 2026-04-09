<script setup>
import { ref, watch, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { importsService } from '@/services/imports'
import { useCustomFieldsStore } from '@/stores/customFields'
import Dialog from 'primevue/dialog'
import Select from 'primevue/select'
import Button from 'primevue/button'
import Stepper from 'primevue/stepper'
import StepList from 'primevue/steplist'
import StepPanels from 'primevue/steppanels'
import Step from 'primevue/step'
import StepPanel from 'primevue/steppanel'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import InputText from 'primevue/inputtext'
import Message from 'primevue/message'

const { t } = useI18n()
const customFieldsStore = useCustomFieldsStore()

const props = defineProps({
  visible: { type: Boolean, default: false },
  account: { type: Object, default: null },
})
const emit = defineEmits(['update:visible'])

// Broker templates
const templates = ref([])
const selectedBroker = ref(null)
const file = ref(null)
const fileName = ref('')
const loading = ref(false)
const error = ref(null)

// Custom mapping state
const fileHeaders = ref([])
const customMapping = ref({
  symbol: '', direction: '', closed_at: '', entry_price: '',
  exit_price: '', size: '', pnl: '', pips: '', comment: '',
})
const customOptions = ref({
  date_format: 'd/m/Y H:i:s',
  direction_buy: 'Buy',
  direction_sell: 'Sell',
})
const showMapping = ref(false)

// Custom fields mapping: field_id → file column header
const customFieldsMapping = ref({})

// Preview state
const preview = ref(null)
const symbolMapping = ref({})

// Result state
const result = ref(null)

const requiredFields = ['symbol', 'direction', 'closed_at', 'entry_price', 'exit_price', 'size', 'pnl']
const optionalFields = ['pips', 'comment']

const isCustom = computed(() => selectedBroker.value === 'custom')

const mappedFieldCount = computed(() => {
  return [...requiredFields, ...optionalFields].filter(f => customMapping.value[f]).length
})

const acceptedFileTypes = computed(() => {
  const tpl = templates.value.find(t => t.broker === selectedBroker.value)
  const types = tpl?.file_types || ['xlsx', 'csv']
  return types.map(t => '.' + t).join(',')
})

const brokerOptions = computed(() => {
  // Show generic template first, then broker-specific, then custom
  const generic = templates.value.filter(t => t.broker === 'generic')
  const brokers = templates.value.filter(t => t.broker !== 'generic')
  const opts = [
    ...generic.map(t => ({ label: t.label, value: t.broker })),
    ...brokers.map(t => ({ label: t.label, value: t.broker })),
  ]
  opts.push({ label: t('import.custom_mapping'), value: 'custom' })
  return opts
})

const headerOptions = computed(() => {
  return [
    { label: '-', value: '' },
    ...fileHeaders.value.map((h) => ({ label: h, value: h })),
  ]
})

const dateFormatOptions = [
  { label: 'dd/mm/yyyy HH:mm:ss', value: 'd/m/Y H:i:s' },
  { label: 'yyyy-mm-dd HH:mm:ss', value: 'Y-m-d H:i:s' },
  { label: 'mm/dd/yyyy HH:mm:ss', value: 'm/d/Y H:i:s' },
]

// Reset when dialog opens/closes
watch(() => props.visible, (val) => {
  if (val) {
    loadTemplates()
    resetForm()
  }
})

async function loadTemplates() {
  try {
    const resp = await importsService.getTemplates()
    templates.value = resp.data

    // Auto-select broker if account broker matches a template
    if (props.account?.broker) {
      const match = resp.data.find(t => t.broker.toLowerCase() === props.account.broker.toLowerCase())
      if (match) {
        selectedBroker.value = match.broker
      }
    }
  } catch {
    // silent
  }
  customFieldsStore.fetchDefinitions()
}

const activeCustomFields = computed(() => customFieldsStore.activeDefinitions)

function onFileSelect(event) {
  const selected = event.target.files[0]
  if (selected) {
    file.value = selected
    fileName.value = selected.name
    showMapping.value = false
    fileHeaders.value = []
    // Auto-fetch headers in custom mode
    if (isCustom.value) {
      fetchHeaders()
    }
  }
}

// Common column name patterns for auto-mapping (lowercase)
const autoMapPatterns = {
  symbol: ['symbol', 'symbole', 'instrument', 'asset', 'actif', 'ticker', 'pair', 'paire'],
  direction: ['direction', 'side', 'sens', 'type', 'action', 'trade type'],
  closed_at: ['close date', 'close time', 'closing time', 'closed at', 'date de clôture', 'exit date', 'exit time'],
  entry_price: ['entry price', 'open price', 'entry', 'prix d\'entrée', 'cours d\'entrée'],
  exit_price: ['exit price', 'close price', 'closing price', 'exit', 'prix de sortie', 'prix de clôture'],
  size: ['size', 'quantity', 'volume', 'lots', 'qty', 'taille', 'quantité'],
  pnl: ['pnl', 'p&l', 'profit', 'profit/loss', 'net profit', 'résultat', 'gain'],
  pips: ['pips', 'points'],
  comment: ['comment', 'comments', 'notes', 'note', 'commentaire'],
}

function autoMapColumns(headers) {
  const lowerHeaders = headers.map(h => h.toLowerCase().trim())
  for (const [field, patterns] of Object.entries(autoMapPatterns)) {
    if (customMapping.value[field]) continue // don't override existing
    for (const pattern of patterns) {
      const idx = lowerHeaders.findIndex(h => h === pattern)
      if (idx !== -1) {
        customMapping.value[field] = headers[idx]
        break
      }
    }
    // Partial match fallback (header contains pattern)
    if (!customMapping.value[field]) {
      for (const pattern of patterns) {
        const idx = lowerHeaders.findIndex(h => h.includes(pattern))
        if (idx !== -1) {
          customMapping.value[field] = headers[idx]
          break
        }
      }
    }
  }
}

async function fetchHeaders() {
  if (!file.value) return
  loading.value = true
  error.value = null
  try {
    const resp = await importsService.getHeaders(file.value)
    fileHeaders.value = resp.data.headers
    showMapping.value = true
    autoMapColumns(resp.data.headers)
  } catch (err) {
    error.value = err.messageKey || 'import.error.parse_failed'
  } finally {
    loading.value = false
  }
}

function getActiveCfMapping() {
  // Filter out empty mappings
  const cfMap = {}
  for (const [fieldId, headerName] of Object.entries(customFieldsMapping.value)) {
    if (headerName) cfMap[fieldId] = headerName
  }
  return Object.keys(cfMap).length > 0 ? cfMap : null
}

async function doPreview(nextCallback) {
  if (!file.value || !selectedBroker.value) return
  loading.value = true
  error.value = null
  try {
    const colMap = isCustom.value ? customMapping.value : null
    const opts = isCustom.value ? customOptions.value : null
    const cfMap = isCustom.value ? getActiveCfMapping() : null
    const resp = await importsService.preview(file.value, selectedBroker.value, colMap, opts, cfMap)
    preview.value = resp.data

    const mapping = {}
    for (const s of resp.data.unknown_symbols) {
      mapping[s] = s
    }
    symbolMapping.value = mapping

    nextCallback()
  } catch (err) {
    error.value = err.messageKey || err.message || 'import.error.parse_failed'
  } finally {
    loading.value = false
  }
}

async function doConfirm(nextCallback) {
  if (!props.account) return
  loading.value = true
  error.value = null
  try {
    const colMap = isCustom.value ? customMapping.value : null
    const opts = isCustom.value ? customOptions.value : null
    const cfMap = isCustom.value ? getActiveCfMapping() : null
    const resp = await importsService.confirm(
      file.value,
      selectedBroker.value,
      props.account.id,
      symbolMapping.value,
      colMap,
      opts,
      cfMap,
    )
    result.value = resp.data
    nextCallback()
  } catch (err) {
    error.value = err.messageKey || err.message || 'import.error.confirm_failed'
  } finally {
    loading.value = false
  }
}

function resetForm() {
  file.value = null
  fileName.value = ''
  selectedBroker.value = null
  preview.value = null
  result.value = null
  error.value = null
  symbolMapping.value = {}
  fileHeaders.value = []
  showMapping.value = false
  customMapping.value = {
    symbol: '', direction: '', closed_at: '', entry_price: '',
    exit_price: '', size: '', pnl: '', pips: '', comment: '',
  }
  customFieldsMapping.value = {}
}

function close() {
  emit('update:visible', false)
}
</script>

<template>
  <Dialog
    :visible="visible"
    @update:visible="emit('update:visible', $event)"
    :header="t('import.title') + (account ? ` — ${account.name}` : '')"
    modal
    :style="{ width: '800px' }"
    :closable="true"
  >
    <Stepper linear value="1">
      <StepList>
        <Step value="1">{{ t('import.step_upload') }}</Step>
        <Step value="2">{{ t('import.step_preview') }}</Step>
        <Step value="3">{{ t('import.step_result') }}</Step>
      </StepList>

      <StepPanels>
        <!-- Step 1: Upload + optional custom mapping -->
        <StepPanel v-slot="{ activateCallback }" value="1">
          <div class="space-y-4 py-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ t('import.select_broker') }}</label>
              <Select
                v-model="selectedBroker"
                :options="brokerOptions"
                optionLabel="label"
                optionValue="value"
                :placeholder="t('import.select_broker')"
                class="w-full md:w-80"
              />
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ t('import.select_file') }}</label>
              <input
                type="file"
                :accept="acceptedFileTypes"
                class="block w-full md:w-80 text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 dark:file:bg-gray-700 dark:file:text-gray-200 cursor-pointer"
                @change="onFileSelect"
              />
            </div>

            <!-- Custom mapping: auto-fetch headers then show mapping -->
            <template v-if="isCustom && file">
              <div v-if="loading && !showMapping" class="flex items-center gap-2 text-sm text-gray-500">
                <i class="pi pi-spin pi-spinner"></i>
                {{ t('import.detecting_columns') }}
              </div>

              <template v-if="showMapping">
                <!-- Auto-mapped indicator -->
                <Message v-if="mappedFieldCount > 0" severity="info" :closable="false" class="text-sm">
                  {{ t('import.auto_mapped', { count: mappedFieldCount }) }}
                </Message>

                <!-- Required fields -->
                <fieldset class="border border-gray-200 dark:border-gray-600 rounded-lg p-3">
                  <legend class="text-sm font-medium text-gray-700 dark:text-gray-300 px-2">{{ t('import.required_fields') }}</legend>
                  <div class="grid grid-cols-2 gap-3">
                    <div v-for="field in requiredFields" :key="field">
                      <label class="block text-xs text-gray-500 mb-1">
                        {{ t(`import.field_${field}`) }}
                        <span class="text-red-500">*</span>
                      </label>
                      <Select
                        v-model="customMapping[field]"
                        :options="headerOptions"
                        optionLabel="label"
                        optionValue="value"
                        class="w-full"
                        size="small"
                      />
                    </div>
                  </div>
                </fieldset>

                <!-- Options (date format, direction values) -->
                <fieldset class="border border-gray-200 dark:border-gray-600 rounded-lg p-3">
                  <legend class="text-sm font-medium text-gray-700 dark:text-gray-300 px-2">{{ t('import.options_section') }}</legend>
                  <div class="grid grid-cols-3 gap-3">
                    <div>
                      <label class="block text-xs text-gray-500 mb-1">{{ t('import.date_format') }}</label>
                      <Select
                        v-model="customOptions.date_format"
                        :options="dateFormatOptions"
                        optionLabel="label"
                        optionValue="value"
                        class="w-full"
                        size="small"
                      />
                    </div>
                    <div>
                      <label class="block text-xs text-gray-500 mb-1">{{ t('import.direction_buy_label') }}</label>
                      <InputText v-model="customOptions.direction_buy" class="w-full" size="small" />
                    </div>
                    <div>
                      <label class="block text-xs text-gray-500 mb-1">{{ t('import.direction_sell_label') }}</label>
                      <InputText v-model="customOptions.direction_sell" class="w-full" size="small" />
                    </div>
                  </div>
                </fieldset>

                <!-- Optional fields -->
                <fieldset class="border border-gray-200 dark:border-gray-600 rounded-lg p-3">
                  <legend class="text-sm font-medium text-gray-700 dark:text-gray-300 px-2">{{ t('import.optional_fields') }}</legend>
                  <div class="grid grid-cols-2 gap-3">
                    <div v-for="field in optionalFields" :key="field">
                      <label class="block text-xs text-gray-500 mb-1">{{ t(`import.field_${field}`) }}</label>
                      <Select
                        v-model="customMapping[field]"
                        :options="headerOptions"
                        optionLabel="label"
                        optionValue="value"
                        class="w-full"
                        size="small"
                      />
                    </div>
                  </div>
                </fieldset>

                <!-- Custom fields mapping -->
                <fieldset v-if="activeCustomFields.length > 0" class="border border-gray-200 dark:border-gray-600 rounded-lg p-3">
                  <legend class="text-sm font-medium text-gray-700 dark:text-gray-300 px-2">{{ t('import.custom_fields_section') }}</legend>
                  <p class="text-xs text-gray-500 mb-2">{{ t('import.custom_fields_hint') }}</p>
                  <div class="grid grid-cols-2 gap-3">
                    <div v-for="field in activeCustomFields" :key="field.id">
                      <label class="block text-xs text-gray-500 mb-1">
                        {{ field.name }}
                        <span class="text-gray-400">({{ t(`custom_fields.types.${field.field_type}`) }})</span>
                      </label>
                      <Select
                        v-model="customFieldsMapping[field.id]"
                        :options="headerOptions"
                        optionLabel="label"
                        optionValue="value"
                        class="w-full"
                        size="small"
                      />
                    </div>
                  </div>
                </fieldset>
              </template>
            </template>

            <Message v-if="error" severity="error" :closable="false">{{ t(error) }}</Message>

            <div class="pt-2">
              <Button
                :label="t('import.analyze')"
                icon="pi pi-search"
                :loading="loading"
                :disabled="!file || !selectedBroker"
                @click="doPreview(activateCallback.bind(null, '2'))"
              />
            </div>
          </div>
        </StepPanel>

        <!-- Step 2: Preview -->
        <StepPanel v-slot="{ activateCallback }" value="2">
          <div v-if="preview" class="space-y-4 py-4">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
              <div class="bg-gray-50 dark:bg-gray-700 rounded-lg p-3 text-center">
                <p class="text-2xl font-bold text-gray-800 dark:text-gray-100">{{ preview.total_rows }}</p>
                <p class="text-xs text-gray-500">{{ t('import.total_rows') }}</p>
              </div>
              <div class="bg-gray-50 dark:bg-gray-700 rounded-lg p-3 text-center">
                <p class="text-2xl font-bold text-blue-600">{{ preview.total_positions }}</p>
                <p class="text-xs text-gray-500">{{ t('import.total_positions') }}</p>
              </div>
              <div class="bg-gray-50 dark:bg-gray-700 rounded-lg p-3 text-center">
                <p class="text-2xl font-bold text-gray-800 dark:text-gray-100">{{ preview.currency || '-' }}</p>
                <p class="text-xs text-gray-500">{{ t('import.currency') }}</p>
              </div>
              <div class="bg-gray-50 dark:bg-gray-700 rounded-lg p-3 text-center">
                <p class="text-2xl font-bold text-amber-600">{{ preview.unknown_symbols.length }}</p>
                <p class="text-xs text-gray-500">{{ t('import.symbols_to_map') }}</p>
              </div>
            </div>

            <div v-if="preview.unknown_symbols.length > 0">
              <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ t('import.symbol_mapping') }}</h3>
              <div class="space-y-2">
                <div v-for="brokerSym in preview.unknown_symbols" :key="brokerSym" class="flex items-center gap-3">
                  <span class="text-sm text-gray-500 w-32 shrink-0">{{ brokerSym }}</span>
                  <i class="pi pi-arrow-right text-gray-400"></i>
                  <InputText v-model="symbolMapping[brokerSym]" class="w-40" size="small" />
                </div>
              </div>
            </div>

            <DataTable :value="preview.positions" size="small" stripedRows scrollable scrollHeight="300px">
              <Column field="symbol" :header="t('import.col_symbol')" />
              <Column field="direction" :header="t('import.col_direction')" />
              <Column :header="t('import.col_entry_price')">
                <template #body="{ data }">{{ Number(data.entry_price).toFixed(2) }}</template>
              </Column>
              <Column :header="t('import.col_avg_exit')">
                <template #body="{ data }">{{ Number(data.avg_exit_price).toFixed(2) }}</template>
              </Column>
              <Column :header="t('import.col_size')">
                <template #body="{ data }">{{ data.total_size }}</template>
              </Column>
              <Column :header="t('import.col_pnl')">
                <template #body="{ data }">
                  <span :class="data.total_pnl >= 0 ? 'text-green-600' : 'text-red-600'">
                    {{ Number(data.total_pnl).toFixed(2) }}
                  </span>
                </template>
              </Column>
              <Column :header="t('import.col_exits')">
                <template #body="{ data }">{{ data.exits.length }}</template>
              </Column>
              <Column field="closed_at" :header="t('import.col_closed_at')" />
            </DataTable>

            <Message v-if="error" severity="error" :closable="false">{{ t(error) }}</Message>

            <div class="flex gap-2 pt-2">
              <Button :label="t('common.back')" severity="secondary" @click="activateCallback('1')" />
              <Button :label="t('import.confirm_import')" icon="pi pi-check" :loading="loading" @click="doConfirm(activateCallback.bind(null, '3'))" />
            </div>
          </div>
        </StepPanel>

        <!-- Step 3: Result -->
        <StepPanel value="3">
          <div v-if="result" class="space-y-4 py-4">
            <Message severity="success" :closable="false">{{ t('import.success_message') }}</Message>

            <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
              <div class="bg-green-50 dark:bg-green-900/20 rounded-lg p-3 text-center">
                <p class="text-2xl font-bold text-green-600">{{ result.imported_positions }}</p>
                <p class="text-xs text-gray-500">{{ t('import.positions_imported') }}</p>
              </div>
              <div class="bg-yellow-50 dark:bg-yellow-900/20 rounded-lg p-3 text-center">
                <p class="text-2xl font-bold text-yellow-600">{{ result.skipped_duplicates }}</p>
                <p class="text-xs text-gray-500">{{ t('import.duplicates') }}</p>
              </div>
              <div v-if="result.skipped_errors > 0" class="bg-red-50 dark:bg-red-900/20 rounded-lg p-3 text-center">
                <p class="text-2xl font-bold text-red-600">{{ result.skipped_errors }}</p>
                <p class="text-xs text-gray-500">{{ t('import.errors') }}</p>
              </div>
            </div>

            <div class="pt-2">
              <Button :label="t('common.close')" @click="close" />
            </div>
          </div>
        </StepPanel>
      </StepPanels>
    </Stepper>
  </Dialog>
</template>
