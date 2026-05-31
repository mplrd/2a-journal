<script setup>
import { ref, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import { useSupportStore } from '@/stores/support'
import {
  TICKET_STATUS_SEVERITY,
  TICKET_PRIORITY_SEVERITY,
  TICKET_TYPE_ICON,
} from '@/constants/support'
import NewTicketDialog from '@/components/support/NewTicketDialog.vue'
import TicketDetailDialog from '@/components/support/TicketDetailDialog.vue'

const { t, locale } = useI18n()
const route = useRoute()
const router = useRouter()
const store = useSupportStore()

const showNew = ref(false)
const showDetail = ref(false)
const selectedId = ref(null)

function formatDate(value) {
  if (!value) return ''
  return new Date(value.replace(' ', 'T')).toLocaleDateString(locale.value)
}

async function load() {
  await store.fetchTickets()
}

function onPage(event) {
  store.page = event.page + 1
  store.perPage = event.rows
  load()
}

function openDetail(id) {
  selectedId.value = id
  showDetail.value = true
}

function onRowClick(event) {
  openDetail(event.data.id)
}

async function onCreated() {
  await load()
}

onMounted(async () => {
  await load()
  // Deep link from notification emails: /support?ticket=ID
  const ticketId = route.query.ticket
  if (ticketId) {
    openDetail(Number(ticketId))
    router.replace({ query: {} })
  }
})
</script>

<template>
  <div class="max-w-5xl mx-auto">
    <div class="flex items-center justify-between mb-4">
      <p class="text-sm text-gray-500 dark:text-gray-400">{{ t('support.subtitle') }}</p>
      <Button
        icon="pi pi-plus"
        :label="t('support.new_request')"
        data-testid="new-ticket-button"
        @click="showNew = true"
      />
    </div>

    <div
      v-if="!store.loading && store.tickets.length === 0"
      class="text-center py-16 text-gray-400"
      data-testid="support-empty"
    >
      <i class="pi pi-inbox text-4xl mb-3 block"></i>
      <p>{{ t('support.empty') }}</p>
    </div>

    <DataTable
      v-else
      :value="store.tickets"
      :loading="store.loading"
      lazy
      paginator
      :rows="store.perPage"
      :total-records="store.totalRecords"
      :first="(store.page - 1) * store.perPage"
      :rows-per-page-options="[10, 20, 50]"
      data-key="id"
      row-hover
      stripedRows
      class="cursor-pointer"
      data-testid="support-table"
      @page="onPage"
      @row-click="onRowClick"
    >
      <Column field="type" :header="t('support.field.type')">
        <template #body="{ data }">
          <span class="inline-flex items-center gap-1">
            <i :class="TICKET_TYPE_ICON[data.type]"></i>
            {{ t(`support.type.${data.type}`) }}
          </span>
        </template>
      </Column>
      <Column field="subject" :header="t('support.field.subject')">
        <template #body="{ data }">
          <span class="font-medium">#{{ data.id }}</span> · {{ data.subject }}
        </template>
      </Column>
      <Column field="status" :header="t('support.field.status')">
        <template #body="{ data }">
          <Tag :value="t(`support.status.${data.status}`)" :severity="TICKET_STATUS_SEVERITY[data.status]" />
        </template>
      </Column>
      <Column field="priority" :header="t('support.field.priority')">
        <template #body="{ data }">
          <Tag :value="t(`support.priority.${data.priority}`)" :severity="TICKET_PRIORITY_SEVERITY[data.priority]" />
        </template>
      </Column>
      <Column field="updated_at" :header="t('support.field.updated')">
        <template #body="{ data }">{{ formatDate(data.updated_at) }}</template>
      </Column>
    </DataTable>

    <NewTicketDialog v-model:visible="showNew" @created="onCreated" />
    <TicketDetailDialog v-model:visible="showDetail" :ticket-id="selectedId" @updated="load" />
  </div>
</template>
