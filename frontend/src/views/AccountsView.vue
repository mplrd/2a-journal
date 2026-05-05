<script setup>
import { onMounted, ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { useToast } from 'primevue/usetoast'
import { useConfirm } from 'primevue/useconfirm'
import { useAccountsStore } from '@/stores/accounts'
import { useFeaturesStore } from '@/stores/features'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import Dialog from 'primevue/dialog'
import Menu from 'primevue/menu'
import AccountForm from '@/components/account/AccountForm.vue'
import ImportDialog from '@/components/import/ImportDialog.vue'
import BrokerConnectionPanel from '@/components/broker/BrokerConnectionPanel.vue'
import { AccountType, AccountStage } from '@/constants/enums'
import { useOnboarding } from '@/composables/useOnboarding'
import FloatingActionButton from '@/components/common/FloatingActionButton.vue'
import TileList from '@/components/common/TileList.vue'
import EmptyState from '@/components/common/EmptyState.vue'
import { useLayout } from '@/composables/useIsMobile'

const { t } = useI18n()
const { isMobile, isCompact } = useLayout()
const router = useRouter()
const { isOnboarding, currentStep, completeOnboarding } = useOnboarding()
const toast = useToast()
const confirm = useConfirm()
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

function handleDelete(account) {
  confirm.require({
    message: t('accounts.confirm_delete'),
    header: t('common.confirm'),
    icon: 'pi pi-exclamation-triangle',
    rejectProps: { label: t('common.cancel'), severity: 'secondary', outlined: true },
    acceptProps: { label: t('common.delete'), severity: 'danger' },
    accept: async () => {
      try {
        await store.deleteAccount(account.id)
        toast.add({ severity: 'success', summary: t('common.success'), detail: t('accounts.success.deleted'), life: 3000 })
      } catch (err) {
        toast.add({ severity: 'error', summary: t('common.error'), detail: t(err.messageKey || 'error.internal'), life: 5000 })
      }
    },
  })
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

function balanceClass(account) {
  const current = Number(account.current_capital)
  const initial = Number(account.initial_capital)
  if (current > initial) return 'text-success dark:text-brand-green-400 font-medium font-mono tabular-nums'
  if (current < initial) return 'text-danger dark:text-danger-fg-dark font-medium font-mono tabular-nums'
  return ''
}

function balanceVariation(account) {
  const current = Number(account.current_capital)
  const initial = Number(account.initial_capital)
  if (!initial) return null
  const pct = ((current - initial) / initial) * 100
  const sign = pct > 0 ? '+' : ''
  return `${sign}${pct.toFixed(2)}%`
}

// Single shared popup menu hosting "edit / delete" — keeps the visible
// per-tile action row short.
const actionMenu = ref(null)
const menuAccount = ref(null)
const actionMenuItems = computed(() => {
  if (!menuAccount.value) return []
  return [
    {
      label: t('common.edit'),
      icon: 'pi pi-pencil',
      command: () => openEdit(menuAccount.value),
    },
    {
      label: t('common.delete'),
      icon: 'pi pi-trash',
      class: 'text-danger',
      command: () => handleDelete(menuAccount.value),
    },
  ]
})
function openActionMenu(event, account) {
  menuAccount.value = account
  actionMenu.value.toggle(event)
}
</script>

<template>
  <div>
    <div
      v-if="isOnboarding && currentStep === 'accounts'"
      class="mb-4 p-4 bg-info-bg dark:bg-info/20 border border-info/30 dark:border-info/40 rounded-lg text-info dark:text-info-bg"
      data-testid="onboarding-banner"
    >
      {{ t('onboarding.welcome') }}
    </div>

    <div v-if="!isMobile" class="flex items-center justify-end mb-4">
      <Button :label="t('accounts.create')" icon="pi pi-plus" @click="openCreate" />
    </div>

    <FloatingActionButton
      icon="plus"
      :aria-label="t('accounts.create')"
      @click="openCreate"
    />

    <EmptyState
      v-if="!store.loading && store.accounts.length === 0"
      icon="pi pi-wallet"
      :title="t('accounts.empty_title')"
      :description="t('accounts.empty')"
    >
      <Button :label="t('accounts.create')" icon="pi pi-plus" @click="openCreate" />
    </EmptyState>

    <DataTable
      v-else-if="!isMobile && store.accounts.length > 0"
      :value="store.accounts"
      :loading="store.loading"
      :size="isCompact ? 'small' : undefined"
      stripedRows
      class="mt-2"
    >
      <Column field="name" :header="t('accounts.name')" />
      <Column field="account_type" :header="t('accounts.account_type')">
        <template #body="{ data }">
          <div class="flex items-center gap-1 flex-wrap">
            <Tag :value="t(`accounts.types.${data.account_type}`)" :severity="typeSeverity(data.account_type)" />
            <Tag v-if="data.stage" :value="t(`accounts.stages.${data.stage}`)" :severity="stageSeverity(data.stage)" />
          </div>
        </template>
      </Column>
      <Column v-if="!isCompact" field="currency" :header="t('accounts.currency')" />
      <Column v-if="!isCompact" field="initial_capital" :header="t('accounts.initial_capital')">
        <template #body="{ data }">
          <span class="font-mono tabular-nums">{{ Number(data.initial_capital).toLocaleString() }}</span>
        </template>
      </Column>
      <Column field="current_capital" :header="t('accounts.balance')">
        <template #body="{ data }">
          <span :class="balanceClass(data)">
            {{ Number(data.current_capital).toLocaleString() }}
            <span v-if="balanceVariation(data) !== null" class="text-xs ml-1">
              ({{ balanceVariation(data) }})
            </span>
          </span>
        </template>
      </Column>
      <Column v-if="!isCompact" field="broker" :header="t('accounts.broker')" />
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

    <!-- Mobile: tile list with the same fields + corner actions. -->
    <TileList
      v-else-if="isMobile && store.accounts.length > 0"
      :items="store.accounts"
      :loading="store.loading"
      class="mt-2"
    >
      <template #default="{ item }">
        <div class="p-4 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800" :data-testid="`account-tile-${item.id}`">
          <!-- Tile header: name (truncated) on the left, actions on a single
               row top-right. Edit/delete tucked behind a popup menu so the
               visible row stays at 3 buttons max (sync, import, more). -->
          <div class="flex items-start justify-between gap-2">
            <div class="font-semibold text-gray-900 dark:text-gray-100 truncate min-w-0">{{ item.name }}</div>
            <div class="flex items-center gap-1 shrink-0">
              <Button v-if="features.brokerAutoSync" icon="pi pi-sync" severity="success" size="small" text rounded :aria-label="t('broker.sync_now')" @click="openBrokerSync(item)" />
              <Button icon="pi pi-upload" severity="info" size="small" text rounded :aria-label="t('import.title')" @click="openImport(item)" />
              <Button icon="pi pi-ellipsis-v" severity="secondary" size="small" text rounded :aria-label="t('common.more')" @click="openActionMenu($event, item)" />
            </div>
          </div>
          <div class="mt-2 flex items-center gap-1 flex-wrap">
            <Tag :value="t(`accounts.types.${item.account_type}`)" :severity="typeSeverity(item.account_type)" />
            <Tag v-if="item.stage" :value="t(`accounts.stages.${item.stage}`)" :severity="stageSeverity(item.stage)" />
            <span v-if="item.broker" class="text-xs text-gray-500 dark:text-gray-400 ml-1">{{ item.broker }}</span>
          </div>
          <div class="mt-3 grid grid-cols-2 gap-x-3 text-sm">
            <div>
              <div class="text-xs text-gray-500 dark:text-gray-400">{{ t('accounts.initial_capital') }}</div>
              <div class="font-mono tabular-nums">{{ Number(item.initial_capital).toLocaleString() }} {{ item.currency }}</div>
            </div>
            <div>
              <div class="text-xs text-gray-500 dark:text-gray-400">{{ t('accounts.balance') }}</div>
              <div :class="balanceClass(item)" class="font-mono tabular-nums">
                {{ Number(item.current_capital).toLocaleString() }}
                <span v-if="balanceVariation(item) !== null" class="text-xs ml-1">({{ balanceVariation(item) }})</span>
              </div>
            </div>
          </div>
        </div>
      </template>
    </TileList>

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

    <!-- Shared popup menu for the per-tile "more" button (edit/delete). -->
    <Menu ref="actionMenu" :model="actionMenuItems" :popup="true" />
  </div>
</template>
