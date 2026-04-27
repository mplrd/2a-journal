<script setup>
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { useSettingsStore } from '@/stores/settings'
import AdminLayout from '@/components/AdminLayout.vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import Checkbox from 'primevue/checkbox'
import Button from 'primevue/button'
import Tag from 'primevue/tag'

const { t } = useI18n()
const toast = useToast()
const store = useSettingsStore()

// Local edit buffer keyed by setting key. Saving applies to one row at a time.
const editBuffer = ref({})

onMounted(async () => {
  await store.fetchSettings()
  // Initialize edit buffer with current values
  for (const s of store.settings) {
    editBuffer.value[s.key] = s.value
  }
})

async function save(setting) {
  const value = editBuffer.value[setting.key]
  try {
    await store.update(setting.key, value)
    toast.add({ severity: 'success', summary: t('common.confirm'), detail: t('settings.saved'), life: 3000 })
    // Re-sync the edit buffer with the fresh server values
    for (const s of store.settings) {
      editBuffer.value[s.key] = s.value
    }
  } catch (err) {
    toast.add({ severity: 'error', summary: t('error.internal'), detail: t(err.messageKey || 'error.internal'), life: 5000 })
  }
}

function sourceSeverity(source) {
  return source === 'db' ? 'success' : (source === 'env' ? 'info' : 'secondary')
}
</script>

<template>
  <AdminLayout>
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-xl font-bold">{{ t('settings.title') }}</h2>
    </div>

    <DataTable :value="store.settings" :loading="store.loading" stripedRows>
      <Column field="key" :header="t('settings.key')">
        <template #body="{ data }">
          <div class="font-mono text-sm">{{ data.key }}</div>
          <div class="text-xs text-gray-500">{{ t(data.description) }}</div>
        </template>
      </Column>
      <Column field="type" :header="t('settings.type')">
        <template #body="{ data }">
          <Tag :value="data.type" severity="secondary" />
        </template>
      </Column>
      <Column :header="t('settings.value')">
        <template #body="{ data }">
          <Checkbox v-if="data.type === 'BOOL'" v-model="editBuffer[data.key]" :binary="true" />
          <InputNumber v-else-if="data.type === 'INT'" v-model="editBuffer[data.key]" class="w-32" />
          <InputText v-else v-model="editBuffer[data.key]" class="w-72" />
        </template>
      </Column>
      <Column field="source" :header="t('settings.source')">
        <template #body="{ data }">
          <Tag :value="t(`settings.sources.${data.source}`)" :severity="sourceSeverity(data.source)" />
        </template>
      </Column>
      <Column :header="t('common.actions')">
        <template #body="{ data }">
          <Button :label="t('settings.save')" icon="pi pi-save" size="small" @click="save(data)" />
        </template>
      </Column>
    </DataTable>
  </AdminLayout>
</template>
