<script setup>
import { ref, onMounted, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { brokerSyncService } from '@/services/brokerSync'
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

const isConnected = computed(() => connection.value && connection.value.status === 'ACTIVE')
const isBroken = computed(() => connection.value && connection.value.status !== 'ACTIVE')

const PROVIDER_LABELS = {
  CTRADER: 'cTrader',
  METAAPI: 'MetaApi (MT4/MT5)',
  OUINEX: 'Ouinex',
  BINGX: 'BingX',
}

const providerLabel = computed(() => {
  if (!connection.value) return ''
  return PROVIDER_LABELS[connection.value.provider] || connection.value.provider
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

function onCtraderConnected() {
  showCtraderDialog.value = false
  loadConnection()
}

function onMetaApiConnected() {
  showMetaApiDialog.value = false
  loadConnection()
}

function onOuinexConnected() {
  showOuinexDialog.value = false
  loadConnection()
}

function onBingxConnected() {
  showBingxDialog.value = false
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
      <div class="flex items-center gap-3">
        <Tag :value="providerLabel" :severity="statusSeverity" />
        <span v-if="connection.last_sync_at" class="text-xs text-gray-400">
          {{ t('broker.last_sync') }}: {{ new Date(connection.last_sync_at).toLocaleString() }}
        </span>
      </div>

      <div v-if="connection.last_sync_status === 'FAILED'" class="text-xs text-red-500">
        {{ connection.last_sync_error }}
      </div>

      <!-- Sync result -->
      <Message v-if="syncResult" severity="success" :closable="true" class="text-sm">
        {{ t('broker.sync_imported', { positions: syncResult.imported_positions, skipped: syncResult.skipped_duplicates }) }}
      </Message>

      <div class="flex gap-2">
        <Button :label="t('broker.sync_now')" icon="pi pi-refresh" size="small" :loading="syncing" @click="doSync" />
        <Button :label="t('broker.history')" icon="pi pi-list" size="small" severity="secondary" text @click="showHistory = true" />
        <Button :label="t('broker.disconnect')" icon="pi pi-times" size="small" severity="danger" text @click="disconnect" />
      </div>
    </div>

    <!-- Connection exists but not ACTIVE (ERROR / REVOKED / PENDING). The
         backend refuses to create a fresh connection while a row exists for
         this account, so we surface the broken row explicitly with its last
         error and a Delete button — otherwise the user is stuck in a
         cul-de-sac (connect form visible but every submit returns
         already_connected). -->
    <div v-else-if="isBroken" class="space-y-3">
      <div class="flex items-center gap-3 flex-wrap">
        <Tag :value="providerLabel" severity="warn" />
        <Tag :value="connection.status" :severity="statusSeverity" />
        <span v-if="connection.last_sync_at" class="text-xs text-gray-400">
          {{ t('broker.last_sync') }}: {{ new Date(connection.last_sync_at).toLocaleString() }}
        </span>
      </div>

      <div v-if="connection.last_sync_error" class="text-xs text-red-500 break-words">
        {{ connection.last_sync_error }}
      </div>

      <p class="text-sm text-gray-500">{{ t('broker.disabled_help') }}</p>

      <div class="flex gap-2">
        <Button :label="t('broker.history')" icon="pi pi-list" size="small" severity="secondary" text @click="showHistory = true" />
        <Button :label="t('broker.disconnect')" icon="pi pi-trash" size="small" severity="danger" @click="disconnect" />
      </div>
    </div>

    <!-- Not connected -->
    <div v-else class="space-y-4">
      <p class="text-sm text-gray-500">{{ t('broker.not_connected') }}</p>

      <div>
        <h5 class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-2">{{ t('broker.section_platforms') }}</h5>
        <div class="flex flex-wrap gap-2">
          <Button :label="t('broker.connect_metaapi')" icon="pi pi-link" size="small" @click="showMetaApiDialog = true" />
          <Button :label="t('broker.connect_ctrader')" icon="pi pi-link" size="small" @click="showCtraderDialog = true" />
        </div>
      </div>

      <div>
        <h5 class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-2">{{ t('broker.section_brokers') }}</h5>
        <div class="flex flex-wrap gap-2">
          <Button :label="t('broker.connect_bingx')" icon="pi pi-link" size="small" severity="secondary" @click="showBingxDialog = true" />
          <Button :label="t('broker.connect_ouinex')" icon="pi pi-link" size="small" severity="secondary" @click="showOuinexDialog = true" />
        </div>
      </div>
    </div>

    <!-- Dialogs -->
    <CtraderConnectDialog
      v-model:visible="showCtraderDialog"
      :account="account"
      @connected="onCtraderConnected"
    />

    <MetaApiConnectDialog
      v-model:visible="showMetaApiDialog"
      :account="account"
      @connected="onMetaApiConnected"
    />

    <OuinexConnectDialog
      v-model:visible="showOuinexDialog"
      :account="account"
      @connected="onOuinexConnected"
    />

    <BingxConnectDialog
      v-model:visible="showBingxDialog"
      :account="account"
      @connected="onBingxConnected"
    />

    <SyncHistoryDialog
      v-if="connection"
      v-model:visible="showHistory"
      :connection="connection"
    />
  </div>
</template>
