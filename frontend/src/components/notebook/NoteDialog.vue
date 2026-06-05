<script setup>
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import DatePicker from 'primevue/datepicker'
import ToggleSwitch from 'primevue/toggleswitch'
import Button from 'primevue/button'
import { useNotebookStore } from '@/stores/notebook'
import { compressImageIfNeeded } from '@/utils/imageCompression'
import NoteImage from './NoteImage.vue'

const ACCEPTED_TYPES = ['image/jpeg', 'image/png', 'image/webp']
const MAX_FILES = 10
const MAX_SIZE = 5 * 1024 * 1024

const props = defineProps({
  visible: { type: Boolean, default: false },
  note: { type: Object, default: null },
})
const emit = defineEmits(['update:visible', 'saved'])

const { t } = useI18n()
const toast = useToast()
const store = useNotebookStore()

const fileInput = ref(null)
const form = ref({ title: '', content: '', note_date: new Date(), category_id: null, is_pinned: false })
const newFiles = ref([])
const existingAttachments = ref([])
const submitting = ref(false)
const clientError = ref(null)

const isEdit = computed(() => !!props.note)
const categoryOptions = computed(() => [
  { label: t('notebook.uncategorized'), value: null },
  ...store.categories.map((c) => ({ label: c.label, value: c.id })),
])
const totalImages = computed(() => existingAttachments.value.length + newFiles.value.length)
const canSubmit = computed(() => form.value.content.trim() !== '' && !!form.value.note_date)

// Local Y-m-d (avoids the UTC shift a toISOString() would introduce).
function toISODate(date) {
  const d = date instanceof Date ? date : new Date(date)
  const y = d.getFullYear()
  const m = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')
  return `${y}-${m}-${day}`
}

watch(
  () => props.visible,
  (val) => {
    if (!val) return
    clientError.value = null
    newFiles.value = []
    if (props.note) {
      form.value = {
        title: props.note.title || '',
        content: props.note.content || '',
        note_date: props.note.note_date ? new Date(props.note.note_date) : new Date(),
        category_id: props.note.category_id ?? null,
        is_pinned: Number(props.note.is_pinned) === 1,
      }
      existingAttachments.value = [...(props.note.attachments || [])]
    } else {
      form.value = { title: '', content: '', note_date: new Date(), category_id: null, is_pinned: false }
      existingAttachments.value = []
    }
  },
)

function pickFiles() {
  fileInput.value?.click()
}

async function onFilesSelected(event) {
  clientError.value = null
  for (const original of Array.from(event.target.files || [])) {
    if (!ACCEPTED_TYPES.includes(original.type)) {
      clientError.value = t('upload.error.invalid_type')
      continue
    }
    if (totalImages.value >= MAX_FILES) {
      clientError.value = t('notes.error.too_many_attachments')
      break
    }
    const file = await compressImageIfNeeded(original)
    if (file.size > MAX_SIZE) {
      clientError.value = t('upload.error.too_large')
      continue
    }
    file.__preview = URL.createObjectURL(file)
    newFiles.value.push(file)
  }
  event.target.value = ''
}

function removeNewFile(index) {
  const [removed] = newFiles.value.splice(index, 1)
  if (removed?.__preview) URL.revokeObjectURL(removed.__preview)
}

async function removeExisting(attachment) {
  try {
    await store.removeNoteAttachment(props.note.id, attachment.id)
    existingAttachments.value = existingAttachments.value.filter((a) => a.id !== attachment.id)
  } catch (err) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: t(err.messageKey || 'error.internal'), life: 5000 })
  }
}

async function handleSubmit() {
  if (!canSubmit.value || submitting.value) return
  submitting.value = true
  clientError.value = null

  const payload = {
    title: form.value.title.trim() || null,
    content: form.value.content.trim(),
    note_date: toISODate(form.value.note_date),
    category_id: form.value.category_id ?? null,
    is_pinned: form.value.is_pinned,
  }

  try {
    if (isEdit.value) {
      await store.updateNote(props.note.id, payload)
      if (newFiles.value.length > 0) {
        await store.addNoteAttachments(props.note.id, newFiles.value)
      }
      toast.add({ severity: 'success', summary: t('common.success'), detail: t('notebook.success.updated'), life: 2500 })
    } else {
      await store.createNote({ ...payload, attachments: newFiles.value })
      toast.add({ severity: 'success', summary: t('common.success'), detail: t('notebook.success.created'), life: 2500 })
    }
    emit('saved')
    emit('update:visible', false)
  } catch (err) {
    clientError.value = t(err.messageKey || 'error.internal')
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <Dialog
    :visible="visible"
    :header="isEdit ? t('notebook.form.edit_title') : t('notebook.form.new_title')"
    modal
    :style="{ width: '560px' }"
    :breakpoints="{ '640px': '95vw' }"
    data-testid="note-dialog"
    @update:visible="emit('update:visible', $event)"
  >
    <div class="flex flex-col gap-4">
      <div>
        <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ t('notebook.form.title_label') }}</label>
        <InputText v-model="form.title" :placeholder="t('notebook.form.title_placeholder')" class="w-full" data-testid="note-title" />
      </div>

      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div>
          <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ t('notebook.form.date_label') }}</label>
          <DatePicker v-model="form.note_date" dateFormat="dd/mm/yy" showIcon class="w-full" data-testid="note-date" />
        </div>
        <div>
          <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ t('notebook.form.category_label') }}</label>
          <Select
            v-model="form.category_id"
            :options="categoryOptions"
            optionLabel="label"
            optionValue="value"
            :placeholder="t('notebook.form.category_placeholder')"
            class="w-full"
            data-testid="note-category"
          />
        </div>
      </div>

      <div>
        <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ t('notebook.form.content_label') }}</label>
        <Textarea v-model="form.content" :placeholder="t('notebook.form.content_placeholder')" rows="5" autoResize class="w-full" data-testid="note-content" />
      </div>

      <!-- Images -->
      <div>
        <div class="mb-2 flex items-center justify-between">
          <label class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ t('notebook.form.attachments_label') }}</label>
          <Button
            :label="t('notebook.form.add_images')"
            icon="pi pi-image"
            size="small"
            text
            :disabled="totalImages >= MAX_FILES"
            data-testid="add-images-btn"
            @click="pickFiles"
          />
          <input ref="fileInput" type="file" accept="image/jpeg,image/png,image/webp" multiple class="hidden" @change="onFilesSelected" />
        </div>
        <div v-if="totalImages > 0" class="grid grid-cols-3 gap-2 sm:grid-cols-4">
          <div v-for="att in existingAttachments" :key="`e-${att.id}`" class="relative aspect-square">
            <NoteImage :note-id="note.id" :attachment-id="att.id" :alt="att.original_name" class="h-full w-full" />
            <button type="button" class="absolute right-1 top-1 rounded-full bg-black/60 p-1 text-white hover:bg-black/80" @click="removeExisting(att)">
              <i class="pi pi-times text-xs"></i>
            </button>
          </div>
          <div v-for="(file, i) in newFiles" :key="`n-${i}`" class="relative aspect-square overflow-hidden rounded-md bg-gray-100 dark:bg-gray-800">
            <img :src="file.__preview || ''" :alt="file.name" class="h-full w-full object-cover" />
            <span class="absolute inset-x-0 bottom-0 truncate bg-black/50 px-1 text-[10px] text-white">{{ file.name }}</span>
            <button type="button" class="absolute right-1 top-1 rounded-full bg-black/60 p-1 text-white hover:bg-black/80" @click="removeNewFile(i)">
              <i class="pi pi-times text-xs"></i>
            </button>
          </div>
        </div>
      </div>

      <div class="flex items-center gap-2">
        <ToggleSwitch v-model="form.is_pinned" inputId="note-pin" data-testid="note-pin" />
        <label for="note-pin" class="text-sm text-gray-700 dark:text-gray-300">{{ t('notebook.form.pin_label') }}</label>
      </div>

      <p v-if="clientError" class="text-sm text-red-500" data-testid="note-error">{{ clientError }}</p>
    </div>

    <template #footer>
      <Button :label="t('common.cancel')" severity="secondary" text @click="emit('update:visible', false)" />
      <Button
        :label="t('notebook.form.save')"
        icon="pi pi-check"
        :loading="submitting"
        :disabled="!canSubmit"
        data-testid="note-save-btn"
        @click="handleSubmit"
      />
    </template>
  </Dialog>
</template>
