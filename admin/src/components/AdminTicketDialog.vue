<script setup>
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import Dialog from 'primevue/dialog'
import Select from 'primevue/select'
import Tag from 'primevue/tag'
import Textarea from 'primevue/textarea'
import Button from 'primevue/button'
import Message from 'primevue/message'
import { useSupportStore } from '@/stores/support'
import { supportService } from '@/services/support'

const TICKET_STATUS = ['OPEN', 'IN_PROGRESS', 'WAITING_USER', 'RESOLVED', 'CLOSED']
const TICKET_PRIORITY = ['LOW', 'NORMAL', 'HIGH']
const STATUS_SEVERITY = {
  OPEN: 'info', IN_PROGRESS: 'warn', WAITING_USER: 'secondary', RESOLVED: 'success', CLOSED: 'contrast',
}
const PRIORITY_SEVERITY = { LOW: 'secondary', NORMAL: 'info', HIGH: 'danger' }

const { t, locale } = useI18n()
const toast = useToast()
const store = useSupportStore()

const props = defineProps({
  visible: Boolean,
  ticketId: { type: [Number, String], default: null },
})
const emit = defineEmits(['update:visible', 'updated'])

const replyBody = ref('')
const files = ref([])
const submitting = ref(false)
const clientError = ref(null)
const fileInput = ref(null)
const statusModel = ref(null)
const priorityModel = ref(null)

const ticket = computed(() => store.current)

// Structured fields the user filled at creation (bug / feature). Read-only.
const detailEntries = computed(() => {
  const d = ticket.value?.details
  if (!d || typeof d !== 'object') return []
  return Object.entries(d)
    .filter(([, value]) => value)
    .map(([key, value]) => ({ key, label: t(`support.field.detail.${key}`), value }))
})

const statusOptions = computed(() => TICKET_STATUS.map((value) => ({ value, label: t(`support.status.${value}`) })))
const priorityOptions = computed(() => TICKET_PRIORITY.map((value) => ({ value, label: t(`support.priority.${value}`) })))

watch(
  () => [props.visible, props.ticketId],
  async ([visible, id]) => {
    if (visible && id) {
      replyBody.value = ''
      files.value = []
      clientError.value = null
      try {
        await store.fetchTicket(id)
        statusModel.value = ticket.value?.status
        priorityModel.value = ticket.value?.priority
      } catch {
        toast.add({ severity: 'error', summary: t('error.internal'), detail: t('support.error.not_found'), life: 4000 })
        emit('update:visible', false)
      }
    }
  },
  { immediate: true },
)

function formatDate(value) {
  if (!value) return ''
  return new Date(value.replace(' ', 'T')).toLocaleString(locale.value)
}

function authorName(message) {
  if (message.author_is_admin == 1) return t('support.support_team')
  const name = [message.author_first_name, message.author_last_name].filter(Boolean).join(' ')
  return name || ticket.value?.user_email || t('support.user')
}

async function applyStatus() {
  try {
    await store.updateStatus(ticket.value.id, statusModel.value)
    toast.add({ severity: 'success', summary: t('common.confirm'), detail: t('support.toast.status_updated'), life: 3000 })
    emit('updated')
  } catch (err) {
    toast.add({ severity: 'error', summary: t('error.internal'), detail: t(err.messageKey || 'error.internal'), life: 5000 })
  }
}

async function applyPriority() {
  try {
    await store.updatePriority(ticket.value.id, priorityModel.value)
    toast.add({ severity: 'success', summary: t('common.confirm'), detail: t('support.toast.priority_updated'), life: 3000 })
    emit('updated')
  } catch (err) {
    toast.add({ severity: 'error', summary: t('error.internal'), detail: t(err.messageKey || 'error.internal'), life: 5000 })
  }
}

async function openAttachment(attachment) {
  try {
    const url = await supportService.attachmentUrl(ticket.value.id, attachment.id)
    window.open(url, '_blank', 'noopener,noreferrer')
    setTimeout(() => URL.revokeObjectURL(url), 60000)
  } catch {
    toast.add({ severity: 'error', summary: t('error.internal'), detail: t('error.internal'), life: 4000 })
  }
}

function pickFiles() {
  fileInput.value?.click()
}

function onFilesSelected(event) {
  for (const file of Array.from(event.target.files || [])) {
    files.value.push(file)
  }
  event.target.value = ''
}

function removeFile(index) {
  files.value.splice(index, 1)
}

async function sendReply() {
  if (!replyBody.value.trim()) return
  submitting.value = true
  clientError.value = null
  try {
    await store.reply(ticket.value.id, { body: replyBody.value.trim(), attachments: files.value })
    replyBody.value = ''
    files.value = []
    emit('updated')
  } catch (err) {
    clientError.value = t(err.messageKey || 'error.internal')
  } finally {
    submitting.value = false
  }
}

function handleClose() {
  emit('update:visible', false)
}
</script>

<template>
  <Dialog
    :visible="visible"
    :modal="true"
    :closable="true"
    :style="{ width: '720px' }"
    :header="ticket ? `#${ticket.id} — ${ticket.subject}` : t('support.title')"
    @update:visible="handleClose"
  >
    <div v-if="ticket" class="flex flex-col gap-4" data-testid="admin-ticket-detail">
      <!-- Header meta -->
      <div class="flex flex-wrap items-center gap-3 text-sm">
        <Tag :value="t(`support.type.${ticket.type}`)" severity="secondary" />
        <span class="text-gray-500">{{ ticket.user_email }}</span>
      </div>

      <!-- Status / priority controls -->
      <div class="flex flex-wrap gap-4">
        <div class="flex items-center gap-2">
          <label class="text-sm text-gray-600 dark:text-gray-300">{{ t('support.field.status') }}</label>
          <Select
            v-model="statusModel"
            :options="statusOptions"
            option-label="label"
            option-value="value"
            class="w-52"
            data-testid="admin-status-select"
            @change="applyStatus"
          />
          <Tag :value="t(`support.status.${ticket.status}`)" :severity="STATUS_SEVERITY[ticket.status]" />
        </div>
        <div class="flex items-center gap-2">
          <label class="text-sm text-gray-600 dark:text-gray-300">{{ t('support.field.priority') }}</label>
          <Select
            v-model="priorityModel"
            :options="priorityOptions"
            option-label="label"
            option-value="value"
            class="w-40"
            data-testid="admin-priority-select"
            @change="applyPriority"
          />
          <Tag :value="t(`support.priority.${ticket.priority}`)" :severity="PRIORITY_SEVERITY[ticket.priority]" />
        </div>
      </div>

      <!-- Structured details captured at creation (bug / feature) -->
      <dl
        v-if="detailEntries.length"
        class="rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/40 p-3 text-sm flex flex-col gap-2"
        data-testid="admin-ticket-details"
      >
        <div v-for="entry in detailEntries" :key="entry.key">
          <dt class="font-semibold text-gray-600 dark:text-gray-300">{{ entry.label }}</dt>
          <dd class="whitespace-pre-wrap text-gray-700 dark:text-gray-300">{{ entry.value }}</dd>
        </div>
      </dl>

      <!-- Thread -->
      <div class="flex flex-col gap-3 max-h-[40vh] overflow-y-auto pr-1" data-testid="admin-ticket-thread">
        <div
          v-for="message in ticket.messages"
          :key="message.id"
          class="rounded-lg px-3 py-2 text-sm border"
          :class="(message.author_is_admin == 1)
            ? 'bg-brand-green-50 dark:bg-brand-green-700/20 border-brand-green-200 dark:border-brand-green-700/40'
            : 'bg-gray-50 dark:bg-gray-700/40 border-gray-200 dark:border-gray-700'"
        >
          <div class="flex items-center justify-between mb-1">
            <span class="font-semibold">{{ authorName(message) }}</span>
            <span class="text-xs text-gray-400">{{ formatDate(message.created_at) }}</span>
          </div>
          <p class="whitespace-pre-wrap text-gray-700 dark:text-gray-300">{{ message.body }}</p>
          <div v-if="message.attachments?.length" class="flex flex-wrap gap-2 mt-2">
            <button
              v-for="att in message.attachments"
              :key="att.id"
              type="button"
              class="flex items-center gap-1 text-xs text-brand-green-700 dark:text-brand-green-300 underline cursor-pointer"
              @click="openAttachment(att)"
            >
              <i class="pi pi-paperclip"></i>{{ att.original_name }}
            </button>
          </div>
        </div>
      </div>

      <!-- Reply -->
      <div class="border-t border-gray-200 dark:border-gray-700 pt-3">
        <Textarea
          v-model="replyBody"
          class="w-full"
          rows="3"
          auto-resize
          :placeholder="t('support.reply_placeholder')"
          data-testid="admin-reply-body"
        />
        <input ref="fileInput" type="file" accept="image/jpeg,image/png,image/webp" multiple class="hidden" @change="onFilesSelected" />
        <ul v-if="files.length" class="mt-2 flex flex-col gap-1">
          <li v-for="(file, index) in files" :key="index" class="flex items-center justify-between text-xs bg-gray-50 dark:bg-gray-700/40 rounded px-2 py-1">
            <span class="truncate">{{ file.name }}</span>
            <button type="button" class="text-red-500 cursor-pointer" @click="removeFile(index)"><i class="pi pi-times"></i></button>
          </li>
        </ul>
        <Message v-if="clientError" severity="error" :closable="false" class="mt-2">{{ clientError }}</Message>
        <div class="flex justify-between items-center mt-2">
          <Button type="button" icon="pi pi-paperclip" :label="t('support.field.add_attachment')" severity="secondary" size="small" text @click="pickFiles" />
          <Button
            type="button"
            :label="t('support.send_reply')"
            icon="pi pi-send"
            :loading="submitting"
            :disabled="!replyBody.trim()"
            data-testid="admin-submit-reply"
            @click="sendReply"
          />
        </div>
      </div>
    </div>
  </Dialog>
</template>
