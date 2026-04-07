<script setup>
import { ref, onMounted, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { brokerSyncService } from '@/services/brokerSync'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import Message from 'primevue/message'
import MetaApiConnectDialog from './MetaApiConnectDialog.vue'
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
const showMetaApiDialog = ref(false)
const showHistory = ref(false)

const isConnected = computed(() => connection.value && connection.value.status === 'ACTIVE')

const providerLabel = computed(() => {
  if (!connection.value) return ''
  return connection.value.provider === 'CTRADER' ? 'cTrader' : 'MetaApi (MT4/MT5)'
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

async function connectCtrader() {
  try {
    const resp = await brokerSyncService.getCtraderAuthorizeUrl(props.account.id)
    window.location.href = resp.data.authorize_url
  } catch (err) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: err.messageKey ? t(err.messageKey) : err.message, life: 5000 })
  }
}

function onMetaApiConnected() {
  showMetaApiDialog.value = false
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

    <!-- Not connected -->
    <div v-else class="space-y-3">
      <p class="text-sm text-gray-500">{{ t('broker.not_connected') }}</p>
      <div class="flex gap-2">
        <Button :label="t('broker.connect_ctrader')" icon="pi pi-link" size="small" @click="connectCtrader" />
        <Button :label="t('broker.connect_metaapi')" icon="pi pi-link" size="small" severity="secondary" @click="showMetaApiDialog = true" />
      </div>
    </div>

    <!-- Dialogs -->
    <MetaApiConnectDialog
      v-model:visible="showMetaApiDialog"
      :account="account"
      @connected="onMetaApiConnected"
    />

    <SyncHistoryDialog
      v-if="connection"
      v-model:visible="showHistory"
      :connection="connection"
    />
  </div>
</template>
