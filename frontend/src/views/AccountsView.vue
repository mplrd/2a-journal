<script setup>
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { useToast } from 'primevue/usetoast'
import { useAccountsStore } from '@/stores/accounts'
import { useFeaturesStore } from '@/stores/features'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import Dialog from 'primevue/dialog'
import AccountForm from '@/components/account/AccountForm.vue'
import ImportDialog from '@/components/import/ImportDialog.vue'
import BrokerConnectionPanel from '@/components/broker/BrokerConnectionPanel.vue'
import { AccountType, AccountStage } from '@/constants/enums'
import { useOnboarding } from '@/composables/useOnboarding'

const { t } = useI18n()
const router = useRouter()
const { isOnboarding, currentStep, completeOnboarding } = useOnboarding()
const toast = useToast()
const store = useAccountsStore()
const features = useFeaturesStore()

const showForm = ref(false)
const editingAccount = ref(null)
const showOnboardingChoice = ref(false)
const showImport = ref(false)
const importAccount = ref(null)
const showBrokerSync = ref(false)
const brokerSyncAccount = ref(null)

function openImport(account) {
  importAccount.value = account
  showImport.value = true
}

function openBrokerSync(account) {
  brokerSyncAccount.value = account
  showBrokerSync.value = true
}

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
    const wasFirstAccount = isOnboarding.value && store.accounts.length === 0
    if (editingAccount.value) {
      await store.updateAccount(editingAccount.value.id, data)
      toast.add({ severity: 'success', summary: t('common.success'), detail: t('accounts.success.updated'), life: 3000 })
    } else {
      await store.createAccount(data)
      toast.add({ severity: 'success', summary: t('common.success'), detail: t('accounts.success.created'), life: 3000 })
    }
    showForm.value = false

    if (wasFirstAccount) {
      showOnboardingChoice.value = true
    }
  } catch {
    // error is set in the store
  }
}

function handleConfigureAssets() {
  showOnboardingChoice.value = false
  router.push({ name: 'account', query: { tab: 'assets' } })
}

async function handleStartNow() {
  showOnboardingChoice.value = false
  await completeOnboarding()
  router.push({ name: 'dashboard' })
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
    <div
      v-if="isOnboarding && currentStep === 'accounts'"
      class="mb-4 p-4 bg-blue-50 dark:bg-blue-900/30 border border-blue-200 dark:border-blue-800 rounded-lg text-blue-800 dark:text-blue-200"
      data-testid="onboarding-banner"
    >
      {{ t('onboarding.welcome') }}
    </div>

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
            <Button v-if="features.brokerAutoSync" icon="pi pi-sync" severity="success" size="small" text v-tooltip.top="t('broker.sync_now')" @click="openBrokerSync(data)" />
            <Button icon="pi pi-upload" severity="info" size="small" text v-tooltip.top="t('import.title')" @click="openImport(data)" />
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

    <ImportDialog
      v-model:visible="showImport"
      :account="importAccount"
    />

    <Dialog v-if="features.brokerAutoSync" v-model:visible="showBrokerSync" :header="t('broker.connection')" modal class="w-full max-w-lg">
      <BrokerConnectionPanel
        v-if="brokerSyncAccount"
        :account="brokerSyncAccount"
        @synced="store.fetchAccounts()"
      />
    </Dialog>

    <!-- Onboarding choice dialog after first account creation -->
    <Dialog
      v-model:visible="showOnboardingChoice"
      :header="t('onboarding.choice_title')"
      modal
      :closable="false"
      :style="{ width: '450px' }"
      data-testid="onboarding-choice-dialog"
    >
      <p class="mb-4">{{ t('onboarding.choice_description') }}</p>
      <div class="flex flex-col gap-2">
        <Button
          :label="t('onboarding.configure_assets')"
          icon="pi pi-cog"
          @click="handleConfigureAssets"
          data-testid="configure-assets-btn"
        />
        <Button
          :label="t('onboarding.start_now')"
          icon="pi pi-play"
          severity="secondary"
          @click="handleStartNow"
          data-testid="start-now-btn"
        />
      </div>
    </Dialog>
  </div>
</template>
