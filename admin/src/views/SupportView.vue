<script setup>
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useSupportStore } from '@/stores/support'
import AdminLayout from '@/components/AdminLayout.vue'
import AdminTicketDialog from '@/components/AdminTicketDialog.vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import Button from 'primevue/button'
import Tag from 'primevue/tag'

const STATUS_SEVERITY = {
  OPEN: 'info', IN_PROGRESS: 'warn', WAITING_USER: 'secondary', RESOLVED: 'success', CLOSED: 'contrast',
}
const PRIORITY_SEVERITY = { LOW: 'secondary', NORMAL: 'info', HIGH: 'danger' }

const { t } = useI18n()
const store = useSupportStore()

const search = ref('')
const type = ref(null)
const status = ref(null)
const priority = ref(null)

const showDetail = ref(false)
const selectedId = ref(null)

const typeOptions = [
  { label: t('support.filter.all'), value: null },
  { label: t('support.type.SUPPORT'), value: 'SUPPORT' },
  { label: t('support.type.BUG'), value: 'BUG' },
  { label: t('support.type.FEATURE'), value: 'FEATURE' },
]
const statusOptions = [
  { label: t('support.filter.all'), value: null },
  ...['OPEN', 'IN_PROGRESS', 'WAITING_USER', 'RESOLVED', 'CLOSED'].map((v) => ({ label: t(`support.status.${v}`), value: v })),
]
const priorityOptions = [
  { label: t('support.filter.all'), value: null },
  ...['LOW', 'NORMAL', 'HIGH'].map((v) => ({ label: t(`support.priority.${v}`), value: v })),
]

onMounted(load)

async function load() {
  store.setFilters({
    search: search.value || undefined,
    type: type.value || undefined,
    status: status.value || undefined,
    priority: priority.value || undefined,
  })
  await store.fetchTickets()
}

function openDetail(row) {
  selectedId.value = row.id
  showDetail.value = true
}
</script>

<template>
  <AdminLayout>
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-xl font-bold">{{ t('support.title') }}</h2>
    </div>

    <div class="flex flex-wrap gap-3 mb-4">
      <InputText v-model="search" :placeholder="t('support.search')" class="w-72" @keyup.enter="load" />
      <Select v-model="type" :options="typeOptions" option-label="label" option-value="value" class="w-44" @change="load" />
      <Select v-model="status" :options="statusOptions" option-label="label" option-value="value" class="w-52" @change="load" />
      <Select v-model="priority" :options="priorityOptions" option-label="label" option-value="value" class="w-40" @change="load" />
      <Button icon="pi pi-search" :label="t('common.search')" @click="load" />
    </div>

    <DataTable :value="store.tickets" :loading="store.loading" row-hover stripedRows data-testid="admin-support-table">
      <Column field="id" header="#" class="w-16" />
      <Column field="user_email" :header="t('support.columns.user')" />
      <Column field="type" :header="t('support.field.type')">
        <template #body="{ data }">{{ t(`support.type.${data.type}`) }}</template>
      </Column>
      <Column field="subject" :header="t('support.field.subject')" />
      <Column field="status" :header="t('support.field.status')">
        <template #body="{ data }">
          <Tag :value="t(`support.status.${data.status}`)" :severity="STATUS_SEVERITY[data.status]" />
        </template>
      </Column>
      <Column field="priority" :header="t('support.field.priority')">
        <template #body="{ data }">
          <Tag :value="t(`support.priority.${data.priority}`)" :severity="PRIORITY_SEVERITY[data.priority]" />
        </template>
      </Column>
      <Column field="message_count" :header="t('support.columns.messages')" class="w-24" />
      <Column field="updated_at" :header="t('support.field.updated')">
        <template #body="{ data }">{{ data.updated_at ? new Date(data.updated_at.replace(' ', 'T')).toLocaleString() : '-' }}</template>
      </Column>
      <Column :header="t('common.actions')">
        <template #body="{ data }">
          <Button
            icon="pi pi-comments"
            severity="info"
            size="small"
            text
            v-tooltip.top="t('support.actions.open')"
            data-testid="open-ticket"
            @click="openDetail(data)"
          />
        </template>
      </Column>
    </DataTable>

    <AdminTicketDialog v-model:visible="showDetail" :ticket-id="selectedId" @updated="load" />
  </AdminLayout>
</template>
