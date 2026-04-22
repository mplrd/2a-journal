<script setup>
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { brokerSyncService } from '@/services/brokerSync'
import Dialog from 'primevue/dialog'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Tag from 'primevue/tag'

const { t } = useI18n()

const props = defineProps({
  visible: { type: Boolean, default: false },
  connection: { type: Object, required: true },
})

defineEmits(['update:visible'])

const logs = ref([])
const loading = ref(false)

watch(() => props.visible, async (val) => {
  if (val && props.connection) {
    loading.value = true
    try {
      const resp = await brokerSyncService.getSyncLogs(props.connection.id)
      logs.value = resp.data
    } catch {
      logs.value = []
    } finally {
      loading.value = false
    }
  }
})

function statusSeverity(status) {
  if (status === 'SUCCESS') return 'success'
  if (status === 'FAILED') return 'danger'
  if (status === 'PARTIAL') return 'warn'
  return 'info'
}
</script>

<template>
  <Dialog
    :visible="visible"
    @update:visible="$emit('update:visible', $event)"
    :header="t('broker.sync_history')"
    modal
    class="w-full max-w-2xl"
  >
    <DataTable :value="logs" :loading="loading" size="small" stripedRows>
      <Column field="started_at" :header="t('broker.sync_date')">
        <template #body="{ data }">{{ new Date(data.started_at).toLocaleString() }}</template>
      </Column>
      <Column field="status" :header="t('broker.sync_status')">
        <template #body="{ data }"><Tag :value="data.status" :severity="statusSeverity(data.status)" /></template>
      </Column>
      <Column field="deals_fetched" :header="t('broker.deals_fetched')" />
      <Column field="deals_imported" :header="t('broker.deals_imported')" />
      <Column field="deals_skipped" :header="t('broker.deals_skipped')" />
      <Column field="error_message" :header="t('common.error')">
        <template #body="{ data }">
          <span v-if="data.error_message" class="text-xs text-red-500">{{ data.error_message }}</span>
        </template>
      </Column>
    </DataTable>
  </Dialog>
</template>
