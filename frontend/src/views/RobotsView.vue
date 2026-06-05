<script setup>
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { useConfirm } from 'primevue/useconfirm'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import Tag from 'primevue/tag'
import Dialog from 'primevue/dialog'
import Textarea from 'primevue/textarea'
import { robotsService } from '@/services/robots'
import { useAccountsStore } from '@/stores/accounts'

const { t, locale } = useI18n()
const toast = useToast()
const confirm = useConfirm()
const accountsStore = useAccountsStore()

const robots = ref([])
const loading = ref(false)

const showCreate = ref(false)
const newName = ref('')
const newAccountId = ref(null)
const creating = ref(false)

const showCreated = ref(false)
const createdResult = ref(null)
// Re-used by both create and regenerate (the one-shot credentials modal).
const createdModalTitle = ref('')

const showDetail = ref(false)
const detail = ref(null)
const detailLoading = ref(false)
const regenerating = ref(false)

const showEvents = ref(false)
const eventsRobot = ref(null)
const events = ref([])
const eventsLoading = ref(false)

const showPayload = ref(false)
const payloadView = ref('')

const ROBOT_STATUS_SEVERITY = { ACTIVE: 'success', PAUSED: 'warn', ARCHIVED: 'contrast' }

const accountOptions = computed(() =>
  accountsStore.accounts.map((a) => ({ label: a.name, value: a.id })),
)

const canCreate = computed(() => newName.value.trim() && newAccountId.value)

function formatDate(value) {
  if (!value) return t('robot.never')
  return new Date(value.replace(' ', 'T')).toLocaleString(locale.value)
}

async function load() {
  loading.value = true
  try {
    const resp = await robotsService.list()
    robots.value = resp.data ?? []
  } catch (err) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: t(err?.messageKey ?? 'error.internal'), life: 4000 })
  } finally {
    loading.value = false
  }
}

function openCreate() {
  newName.value = ''
  newAccountId.value = null
  showCreate.value = true
}

async function createRobot() {
  if (!canCreate.value) return
  creating.value = true
  try {
    const resp = await robotsService.create({ name: newName.value.trim(), accountId: newAccountId.value })
    createdResult.value = resp.data
    createdModalTitle.value = t('robot.created.title')
    showCreate.value = false
    showCreated.value = true
    toast.add({ severity: 'success', summary: t('robot.success.created'), life: 3000 })
    await load()
  } catch (err) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: t(err?.messageKey ?? 'error.internal'), life: 4000 })
  } finally {
    creating.value = false
  }
}

async function openDetail(robot) {
  showDetail.value = true
  detailLoading.value = true
  detail.value = null
  try {
    const resp = await robotsService.get(robot.id)
    detail.value = resp.data
  } catch (err) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: t(err?.messageKey ?? 'error.internal'), life: 4000 })
    showDetail.value = false
  } finally {
    detailLoading.value = false
  }
}

function askRegenerate() {
  confirm.require({
    message: t('robot.regenerate_confirm'),
    header: detail.value?.robot?.name,
    icon: 'pi pi-exclamation-triangle',
    rejectProps: { label: t('common.cancel'), severity: 'secondary', outlined: true },
    acceptProps: { label: t('robot.actions.regenerate'), severity: 'warn' },
    accept: regenerate,
  })
}

async function regenerate() {
  const robotId = detail.value?.robot?.id
  if (!robotId) return
  regenerating.value = true
  try {
    const resp = await robotsService.regenerate(robotId)
    createdResult.value = resp.data
    createdModalTitle.value = t('robot.created.title_regenerated')
    showDetail.value = false
    showCreated.value = true
    toast.add({ severity: 'success', summary: t('robot.success.regenerated'), life: 3000 })
  } catch (err) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: t(err?.messageKey ?? 'error.internal'), life: 4000 })
  } finally {
    regenerating.value = false
  }
}

async function toggleStatus(robot) {
  const next = robot.status === 'ACTIVE' ? 'PAUSED' : 'ACTIVE'
  try {
    await robotsService.setStatus(robot.id, next)
    toast.add({ severity: 'success', summary: t(next === 'PAUSED' ? 'robot.success.paused' : 'robot.success.resumed'), life: 2500 })
    await load()
  } catch (err) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: t(err?.messageKey ?? 'error.internal'), life: 4000 })
  }
}

function askArchive(robot) {
  confirm.require({
    message: t('robot.archive_confirm'),
    header: robot.name,
    icon: 'pi pi-exclamation-triangle',
    rejectProps: { label: t('common.cancel'), severity: 'secondary', outlined: true },
    acceptProps: { label: t('robot.actions.archive'), severity: 'danger' },
    accept: () => archive(robot),
  })
}

async function archive(robot) {
  try {
    await robotsService.archive(robot.id)
    toast.add({ severity: 'info', summary: t('robot.success.archived'), life: 2500 })
    await load()
  } catch (err) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: t(err?.messageKey ?? 'error.internal'), life: 4000 })
  }
}

async function openEvents(robot) {
  eventsRobot.value = robot
  showEvents.value = true
  eventsLoading.value = true
  try {
    const resp = await robotsService.events(robot.id)
    events.value = resp.data ?? []
  } catch {
    events.value = []
  } finally {
    eventsLoading.value = false
  }
}

function eventStatusSeverity(status) {
  return ({ PROCESSED: 'success', REJECTED: 'danger', FAILED: 'danger', DUPLICATE: 'warn', RECEIVED: 'info' })[status] ?? 'info'
}

function viewPayload(raw) {
  try {
    payloadView.value = JSON.stringify(typeof raw === 'string' ? JSON.parse(raw) : raw, null, 2)
  } catch {
    payloadView.value = String(raw)
  }
  showPayload.value = true
}

function templateString(template) {
  return JSON.stringify(template, null, 2)
}

// Ordered [{ action, json }] from a { OPEN, MODIFY, CLOSE, CANCEL } map, so the
// UI can show one ready-to-paste block per TradingView alert.
const TEMPLATE_ORDER = ['OPEN', 'MODIFY', 'CLOSE', 'CANCEL']
function templateList(templates) {
  if (!templates) return []
  return TEMPLATE_ORDER
    .filter((a) => templates[a])
    .map((a) => ({ action: a, json: templateString(templates[a]) }))
}

async function copy(text, key) {
  try {
    await navigator.clipboard.writeText(text)
    toast.add({ severity: 'success', summary: t(key), life: 1500 })
  } catch {
    toast.add({ severity: 'warn', summary: t('common.error'), life: 1500 })
  }
}

onMounted(async () => {
  if (!accountsStore.loaded) await accountsStore.fetchAccounts()
  await load()
})
</script>

<template>
  <div class="max-w-5xl mx-auto">
    <div class="flex items-center justify-between mb-4">
      <p class="text-sm text-gray-500 dark:text-gray-400">{{ t('robot.subtitle') }}</p>
      <Button icon="pi pi-plus" :label="t('robot.new')" data-testid="new-robot-button" @click="openCreate" />
    </div>

    <div
      v-if="!loading && robots.length === 0"
      class="text-center py-16 text-gray-400"
      data-testid="robots-empty"
    >
      <i class="pi pi-android text-4xl mb-3 block"></i>
      <p>{{ t('robot.empty') }}</p>
    </div>

    <DataTable
      v-else
      :value="robots"
      :loading="loading"
      data-key="id"
      row-hover
      stripedRows
      data-testid="robots-table"
    >
      <Column field="name" :header="t('robot.field.name')">
        <template #body="{ data }"><span class="font-medium">{{ data.name }}</span></template>
      </Column>
      <Column field="account_name" :header="t('robot.field.account')" />
      <Column field="status" :header="t('robot.field.status')">
        <template #body="{ data }">
          <Tag :value="t(`robot.status.${data.status}`)" :severity="ROBOT_STATUS_SEVERITY[data.status]" />
        </template>
      </Column>
      <Column field="last_triggered_at" :header="t('robot.field.last_triggered')">
        <template #body="{ data }">{{ formatDate(data.last_triggered_at) }}</template>
      </Column>
      <Column :header="t('robot.field.counters')">
        <template #body="{ data }">
          <span class="text-xs">
            {{ t('robot.triggered_count', { count: data.total_triggered }) }} ·
            {{ t('robot.errors_count', { count: data.total_errors }) }}
          </span>
        </template>
      </Column>
      <Column :header="t('common.actions')">
        <template #body="{ data }">
          <div class="flex gap-1">
            <Button
              icon="pi pi-cog"
              size="small"
              text
              v-tooltip.top="t('robot.actions.details')"
              data-testid="robot-details"
              @click="openDetail(data)"
            />
            <Button
              icon="pi pi-list"
              size="small"
              text
              v-tooltip.top="t('robot.actions.events')"
              data-testid="robot-events"
              @click="openEvents(data)"
            />
            <Button
              :icon="data.status === 'ACTIVE' ? 'pi pi-pause' : 'pi pi-play'"
              size="small"
              text
              :severity="data.status === 'ACTIVE' ? 'warn' : 'success'"
              v-tooltip.top="t(data.status === 'ACTIVE' ? 'robot.actions.pause' : 'robot.actions.resume')"
              data-testid="robot-toggle"
              @click="toggleStatus(data)"
            />
            <Button
              icon="pi pi-trash"
              size="small"
              text
              severity="danger"
              v-tooltip.top="t('robot.actions.archive')"
              data-testid="robot-archive"
              @click="askArchive(data)"
            />
          </div>
        </template>
      </Column>
    </DataTable>

    <!-- Create dialog -->
    <Dialog v-model:visible="showCreate" :header="t('robot.new')" modal class="w-full max-w-md">
      <div class="flex flex-col gap-4">
        <div>
          <label class="block text-sm font-medium mb-1">{{ t('robot.field.name') }}</label>
          <InputText v-model="newName" class="w-full" maxlength="120" :placeholder="t('robot.name_placeholder')" data-testid="robot-name-input" />
        </div>
        <div>
          <label class="block text-sm font-medium mb-1">{{ t('robot.field.account') }}</label>
          <Select
            v-model="newAccountId"
            :options="accountOptions"
            option-label="label"
            option-value="value"
            class="w-full"
            :placeholder="t('robot.account_placeholder')"
            data-testid="robot-account-select"
          />
        </div>
      </div>
      <template #footer>
        <Button :label="t('common.cancel')" severity="secondary" @click="showCreate = false" />
        <Button :label="t('robot.create')" :loading="creating" :disabled="!canCreate" data-testid="robot-create-submit" @click="createRobot" />
      </template>
    </Dialog>

    <!-- Read-only detail (masked) + regenerate -->
    <Dialog v-model:visible="showDetail" :header="detail?.robot?.name ?? t('robot.detail.title')" modal class="w-full max-w-2xl" data-testid="robot-detail-modal">
      <div v-if="detailLoading" class="text-sm text-gray-500">…</div>
      <div v-else-if="detail" class="space-y-3">
        <p class="text-sm text-gray-600 dark:text-gray-300">{{ t('robot.detail.intro') }}</p>
        <div class="grid grid-cols-2 gap-3 text-sm">
          <div><span class="text-gray-500">{{ t('robot.field.account') }}:</span> {{ detail.robot.account_name }}</div>
          <div><span class="text-gray-500">{{ t('robot.field.status') }}:</span> {{ t(`robot.status.${detail.robot.status}`) }}</div>
        </div>
        <div>
          <label class="block text-xs font-medium mb-1">{{ t('robot.created.url_label') }}</label>
          <InputText :model-value="detail.webhook.url_masked" readonly class="w-full font-mono text-xs" data-testid="robot-detail-url" />
        </div>
        <div>
          <label class="block text-xs font-medium mb-1">{{ t('robot.created.secret_label') }}</label>
          <InputText :model-value="detail.webhook.secret_masked" readonly class="w-full font-mono text-xs" />
        </div>
        <div>
          <label class="block text-xs font-medium mb-1">{{ t('robot.created.templates_label') }}</label>
          <div v-for="tpl in templateList(detail.webhook.templates)" :key="tpl.action" class="mb-2">
            <div class="text-xs font-semibold text-gray-500">{{ t(`robot.action.${tpl.action}`) }}</div>
            <Textarea :model-value="tpl.json" readonly rows="6" class="w-full font-mono text-xs" />
          </div>
        </div>
        <p class="text-xs text-gray-400">{{ t('robot.detail.masked_note') }}</p>
      </div>
      <template #footer>
        <Button :label="t('common.close')" severity="secondary" @click="showDetail = false" />
        <Button :label="t('robot.actions.regenerate')" icon="pi pi-refresh" severity="warn" :loading="regenerating" data-testid="robot-regenerate" @click="askRegenerate" />
      </template>
    </Dialog>

    <!-- One-shot credentials modal (create + regenerate) -->
    <Dialog v-model:visible="showCreated" :header="createdModalTitle" modal class="w-full max-w-2xl" data-testid="robot-created-modal">
      <div v-if="createdResult" class="space-y-3">
        <p class="text-sm text-orange-600 dark:text-orange-400">{{ t('robot.created.warning') }}</p>
        <div>
          <label class="block text-xs font-medium mb-1">{{ t('robot.created.url_label') }}</label>
          <div class="flex gap-2">
            <InputText :model-value="createdResult.url" readonly class="flex-1 font-mono text-xs" data-testid="robot-url" />
            <Button icon="pi pi-copy" text @click="copy(createdResult.url, 'robot.created.copied')" />
          </div>
        </div>
        <div>
          <label class="block text-xs font-medium mb-1">{{ t('robot.created.secret_label') }}</label>
          <div class="flex gap-2">
            <InputText :model-value="createdResult.body_secret" readonly class="flex-1 font-mono text-xs" data-testid="robot-secret" />
            <Button icon="pi pi-copy" text @click="copy(createdResult.body_secret, 'robot.created.copied')" />
          </div>
        </div>
        <div>
          <label class="block text-xs font-medium mb-1">{{ t('robot.created.templates_label') }}</label>
          <p class="text-xs text-gray-400 mb-2">{{ t('robot.created.templates_hint') }}</p>
          <div v-for="tpl in templateList(createdResult.templates)" :key="tpl.action" class="mb-2" :data-testid="`robot-template-${tpl.action}`">
            <div class="flex items-center justify-between">
              <span class="text-xs font-semibold text-gray-500">{{ t(`robot.action.${tpl.action}`) }}</span>
              <Button icon="pi pi-copy" text size="small" :label="t('robot.created.copied')" @click="copy(tpl.json, 'robot.created.copied')" />
            </div>
            <Textarea :model-value="tpl.json" readonly rows="6" class="w-full font-mono text-xs" />
          </div>
        </div>
      </div>
      <template #footer>
        <Button :label="t('robot.created.close')" @click="showCreated = false" />
      </template>
    </Dialog>

    <!-- Events modal -->
    <Dialog v-model:visible="showEvents" :header="(eventsRobot?.name ?? '') + ' — ' + t('robot.events.title')" modal class="w-full max-w-4xl">
      <div v-if="eventsLoading" class="text-sm text-gray-500">…</div>
      <div v-else-if="events.length === 0" class="text-sm text-gray-500 italic">{{ t('robot.events.empty') }}</div>
      <DataTable v-else :value="events" size="small" striped-rows>
        <Column field="created_at" :header="t('robot.events.received_at')" />
        <Column :header="t('robot.events.status')">
          <template #body="{ data }">
            <Tag :value="t('robot.events.status_' + data.status.toLowerCase())" :severity="eventStatusSeverity(data.status)" />
          </template>
        </Column>
        <Column :header="t('robot.events.reason')">
          <template #body="{ data }">
            <span v-if="data.reject_reason" class="text-xs">{{ t('webhook.tradingview.reject_reason.' + data.reject_reason) }}</span>
            <span v-else class="text-xs text-gray-400">—</span>
          </template>
        </Column>
        <Column :header="t('robot.events.order')">
          <template #body="{ data }">
            <span v-if="data.created_order_id" class="text-xs">#{{ data.created_order_id }}</span>
            <span v-else class="text-xs text-gray-400">—</span>
          </template>
        </Column>
        <Column>
          <template #body="{ data }">
            <Button size="small" text :label="t('robot.events.view_payload')" @click="viewPayload(data.payload_raw)" />
          </template>
        </Column>
      </DataTable>
    </Dialog>

    <Dialog v-model:visible="showPayload" header="Payload" modal class="w-full max-w-2xl">
      <pre class="text-xs whitespace-pre-wrap font-mono">{{ payloadView }}</pre>
    </Dialog>
  </div>
</template>
