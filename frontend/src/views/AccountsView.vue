<script setup>
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAccountsStore } from '@/stores/accounts'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import AccountForm from '@/components/account/AccountForm.vue'

const { t } = useI18n()
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
    } else {
      await store.createAccount(data)
    }
    showForm.value = false
  } catch {
    // error is set in the store
  }
}

async function handleDelete(account) {
  if (confirm(t('accounts.confirm_delete'))) {
    await store.deleteAccount(account.id)
  }
}

function modeSeverity(mode) {
  const map = { DEMO: 'info', LIVE: 'success', CHALLENGE: 'warn', VERIFICATION: 'warn', FUNDED: 'success' }
  return map[mode] || 'secondary'
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
          {{ t(`accounts.types.${data.account_type}`) }}
        </template>
      </Column>
      <Column field="mode" :header="t('accounts.mode')">
        <template #body="{ data }">
          <Tag :value="t(`accounts.modes.${data.mode}`)" :severity="modeSeverity(data.mode)" />
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
            <Button icon="pi pi-pencil" severity="secondary" size="small" text @click="openEdit(data)" />
            <Button icon="pi pi-trash" severity="danger" size="small" text @click="handleDelete(data)" />
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
