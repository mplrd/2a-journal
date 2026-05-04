<script setup>
import { onMounted, ref, computed, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { useConfirm } from 'primevue/useconfirm'
import { useTradesStore } from '@/stores/trades'
import { useAccountsStore } from '@/stores/accounts'
import { useSymbolsStore } from '@/stores/symbols'
import { useSetupsStore } from '@/stores/setups'
import { useCustomFieldsStore } from '@/stores/customFields'
import { useAuthStore } from '@/stores/auth'
import { formatSize } from '@/utils/format'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import Dialog from 'primevue/dialog'
import DateRangePicker from '@/components/common/DateRangePicker.vue'
import CollapsibleFilters from '@/components/common/CollapsibleFilters.vue'
import FloatingActionButton from '@/components/common/FloatingActionButton.vue'
import TileList from '@/components/common/TileList.vue'
import { useLayout } from '@/composables/useIsMobile'
import TradeForm from '@/components/trade/TradeForm.vue'
import CloseTradeDialog from '@/components/trade/CloseTradeDialog.vue'
import TransferDialog from '@/components/position/TransferDialog.vue'
import ShareDialog from '@/components/common/ShareDialog.vue'
import { usePositionsStore } from '@/stores/positions'
import { tradesService } from '@/services/trades'
import { useSetupCategory } from '@/utils/setupCategory'
import EmptyState from '@/components/common/EmptyState.vue'
import BadgeFilter from '@/components/common/BadgeFilter.vue'
import { Direction, ExitType, TradeStatus, CustomFieldType } from '@/constants/enums'

const route = useRoute()
const { t } = useI18n()
const { isMobile, isCompact } = useLayout()
const toast = useToast()
const confirm = useConfirm()
const store = useTradesStore()
const accountsStore = useAccountsStore()
const symbolsStore = useSymbolsStore()
const setupsStore = useSetupsStore()
const { classFor: setupTagClass } = useSetupCategory()
const customFieldsStore = useCustomFieldsStore()
const authStore = useAuthStore()

const positionsStore = usePositionsStore()

const showForm = ref(false)
const editingTrade = ref(null)
const showEditForm = ref(false)
const transferringPosition = ref(null)
const showTransfer = ref(false)

function parseSetup(setup) {
  if (Array.isArray(setup)) return setup
  if (!setup) return []
  try { return JSON.parse(setup) } catch { return [setup] }
}

function symbolName(code) {
  const s = symbolsStore.symbols.find((sym) => sym.code === code)
  return s ? s.name : code
}

function accountName(accountId) {
  const a = accountsStore.accounts.find((acc) => acc.id === accountId)
  return a ? a.name : '-'
}
const showCloseDialog = ref(false)
const selectedTrade = ref(null)
const closePrefill = ref(null)
const showShare = ref(false)
const sharePositionId = ref(null)

const filterAccountIds = ref([])
const filterStatuses = ref([])
const filterDateFrom = ref(null)
const filterDateTo = ref(null)

// Multi-select state for bulk delete
const selectedTrades = ref([])
const showBulkDeleteDialog = ref(false)
const bulkDeleting = ref(false)

function ymd(date) {
  if (!date) return null
  const d = date instanceof Date ? date : new Date(date)
  if (isNaN(d.getTime())) return null
  const y = d.getFullYear()
  const m = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')
  return `${y}-${m}-${day}`
}

// On compact viewports the custom-field columns are stripped from the
// DataTable so it fits without horizontal scroll (the values stay
// available on the trade detail / edit dialog).
const customColumnsToShow = computed(() =>
  isCompact.value ? [] : customFieldsStore.activeDefinitions
)

// DateRangePicker emits update:from / update:to via v-model:from/to. We
// watch the refs to auto-apply the filter, matching the BadgeFilter
// @change pattern (no explicit Apply button on this view).
let dateFilterDebounce = null
function scheduleDateFilterApply() {
  if (dateFilterDebounce) clearTimeout(dateFilterDebounce)
  dateFilterDebounce = setTimeout(() => applyFilters(), 200)
}

const statusOptions = Object.values(TradeStatus).map((value) => ({
  label: t(`trades.statuses.${value}`),
  value,
}))

function applyQueryParamFilters() {
  // ?statuses=OPEN,SECURED or ?statuses=OPEN&statuses=SECURED — accept both.
  // Values not matching the TradeStatus enum are silently dropped.
  const raw = route.query.statuses
  if (!raw) return
  const list = Array.isArray(raw) ? raw : String(raw).split(',')
  const valid = Object.values(TradeStatus)
  const filtered = list.map((s) => String(s).trim()).filter((s) => valid.includes(s))
  if (filtered.length > 0) {
    filterStatuses.value = filtered
  }
}

onMounted(async () => {
  applyQueryParamFilters()
  store.perPage = Number(authStore.user?.default_page_size) || 10
  await Promise.all([accountsStore.fetchAccounts(), symbolsStore.fetchSymbols(), setupsStore.fetchSetups(), customFieldsStore.fetchDefinitions()])
  if (filterStatuses.value.length > 0) {
    store.setFilters({ statuses: filterStatuses.value })
  }
  await store.fetchTrades()
})

function getCustomFieldValue(trade, fieldId) {
  const cf = (trade.custom_fields || []).find((f) => f.field_id === fieldId || f.field_id === String(fieldId))
  return cf ? cf.value : null
}

function formatCustomFieldValue(value, fieldType) {
  if (value === null || value === undefined) return '-'
  if (fieldType === CustomFieldType.BOOLEAN) {
    return value === 'true' ? '\u2714' : '\u2718'
  }
  return value
}

async function applyFilters() {
  const filters = {}
  if (filterAccountIds.value.length > 0) filters.account_ids = filterAccountIds.value
  if (filterStatuses.value && filterStatuses.value.length > 0) {
    filters.statuses = filterStatuses.value
  }
  const dfStr = ymd(filterDateFrom.value)
  const dtStr = ymd(filterDateTo.value)
  if (dfStr) filters.date_from = dfStr
  if (dtStr) filters.date_to = dtStr
  store.setFilters(filters)
  store.page = 1
  selectedTrades.value = []
  await store.fetchTrades()
}

watch([filterDateFrom, filterDateTo], scheduleDateFilterApply)

// Recap pour la modale de confirmation : nb, dates, comptes, P&L cumulé.
const bulkDeleteRecap = computed(() => {
  const list = selectedTrades.value || []
  if (list.length === 0) return null

  const dates = list
    .map((t) => t.opened_at)
    .filter(Boolean)
    .sort()
  const dateFrom = dates[0]
  const dateTo = dates[dates.length - 1]

  const accountNames = [...new Set(list.map((t) => accountName(t.account_id)))].filter(Boolean)

  const totalPnl = list.reduce((sum, t) => sum + (Number(t.pnl) || 0), 0)

  return {
    count: list.length,
    dateFrom,
    dateTo,
    accounts: accountNames,
    totalPnl,
  }
})

function openBulkDeleteDialog() {
  if (!selectedTrades.value || selectedTrades.value.length === 0) return
  showBulkDeleteDialog.value = true
}

async function confirmBulkDelete() {
  const ids = selectedTrades.value.map((t) => t.id)
  bulkDeleting.value = true
  try {
    const count = await store.bulkDeleteTrades(ids)
    toast.add({
      severity: 'success',
      summary: t('common.success'),
      detail: t('trades.success.bulk_deleted', { count }),
      life: 3000,
    })
    selectedTrades.value = []
    showBulkDeleteDialog.value = false
    await store.fetchTrades()
  } catch (err) {
    toast.add({
      severity: 'error',
      summary: t('common.error'),
      detail: t(err.messageKey || 'error.internal'),
      life: 5000,
    })
  } finally {
    bulkDeleting.value = false
  }
}

function onPage(event) {
  store.page = event.page + 1
  store.perPage = event.rows
  store.fetchTrades()
}

async function handleCreate(data) {
  try {
    await store.createTrade(data)
    toast.add({ severity: 'success', summary: t('common.success'), detail: t('trades.success.created'), life: 3000 })
    showForm.value = false
  } catch (err) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: t(err.messageKey || 'error.internal'), life: 5000 })
  }
}

function getNextObjective(trade) {
  const partialExits = trade.partial_exits || []

  // Step 1: BE if be_price defined
  if (trade.be_price) {
    const beSize = Number(trade.be_size) || 0
    if (beSize > 0) {
      // BE with partial exit: check if already taken
      const beAlreadyTaken = partialExits.some((pe) => pe.exit_type === ExitType.BE)
      if (!beAlreadyTaken) {
        return {
          label: 'BE',
          exit_price: Number(trade.be_price),
          exit_size: beSize,
          exit_type: ExitType.BE,
          action: 'close',
        }
      }
    } else if (!Number(trade.be_reached)) {
      // BE without partial exit: just mark as reached
      return {
        label: 'BE',
        action: 'mark',
      }
    }
  }

  // Step 2: First untaken target
  let targets = trade.targets
  if (typeof targets === 'string') {
    try { targets = JSON.parse(targets) } catch { targets = null }
  }
  if (Array.isArray(targets)) {
    const takenTargetIds = new Set(partialExits.map((pe) => pe.target_id).filter(Boolean))
    for (const target of targets) {
      if (!takenTargetIds.has(target.id)) {
        return {
          label: target.label || target.id,
          exit_price: Number(target.price),
          exit_size: Number(target.size),
          exit_type: ExitType.TP,
          target_id: target.id,
          action: 'close',
        }
      }
    }
  }

  return null
}

function openCloseDialog(trade) {
  closePrefill.value = null
  selectedTrade.value = trade
  showCloseDialog.value = true
}

async function openNextObjective(trade) {
  const objective = getNextObjective(trade)
  if (!objective) return

  if (objective.action === 'mark') {
    try {
      await store.markBeHit(trade.id)
      toast.add({ severity: 'success', summary: t('common.success'), detail: t('trades.be_reached'), life: 3000 })
    } catch (err) {
      toast.add({ severity: 'error', summary: t('common.error'), detail: t(err.messageKey || 'error.internal'), life: 5000 })
    }
    return
  }

  closePrefill.value = objective
  selectedTrade.value = trade
  showCloseDialog.value = true
}

async function handleClose(data) {
  try {
    await store.closeTrade(selectedTrade.value.id, data)
    toast.add({ severity: 'success', summary: t('common.success'), detail: t('trades.success.closed'), life: 3000 })
    showCloseDialog.value = false
    selectedTrade.value = null
    closePrefill.value = null
  } catch (err) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: t(err.messageKey || 'error.internal'), life: 5000 })
  }
}

function handleDelete(trade) {
  confirm.require({
    message: t('trades.confirm_delete'),
    header: t('common.confirm'),
    icon: 'pi pi-exclamation-triangle',
    rejectProps: { label: t('common.cancel'), severity: 'secondary', outlined: true },
    acceptProps: { label: t('common.delete'), severity: 'danger' },
    accept: async () => {
      try {
        await store.deleteTrade(trade.id)
        toast.add({ severity: 'success', summary: t('common.success'), detail: t('trades.success.deleted'), life: 3000 })
      } catch (err) {
        toast.add({ severity: 'error', summary: t('common.error'), detail: t(err.messageKey || 'error.internal'), life: 5000 })
      }
    },
  })
}

function openShare(trade) {
  sharePositionId.value = Number(trade.position_id)
  showShare.value = true
}

async function openEdit(trade) {
  // Fetch the full trade (incl. position fields, opened_at, closed_at,
  // and custom_field values) so the edit form can mirror the create one.
  const response = await tradesService.get(trade.id)
  editingTrade.value = response.data
  showEditForm.value = true
}

async function handleEditSave(data) {
  try {
    await tradesService.update(editingTrade.value.id, data)
    toast.add({ severity: 'success', summary: t('common.success'), detail: t('trades.success.updated'), life: 3000 })
    showEditForm.value = false
    await store.fetchTrades()
  } catch (err) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: t(err.messageKey || 'error.internal'), life: 5000 })
  }
}

async function openTransfer(trade) {
  transferringPosition.value = await positionsStore.fetchPosition(trade.position_id)
  showTransfer.value = true
}

async function handleTransfer(accountId) {
  try {
    await positionsStore.transferPosition(transferringPosition.value.id, accountId)
    toast.add({ severity: 'success', summary: t('common.success'), detail: t('positions.success.transferred'), life: 3000 })
    showTransfer.value = false
    await store.fetchTrades()
  } catch (err) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: t(err.messageKey || 'error.internal'), life: 5000 })
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

function realizedPnl(trade) {
  if (trade.pnl != null) return Number(trade.pnl)
  const exits = trade.partial_exits || []
  if (exits.length === 0) return null
  return exits.reduce((sum, pe) => sum + Number(pe.pnl), 0)
}

function pnlClass(pnl) {
  if (pnl === null || pnl === undefined) return ''
  return Number(pnl) >= 0 ? 'text-success font-medium font-mono tabular-nums' : 'text-danger font-medium font-mono tabular-nums'
}
</script>

<template>
  <div>
    <!-- Desktop filter bar: filters + create button on a single line. -->
    <div v-if="!isMobile" class="flex items-end gap-6 flex-wrap mb-4">
      <div class="flex flex-col gap-1">
        <span class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ t('trades.account') }}</span>
        <BadgeFilter
          v-model="filterAccountIds"
          :options="accountsStore.accounts.map((a) => ({ label: a.name, value: a.id }))"
          multi
          @change="applyFilters"
        />
      </div>
      <div class="flex flex-col gap-1">
        <span class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ t('trades.status') }}</span>
        <BadgeFilter
          v-model="filterStatuses"
          :options="statusOptions"
          multi
          @change="applyFilters"
        />
      </div>
      <div class="flex flex-col gap-1 min-w-[220px]">
        <span class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ t('trades.date_range') }}</span>
        <DateRangePicker
          v-model:from="filterDateFrom"
          v-model:to="filterDateTo"
        />
      </div>
      <div class="ml-auto">
        <Button :label="t('trades.create')" icon="pi pi-plus" @click="showForm = true" />
      </div>
    </div>

    <!-- Mobile filter bar: collapsed by default, vertical stack when open. -->
    <CollapsibleFilters v-else storage-key="trades-filters-expanded" class="mb-4">
      <div class="flex flex-col gap-3">
        <div class="flex flex-col gap-1">
          <span class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ t('trades.account') }}</span>
          <BadgeFilter
            v-model="filterAccountIds"
            :options="accountsStore.accounts.map((a) => ({ label: a.name, value: a.id }))"
            multi
            @change="applyFilters"
          />
        </div>
        <div class="flex flex-col gap-1">
          <span class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ t('trades.status') }}</span>
          <BadgeFilter
            v-model="filterStatuses"
            :options="statusOptions"
            multi
            @change="applyFilters"
          />
        </div>
        <div class="flex flex-col gap-1">
          <span class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ t('trades.date_range') }}</span>
          <DateRangePicker
            v-model:from="filterDateFrom"
            v-model:to="filterDateTo"
          />
        </div>
      </div>
    </CollapsibleFilters>

    <!-- Mobile FAB for "New trade" — replaces the inline button hidden on mobile. -->
    <FloatingActionButton
      icon="plus"
      :aria-label="t('trades.create')"
      @click="showForm = true"
    />

    <!-- Bulk action bar (visible when at least one trade is selected) -->
    <div
      v-if="selectedTrades.length > 0"
      class="mb-4 p-3 border border-red-300 dark:border-red-700 rounded-lg bg-red-50 dark:bg-red-900/20 flex items-center justify-between gap-3"
      data-testid="bulk-action-bar"
    >
      <span class="text-sm text-red-700 dark:text-red-300">
        {{ t('trades.bulk.selected_count', { count: selectedTrades.length }) }}
      </span>
      <Button
        :label="t('trades.bulk.delete_selection')"
        icon="pi pi-trash"
        severity="danger"
        outlined
        data-testid="open-bulk-delete"
        @click="openBulkDeleteDialog"
      />
    </div>

    <EmptyState
      v-if="!store.loading && store.totalRecords === 0"
      icon="pi pi-arrow-right-arrow-left"
      :title="t('trades.empty_title')"
      :description="t('trades.empty')"
    >
      <Button :label="t('trades.create')" icon="pi pi-plus" @click="showForm = true" />
    </EmptyState>

    <DataTable
      v-else-if="!isMobile"
      v-model:selection="selectedTrades"
      :value="store.trades"
      :loading="store.loading"
      :size="isCompact ? 'small' : undefined"
      lazy
      paginator
      :rows="store.perPage"
      :totalRecords="store.totalRecords"
      :first="(store.page - 1) * store.perPage"
      :rowsPerPageOptions="[10, 25, 50]"
      @page="onPage"
      stripedRows
      dataKey="id"
      class="mt-2"
    >
      <Column selectionMode="multiple" headerStyle="width: 3rem" />
      <Column field="account_id" :header="t('trades.account')">
        <template #body="{ data }">{{ accountName(data.account_id) }}</template>
      </Column>
      <Column field="symbol" :header="t('positions.symbol')">
        <template #body="{ data }">{{ symbolName(data.symbol) }}</template>
      </Column>
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
      <Column field="size" :header="t('positions.size')">
        <template #body="{ data }">
          <span class="font-mono tabular-nums">{{ formatSize(data.size) }}</span>
        </template>
      </Column>
      <Column v-if="!isCompact" field="setup" :header="t('positions.setup')">
        <template #body="{ data }">
          <div class="flex flex-wrap gap-1">
            <span
              v-for="s in parseSetup(data.setup)"
              :key="s"
              class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium"
              :class="setupTagClass(s)"
            >
              {{ s }}
            </span>
          </div>
        </template>
      </Column>
      <Column v-if="!isCompact" field="remaining_size" :header="t('trades.remaining_size')">
        <template #body="{ data }">
          <span class="font-mono tabular-nums">{{ formatSize(data.remaining_size) }}</span>
        </template>
      </Column>
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
          <span :class="pnlClass(realizedPnl(data))">
            {{ realizedPnl(data) != null ? (realizedPnl(data) >= 0 ? '+' : '') + realizedPnl(data).toFixed(2) : '-' }}
          </span>
        </template>
      </Column>
      <!-- Dynamic custom field columns — hidden in compact mode to keep
           the grid focused on core trade fields (see customColumnsToShow). -->
      <Column
        v-for="def in customColumnsToShow"
        :key="`cf-${def.id}`"
        :header="def.name"
      >
        <template #body="{ data }">
          <span :class="{ 'text-green-600': def.field_type === 'BOOLEAN' && getCustomFieldValue(data, def.id) === 'true', 'text-red-500': def.field_type === 'BOOLEAN' && getCustomFieldValue(data, def.id) === 'false' }">
            {{ formatCustomFieldValue(getCustomFieldValue(data, def.id), def.field_type) }}
          </span>
        </template>
      </Column>

      <Column :header="''">
        <template #body="{ data }">
          <div class="flex items-center justify-end divide-x divide-gray-200 dark:divide-gray-700">
            <!-- Group: trade management -->
            <div v-if="data.status !== TradeStatus.CLOSED" class="flex gap-1 pr-2">
              <Button
                v-if="getNextObjective(data)"
                icon="pi pi-angle-double-up"
                severity="success"
                size="small"
                text
                v-tooltip.top="getNextObjective(data)?.label"
                @click="openNextObjective(data)"
              />
              <Button
                icon="pi pi-sign-out"
                severity="warn"
                size="small"
                text
                v-tooltip.top="t('trades.close_trade')"
                @click="openCloseDialog(data)"
              />
              <Button
                v-if="authStore.user?.public_settings?.trade_transfer_enabled"
                icon="pi pi-arrow-right-arrow-left"
                severity="info"
                size="small"
                text
                v-tooltip.top="t('positions.transfer')"
                @click="openTransfer(data)"
              />
            </div>
            <!-- Group: edit & delete -->
            <div class="flex gap-1 px-2">
              <Button icon="pi pi-pencil" severity="secondary" size="small" text v-tooltip.top="t('common.edit')" @click="openEdit(data)" />
              <Button icon="pi pi-trash" severity="danger" size="small" text v-tooltip.top="t('common.delete')" @click="handleDelete(data)" />
            </div>
            <!-- Group: share -->
            <div class="flex gap-1 pl-2">
              <Button icon="pi pi-share-alt" severity="info" size="small" text v-tooltip.top="t('share.share')" @click="openShare(data)" />
            </div>
          </div>
        </template>
      </Column>
    </DataTable>

    <!-- Mobile: tile list mirroring the DataTable columns + corner actions.
         Bulk-select is intentionally not surfaced on mobile (cf. doc 58). -->
    <TileList
      v-else-if="isMobile && store.totalRecords > 0"
      :items="store.trades"
      :loading="store.loading"
      :total-records="store.totalRecords"
      :page="store.page"
      :per-page="store.perPage"
      class="mt-2"
      @page="onPage"
    >
      <template #default="{ item }">
        <div class="relative p-4 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800" :data-testid="`trade-tile-${item.id}`">
          <div class="absolute top-2 right-2 flex gap-1 flex-wrap justify-end max-w-[60%]">
            <Button v-if="item.status !== TradeStatus.CLOSED && getNextObjective(item)" icon="pi pi-angle-double-up" severity="success" size="small" text rounded :aria-label="getNextObjective(item)?.label" @click="openNextObjective(item)" />
            <Button v-if="item.status !== TradeStatus.CLOSED" icon="pi pi-sign-out" severity="warn" size="small" text rounded :aria-label="t('trades.close_trade')" @click="openCloseDialog(item)" />
            <Button icon="pi pi-pencil" severity="secondary" size="small" text rounded :aria-label="t('common.edit')" @click="openEdit(item)" />
            <Button icon="pi pi-share-alt" severity="info" size="small" text rounded :aria-label="t('share.share')" @click="openShare(item)" />
            <Button icon="pi pi-trash" severity="danger" size="small" text rounded :aria-label="t('common.delete')" @click="handleDelete(item)" />
          </div>
          <div class="flex items-center gap-2 mb-2 pr-2">
            <Tag :value="t(`positions.directions.${item.direction}`)" :severity="directionSeverity(item.direction)" />
            <span class="font-semibold">{{ symbolName(item.symbol) }}</span>
            <Tag :value="t(`trades.statuses.${item.status}`)" :severity="statusSeverity(item.status)" />
          </div>
          <div class="text-xs text-gray-500 dark:text-gray-400 mb-2">
            {{ accountName(item.account_id) }} · {{ new Date(item.opened_at).toLocaleString() }}
          </div>
          <div class="grid grid-cols-3 gap-x-3 text-sm">
            <div>
              <div class="text-xs text-gray-500 dark:text-gray-400">{{ t('positions.entry_price') }}</div>
              <div class="font-mono tabular-nums">{{ Number(item.entry_price).toLocaleString() }}</div>
            </div>
            <div>
              <div class="text-xs text-gray-500 dark:text-gray-400">{{ t('positions.size') }}</div>
              <div class="font-mono tabular-nums">{{ formatSize(item.size) }}</div>
            </div>
            <div>
              <div class="text-xs text-gray-500 dark:text-gray-400">{{ t('trades.pnl') }}</div>
              <div :class="pnlClass(realizedPnl(item))" class="font-mono tabular-nums">
                {{ realizedPnl(item) != null ? (realizedPnl(item) >= 0 ? '+' : '') + realizedPnl(item).toFixed(2) : '-' }}
              </div>
            </div>
          </div>
          <div v-if="parseSetup(item.setup).length > 0" class="mt-2 flex flex-wrap gap-1">
            <span
              v-for="s in parseSetup(item.setup)"
              :key="s"
              class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium"
              :class="setupTagClass(s)"
            >{{ s }}</span>
          </div>
        </div>
      </template>
    </TileList>

    <TradeForm
      v-model:visible="showForm"
      :accounts="accountsStore.accounts"
      :symbols="symbolsStore.symbolOptions"
      :setups="setupsStore.setupOptions"
      :customFieldDefinitions="customFieldsStore.activeDefinitions"
      :loading="store.loading"
      @save="handleCreate"
    />

    <CloseTradeDialog
      v-model:visible="showCloseDialog"
      :trade="selectedTrade"
      :prefill="closePrefill"
      :loading="store.loading"
      @close="handleClose"
    />

    <TradeForm
      v-model:visible="showEditForm"
      :trade="editingTrade"
      :accounts="accountsStore.accounts"
      :symbols="symbolsStore.symbolOptions"
      :setups="setupsStore.setupOptions"
      :customFieldDefinitions="customFieldsStore.activeDefinitions"
      :loading="store.loading"
      @save="handleEditSave"
    />

    <TransferDialog
      v-model:visible="showTransfer"
      :position="transferringPosition"
      :accounts="accountsStore.accounts"
      :loading="positionsStore.loading"
      @transfer="handleTransfer"
    />

    <ShareDialog v-model:visible="showShare" :positionId="sharePositionId" />

    <!-- Bulk delete confirmation -->
    <Dialog
      v-model:visible="showBulkDeleteDialog"
      :header="t('trades.bulk.confirm_title')"
      modal
      :closable="!bulkDeleting"
      :style="{ width: '500px' }"
      data-testid="bulk-delete-dialog"
    >
      <div v-if="bulkDeleteRecap" class="space-y-3 text-sm">
        <p class="text-gray-700 dark:text-gray-300">{{ t('trades.bulk.confirm_intro') }}</p>
        <ul class="space-y-1 list-disc pl-5 text-gray-700 dark:text-gray-300">
          <li>
            <strong>{{ t('trades.bulk.recap_count') }}</strong> :
            {{ t('trades.bulk.count_value', { count: bulkDeleteRecap.count }) }}
          </li>
          <li v-if="bulkDeleteRecap.dateFrom">
            <strong>{{ t('trades.bulk.recap_dates') }}</strong> :
            {{ bulkDeleteRecap.dateFrom?.slice(0, 10) }} → {{ bulkDeleteRecap.dateTo?.slice(0, 10) }}
          </li>
          <li v-if="bulkDeleteRecap.accounts.length > 0">
            <strong>{{ t('trades.bulk.recap_accounts') }}</strong> :
            {{ bulkDeleteRecap.accounts.join(', ') }}
          </li>
          <li>
            <strong>{{ t('trades.bulk.recap_total_pnl') }}</strong> :
            <span :class="bulkDeleteRecap.totalPnl >= 0 ? 'text-success' : 'text-danger'">
              {{ bulkDeleteRecap.totalPnl >= 0 ? '+' : '' }}{{ bulkDeleteRecap.totalPnl.toFixed(2) }}
            </span>
          </li>
        </ul>
        <p class="text-xs text-red-700 dark:text-red-300 mt-2">{{ t('trades.bulk.confirm_warning') }}</p>
      </div>
      <template #footer>
        <Button
          :label="t('common.cancel')"
          severity="secondary"
          text
          :disabled="bulkDeleting"
          @click="showBulkDeleteDialog = false"
        />
        <Button
          :label="t('trades.bulk.confirm_button')"
          icon="pi pi-trash"
          severity="danger"
          :loading="bulkDeleting"
          data-testid="confirm-bulk-delete"
          @click="confirmBulkDelete"
        />
      </template>
    </Dialog>
  </div>
</template>
