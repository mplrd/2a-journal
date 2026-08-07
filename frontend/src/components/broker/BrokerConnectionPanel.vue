<script setup>
import { ref, onMounted, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { brokerSyncService } from '@/services/brokerSync'
import { CtraderEnvironment } from '@/constants/enums'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import Message from 'primevue/message'
import CtraderConnectDialog from './CtraderConnectDialog.vue'
import MetaApiConnectDialog from './MetaApiConnectDialog.vue'
import OuinexConnectDialog from './OuinexConnectDialog.vue'
import BingxConnectDialog from './BingxConnectDialog.vue'
import SyncHistoryDialog from './SyncHistoryDialog.vue'

const { t } = useI18n()
const toast = useToast()

const props = defineProps({
  account: { type: Object, required: true },
})

const emit = defineEmits(['synced'])

const connection = ref(null)
const loading = ref(false)
const syncing = ref(false)
const syncResult = ref(null)
const showCtraderDialog = ref(false)
const showMetaApiDialog = ref(false)
const showOuinexDialog = ref(false)
const showBingxDialog = ref(false)
const showHistory = ref(false)
// Set while a dialog is open in reconfigure mode; null means create mode.
const editingConnection = ref(null)

const isConnected = computed(() => connection.value && connection.value.status === 'ACTIVE')
const isBroken = computed(() => connection.value && connection.value.status !== 'ACTIVE')

const PROVIDER_LABELS = {
  CTRADER: 'cTrader',
  METAAPI: 'MetaApi (MT4/MT5)',
  OUINEX: 'Ouinex',
  BINGX: 'BingX',
}

const DIALOG_FLAGS = {
  CTRADER: showCtraderDialog,
  METAAPI: showMetaApiDialog,
  OUINEX: showOuinexDialog,
  BINGX: showBingxDialog,
}

const providerLabel = computed(() => {
  if (!connection.value) return ''
  return PROVIDER_LABELS[connection.value.provider] || connection.value.provider
})

/** cTrader connections carry which server they talk to — worth showing. */
const environmentLabel = computed(() => {
  const env = connection.value?.credentials_public?.environment
  if (!env) return null
  return env === CtraderEnvironment.DEMO ? t('broker.ctrader_env_demo') : t('broker.ctrader_env_live')
})

const statusSeverity = computed(() => {
  const s = connection.value?.status
  if (s === 'ACTIVE') return 'success'
  if (s === 'ERROR') return 'danger'
  if (s === 'REVOKED') return 'warn'
  return 'info'
})

onMounted(async () => {
  await loadConnection()
})

async function loadConnection() {
  loading.value = true
  try {
    const resp = await brokerSyncService.getConnection(props.account.id)
    connection.value = resp.data
  } catch {
    connection.value = null
  } finally {
    loading.value = false
  }
}

async function doSync() {
  if (!connection.value) return
  syncing.value = true
  syncResult.value = null
  try {
    const resp = await brokerSyncService.sync(connection.value.id)

    // The scheduled run — or another tab — already holds this connection. No
    // work was done, so no import recap: "success, 0 positions" would read as
    // "the broker had nothing new", which is a different thing entirely.
    if (resp.data?.status === 'SKIPPED') {
      toast.add({
        severity: 'info',
        summary: t('broker.sync_already_running'),
        detail: t('broker.sync_already_running_detail'),
        life: 5000,
      })
      return
    }

    syncResult.value = resp.data
    toast.add({ severity: 'success', summary: t('broker.sync_success'), detail: t('broker.sync_detail', { count: resp.data.imported_positions }), life: 5000 })
    emit('synced')
    await loadConnection()
  } catch (err) {
    toast.add({ severity: 'error', summary: t('broker.sync_failed'), detail: err.messageKey ? t(err.messageKey) : err.message, life: 5000 })
  } finally {
    syncing.value = false
  }
}

async function disconnect() {
  if (!connection.value) return
  try {
    await brokerSyncService.deleteConnection(connection.value.id)
    connection.value = null
    syncResult.value = null
    toast.add({ severity: 'info', summary: t('broker.disconnected'), life: 3000 })
  } catch (err) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: err.message, life: 3000 })
  }
}

/** Open the current provider's dialog prefilled with the existing connection. */
function reconfigure() {
  if (!connection.value) return
  const flag = DIALOG_FLAGS[connection.value.provider]
  if (!flag) return
  editingConnection.value = connection.value
  flag.value = true
}

function openConnectDialog(provider) {
  editingConnection.value = null
  DIALOG_FLAGS[provider].value = true
}

/**
 * Saving is deliberately never blocked on the credential test (a broker
 * outage must not stop the user from correcting a secret), so this toast is
 * the only signal that the credentials just saved are still refused — and it
 * repeats the broker's own wording, which names the offending field.
 */
function onConnected(provider, result) {
  DIALOG_FLAGS[provider].value = false
  editingConnection.value = null

  const test = result?.connection_test
  if (test && test.success === false) {
    toast.add({
      severity: 'warn',
      summary: t('broker.credentials_saved_test_failed'),
      detail: test.error || t('broker.credentials_test_failed_generic'),
      life: 10000,
    })
  } else if (test?.success) {
    toast.add({ severity: 'success', summary: t('broker.credentials_saved_test_ok'), life: 4000 })
  }

  loadConnection()
}
</script>

<template>
  <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4">
    <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3">{{ t('broker.connection') }}</h4>

    <!-- Loading -->
    <div v-if="loading" class="text-sm text-gray-400">{{ t('common.loading') }}...</div>

    <!-- Connected -->
    <div v-else-if="isConnected" class="space-y-3">
      <div class="flex items-center gap-3 flex-wrap">
        <Tag :value="providerLabel" :severity="statusSeverity" />
        <Tag v-if="environmentLabel" :value="environmentLabel" severity="info" />
        <span v-if="connection.last_sync_at" class="text-xs text-gray-400">
          {{ t('broker.last_sync') }}: {{ new Date(connection.last_sync_at).toLocaleString() }}
        </span>
      </div>

      <div v-if="connection.last_sync_status === 'FAILED'" class="text-xs text-red-500 break-words">
        {{ connection.last_sync_error }}
      </div>

      <!-- Sync result -->
      <Message v-if="syncResult" severity="success" :closable="true" class="text-sm">
        {{ t('broker.sync_imported', { positions: syncResult.imported_positions, skipped: syncResult.skipped_duplicates }) }}
      </Message>

      <div class="flex gap-2 flex-wrap">
        <Button :label="t('broker.sync_now')" icon="pi pi-refresh" size="small" data-testid="broker-sync" :loading="syncing" @click="doSync" />
        <Button :label="t('broker.reconfigure')" icon="pi pi-pencil" size="small" severity="secondary" data-testid="broker-reconfigure" @click="reconfigure" />
        <Button :label="t('broker.history')" icon="pi pi-list" size="small" severity="secondary" text data-testid="broker-history" @click="showHistory = true" />
        <Button :label="t('broker.disconnect')" icon="pi pi-times" size="small" severity="danger" text data-testid="broker-disconnect" @click="disconnect" />
      </div>
    </div>

    <!-- Connection exists but not ACTIVE (ERROR / REVOKED / PENDING). Sync
         refuses anything but ACTIVE, so without a reconfigure path the only
         way out of a stale credential was deleting the connection — which
         drops its sync cursor and its whole log history. Reconfiguring
         replaces the credentials in place and resets the status. -->
    <div v-else-if="isBroken" class="space-y-3">
      <div class="flex items-center gap-3 flex-wrap">
        <Tag :value="providerLabel" severity="warn" />
        <Tag :value="connection.status" :severity="statusSeverity" />
        <Tag v-if="environmentLabel" :value="environmentLabel" severity="info" />
        <span v-if="connection.last_sync_at" class="text-xs text-gray-400">
          {{ t('broker.last_sync') }}: {{ new Date(connection.last_sync_at).toLocaleString() }}
        </span>
      </div>

      <div v-if="connection.last_sync_error" class="text-xs text-red-500 break-words">
        {{ connection.last_sync_error }}
      </div>

      <p class="text-sm text-gray-500">{{ t('broker.broken_help') }}</p>

      <div class="flex gap-2 flex-wrap">
        <Button :label="t('broker.reconfigure')" icon="pi pi-pencil" size="small" data-testid="broker-reconfigure" @click="reconfigure" />
        <Button :label="t('broker.history')" icon="pi pi-list" size="small" severity="secondary" text data-testid="broker-history" @click="showHistory = true" />
        <Button :label="t('broker.disconnect')" icon="pi pi-trash" size="small" severity="danger" text data-testid="broker-disconnect" @click="disconnect" />
      </div>
    </div>

    <!-- Not connected -->
    <div v-else class="space-y-4">
      <p class="text-sm text-gray-500">{{ t('broker.not_connected') }}</p>

      <div>
        <h5 class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-2">{{ t('broker.section_platforms') }}</h5>
        <div class="flex flex-wrap gap-2">
          <Button :label="t('broker.connect_metaapi')" icon="pi pi-link" size="small" @click="openConnectDialog('METAAPI')" />
          <Button :label="t('broker.connect_ctrader')" icon="pi pi-link" size="small" @click="openConnectDialog('CTRADER')" />
        </div>
      </div>

      <div>
        <h5 class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-2">{{ t('broker.section_brokers') }}</h5>
        <div class="flex flex-wrap gap-2">
          <Button :label="t('broker.connect_bingx')" icon="pi pi-link" size="small" severity="secondary" @click="openConnectDialog('BINGX')" />
          <Button :label="t('broker.connect_ouinex')" icon="pi pi-link" size="small" severity="secondary" @click="openConnectDialog('OUINEX')" />
        </div>
      </div>
    </div>

    <!-- Dialogs -->
    <CtraderConnectDialog
      v-model:visible="showCtraderDialog"
      :account="account"
      :connection="editingConnection"
      @connected="onConnected('CTRADER', $event)"
    />

    <MetaApiConnectDialog
      v-model:visible="showMetaApiDialog"
      :account="account"
      :connection="editingConnection"
      @connected="onConnected('METAAPI', $event)"
    />

    <OuinexConnectDialog
      v-model:visible="showOuinexDialog"
      :account="account"
      :connection="editingConnection"
      @connected="onConnected('OUINEX', $event)"
    />

    <BingxConnectDialog
      v-model:visible="showBingxDialog"
      :account="account"
      :connection="editingConnection"
      @connected="onConnected('BINGX', $event)"
    />

    <SyncHistoryDialog
      v-if="connection"
      v-model:visible="showHistory"
      :connection="connection"
    />
  </div>
</template>
