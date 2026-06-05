import { describe, it, expect, vi, beforeEach } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useNotebookStore } from '@/stores/notebook'

vi.mock('@/services/notebook', () => ({
  noteCategoriesService: {
    list: vi.fn(),
    create: vi.fn(),
    update: vi.fn(),
    remove: vi.fn(),
  },
  notesService: {
    list: vi.fn(),
    get: vi.fn(),
    create: vi.fn(),
    update: vi.fn(),
    remove: vi.fn(),
    addAttachments: vi.fn(),
    removeAttachment: vi.fn(),
  },
}))

import { noteCategoriesService, notesService } from '@/services/notebook'

describe('notebook store', () => {
  let store

  beforeEach(() => {
    setActivePinia(createPinia())
    store = useNotebookStore()
    vi.clearAllMocks()
  })

  // ── Categories ─────────────────────────────────────────────────

  it('fetches and caches categories', async () => {
    noteCategoriesService.list.mockResolvedValue({ data: [{ id: 1, label: 'B' }, { id: 2, label: 'A' }] })

    await store.fetchCategories()
    expect(store.categories).toHaveLength(2)
    expect(store.categoriesLoaded).toBe(true)

    await store.fetchCategories() // cached → no second call
    expect(noteCategoriesService.list).toHaveBeenCalledTimes(1)
  })

  it('creates a category and keeps the list alphabetically sorted', async () => {
    store.categories = [{ id: 1, label: 'Money' }]
    noteCategoriesService.create.mockResolvedValue({ data: { id: 2, label: 'Discipline' } })

    await store.createCategory({ label: 'Discipline' })

    expect(store.categories.map((c) => c.label)).toEqual(['Discipline', 'Money'])
  })

  it('renaming a category refreshes the label on loaded notes', async () => {
    store.categories = [{ id: 5, label: 'Old' }]
    store.notes = [{ id: 1, category_id: 5, category_label: 'Old', is_pinned: 0 }]
    noteCategoriesService.update.mockResolvedValue({ data: { id: 5, label: 'New' } })

    await store.updateCategory(5, { label: 'New' })

    expect(store.notes[0].category_label).toBe('New')
  })

  it('deleting a category detaches its notes locally', async () => {
    store.categories = [{ id: 5, label: 'Temp' }]
    store.notes = [{ id: 1, category_id: 5, category_label: 'Temp', is_pinned: 0 }]
    noteCategoriesService.remove.mockResolvedValue({})

    await store.deleteCategory(5)

    expect(store.categories).toHaveLength(0)
    expect(store.notes[0].category_id).toBeNull()
    expect(store.notes[0].category_label).toBeNull()
  })

  // ── Notes ──────────────────────────────────────────────────────

  it('fetches notes', async () => {
    notesService.list.mockResolvedValue({ data: [{ id: 1, content: 'x', is_pinned: 0 }] })
    await store.fetchNotes()
    expect(store.notes).toHaveLength(1)
  })

  it('creates a note and prepends it', async () => {
    store.notes = [{ id: 1, content: 'old', is_pinned: 0 }]
    notesService.create.mockResolvedValue({ data: { id: 2, content: 'new', is_pinned: 0 } })

    await store.createNote({ content: 'new', note_date: '2026-06-01' })

    expect(store.notes[0].id).toBe(2)
  })

  it('updates a note in place', async () => {
    store.notes = [{ id: 1, content: 'old', is_pinned: 0 }]
    notesService.update.mockResolvedValue({ data: { id: 1, content: 'edited', is_pinned: 0 } })

    await store.updateNote(1, { content: 'edited' })

    expect(store.notes[0].content).toBe('edited')
  })

  it('deletes a note', async () => {
    store.notes = [{ id: 1, content: 'x', is_pinned: 0 }]
    notesService.remove.mockResolvedValue({})

    await store.deleteNote(1)

    expect(store.notes).toHaveLength(0)
  })

  it('togglePin flips is_pinned and exposes it via pinnedNotes', async () => {
    store.notes = [{ id: 1, content: 'x', is_pinned: 0 }]
    notesService.update.mockResolvedValue({ data: { id: 1, content: 'x', is_pinned: 1 } })

    await store.togglePin(store.notes[0])

    expect(notesService.update).toHaveBeenCalledWith(1, { is_pinned: true })
    expect(store.pinnedNotes).toHaveLength(1)
  })

  it('removeNoteAttachment drops the image from the note', async () => {
    store.notes = [{ id: 1, content: 'x', is_pinned: 0, attachments: [{ id: 9 }, { id: 10 }] }]
    notesService.removeAttachment.mockResolvedValue({})

    await store.removeNoteAttachment(1, 9)

    expect(store.notes[0].attachments.map((a) => a.id)).toEqual([10])
  })
})
