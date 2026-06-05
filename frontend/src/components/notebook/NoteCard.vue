<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import { useNotebookStore } from '@/stores/notebook'
import NoteImage from './NoteImage.vue'

const props = defineProps({ note: { type: Object, required: true } })
const emit = defineEmits(['edit', 'delete'])

const { t, locale } = useI18n()
const toast = useToast()
const store = useNotebookStore()

const pinned = computed(() => Number(props.note.is_pinned) === 1)
const formattedDate = computed(() => {
  if (!props.note.note_date) return ''
  const d = new Date(props.note.note_date)
  return new Intl.DateTimeFormat(locale.value === 'fr' ? 'fr-FR' : 'en-GB', {
    day: '2-digit', month: 'short', year: 'numeric',
  }).format(d)
})
const attachments = computed(() => props.note.attachments || [])

async function togglePin() {
  try {
    await store.togglePin(props.note)
  } catch (err) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: t(err.messageKey || 'error.internal'), life: 5000 })
  }
}
</script>

<template>
  <div
    class="flex flex-col rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800"
    data-testid="note-card"
  >
    <div class="mb-2 flex items-start justify-between gap-2">
      <div class="min-w-0">
        <h3 v-if="note.title" class="truncate font-semibold text-gray-900 dark:text-gray-100">{{ note.title }}</h3>
        <div class="mt-0.5 flex flex-wrap items-center gap-2 text-xs text-gray-500">
          <span><i class="pi pi-calendar mr-1"></i>{{ formattedDate }}</span>
          <span class="rounded-full bg-brand-navy-200 px-2 py-0.5 font-medium text-brand-navy-900 dark:bg-brand-navy-700/40 dark:text-brand-cream">
            {{ note.category_label || t('notebook.uncategorized') }}
          </span>
        </div>
      </div>
      <div class="flex shrink-0 items-center gap-1">
        <Button
          :icon="pinned ? 'pi pi-bookmark-fill' : 'pi pi-bookmark'"
          :severity="pinned ? 'warn' : 'secondary'"
          size="small"
          text
          v-tooltip.top="pinned ? t('notebook.unpin') : t('notebook.pin')"
          data-testid="note-pin-toggle"
          @click="togglePin"
        />
        <Button icon="pi pi-pencil" severity="secondary" size="small" text v-tooltip.top="t('common.edit')" @click="emit('edit', note)" />
        <Button icon="pi pi-trash" severity="danger" size="small" text v-tooltip.top="t('common.delete')" @click="emit('delete', note)" />
      </div>
    </div>

    <p class="whitespace-pre-wrap break-words text-sm text-gray-700 dark:text-gray-300">{{ note.content }}</p>

    <div v-if="attachments.length > 0" class="mt-3 grid grid-cols-3 gap-2 sm:grid-cols-4">
      <NoteImage
        v-for="att in attachments"
        :key="att.id"
        :note-id="note.id"
        :attachment-id="att.id"
        :alt="att.original_name"
        class="aspect-square"
      />
    </div>
  </div>
</template>
