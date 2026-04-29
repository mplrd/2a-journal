<script setup>
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAccountsStore } from '@/stores/accounts'
import { useSymbolsStore } from '@/stores/symbols'
import { useSetupsStore } from '@/stores/setups'
import Select from 'primevue/select'
import MultiSelect from 'primevue/multiselect'
import Button from 'primevue/button'
import DateRangePicker from '@/components/common/DateRangePicker.vue'

const { t } = useI18n()

const emit = defineEmits(['apply', 'reset'])

const accountsStore = useAccountsStore()
const symbolsStore = useSymbolsStore()
const setupsStore = useSetupsStore()

const accountId = ref(null)
const dateFrom = ref(null)
const dateTo = ref(null)
const direction = ref(null)
const selectedSymbols = ref([])
const selectedSetups = ref([])

const directionOptions = [
  { label: t('dashboard.all_directions'), value: null },
  { label: 'BUY', value: 'BUY' },
  { label: 'SELL', value: 'SELL' },
]

function formatDate(date) {
  if (!date) return null
  const d = new Date(date)
  const y = d.getFullYear()
  const m = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')
  return `${y}-${m}-${day}`
}

function applyFilters() {
  const filters = {}
  if (accountId.value) filters.account_id = accountId.value
  if (dateFrom.value) filters.date_from = formatDate(dateFrom.value)
  if (dateTo.value) filters.date_to = formatDate(dateTo.value)
  if (direction.value) filters.direction = direction.value
  if (selectedSymbols.value.length > 0) filters.symbols = selectedSymbols.value
  if (selectedSetups.value.length > 0) filters.setups = selectedSetups.value
  emit('apply', filters)
}

function resetFilters() {
  accountId.value = null
  dateFrom.value = null
  dateTo.value = null
  direction.value = null
  selectedSymbols.value = []
  selectedSetups.value = []
  emit('reset')
}
</script>

<template>
  <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 p-4 mb-6">
    <h3 class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-3">{{ t('dashboard.filters') }}</h3>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-3 items-end">
      <div>
        <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">{{ t('dashboard.filter_account') }}</label>
        <Select
          v-model="accountId"
          :options="[
            { label: t('dashboard.all_accounts'), value: null },
            ...accountsStore.accounts.map((a) => ({ label: a.name, value: a.id })),
          ]"
          optionLabel="label"
          optionValue="value"
          class="w-full"
        />
      </div>
      <div class="sm:col-span-2">
        <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">{{ t('dashboard.date_range') }}</label>
        <DateRangePicker v-model:from="dateFrom" v-model:to="dateTo" />
      </div>
      <div>
        <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">{{ t('dashboard.direction') }}</label>
        <Select
          v-model="direction"
          :options="directionOptions"
          optionLabel="label"
          optionValue="value"
          class="w-full"
        />
      </div>
      <div>
        <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">{{ t('dashboard.symbols') }}</label>
        <MultiSelect
          v-model="selectedSymbols"
          :options="symbolsStore.symbolOptions"
          optionLabel="label"
          optionValue="value"
          :placeholder="t('dashboard.symbols')"
          class="w-full"
        />
      </div>
      <div>
        <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">{{ t('dashboard.setups') }}</label>
        <MultiSelect
          v-model="selectedSetups"
          :options="setupsStore.setupOptions.map((s) => ({ label: s, value: s }))"
          optionLabel="label"
          optionValue="value"
          :placeholder="t('dashboard.setups')"
          class="w-full"
        />
      </div>
    </div>
    <div class="flex gap-2 mt-3">
      <Button :label="t('dashboard.apply_filters')" icon="pi pi-check" size="small" @click="applyFilters" />
      <Button
        :label="t('dashboard.reset_filters')"
        icon="pi pi-times"
        size="small"
        severity="secondary"
        @click="resetFilters"
      />
    </div>
  </div>
</template>
