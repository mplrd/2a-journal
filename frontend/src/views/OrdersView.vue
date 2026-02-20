<script setup>
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { useOrdersStore } from '@/stores/orders'
import { useAccountsStore } from '@/stores/accounts'
import { useSymbolsStore } from '@/stores/symbols'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import Select from 'primevue/select'
import OrderForm from '@/components/order/OrderForm.vue'
import ShareDialog from '@/components/common/ShareDialog.vue'
import { Direction, OrderStatus } from '@/constants/enums'

const { t } = useI18n()
const toast = useToast()
const store = useOrdersStore()
const accountsStore = useAccountsStore()
const symbolsStore = useSymbolsStore()

const showForm = ref(false)
const showShare = ref(false)
const sharePositionId = ref(null)

function symbolName(code) {
  const s = symbolsStore.symbols.find((sym) => sym.code === code)
  return s ? s.name : code
}

function accountName(accountId) {
  const a = accountsStore.accounts.find((acc) => acc.id === accountId)
  return a ? a.name : '-'
}

const filterAccountId = ref(null)
const filterStatus = ref(null)

const statusOptions = [
  { label: t('orders.all_statuses'), value: null },
  ...Object.values(OrderStatus).map((value) => ({
    label: t(`orders.statuses.${value}`),
    value,
  })),
]

onMounted(async () => {
  await Promise.all([accountsStore.fetchAccounts(), symbolsStore.fetchSymbols()])
  await store.fetchOrders()
})

async function applyFilters() {
  const filters = {}
  if (filterAccountId.value) filters.account_id = filterAccountId.value
  if (filterStatus.value) filters.status = filterStatus.value
  store.setFilters(filters)
  await store.fetchOrders()
}

async function handleCreate(data) {
  try {
    await store.createOrder(data)
    toast.add({ severity: 'success', summary: t('common.success'), detail: t('orders.success.created'), life: 3000 })
    showForm.value = false
  } catch {
    // error is set in the store
  }
}

async function handleCancel(order) {
  if (confirm(t('orders.confirm_cancel'))) {
    try {
      await store.cancelOrder(order.id)
      toast.add({ severity: 'success', summary: t('common.success'), detail: t('orders.success.cancelled'), life: 3000 })
    } catch {
      // error is set in the store
    }
  }
}

async function handleExecute(order) {
  if (confirm(t('orders.confirm_execute'))) {
    try {
      await store.executeOrder(order.id)
      toast.add({ severity: 'success', summary: t('common.success'), detail: t('orders.success.executed'), life: 3000 })
    } catch {
      // error is set in the store
    }
  }
}

async function handleDelete(order) {
  if (confirm(t('orders.confirm_delete'))) {
    try {
      await store.deleteOrder(order.id)
      toast.add({ severity: 'success', summary: t('common.success'), detail: t('orders.success.deleted'), life: 3000 })
    } catch {
      // error is set in the store
    }
  }
}

function openShare(order) {
  sharePositionId.value = Number(order.position_id)
  showShare.value = true
}

function directionSeverity(direction) {
  return direction === Direction.BUY ? 'success' : 'danger'
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
    <div class="flex items-center justify-between mb-4">
      <h1 class="text-2xl font-bold">{{ t('orders.title') }}</h1>
      <Button :label="t('orders.create')" icon="pi pi-plus" @click="showForm = true" />
    </div>

    <div class="flex gap-4 mb-4">
      <Select
        v-model="filterAccountId"
        :options="[{ label: t('orders.all_accounts'), value: null }, ...accountsStore.accounts.map((a) => ({ label: a.name, value: a.id }))]"
        optionLabel="label"
        optionValue="value"
        :placeholder="t('orders.filter_account')"
        class="w-48"
        @change="applyFilters"
      />
      <Select
        v-model="filterStatus"
        :options="statusOptions"
        optionLabel="label"
        optionValue="value"
        :placeholder="t('orders.filter_status')"
        class="w-48"
        @change="applyFilters"
      />
    </div>

    <p v-if="!store.loading && store.orders.length === 0" class="text-gray-500">
      {{ t('orders.empty') }}
    </p>

    <DataTable
      v-if="store.orders.length > 0"
      :value="store.orders"
      :loading="store.loading"
      stripedRows
      class="mt-2"
    >
      <Column field="account_id" :header="t('orders.account')">
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
      <Column field="size" :header="t('positions.size')" />
      <Column field="setup" :header="t('positions.setup')" />
      <Column field="sl_price" :header="t('positions.sl_price')">
        <template #body="{ data }">
          {{ Number(data.sl_price).toLocaleString() }}
        </template>
      </Column>
      <Column field="status" :header="t('orders.status')">
        <template #body="{ data }">
          <Tag :value="t(`orders.statuses.${data.status}`)" :severity="statusSeverity(data.status)" />
        </template>
      </Column>
      <Column field="expires_at" :header="t('orders.expires_at')">
        <template #body="{ data }">
          {{ data.expires_at ? new Date(data.expires_at).toLocaleString() : '-' }}
        </template>
      </Column>
      <Column field="order_created_at" :header="t('positions.created_at')">
        <template #body="{ data }">
          {{ new Date(data.order_created_at || data.created_at).toLocaleDateString() }}
        </template>
      </Column>
      <Column :header="''">
        <template #body="{ data }">
          <div class="flex gap-2">
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

    <OrderForm
      v-model:visible="showForm"
      :accounts="accountsStore.accounts"
      :symbols="symbolsStore.symbolOptions"
      :loading="store.loading"
      @save="handleCreate"
    />

    <ShareDialog v-model:visible="showShare" :positionId="sharePositionId" />
  </div>
</template>
