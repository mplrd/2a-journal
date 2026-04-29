<script setup>
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAccountsStore } from '@/stores/accounts'
import { useSymbolsStore } from '@/stores/symbols'
import { useSetupsStore } from '@/stores/setups'
import MultiSelect from 'primevue/multiselect'
import Button from 'primevue/button'
import BadgeFilter from '@/components/common/BadgeFilter.vue'
import DateRangePicker from '@/components/common/DateRangePicker.vue'

const { t } = useI18n()

const emit = defineEmits(['apply', 'reset'])

const accountsStore = useAccountsStore()
const symbolsStore = useSymbolsStore()
const setupsStore = useSetupsStore()

const accountIds = ref([])
const dateFrom = ref(null)
const dateTo = ref(null)
const direction = ref(null)
const selectedSymbols = ref([])
const selectedSetups = ref([])

const directionOptions = [
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

function buildFilters() {
  const filters = {}
  if (accountIds.value.length > 0) filters.account_ids = accountIds.value
  if (dateFrom.value) filters.date_from = formatDate(dateFrom.value)
  if (dateTo.value) filters.date_to = formatDate(dateTo.value)
  if (direction.value) filters.direction = direction.value
  if (selectedSymbols.value.length > 0) filters.symbols = selectedSymbols.value
  if (selectedSetups.value.length > 0) filters.setups = selectedSetups.value
  return filters
}

// Auto-submit: any change to a filter input emits apply with the new payload.
// Watching deeply on the refs covers MultiSelect updates without an Apply
// button.
let debounceTimer = null
function scheduleApply() {
  if (debounceTimer) clearTimeout(debounceTimer)
  debounceTimer = setTimeout(() => emit('apply', buildFilters()), 200)
}

watch(
  [accountIds, dateFrom, dateTo, direction, selectedSymbols, selectedSetups],
  scheduleApply,
  { deep: true },
)

function resetFilters() {
  accountIds.value = []
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
    <div class="flex items-center justify-between mb-3">
      <h3 class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ t('dashboard.filters') }}</h3>
      <Button
        :label="t('dashboard.reset_filters')"
        icon="pi pi-times"
        size="small"
        severity="secondary"
        text
        @click="resetFilters"
      />
    </div>

    <div class="flex flex-col gap-3">
      <div class="flex items-center gap-3 flex-wrap">
        <span class="text-xs font-medium text-gray-500 dark:text-gray-400 shrink-0 w-20">{{ t('dashboard.filter_account') }}</span>
        <BadgeFilter
          v-model="accountIds"
          :options="accountsStore.accounts.map((a) => ({ label: a.name, value: a.id }))"
          multi
        />
      </div>

      <div class="flex items-center gap-3 flex-wrap">
        <span class="text-xs font-medium text-gray-500 dark:text-gray-400 shrink-0 w-20">{{ t('dashboard.date_range') }}</span>
        <div class="flex-1 max-w-md">
          <DateRangePicker v-model:from="dateFrom" v-model:to="dateTo" />
        </div>
      </div>

      <div class="flex items-center gap-3 flex-wrap">
        <span class="text-xs font-medium text-gray-500 dark:text-gray-400 shrink-0 w-20">{{ t('dashboard.direction') }}</span>
        <BadgeFilter
          v-model="direction"
          :options="directionOptions"
        />
      </div>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
        <div>
          <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">{{ t('dashboard.symbols') }}</label>
          <MultiSelect
            v-model="selectedSymbols"
            :options="symbolsStore.symbolOptions"
            optionLabel="label"
            optionValue="value"
            :placeholder="t('dashboard.symbols')"
            display="chip"
            class="w-full"
          />
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">{{ t('dashboard.setups') }}</label>
          <MultiSelect
            v-model="selectedSetups"
            :options="setupsStore.setupOptions.map((s) => ({ label: s, value: s }))"
            optionLabel="label"
            optionValue="value"
            :placeholder="t('dashboard.setups')"
            display="chip"
            class="w-full"
          />
        </div>
      </div>
    </div>
  </div>
</template>
