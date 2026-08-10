<script setup>
import { ref, onMounted, onUnmounted, computed } from 'vue'
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
// The user's stored app credentials per provider (docs/91), fetched when a
// connect dialog is opened. Only useful in create mode: a connection already
// carries its own view of them.
const sharedCredentials = ref({})

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

onUnmounted(stopPolling)

/**
 * `silent` skips the loading flag: a poll must not blank the panel every few
 * seconds while the user is watching the run.
 */
async function loadConnection({ silent = false } = {}) {
  if (!silent) loading.value = true
  try {
    const resp = await brokerSyncService.getConnection(props.account.id)
    connection.value = resp.data
  } catch {
    if (!silent) connection.value = null
  } finally {
    if (!silent) loading.value = false
  }
}

/**
 * The sync is queued server-side and run by the scheduler, which ticks every
 * minute. Nothing is known about the import when the request returns, so the
 * panel watches the connection's state instead of the response.
 */
const POLL_INTERVAL_MS = 4000
/** Past this, stop watching and say so — the scheduler may simply be off. */
const POLL_TIMEOUT_MS = 5 * 60 * 1000

let pollTimer = null

const syncPending = computed(() => Boolean(connection.value?.sync_requested_at))
const syncRunning = computed(() => Boolean(connection.value?.syncing_since))

function stopPolling() {
  if (pollTimer) {
    clearTimeout(pollTimer)
    pollTimer = null
  }
}

async function doSync() {
  if (!connection.value) return
  syncing.value = true
  syncResult.value = null
  try {
    const resp = await brokerSyncService.sync(connection.value.id)
    toast.add({
      severity: 'info',
      summary: t('broker.sync_queued'),
      // A run already in flight took its reservation before this request was
      // recorded, so it will not swallow it — this one runs right after.
      detail: resp.data?.syncing ? t('broker.sync_already_running_detail') : t('broker.sync_queued_detail'),
      life: 4000,
    })
    await loadConnection({ silent: true })
    schedulePoll(Date.now() + POLL_TIMEOUT_MS)
  } catch (err) {
    syncing.value = false
    toast.add({ severity: 'error', summary: t('broker.sync_failed'), detail: err.messageKey ? t(err.messageKey) : err.message, life: 5000 })
  }
}

function schedulePoll(deadline) {
  stopPolling()
  pollTimer = setTimeout(() => pollOnce(deadline), POLL_INTERVAL_MS)
}

async function pollOnce(deadline) {
  pollTimer = null
  await loadConnection({ silent: true })

  if (syncPending.value || syncRunning.value) {
    if (Date.now() >= deadline) {
      syncing.value = false
      toast.add({ severity: 'warn', summary: t('broker.sync_still_running'), detail: t('broker.sync_still_running_detail'), life: 8000 })
      return
    }
    schedulePoll(deadline)
    return
  }

  await reportFinishedSync()
}

/**
 * The run is over. Its outcome lives on the connection, and the counts on the
 * sync log it just wrote — the response to the click could not carry either.
 */
async function reportFinishedSync() {
  syncing.value = false
  emit('synced')

  if (connection.value?.last_sync_status === 'FAILED') {
    toast.add({
      severity: 'error',
      summary: t('broker.sync_failed'),
      detail: connection.value.last_sync_error || t('common.error'),
      life: 8000,
    })
    return
  }

  const log = await lastSyncLog()
  if (log) {
    syncResult.value = {
      imported_positions: log.deals_imported ?? 0,
      skipped_duplicates: log.deals_skipped ?? 0,
    }
  }

  toast.add({
    severity: 'success',
    summary: t('broker.sync_success'),
    detail: t('broker.sync_detail', { count: syncResult.value?.imported_positions ?? 0 }),
    life: 5000,
  })
}

/** Logs come back newest first; a missing recap must not lose the toast. */
async function lastSyncLog() {
  try {
    const resp = await brokerSyncService.getSyncLogs(connection.value.id)
    return resp.data?.[0] ?? null
  } catch {
    return null
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

/**
 * Fetched here rather than on mount: this panel is rendered once per account,
 * and most sessions never open a connect dialog at all — one request per
 * account for a prefill nobody asked for.
 *
 * A failure is swallowed on purpose. Prefilling is a convenience; losing it
 * must never cost the user the ability to connect an account by hand.
 */
async function loadSharedCredentials() {
  try {
    const resp = await brokerSyncService.getSharedCredentials()
    sharedCredentials.value = resp.data || {}
  } catch {
    sharedCredentials.value = {}
  }
}

async function openConnectDialog(provider) {
  editingConnection.value = null
  await loadSharedCredentials()
  DIALOG_FLAGS[provider].value = true
}

/**
 * What a *create* dialog prefills from. Null while reconfiguring: the
 * connection already carries its own credential view and sharing count, and
 * feeding both would leave the dialog with two sources of truth.
 */
const sharedFor = (provider) =>
  editingConnection.value ? null : (sharedCredentials.value[provider] ?? null)

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
        <!-- The run happens server-side now, so this is the only place the user
             can see it is under way. -->
        <span v-if="syncRunning" class="text-xs text-blue-500" data-testid="broker-sync-state">
          <i class="pi pi-spin pi-spinner mr-1" />{{ t('broker.sync_already_running') }}
        </span>
        <span v-else-if="syncPending" class="text-xs text-blue-500" data-testid="broker-sync-state">
          <i class="pi pi-clock mr-1" />{{ t('broker.sync_pending') }}
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
          <Button :label="t('broker.connect_metaapi')" icon="pi pi-link" size="small" data-testid="broker-connect-metaapi" @click="openConnectDialog('METAAPI')" />
          <Button :label="t('broker.connect_ctrader')" icon="pi pi-link" size="small" data-testid="broker-connect-ctrader" @click="openConnectDialog('CTRADER')" />
        </div>
      </div>

      <div>
        <h5 class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-2">{{ t('broker.section_brokers') }}</h5>
        <div class="flex flex-wrap gap-2">
          <Button :label="t('broker.connect_bingx')" icon="pi pi-link" size="small" severity="secondary" data-testid="broker-connect-bingx" @click="openConnectDialog('BINGX')" />
          <Button :label="t('broker.connect_ouinex')" icon="pi pi-link" size="small" severity="secondary" data-testid="broker-connect-ouinex" @click="openConnectDialog('OUINEX')" />
        </div>
      </div>
    </div>

    <!-- Dialogs -->
    <CtraderConnectDialog
      v-model:visible="showCtraderDialog"
      :account="account"
      :connection="editingConnection"
      :shared="sharedFor('CTRADER')"
      @connected="onConnected('CTRADER', $event)"
    />

    <MetaApiConnectDialog
      v-model:visible="showMetaApiDialog"
      :account="account"
      :connection="editingConnection"
      :shared="sharedFor('METAAPI')"
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
