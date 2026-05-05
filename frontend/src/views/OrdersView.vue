<script setup>
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { useConfirm } from 'primevue/useconfirm'
import { useOrdersStore } from '@/stores/orders'
import { useAccountsStore } from '@/stores/accounts'
import { useSymbolsStore } from '@/stores/symbols'
import { useSetupsStore } from '@/stores/setups'
import { useAuthStore } from '@/stores/auth'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import OrderForm from '@/components/order/OrderForm.vue'
import { formatSize } from '@/utils/format'
import { useSetupCategory } from '@/utils/setupCategory'
import EmptyState from '@/components/common/EmptyState.vue'
import BadgeFilter from '@/components/common/BadgeFilter.vue'
import CollapsibleFilters from '@/components/common/CollapsibleFilters.vue'
import FloatingActionButton from '@/components/common/FloatingActionButton.vue'
import TileList from '@/components/common/TileList.vue'
import { useLayout } from '@/composables/useIsMobile'
import PositionForm from '@/components/position/PositionForm.vue'
import TransferDialog from '@/components/position/TransferDialog.vue'
import ShareDialog from '@/components/common/ShareDialog.vue'
import { usePositionsStore } from '@/stores/positions'
import { Direction, OrderStatus } from '@/constants/enums'

const { t } = useI18n()
const { isMobile, isCompact } = useLayout()
const toast = useToast()
const confirm = useConfirm()
const store = useOrdersStore()
const accountsStore = useAccountsStore()
const symbolsStore = useSymbolsStore()
const setupsStore = useSetupsStore()
const { classFor: setupTagClass } = useSetupCategory()
const authStore = useAuthStore()

const positionsStore = usePositionsStore()

const showForm = ref(false)
const showShare = ref(false)
const sharePositionId = ref(null)
const editingPosition = ref(null)
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

const filterAccountIds = ref([])
const filterStatuses = ref([])

const statusOptions = Object.values(OrderStatus).map((value) => ({
  label: t(`orders.statuses.${value}`),
  value,
}))

onMounted(async () => {
  store.perPage = Number(authStore.user?.default_page_size) || 10
  await Promise.all([accountsStore.fetchAccounts(), symbolsStore.fetchSymbols(), setupsStore.fetchSetups()])
  await store.fetchOrders()
})

async function applyFilters() {
  const filters = {}
  if (filterAccountIds.value.length > 0) filters.account_ids = filterAccountIds.value
  if (filterStatuses.value.length > 0) filters.statuses = filterStatuses.value
  store.setFilters(filters)
  store.page = 1
  await store.fetchOrders()
}

function onPage(event) {
  store.page = event.page + 1
  store.perPage = event.rows
  store.fetchOrders()
}

async function handleCreate(data) {
  try {
    await store.createOrder(data)
    toast.add({ severity: 'success', summary: t('common.success'), detail: t('orders.success.created'), life: 3000 })
    showForm.value = false
  } catch (err) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: t(err.messageKey || 'error.internal'), life: 5000 })
  }
}

function handleCancel(order) {
  confirm.require({
    message: t('orders.confirm_cancel'),
    header: t('common.confirm'),
    icon: 'pi pi-exclamation-triangle',
    rejectProps: { label: t('common.cancel'), severity: 'secondary', outlined: true },
    acceptProps: { label: t('common.confirm'), severity: 'warn' },
    accept: async () => {
      try {
        await store.cancelOrder(order.id)
        toast.add({ severity: 'success', summary: t('common.success'), detail: t('orders.success.cancelled'), life: 3000 })
      } catch (err) {
        toast.add({ severity: 'error', summary: t('common.error'), detail: t(err.messageKey || 'error.internal'), life: 5000 })
      }
    },
  })
}

function handleExecute(order) {
  confirm.require({
    message: t('orders.confirm_execute'),
    header: t('common.confirm'),
    icon: 'pi pi-exclamation-triangle',
    rejectProps: { label: t('common.cancel'), severity: 'secondary', outlined: true },
    acceptProps: { label: t('common.confirm'), severity: 'success' },
    accept: async () => {
      try {
        await store.executeOrder(order.id)
        toast.add({ severity: 'success', summary: t('common.success'), detail: t('orders.success.executed'), life: 3000 })
      } catch (err) {
        toast.add({ severity: 'error', summary: t('common.error'), detail: t(err.messageKey || 'error.internal'), life: 5000 })
      }
    },
  })
}

function handleDelete(order) {
  confirm.require({
    message: t('orders.confirm_delete'),
    header: t('common.confirm'),
    icon: 'pi pi-exclamation-triangle',
    rejectProps: { label: t('common.cancel'), severity: 'secondary', outlined: true },
    acceptProps: { label: t('common.delete'), severity: 'danger' },
    accept: async () => {
      try {
        await store.deleteOrder(order.id)
        toast.add({ severity: 'success', summary: t('common.success'), detail: t('orders.success.deleted'), life: 3000 })
      } catch (err) {
        toast.add({ severity: 'error', summary: t('common.error'), detail: t(err.messageKey || 'error.internal'), life: 5000 })
      }
    },
  })
}

function openShare(order) {
  sharePositionId.value = Number(order.position_id)
  showShare.value = true
}

async function openEdit(order) {
  editingPosition.value = await positionsStore.fetchPosition(order.position_id)
  showEditForm.value = true
}

async function handleEditSave(data) {
  try {
    await positionsStore.updatePosition(editingPosition.value.id, data)
    toast.add({ severity: 'success', summary: t('common.success'), detail: t('positions.success.updated'), life: 3000 })
    showEditForm.value = false
    await store.fetchOrders()
  } catch {
    // error is set in the store
  }
}

async function openTransfer(order) {
  transferringPosition.value = await positionsStore.fetchPosition(order.position_id)
  showTransfer.value = true
}

async function handleTransfer(accountId) {
  try {
    await positionsStore.transferPosition(transferringPosition.value.id, accountId)
    toast.add({ severity: 'success', summary: t('common.success'), detail: t('positions.success.transferred'), life: 3000 })
    showTransfer.value = false
    await store.fetchOrders()
  } catch {
    // error is set in the store
  }
}

function directionSeverity(direction) {
  return direction === Direction.BUY ? 'success' : 'danger'
}

// Picto-form direction + status for compact / mobile views (saves the
// ~140 px of two PrimeVue Tags). Color follows the Tag severity so the
// glance-instant cue is preserved.
function directionIcon(direction) {
  return direction === Direction.BUY ? 'pi pi-arrow-up' : 'pi pi-arrow-down'
}
function directionIconClass(direction) {
  return direction === Direction.BUY ? 'text-success' : 'text-danger'
}
function statusIcon(status) {
  switch (status) {
    case OrderStatus.PENDING: return 'pi pi-clock'
    case OrderStatus.EXECUTED: return 'pi pi-check-circle'
    case OrderStatus.CANCELED: return 'pi pi-times-circle'
    case OrderStatus.EXPIRED: return 'pi pi-ban'
    default: return 'pi pi-circle'
  }
}
function statusIconClass(status) {
  switch (status) {
    case OrderStatus.PENDING: return 'text-blue-600 dark:text-blue-400'
    case OrderStatus.EXECUTED: return 'text-success'
    case OrderStatus.CANCELED: return 'text-orange-600 dark:text-orange-400'
    case OrderStatus.EXPIRED: return 'text-danger'
    default: return 'text-gray-500'
  }
}

function statusSeverity(status) {
  switch (status) {
    case OrderStatus.PENDING:
      return 'warn'
    case OrderStatus.EXECUTED:
      return 'success'
    case OrderStatus.CANCELLED:
      return 'secondary'
    case OrderStatus.EXPIRED:
      return 'danger'
    default:
      return 'info'
  }
}
</script>

<template>
  <div>
    <!-- Desktop filter bar: filters + create button on a single line. -->
    <div v-if="!isMobile" class="flex items-end gap-6 flex-wrap mb-4">
      <div class="flex flex-col gap-1">
        <span class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ t('orders.account') }}</span>
        <BadgeFilter
          v-model="filterAccountIds"
          :options="accountsStore.accounts.map((a) => ({ label: a.name, value: a.id }))"
          multi
          @change="applyFilters"
        />
      </div>
      <div class="flex flex-col gap-1">
        <span class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ t('orders.status') }}</span>
        <BadgeFilter
          v-model="filterStatuses"
          :options="statusOptions"
          multi
          @change="applyFilters"
        />
      </div>
      <div class="ml-auto">
        <Button :label="t('orders.create')" icon="pi pi-plus" @click="showForm = true" />
      </div>
    </div>

    <!-- Mobile filter bar: collapsible vertical stack. -->
    <CollapsibleFilters v-else storage-key="orders-filters-expanded" class="mb-4">
      <div class="flex flex-col gap-3">
        <div class="flex flex-col gap-1">
          <span class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ t('orders.account') }}</span>
          <BadgeFilter
            v-model="filterAccountIds"
            :options="accountsStore.accounts.map((a) => ({ label: a.name, value: a.id }))"
            multi
            @change="applyFilters"
          />
        </div>
        <div class="flex flex-col gap-1">
          <span class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ t('orders.status') }}</span>
          <BadgeFilter
            v-model="filterStatuses"
            :options="statusOptions"
            multi
            @change="applyFilters"
          />
        </div>
      </div>
    </CollapsibleFilters>

    <FloatingActionButton
      icon="plus"
      :aria-label="t('orders.create')"
      @click="showForm = true"
    />

    <EmptyState
      v-if="!store.loading && store.totalRecords === 0"
      icon="pi pi-list"
      :title="t('orders.empty_title')"
      :description="t('orders.empty')"
    >
      <Button :label="t('orders.create')" icon="pi pi-plus" @click="showForm = true" />
    </EmptyState>

    <DataTable
      v-else-if="!isMobile"
      :value="store.orders"
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
      class="mt-2"
    >
      <Column field="account_id" :header="t('orders.account')">
        <template #body="{ data }">{{ accountName(data.account_id) }}</template>
      </Column>
      <Column field="symbol" :header="t('positions.symbol')">
        <template #body="{ data }">
          <span v-if="isCompact" class="inline-flex items-center gap-1.5">
            <i :class="[directionIcon(data.direction), directionIconClass(data.direction), 'text-xs']" v-tooltip.top="t(`positions.directions.${data.direction}`)"></i>
            <span>{{ symbolName(data.symbol) }}</span>
            <i :class="[statusIcon(data.status), statusIconClass(data.status), 'text-xs']" v-tooltip.top="t(`orders.statuses.${data.status}`)"></i>
          </span>
          <span v-else>{{ symbolName(data.symbol) }}</span>
        </template>
      </Column>
      <Column v-if="!isCompact" field="direction" :header="t('positions.direction')">
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
      <Column field="setup" :header="t('positions.setup')">
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
      <Column field="sl_price" :header="t('positions.sl_price')">
        <template #body="{ data }">
          {{ Number(data.sl_price).toLocaleString() }}
        </template>
      </Column>
      <Column v-if="!isCompact" field="status" :header="t('orders.status')">
        <template #body="{ data }">
          <Tag :value="t(`orders.statuses.${data.status}`)" :severity="statusSeverity(data.status)" />
        </template>
      </Column>
      <Column v-if="!isCompact" field="expires_at" :header="t('orders.expires_at')">
        <template #body="{ data }">
          {{ data.expires_at ? new Date(data.expires_at).toLocaleString() : '-' }}
        </template>
      </Column>
      <Column v-if="!isCompact" field="order_created_at" :header="t('positions.created_at')">
        <template #body="{ data }">
          {{ new Date(data.order_created_at || data.created_at).toLocaleDateString() }}
        </template>
      </Column>
      <Column :header="''">
        <template #body="{ data }">
          <div class="flex gap-2">
            <Button v-if="data.status === OrderStatus.PENDING" icon="pi pi-pencil" severity="secondary" size="small" text v-tooltip.top="t('common.edit')" @click="openEdit(data)" />
            <Button v-if="data.status === OrderStatus.PENDING" icon="pi pi-arrow-right-arrow-left" severity="info" size="small" text v-tooltip.top="t('positions.transfer')" @click="openTransfer(data)" />
            <Button
              v-if="data.status === OrderStatus.PENDING"
              icon="pi pi-check"
              severity="success"
              size="small"
              text
              v-tooltip.top="t('orders.execute')"
              @click="handleExecute(data)"
            />
            <Button
              v-if="data.status === OrderStatus.PENDING"
              icon="pi pi-times"
              severity="warn"
              size="small"
              text
              v-tooltip.top="t('orders.cancel_action')"
              @click="handleCancel(data)"
            />
            <Button icon="pi pi-share-alt" severity="info" size="small" text v-tooltip.top="t('share.share')" @click="openShare(data)" />
            <Button icon="pi pi-trash" severity="danger" size="small" text v-tooltip.top="t('common.delete')" @click="handleDelete(data)" />
          </div>
        </template>
      </Column>
    </DataTable>

    <!-- Mobile: tile list mirroring the DataTable columns + corner actions. -->
    <TileList
      v-else-if="isMobile && store.totalRecords > 0"
      :items="store.orders"
      :loading="store.loading"
      :total-records="store.totalRecords"
      :page="store.page"
      :per-page="store.perPage"
      class="mt-2"
      @page="onPage"
    >
      <template #default="{ item }">
        <div class="p-4 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800" :data-testid="`order-tile-${item.id}`">
          <div class="grid grid-cols-[1fr_auto] gap-2">
            <div>
              <div class="flex items-center gap-1.5 mb-1.5">
                <i :class="[directionIcon(item.direction), directionIconClass(item.direction)]" v-tooltip.top="t(`positions.directions.${item.direction}`)"></i>
                <span class="font-semibold">{{ symbolName(item.symbol) }}</span>
                <i :class="[statusIcon(item.status), statusIconClass(item.status)]" v-tooltip.top="t(`orders.statuses.${item.status}`)"></i>
              </div>
              <div class="text-xs text-gray-500 dark:text-gray-400 mb-2">{{ accountName(item.account_id) }}</div>
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
                  <div class="text-xs text-gray-500 dark:text-gray-400">{{ t('positions.sl_price') }}</div>
                  <div class="font-mono tabular-nums">{{ Number(item.sl_price).toLocaleString() }}</div>
                </div>
              </div>
            </div>
            <div class="flex flex-col items-end gap-1">
              <!-- Mgmt row: PENDING-only lifecycle actions. -->
              <div v-if="item.status === OrderStatus.PENDING" class="flex gap-1">
                <Button icon="pi pi-check" severity="success" size="small" text rounded :aria-label="t('orders.execute')" @click="handleExecute(item)" />
                <Button icon="pi pi-times" severity="warn" size="small" text rounded :aria-label="t('orders.cancel_action')" @click="handleCancel(item)" />
              </div>
              <!-- Secondary stack -->
              <div class="flex flex-col gap-1">
                <Button v-if="item.status === OrderStatus.PENDING" icon="pi pi-pencil" severity="secondary" size="small" text rounded :aria-label="t('common.edit')" @click="openEdit(item)" />
                <Button v-if="item.status === OrderStatus.PENDING" icon="pi pi-arrow-right-arrow-left" severity="info" size="small" text rounded :aria-label="t('positions.transfer')" @click="openTransfer(item)" />
                <Button icon="pi pi-share-alt" severity="info" size="small" text rounded :aria-label="t('share.share')" @click="openShare(item)" />
                <Button icon="pi pi-trash" severity="danger" size="small" text rounded :aria-label="t('common.delete')" @click="handleDelete(item)" />
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

    <OrderForm
      v-model:visible="showForm"
      :accounts="accountsStore.accounts"
      :symbols="symbolsStore.symbolOptions"
      :setups="setupsStore.setupOptions"
      :loading="store.loading"
      @save="handleCreate"
    />

    <PositionForm
      v-model:visible="showEditForm"
      :position="editingPosition"
      :symbols="symbolsStore.symbolOptions"
      :setups="setupsStore.setupOptions"
      :loading="positionsStore.loading"
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
  </div>
</template>
