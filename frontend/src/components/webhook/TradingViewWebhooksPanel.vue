<script setup>
import { ref, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { useConfirm } from 'primevue/useconfirm'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Tag from 'primevue/tag'
import Dialog from 'primevue/dialog'
import Textarea from 'primevue/textarea'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import { webhooksService } from '@/services/webhooks'

const { t } = useI18n()
const toast = useToast()
const confirm = useConfirm()

const props = defineProps({
  account: { type: Object, required: true },
})

const webhooks = ref([])
const loading = ref(false)
const creating = ref(false)
const newName = ref('')
const showCreatedModal = ref(false)
const createdResult = ref(null)
const showEventsModal = ref(false)
const eventsWebhook = ref(null)
const events = ref([])
const eventsLoading = ref(false)
const showPayloadModal = ref(false)
const payloadView = ref('')

async function loadWebhooks() {
  loading.value = true
  try {
    const resp = await webhooksService.list(props.account.id)
    webhooks.value = resp.data ?? []
  } catch (err) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: t(err?.messageKey ?? 'error.internal'), life: 4000 })
  } finally {
    loading.value = false
  }
}

async function createWebhook() {
  if (!newName.value.trim()) return
  creating.value = true
  try {
    const resp = await webhooksService.create(props.account.id, newName.value.trim())
    createdResult.value = resp.data
    showCreatedModal.value = true
    newName.value = ''
    toast.add({ severity: 'success', summary: t('webhook.success.created'), life: 3000 })
    await loadWebhooks()
  } catch (err) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: t(err?.messageKey ?? 'error.internal'), life: 4000 })
  } finally {
    creating.value = false
  }
}

function askRevoke(webhook) {
  confirm.require({
    message: t('webhook.tradingview.actions.revoke') + ' ?',
    header: webhook.name,
    icon: 'pi pi-exclamation-triangle',
    rejectProps: { label: t('common.cancel'), severity: 'secondary', outlined: true },
    acceptProps: { label: t('webhook.tradingview.actions.revoke'), severity: 'danger' },
    accept: () => revoke(webhook),
  })
}

async function revoke(webhook) {
  try {
    await webhooksService.revoke(props.account.id, webhook.id)
    toast.add({ severity: 'info', summary: t('webhook.success.revoked'), life: 3000 })
    await loadWebhooks()
  } catch (err) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: t(err?.messageKey ?? 'error.internal'), life: 4000 })
  }
}

async function openEvents(webhook) {
  eventsWebhook.value = webhook
  showEventsModal.value = true
  eventsLoading.value = true
  try {
    const resp = await webhooksService.events(props.account.id, webhook.id)
    events.value = resp.data ?? []
  } catch {
    events.value = []
  } finally {
    eventsLoading.value = false
  }
}

async function copy(text, key) {
  try {
    await navigator.clipboard.writeText(text)
    toast.add({ severity: 'success', summary: t(key), life: 1500 })
  } catch {
    toast.add({ severity: 'warn', summary: t('common.error'), life: 1500 })
  }
}

function formatTemplate(template) {
  return JSON.stringify(template, null, 2)
}

function statusSeverity(status) {
  return ({
    PROCESSED: 'success',
    REJECTED: 'danger',
    FAILED: 'danger',
    DUPLICATE: 'warn',
    RECEIVED: 'info',
  })[status] ?? 'info'
}

function showPayload(raw) {
  try {
    payloadView.value = JSON.stringify(typeof raw === 'string' ? JSON.parse(raw) : raw, null, 2)
  } catch {
    payloadView.value = String(raw)
  }
  showPayloadModal.value = true
}

onMounted(loadWebhooks)
</script>

<template>
  <div class="space-y-4">
    <p class="text-sm text-gray-600 dark:text-gray-300">{{ t('webhook.tradingview.intro') }}</p>

    <div class="flex gap-2 items-end">
      <div class="flex-1">
        <label class="block text-xs font-medium mb-1">{{ t('webhook.tradingview.name_label') }}</label>
        <InputText
          v-model="newName"
          :placeholder="t('webhook.tradingview.name_placeholder')"
          class="w-full"
          data-testid="webhook-name-input"
        />
      </div>
      <Button
        :label="t('webhook.tradingview.create')"
        icon="pi pi-plus"
        :loading="creating"
        :disabled="!newName.trim()"
        @click="createWebhook"
        data-testid="webhook-create-btn"
      />
    </div>

    <div v-if="loading" class="text-sm text-gray-500">…</div>
    <div v-else-if="webhooks.length === 0" class="text-sm text-gray-500 italic">
      {{ t('webhook.tradingview.no_webhooks') }}
    </div>
    <ul v-else class="space-y-2" data-testid="webhooks-list">
      <li
        v-for="wh in webhooks"
        :key="wh.id"
        class="border rounded p-3 flex items-center justify-between gap-3"
      >
        <div class="flex-1 min-w-0">
          <div class="flex items-center gap-2">
            <span class="font-medium truncate">{{ wh.name }}</span>
            <Tag
              :value="wh.status === 'ACTIVE' ? t('webhook.tradingview.status_active') : t('webhook.tradingview.status_revoked')"
              :severity="wh.status === 'ACTIVE' ? 'success' : 'warn'"
            />
          </div>
          <div class="text-xs text-gray-500 mt-1">
            {{ t('webhook.tradingview.last_triggered') }}:
            {{ wh.last_triggered_at ?? t('webhook.tradingview.never') }} —
            {{ t('webhook.tradingview.triggered_count', { count: wh.total_triggered }) }},
            {{ t('webhook.tradingview.errors_count', { count: wh.total_errors }) }}
          </div>
        </div>
        <div class="flex gap-2">
          <Button
            text
            size="small"
            icon="pi pi-list"
            :label="t('webhook.tradingview.actions.events')"
            @click="openEvents(wh)"
          />
          <Button
            v-if="wh.status === 'ACTIVE'"
            text
            size="small"
            severity="danger"
            icon="pi pi-times"
            :label="t('webhook.tradingview.actions.revoke')"
            @click="askRevoke(wh)"
          />
        </div>
      </li>
    </ul>

    <!-- Created modal (one-shot) -->
    <Dialog
      v-model:visible="showCreatedModal"
      :header="t('webhook.tradingview.created_modal.title')"
      modal
      class="w-full max-w-2xl"
      data-testid="webhook-created-modal"
    >
      <div v-if="createdResult" class="space-y-3">
        <p class="text-sm text-warn">{{ t('webhook.tradingview.created_modal.warning') }}</p>
        <div>
          <label class="block text-xs font-medium mb-1">{{ t('webhook.tradingview.created_modal.url_label') }}</label>
          <div class="flex gap-2">
            <InputText :model-value="createdResult.url" readonly class="flex-1 font-mono text-xs" data-testid="webhook-url" />
            <Button icon="pi pi-copy" text @click="copy(createdResult.url, 'webhook.tradingview.actions.copy_url')" />
          </div>
        </div>
        <div>
          <label class="block text-xs font-medium mb-1">{{ t('webhook.tradingview.created_modal.secret_label') }}</label>
          <div class="flex gap-2">
            <InputText :model-value="createdResult.body_secret" readonly class="flex-1 font-mono text-xs" data-testid="webhook-secret" />
            <Button icon="pi pi-copy" text @click="copy(createdResult.body_secret, 'webhook.tradingview.actions.copy_secret')" />
          </div>
        </div>
        <div>
          <label class="block text-xs font-medium mb-1">{{ t('webhook.tradingview.created_modal.template_label') }}</label>
          <div class="flex gap-2">
            <Textarea :model-value="formatTemplate(createdResult.template)" readonly rows="14" class="flex-1 font-mono text-xs" data-testid="webhook-template" />
            <Button icon="pi pi-copy" text @click="copy(formatTemplate(createdResult.template), 'webhook.tradingview.actions.copy_template')" />
          </div>
        </div>
      </div>
      <template #footer>
        <Button :label="t('webhook.tradingview.created_modal.close')" @click="showCreatedModal = false" />
      </template>
    </Dialog>

    <!-- Events modal -->
    <Dialog
      v-model:visible="showEventsModal"
      :header="eventsWebhook?.name + ' — ' + t('webhook.tradingview.events.title')"
      modal
      class="w-full max-w-4xl"
    >
      <div v-if="eventsLoading" class="text-sm text-gray-500">…</div>
      <div v-else-if="events.length === 0" class="text-sm text-gray-500 italic">
        {{ t('webhook.tradingview.events.empty') }}
      </div>
      <DataTable v-else :value="events" size="small" striped-rows>
        <Column :header="t('webhook.tradingview.events.received_at')" field="created_at" />
        <Column :header="t('webhook.tradingview.events.status')">
          <template #body="{ data }">
            <Tag :value="t('webhook.tradingview.events.status_' + data.status.toLowerCase())" :severity="statusSeverity(data.status)" />
          </template>
        </Column>
        <Column :header="t('webhook.tradingview.events.reason')">
          <template #body="{ data }">
            <span v-if="data.reject_reason" class="text-xs">{{ t('webhook.tradingview.reject_reason.' + data.reject_reason) }}</span>
            <span v-else class="text-xs text-gray-400">—</span>
          </template>
        </Column>
        <Column :header="t('webhook.tradingview.events.order')">
          <template #body="{ data }">
            <span v-if="data.created_order_id" class="text-xs">#{{ data.created_order_id }}</span>
            <span v-else class="text-xs text-gray-400">—</span>
          </template>
        </Column>
        <Column>
          <template #body="{ data }">
            <Button size="small" text :label="t('webhook.tradingview.events.view_payload')" @click="showPayload(data.payload_raw)" />
          </template>
        </Column>
      </DataTable>
    </Dialog>

    <Dialog v-model:visible="showPayloadModal" header="Payload" modal class="w-full max-w-2xl">
      <pre class="text-xs whitespace-pre-wrap font-mono">{{ payloadView }}</pre>
    </Dialog>
  </div>
</template>
