<script setup>
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import { useNotebookStore } from '@/stores/notebook'
import NoteCard from '@/components/notebook/NoteCard.vue'
import NoteDialog from '@/components/notebook/NoteDialog.vue'
import CategoryManagerDialog from '@/components/notebook/CategoryManagerDialog.vue'

const { t } = useI18n()
const toast = useToast()
const store = useNotebookStore()

const activeCategory = ref('all') // 'all' | 'none' | <categoryId>
const showNoteDialog = ref(false)
const showCategoryManager = ref(false)
const editingNote = ref(null)
const noteToDelete = ref(null)

onMounted(async () => {
  await Promise.all([store.fetchCategories(true), store.fetchNotes()])
})

const filteredNotes = computed(() => {
  if (activeCategory.value === 'all') return store.notes
  if (activeCategory.value === 'none') return store.notes.filter((n) => !n.category_id)
  return store.notes.filter((n) => n.category_id === activeCategory.value)
})

function openCreate() {
  editingNote.value = null
  showNoteDialog.value = true
}

function openEdit(note) {
  editingNote.value = note
  showNoteDialog.value = true
}

async function handleDelete() {
  const note = noteToDelete.value
  noteToDelete.value = null
  if (!note) return
  try {
    await store.deleteNote(note.id)
    toast.add({ severity: 'success', summary: t('common.success'), detail: t('notebook.success.deleted'), life: 2500 })
  } catch (err) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: t(err.messageKey || 'error.internal'), life: 5000 })
  }
}
</script>

<template>
  <div>
    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
      <!-- Category filter -->
      <div class="flex flex-wrap gap-2" data-testid="category-filter">
        <button
          class="rounded-full border px-3 py-1 text-sm transition"
          :class="activeCategory === 'all'
            ? 'border-brand-navy-700 bg-brand-navy-700 text-white'
            : 'border-gray-300 text-gray-600 hover:bg-gray-100 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700'"
          @click="activeCategory = 'all'"
        >
          {{ t('notebook.all_categories') }}
        </button>
        <button
          v-for="cat in store.categories"
          :key="cat.id"
          class="rounded-full border px-3 py-1 text-sm transition"
          :class="activeCategory === cat.id
            ? 'border-brand-navy-700 bg-brand-navy-700 text-white'
            : 'border-gray-300 text-gray-600 hover:bg-gray-100 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700'"
          @click="activeCategory = cat.id"
        >
          {{ cat.label }}
        </button>
        <button
          class="rounded-full border px-3 py-1 text-sm transition"
          :class="activeCategory === 'none'
            ? 'border-brand-navy-700 bg-brand-navy-700 text-white'
            : 'border-gray-300 text-gray-600 hover:bg-gray-100 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700'"
          @click="activeCategory = 'none'"
        >
          {{ t('notebook.uncategorized') }}
        </button>
      </div>

      <div class="flex items-center gap-2">
        <Button
          :label="t('notebook.manage_categories')"
          icon="pi pi-tags"
          severity="secondary"
          outlined
          size="small"
          data-testid="manage-categories-btn"
          @click="showCategoryManager = true"
        />
        <Button
          :label="t('notebook.add_note')"
          icon="pi pi-plus"
          size="small"
          data-testid="add-note-btn"
          @click="openCreate"
        />
      </div>
    </div>

    <!-- Notes -->
    <div v-if="store.loadingNotes" class="flex justify-center py-12">
      <i class="pi pi-spin pi-spinner text-3xl text-gray-400"></i>
    </div>
    <p v-else-if="store.notes.length === 0" class="py-12 text-center text-gray-500" data-testid="notebook-empty">
      {{ t('notebook.empty') }}
    </p>
    <p v-else-if="filteredNotes.length === 0" class="py-12 text-center text-gray-500" data-testid="notebook-empty-filtered">
      {{ t('notebook.empty_filtered') }}
    </p>
    <div v-else class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
      <NoteCard
        v-for="note in filteredNotes"
        :key="note.id"
        :note="note"
        @edit="openEdit"
        @delete="noteToDelete = note"
      />
    </div>

    <NoteDialog v-model:visible="showNoteDialog" :note="editingNote" />
    <CategoryManagerDialog v-model:visible="showCategoryManager" />

    <Dialog
      :visible="noteToDelete !== null"
      :header="t('notebook.confirm_delete_title')"
      modal
      :style="{ width: '400px' }"
      @update:visible="noteToDelete = null"
    >
      <p>{{ t('notebook.confirm_delete') }}</p>
      <template #footer>
        <Button :label="t('common.cancel')" severity="secondary" text @click="noteToDelete = null" />
        <Button :label="t('common.delete')" severity="danger" data-testid="confirm-delete-note" @click="handleDelete" />
      </template>
    </Dialog>
  </div>
</template>
