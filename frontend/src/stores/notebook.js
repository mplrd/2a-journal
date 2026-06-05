import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { noteCategoriesService, notesService } from '@/services/notebook'

export const useNotebookStore = defineStore('notebook', () => {
  const categories = ref([])
  const notes = ref([])
  const loadingCategories = ref(false)
  const loadingNotes = ref(false)
  const categoriesLoaded = ref(false)
  const error = ref(null)

  // Notes pinned to the dashboard, freshest first by note_date.
  const pinnedNotes = computed(() => notes.value.filter((n) => Number(n.is_pinned) === 1))

  // ── Categories ─────────────────────────────────────────────────

  async function fetchCategories(force = false) {
    if (categoriesLoaded.value && !force) return
    loadingCategories.value = true
    error.value = null
    try {
      const response = await noteCategoriesService.list()
      categories.value = response.data
      categoriesLoaded.value = true
    } catch (err) {
      error.value = err.messageKey || 'error.internal'
      throw err
    } finally {
      loadingCategories.value = false
    }
  }

  async function createCategory(data) {
    const response = await noteCategoriesService.create(data)
    categories.value.push(response.data)
    categories.value.sort((a, b) => a.label.localeCompare(b.label))
    return response.data
  }

  async function updateCategory(id, data) {
    const response = await noteCategoriesService.update(id, data)
    const index = categories.value.findIndex((c) => c.id === id)
    if (index !== -1) categories.value[index] = response.data
    categories.value.sort((a, b) => a.label.localeCompare(b.label))
    // A renamed category must refresh its label on already-loaded notes.
    for (const note of notes.value) {
      if (note.category_id === id) note.category_label = response.data.label
    }
    return response.data
  }

  async function deleteCategory(id) {
    await noteCategoriesService.remove(id)
    categories.value = categories.value.filter((c) => c.id !== id)
    // Deleted category → its notes fall back to "Autre" (null), mirror server.
    for (const note of notes.value) {
      if (note.category_id === id) {
        note.category_id = null
        note.category_label = null
      }
    }
  }

  // ── Notes ──────────────────────────────────────────────────────

  async function fetchNotes(filters = {}) {
    loadingNotes.value = true
    error.value = null
    try {
      const response = await notesService.list(filters)
      notes.value = response.data
    } catch (err) {
      error.value = err.messageKey || 'error.internal'
      throw err
    } finally {
      loadingNotes.value = false
    }
  }

  function upsertNote(note) {
    const index = notes.value.findIndex((n) => n.id === note.id)
    if (index !== -1) {
      notes.value[index] = note
    } else {
      notes.value.unshift(note)
    }
  }

  async function createNote(payload) {
    const response = await notesService.create(payload)
    upsertNote(response.data)
    return response.data
  }

  async function updateNote(id, data) {
    const response = await notesService.update(id, data)
    upsertNote(response.data)
    return response.data
  }

  async function deleteNote(id) {
    await notesService.remove(id)
    notes.value = notes.value.filter((n) => n.id !== id)
  }

  async function addNoteAttachments(id, files) {
    const response = await notesService.addAttachments(id, files)
    upsertNote(response.data)
    return response.data
  }

  async function removeNoteAttachment(noteId, attachmentId) {
    await notesService.removeAttachment(noteId, attachmentId)
    const note = notes.value.find((n) => n.id === noteId)
    if (note) note.attachments = (note.attachments || []).filter((a) => a.id !== attachmentId)
  }

  async function togglePin(note) {
    return updateNote(note.id, { is_pinned: Number(note.is_pinned) !== 1 })
  }

  function $reset() {
    categories.value = []
    notes.value = []
    loadingCategories.value = false
    loadingNotes.value = false
    categoriesLoaded.value = false
    error.value = null
  }

  return {
    categories,
    notes,
    loadingCategories,
    loadingNotes,
    categoriesLoaded,
    error,
    pinnedNotes,
    fetchCategories,
    createCategory,
    updateCategory,
    deleteCategory,
    fetchNotes,
    createNote,
    updateNote,
    deleteNote,
    addNoteAttachments,
    removeNoteAttachment,
    togglePin,
    $reset,
  }
})
