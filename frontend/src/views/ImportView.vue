<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAccountsStore } from '@/stores/accounts'
import { importsService } from '@/services/imports'
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
const accountsStore = useAccountsStore()

// State
const templates = ref([])
const selectedBroker = ref(null)
const selectedAccountId = ref(null)
const file = ref(null)
const fileName = ref('')
const loading = ref(false)
const error = ref(null)

// Preview state
const preview = ref(null)
const symbolMapping = ref({})

// Result state
const result = ref(null)

// History
const batches = ref([])
const showHistory = ref(false)

// Auto-select broker when account changes (if account broker matches a template)
watch(selectedAccountId, (accountId) => {
  if (!accountId) return
  const account = accountsStore.accounts.find(a => a.id === accountId)
  if (!account?.broker) return
  const match = templates.value.find(t => t.broker.toLowerCase() === account.broker.toLowerCase())
  if (match) {
    selectedBroker.value = match.broker
  }
})

// Dynamic file accept based on selected broker template
const acceptedFileTypes = computed(() => {
  const tpl = templates.value.find(t => t.broker === selectedBroker.value)
  const types = tpl?.file_types || ['xlsx', 'csv']
  return types.map(t => '.' + t).join(',')
})

onMounted(async () => {
  await accountsStore.fetchAccounts()
  try {
    const resp = await importsService.getTemplates()
    templates.value = resp.data
  } catch {
    // silent
  }
  loadBatches()
})

async function loadBatches() {
  try {
    const resp = await importsService.getBatches()
    batches.value = resp.data
  } catch {
    // silent
  }
}

function onFileSelect(event) {
  const selected = event.target.files[0]
  if (selected) {
    file.value = selected
    fileName.value = selected.name
  }
}

async function doPreview(nextCallback) {
  if (!file.value || !selectedBroker.value) return
  loading.value = true
  error.value = null
  try {
    const resp = await importsService.preview(file.value, selectedBroker.value)
    preview.value = resp.data

    // Init symbol mapping with identity for all unknown symbols
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
  if (!selectedAccountId.value) return
  loading.value = true
  error.value = null
  try {
    const resp = await importsService.confirm(
      file.value,
      selectedBroker.value,
      selectedAccountId.value,
      symbolMapping.value,
    )
    result.value = resp.data
    loadBatches()
    nextCallback()
  } catch (err) {
    error.value = err.messageKey || err.message || 'import.error.confirm_failed'
  } finally {
    loading.value = false
  }
}

async function doRollback(batchId) {
  try {
    await importsService.rollback(batchId)
    loadBatches()
  } catch {
    // silent
  }
}

function resetForm() {
  file.value = null
  fileName.value = ''
  preview.value = null
  result.value = null
  error.value = null
  symbolMapping.value = {}
}

function formatDate(dateStr) {
  if (!dateStr) return '-'
  return new Date(dateStr).toLocaleDateString(undefined, { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <h1 class="text-2xl font-bold dark:text-gray-100">{{ t('import.title') }}</h1>
      <Button
        :label="t('import.history')"
        icon="pi pi-history"
        severity="secondary"
        size="small"
        @click="showHistory = !showHistory"
      />
    </div>

    <!-- Import History -->
    <div v-if="showHistory && batches.length > 0" class="mb-6 bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 p-4">
      <h3 class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-3">{{ t('import.history') }}</h3>
      <DataTable :value="batches" size="small" stripedRows>
        <Column field="original_filename" :header="t('import.filename')" />
        <Column field="broker_template" :header="t('import.broker')" />
        <Column field="account_name" :header="t('import.account')" />
        <Column :header="t('import.result')">
          <template #body="{ data }">
            <span class="text-green-600">{{ data.imported_positions }} {{ t('import.positions_imported') }}</span>
            <span v-if="data.skipped_duplicates > 0" class="text-gray-400 ml-2">({{ data.skipped_duplicates }} {{ t('import.duplicates') }})</span>
          </template>
        </Column>
        <Column field="status" :header="t('import.status')">
          <template #body="{ data }">
            <span :class="{
              'text-green-600': data.status === 'COMPLETED',
              'text-yellow-600': data.status === 'PENDING' || data.status === 'PROCESSING',
              'text-red-600': data.status === 'FAILED',
              'text-gray-400': data.status === 'ROLLED_BACK',
            }">{{ data.status }}</span>
          </template>
        </Column>
        <Column :header="t('import.date')">
          <template #body="{ data }">{{ formatDate(data.created_at) }}</template>
        </Column>
        <Column>
          <template #body="{ data }">
            <Button
              v-if="data.status === 'COMPLETED'"
              icon="pi pi-undo"
              severity="danger"
              size="small"
              text
              v-tooltip.top="t('import.rollback')"
              @click="doRollback(data.id)"
            />
          </template>
        </Column>
      </DataTable>
    </div>

    <!-- Import Stepper -->
    <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 p-6">
      <Stepper linear value="1">
        <StepList>
          <Step value="1">{{ t('import.step_upload') }}</Step>
          <Step value="2">{{ t('import.step_preview') }}</Step>
          <Step value="3">{{ t('import.step_result') }}</Step>
        </StepList>

        <StepPanels>
          <!-- Step 1: Upload -->
          <StepPanel v-slot="{ activateCallback }" value="1">
            <div class="space-y-4 py-4">
              <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ t('import.select_broker') }}</label>
                <Select
                  v-model="selectedBroker"
                  :options="templates"
                  optionLabel="label"
                  optionValue="broker"
                  :placeholder="t('import.select_broker')"
                  class="w-full md:w-80"
                />
              </div>

              <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ t('import.select_account') }}</label>
                <Select
                  v-model="selectedAccountId"
                  :options="accountsStore.accounts"
                  optionLabel="name"
                  optionValue="id"
                  :placeholder="t('import.select_account')"
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
                <p v-if="fileName" class="text-xs text-gray-400 mt-1">{{ fileName }}</p>
              </div>

              <Message v-if="error" severity="error" :closable="false" class="mt-2">{{ t(error) }}</Message>

              <div class="pt-2">
                <Button
                  :label="t('import.analyze')"
                  icon="pi pi-search"
                  :loading="loading"
                  :disabled="!file || !selectedBroker || !selectedAccountId"
                  @click="doPreview(activateCallback.bind(null, '2'))"
                />
              </div>
            </div>
          </StepPanel>

          <!-- Step 2: Preview -->
          <StepPanel v-slot="{ activateCallback }" value="2">
            <div v-if="preview" class="space-y-4 py-4">
              <!-- Summary -->
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

              <!-- Symbol mapping -->
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

              <!-- Positions preview table -->
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
                <Button
                  :label="t('common.back')"
                  severity="secondary"
                  @click="activateCallback('1')"
                />
                <Button
                  :label="t('import.confirm_import')"
                  icon="pi pi-check"
                  :loading="loading"
                  @click="doConfirm(activateCallback.bind(null, '3'))"
                />
              </div>
            </div>
          </StepPanel>

          <!-- Step 3: Result -->
          <StepPanel value="3">
            <div v-if="result" class="space-y-4 py-4">
              <Message severity="success" :closable="false">
                {{ t('import.success_message') }}
              </Message>

              <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div class="bg-green-50 dark:bg-green-900/20 rounded-lg p-3 text-center">
                  <p class="text-2xl font-bold text-green-600">{{ result.imported_positions }}</p>
                  <p class="text-xs text-gray-500">{{ t('import.positions_imported') }}</p>
                </div>
                <div class="bg-blue-50 dark:bg-blue-900/20 rounded-lg p-3 text-center">
                  <p class="text-2xl font-bold text-blue-600">{{ result.imported_trades }}</p>
                  <p class="text-xs text-gray-500">{{ t('import.trades_imported') }}</p>
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
                <Button :label="t('import.new_import')" icon="pi pi-plus" @click="resetForm" />
              </div>
            </div>
          </StepPanel>
        </StepPanels>
      </Stepper>
    </div>
  </div>
</template>
