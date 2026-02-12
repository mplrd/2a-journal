<script setup>
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { useTradesStore } from '@/stores/trades'
import { useAccountsStore } from '@/stores/accounts'
import { useSymbolsStore } from '@/stores/symbols'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import Select from 'primevue/select'
import TradeForm from '@/components/trade/TradeForm.vue'
import CloseTradeDialog from '@/components/trade/CloseTradeDialog.vue'
import { Direction, TradeStatus } from '@/constants/enums'

const { t } = useI18n()
const toast = useToast()
const store = useTradesStore()
const accountsStore = useAccountsStore()
const symbolsStore = useSymbolsStore()

const showForm = ref(false)
const showCloseDialog = ref(false)
const selectedTrade = ref(null)

const filterAccountId = ref(null)
const filterStatus = ref(null)

const statusOptions = [
  { label: t('trades.all_statuses'), value: null },
  ...Object.values(TradeStatus).map((value) => ({
    label: t(`trades.statuses.${value}`),
    value,
  })),
]

onMounted(async () => {
  await Promise.all([accountsStore.fetchAccounts(), symbolsStore.fetchSymbols()])
  await store.fetchTrades()
})

async function applyFilters() {
  const filters = {}
  if (filterAccountId.value) filters.account_id = filterAccountId.value
  if (filterStatus.value) filters.status = filterStatus.value
  store.setFilters(filters)
  await store.fetchTrades()
}

async function handleCreate(data) {
  try {
    await store.createTrade(data)
    toast.add({ severity: 'success', summary: t('common.success'), detail: t('trades.success.created'), life: 3000 })
    showForm.value = false
  } catch {
    // error is set in the store
  }
}

function openCloseDialog(trade) {
  selectedTrade.value = trade
  showCloseDialog.value = true
}

async function handleClose(data) {
  try {
    await store.closeTrade(selectedTrade.value.id, data)
    toast.add({ severity: 'success', summary: t('common.success'), detail: t('trades.success.closed'), life: 3000 })
    showCloseDialog.value = false
    selectedTrade.value = null
  } catch {
    // error is set in the store
  }
}

async function handleDelete(trade) {
  if (confirm(t('trades.confirm_delete'))) {
    try {
      await store.deleteTrade(trade.id)
      toast.add({ severity: 'success', summary: t('common.success'), detail: t('trades.success.deleted'), life: 3000 })
    } catch {
      // error is set in the store
    }
  }
}

function directionSeverity(direction) {
  return direction === Direction.BUY ? 'success' : 'danger'
}

function statusSeverity(status) {
  switch (status) {
    case TradeStatus.OPEN:
      return 'info'
    case TradeStatus.SECURED:
      return 'warn'
    case TradeStatus.CLOSED:
      return 'success'
    default:
      return 'info'
  }
}

function pnlClass(pnl) {
  if (pnl === null || pnl === undefined) return ''
  return Number(pnl) >= 0 ? 'text-green-600 font-medium' : 'text-red-600 font-medium'
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <h1 class="text-2xl font-bold">{{ t('trades.title') }}</h1>
      <Button :label="t('trades.create')" icon="pi pi-plus" @click="showForm = true" />
    </div>

    <div class="flex gap-4 mb-4">
      <Select
        v-model="filterAccountId"
        :options="[{ label: t('trades.all_accounts'), value: null }, ...accountsStore.accounts.map((a) => ({ label: a.name, value: a.id }))]"
        optionLabel="label"
        optionValue="value"
        :placeholder="t('trades.filter_account')"
        class="w-48"
        @change="applyFilters"
      />
      <Select
        v-model="filterStatus"
        :options="statusOptions"
        optionLabel="label"
        optionValue="value"
        :placeholder="t('trades.filter_status')"
        class="w-48"
        @change="applyFilters"
      />
    </div>

    <p v-if="!store.loading && store.trades.length === 0" class="text-gray-500">
      {{ t('trades.empty') }}
    </p>

    <DataTable
      v-if="store.trades.length > 0"
      :value="store.trades"
      :loading="store.loading"
      stripedRows
      class="mt-2"
    >
      <Column field="symbol" :header="t('positions.symbol')" />
      <Column field="direction" :header="t('positions.direction')">
        <template #body="{ data }">
          <Tag :value="t(`positions.directions.${data.direction}`)" :severity="directionSeverity(data.direction)" />
        </template>
      </Column>
      <Column field="entry_price" :header="t('positions.entry_price')">
        <template #body="{ data }">
          {{ Number(data.entry_price).toLocaleString() }}
        </template>
      </Column>
      <Column field="size" :header="t('positions.size')" />
      <Column field="remaining_size" :header="t('trades.remaining_size')" />
      <Column field="opened_at" :header="t('trades.opened_at')">
        <template #body="{ data }">
          {{ new Date(data.opened_at).toLocaleString() }}
        </template>
      </Column>
      <Column field="status" :header="t('trades.status')">
        <template #body="{ data }">
          <Tag :value="t(`trades.statuses.${data.status}`)" :severity="statusSeverity(data.status)" />
        </template>
      </Column>
      <Column field="pnl" :header="t('trades.pnl')">
        <template #body="{ data }">
          <span :class="pnlClass(data.pnl)">
            {{ data.pnl != null ? (Number(data.pnl) >= 0 ? '+' : '') + Number(data.pnl).toFixed(2) : '-' }}
          </span>
        </template>
      </Column>
      <Column :header="''">
        <template #body="{ data }">
          <div class="flex gap-2">
            <Button
              v-if="data.status !== TradeStatus.CLOSED"
              icon="pi pi-sign-out"
              severity="warn"
              size="small"
              text
              v-tooltip.top="t('trades.close_trade')"
              @click="openCloseDialog(data)"
            />
            <Button icon="pi pi-trash" severity="danger" size="small" text v-tooltip.top="t('common.delete')" @click="handleDelete(data)" />
          </div>
        </template>
      </Column>
    </DataTable>

    <TradeForm
      v-model:visible="showForm"
      :accounts="accountsStore.accounts"
      :symbols="symbolsStore.symbolOptions"
      :loading="store.loading"
      @save="handleCreate"
    />

    <CloseTradeDialog
      v-model:visible="showCloseDialog"
      :trade="selectedTrade"
      :loading="store.loading"
      @close="handleClose"
    />
  </div>
</template>
