<script setup>
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { useAccountsStore } from '@/stores/accounts'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import AccountForm from '@/components/account/AccountForm.vue'
import { AccountType, AccountStage } from '@/constants/enums'

const { t } = useI18n()
const toast = useToast()
const store = useAccountsStore()

const showForm = ref(false)
const editingAccount = ref(null)

onMounted(() => {
  store.fetchAccounts()
})

function openCreate() {
  editingAccount.value = null
  showForm.value = true
}

function openEdit(account) {
  editingAccount.value = account
  showForm.value = true
}

async function handleSave(data) {
  try {
    if (editingAccount.value) {
      await store.updateAccount(editingAccount.value.id, data)
      toast.add({ severity: 'success', summary: t('common.success'), detail: t('accounts.success.updated'), life: 3000 })
    } else {
      await store.createAccount(data)
      toast.add({ severity: 'success', summary: t('common.success'), detail: t('accounts.success.created'), life: 3000 })
    }
    showForm.value = false
  } catch {
    // error is set in the store
  }
}

async function handleDelete(account) {
  if (confirm(t('accounts.confirm_delete'))) {
    try {
      await store.deleteAccount(account.id)
      toast.add({ severity: 'success', summary: t('common.success'), detail: t('accounts.success.deleted'), life: 3000 })
    } catch {
      // error is set in the store
    }
  }
}

function typeSeverity(accountType) {
  const map = {
    [AccountType.BROKER_DEMO]: 'info',
    [AccountType.BROKER_LIVE]: 'success',
    [AccountType.PROP_FIRM]: 'warn',
  }
  return map[accountType] || 'secondary'
}

function stageSeverity(stage) {
  const map = {
    [AccountStage.CHALLENGE]: 'warn',
    [AccountStage.VERIFICATION]: 'warn',
    [AccountStage.FUNDED]: 'success',
  }
  return map[stage] || 'secondary'
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <h1 class="text-2xl font-bold">{{ t('accounts.title') }}</h1>
      <Button :label="t('accounts.create')" icon="pi pi-plus" @click="openCreate" />
    </div>

    <p v-if="!store.loading && store.accounts.length === 0" class="text-gray-500">
      {{ t('accounts.empty') }}
    </p>

    <DataTable
      v-if="store.accounts.length > 0"
      :value="store.accounts"
      :loading="store.loading"
      stripedRows
      class="mt-2"
    >
      <Column field="name" :header="t('accounts.name')" />
      <Column field="account_type" :header="t('accounts.account_type')">
        <template #body="{ data }">
          <Tag :value="t(`accounts.types.${data.account_type}`)" :severity="typeSeverity(data.account_type)" />
        </template>
      </Column>
      <Column field="stage" :header="t('accounts.stage')">
        <template #body="{ data }">
          <Tag v-if="data.stage" :value="t(`accounts.stages.${data.stage}`)" :severity="stageSeverity(data.stage)" />
          <span v-else>-</span>
        </template>
      </Column>
      <Column field="currency" :header="t('accounts.currency')" />
      <Column field="initial_capital" :header="t('accounts.initial_capital')">
        <template #body="{ data }">
          {{ Number(data.initial_capital).toLocaleString() }}
        </template>
      </Column>
      <Column field="broker" :header="t('accounts.broker')" />
      <Column :header="''">
        <template #body="{ data }">
          <div class="flex gap-2">
            <Button icon="pi pi-pencil" severity="secondary" size="small" text v-tooltip.top="t('common.edit')" @click="openEdit(data)" />
            <Button icon="pi pi-trash" severity="danger" size="small" text v-tooltip.top="t('common.delete')" @click="handleDelete(data)" />
          </div>
        </template>
      </Column>
    </DataTable>

    <AccountForm
      v-model:visible="showForm"
      :account="editingAccount"
      :loading="store.loading"
      @save="handleSave"
    />
  </div>
</template>
