<script setup>
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAccountsStore } from '@/stores/accounts'
import { useSymbolsStore } from '@/stores/symbols'
import { useSetupsStore } from '@/stores/setups'
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

// Collapsible state, persisted across navigations within the session
const expanded = ref(localStorage.getItem('dashboardFiltersExpanded') !== 'false')
function toggleExpanded() {
  expanded.value = !expanded.value
  localStorage.setItem('dashboardFiltersExpanded', String(expanded.value))
}

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
    <div class="flex items-center justify-between" :class="expanded ? 'mb-3' : ''">
      <button
        type="button"
        class="flex items-center gap-2 text-sm font-medium text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 cursor-pointer"
        @click="toggleExpanded"
      >
        <i class="pi text-xs" :class="expanded ? 'pi-chevron-down' : 'pi-chevron-right'"></i>
        {{ t('dashboard.filters') }}
      </button>
      <Button
        v-if="expanded"
        :label="t('dashboard.reset_filters')"
        icon="pi pi-times"
        size="small"
        severity="secondary"
        text
        @click="resetFilters"
      />
    </div>

    <div v-if="expanded" class="flex flex-col gap-3">
      <!-- Row 1: account + period -->
      <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
        <div class="flex items-center gap-3 flex-wrap">
          <span class="text-xs font-medium text-gray-500 dark:text-gray-400 shrink-0 w-20">{{ t('dashboard.filter_account') }}</span>
          <div class="flex-1 min-w-0">
            <BadgeFilter
              v-model="accountIds"
              :options="accountsStore.accounts.map((a) => ({ label: a.name, value: a.id }))"
              multi
            />
          </div>
        </div>

        <div class="flex items-center gap-3 flex-wrap">
          <span class="text-xs font-medium text-gray-500 dark:text-gray-400 shrink-0 w-20">{{ t('dashboard.date_range') }}</span>
          <div class="flex-1 min-w-0 max-w-md">
            <DateRangePicker v-model:from="dateFrom" v-model:to="dateTo" />
          </div>
        </div>
      </div>

      <!-- Row 2: direction + symbols -->
      <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
        <div class="flex items-center gap-3 flex-wrap">
          <span class="text-xs font-medium text-gray-500 dark:text-gray-400 shrink-0 w-20">{{ t('dashboard.direction') }}</span>
          <BadgeFilter
            v-model="direction"
            :options="directionOptions"
          />
        </div>

        <div class="flex items-start gap-3 flex-wrap">
          <span class="text-xs font-medium text-gray-500 dark:text-gray-400 shrink-0 w-20 mt-1">{{ t('dashboard.symbols') }}</span>
          <div class="flex-1 min-w-0">
            <BadgeFilter
              v-model="selectedSymbols"
              :options="symbolsStore.symbolOptions"
              multi
            />
          </div>
        </div>
      </div>

      <!-- Row 3: setups -->
      <div class="flex items-start gap-3 flex-wrap">
        <span class="text-xs font-medium text-gray-500 dark:text-gray-400 shrink-0 w-20 mt-1">{{ t('dashboard.setups') }}</span>
        <div class="flex-1 min-w-0">
          <BadgeFilter
            v-model="selectedSetups"
            :options="setupsStore.setupOptions.map((s) => ({ label: s, value: s }))"
            multi
          />
        </div>
      </div>
    </div>
  </div>
</template>
