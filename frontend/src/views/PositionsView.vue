<script setup>
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { usePositionsStore } from '@/stores/positions'
import { useAccountsStore } from '@/stores/accounts'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import Select from 'primevue/select'
import PositionForm from '@/components/position/PositionForm.vue'
import TransferDialog from '@/components/position/TransferDialog.vue'
import { Direction, PositionType } from '@/constants/enums'

const { t } = useI18n()
const toast = useToast()
const store = usePositionsStore()
const accountsStore = useAccountsStore()

const showForm = ref(false)
const editingPosition = ref(null)
const showTransfer = ref(false)
const transferringPosition = ref(null)

const filterAccountId = ref(null)
const filterType = ref(null)

const typeOptions = [
  { label: t('positions.all_types'), value: null },
  ...Object.values(PositionType).map((value) => ({
    label: t(`positions.types.${value}`),
    value,
  })),
]

onMounted(async () => {
  await accountsStore.fetchAccounts()
  await store.fetchPositions()
})

async function applyFilters() {
  const filters = {}
  if (filterAccountId.value) filters.account_id = filterAccountId.value
  if (filterType.value) filters.position_type = filterType.value
  store.setFilters(filters)
  await store.fetchPositions()
}

function openEdit(position) {
  editingPosition.value = position
  showForm.value = true
}

async function handleSave(data) {
  try {
    await store.updatePosition(editingPosition.value.id, data)
    toast.add({ severity: 'success', summary: t('common.success'), detail: t('positions.success.updated'), life: 3000 })
    showForm.value = false
  } catch {
    // error is set in the store
  }
}

function openTransfer(position) {
  transferringPosition.value = position
  showTransfer.value = true
}

async function handleTransfer(accountId) {
  try {
    await store.transferPosition(transferringPosition.value.id, accountId)
    toast.add({ severity: 'success', summary: t('common.success'), detail: t('positions.success.transferred'), life: 3000 })
    showTransfer.value = false
  } catch {
    // error is set in the store
  }
}

async function handleDelete(position) {
  if (confirm(t('positions.confirm_delete'))) {
    try {
      await store.deletePosition(position.id)
      toast.add({ severity: 'success', summary: t('common.success'), detail: t('positions.success.deleted'), life: 3000 })
    } catch {
      // error is set in the store
    }
  }
}

function directionSeverity(direction) {
  return direction === Direction.BUY ? 'success' : 'danger'
}

function typeSeverity(type) {
  return type === PositionType.ORDER ? 'warn' : 'info'
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <h1 class="text-2xl font-bold">{{ t('positions.title') }}</h1>
    </div>

    <div class="flex gap-4 mb-4">
      <Select
        v-model="filterAccountId"
        :options="[{ label: t('positions.all_accounts'), value: null }, ...accountsStore.accounts.map((a) => ({ label: a.name, value: a.id }))]"
        optionLabel="label"
        optionValue="value"
        :placeholder="t('positions.filter_account')"
        class="w-48"
        @change="applyFilters"
      />
      <Select
        v-model="filterType"
        :options="typeOptions"
        optionLabel="label"
        optionValue="value"
        :placeholder="t('positions.filter_type')"
        class="w-48"
        @change="applyFilters"
      />
    </div>

    <p v-if="!store.loading && store.positions.length === 0" class="text-gray-500">
      {{ t('positions.empty') }}
    </p>

    <DataTable
      v-if="store.positions.length > 0"
      :value="store.positions"
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
      <Column field="setup" :header="t('positions.setup')" />
      <Column field="sl_price" :header="t('positions.sl_price')">
        <template #body="{ data }">
          {{ Number(data.sl_price).toLocaleString() }}
        </template>
      </Column>
      <Column field="position_type" :header="t('positions.type')">
        <template #body="{ data }">
          <Tag :value="t(`positions.types.${data.position_type}`)" :severity="typeSeverity(data.position_type)" />
        </template>
      </Column>
      <Column field="created_at" :header="t('positions.created_at')">
        <template #body="{ data }">
          {{ new Date(data.created_at).toLocaleDateString() }}
        </template>
      </Column>
      <Column :header="''">
        <template #body="{ data }">
          <div class="flex gap-2">
            <Button icon="pi pi-pencil" severity="secondary" size="small" text @click="openEdit(data)" />
            <Button icon="pi pi-arrow-right-arrow-left" severity="info" size="small" text @click="openTransfer(data)" />
            <Button icon="pi pi-trash" severity="danger" size="small" text @click="handleDelete(data)" />
          </div>
        </template>
      </Column>
    </DataTable>

    <PositionForm
      v-model:visible="showForm"
      :position="editingPosition"
      :loading="store.loading"
      @save="handleSave"
    />

    <TransferDialog
      v-model:visible="showTransfer"
      :position="transferringPosition"
      :accounts="accountsStore.accounts"
      :loading="store.loading"
      @transfer="handleTransfer"
    />
  </div>
</template>
