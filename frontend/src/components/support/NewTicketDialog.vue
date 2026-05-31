<script setup>
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import Dialog from 'primevue/dialog'
import Select from 'primevue/select'
import InputText from 'primevue/inputtext'
import Textarea from 'primevue/textarea'
import Button from 'primevue/button'
import Message from 'primevue/message'
import { useSupportStore } from '@/stores/support'
import { TicketType } from '@/constants/support'
import { compressImageIfNeeded } from '@/utils/imageCompression'

const ACCEPTED_TYPES = ['image/jpeg', 'image/png', 'image/webp']
const MAX_FILES = 5
const MAX_SIZE = 5 * 1024 * 1024

const { t } = useI18n()
const toast = useToast()
const supportStore = useSupportStore()

const props = defineProps({ visible: Boolean })
const emit = defineEmits(['update:visible', 'created'])

const form = ref({ type: TicketType.SUPPORT, subject: '', body: '' })
const files = ref([])
const submitting = ref(false)
const clientError = ref(null)
const fileInput = ref(null)

const typeOptions = computed(() =>
  Object.values(TicketType).map((value) => ({ value, label: t(`support.type.${value}`) })),
)

const canSubmit = computed(
  () => form.value.type && form.value.subject.trim() && form.value.body.trim(),
)

watch(
  () => props.visible,
  (val) => {
    if (val) {
      form.value = { type: TicketType.SUPPORT, subject: '', body: '' }
      files.value = []
      clientError.value = null
    }
  },
)

function pickFiles() {
  fileInput.value?.click()
}

async function onFilesSelected(event) {
  clientError.value = null
  const selected = Array.from(event.target.files || [])
  for (const original of selected) {
    if (!ACCEPTED_TYPES.includes(original.type)) {
      clientError.value = t('support.error.attachment_type')
      continue
    }
    if (files.value.length >= MAX_FILES) {
      clientError.value = t('support.error.too_many_attachments')
      break
    }
    // Shrink heavy phone photos before they ever leave the browser.
    const file = await compressImageIfNeeded(original)
    if (file.size > MAX_SIZE) {
      clientError.value = t('support.error.attachment_too_large')
      continue
    }
    files.value.push(file)
  }
  event.target.value = ''
}

function removeFile(index) {
  files.value.splice(index, 1)
}

async function handleSubmit() {
  if (!canSubmit.value) return
  submitting.value = true
  clientError.value = null
  try {
    await supportStore.createTicket({
      type: form.value.type,
      subject: form.value.subject.trim(),
      body: form.value.body.trim(),
      attachments: files.value,
    })
    toast.add({
      severity: 'success',
      summary: t('common.success'),
      detail: t('support.success.created'),
      life: 3000,
    })
    emit('created')
    emit('update:visible', false)
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
    :header="t('support.new_request')"
    :modal="true"
    :closable="true"
    :style="{ width: '560px' }"
    @update:visible="handleClose"
  >
    <form class="flex flex-col gap-4" data-testid="new-ticket-form" @submit.prevent="handleSubmit">
      <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ t('support.field.type') }}</label>
        <Select
          v-model="form.type"
          :options="typeOptions"
          option-label="label"
          option-value="value"
          class="w-full"
          data-testid="ticket-type"
        />
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ t('support.field.subject') }}</label>
        <InputText
          v-model="form.subject"
          class="w-full"
          maxlength="200"
          :placeholder="t('support.field.subject_placeholder')"
          data-testid="ticket-subject"
        />
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ t('support.field.description') }}</label>
        <Textarea
          v-model="form.body"
          class="w-full"
          rows="5"
          auto-resize
          :placeholder="t('support.field.description_placeholder')"
          data-testid="ticket-body"
        />
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ t('support.field.attachments') }}</label>
        <input
          ref="fileInput"
          type="file"
          accept="image/jpeg,image/png,image/webp"
          multiple
          class="hidden"
          data-testid="ticket-files"
          @change="onFilesSelected"
        />
        <Button
          type="button"
          icon="pi pi-paperclip"
          :label="t('support.field.add_attachment')"
          severity="secondary"
          size="small"
          @click="pickFiles"
        />
        <ul v-if="files.length" class="mt-2 flex flex-col gap-1">
          <li
            v-for="(file, index) in files"
            :key="index"
            class="flex items-center justify-between text-sm bg-gray-50 dark:bg-gray-700/40 rounded px-2 py-1"
          >
            <span class="truncate">{{ file.name }}</span>
            <button type="button" class="text-red-500 cursor-pointer" @click="removeFile(index)">
              <i class="pi pi-times"></i>
            </button>
          </li>
        </ul>
        <p class="text-xs text-gray-400 mt-1">{{ t('support.field.attachments_hint') }}</p>
      </div>

      <Message v-if="clientError" severity="error" :closable="false" data-testid="new-ticket-error">{{ clientError }}</Message>

      <div class="flex justify-end gap-2 pt-2">
        <Button type="button" :label="t('common.cancel')" severity="secondary" @click="handleClose" />
        <Button
          type="submit"
          :label="t('support.send_request')"
          :loading="submitting"
          :disabled="!canSubmit"
          data-testid="submit-ticket"
        />
      </div>
    </form>
  </Dialog>
</template>
