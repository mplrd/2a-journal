import { api } from './api'

function buildQueryString(filters) {
  const params = new URLSearchParams()
  for (const [key, value] of Object.entries(filters)) {
    if (value === null || value === undefined || value === '') continue
    params.append(key, value)
  }
  return params.toString()
}

function appendAttachments(formData, attachments = []) {
  for (const file of attachments) {
    formData.append('attachments[]', file)
  }
}

export const noteCategoriesService = {
  async list() {
    return api.get('/note-categories')
  },
  async create(data) {
    return api.post('/note-categories', data)
  },
  async update(id, data) {
    return api.put(`/note-categories/${id}`, data)
  },
  async remove(id) {
    return api.delete(`/note-categories/${id}`)
  },
}

export const notesService = {
  async list(filters = {}) {
    const query = buildQueryString(filters)
    return api.get(`/notes${query ? `?${query}` : ''}`)
  },

  async get(id) {
    return api.get(`/notes/${id}`)
  },

  // Create a note with its images in a single multipart request.
  async create({ title, content, note_date, category_id, is_pinned, attachments }) {
    const formData = new FormData()
    if (title) formData.append('title', title)
    formData.append('content', content)
    formData.append('note_date', note_date)
    if (category_id) formData.append('category_id', category_id)
    formData.append('is_pinned', is_pinned ? '1' : '0')
    appendAttachments(formData, attachments)
    return api.upload('/notes', formData)
  },

  // Field-only edit (no upload here — extra images go through addAttachments).
  async update(id, data) {
    return api.put(`/notes/${id}`, data)
  },

  async remove(id) {
    return api.delete(`/notes/${id}`)
  },

  async addAttachments(id, attachments) {
    const formData = new FormData()
    appendAttachments(formData, attachments)
    return api.upload(`/notes/${id}/attachments`, formData)
  },

  async removeAttachment(noteId, attachmentId) {
    return api.delete(`/notes/${noteId}/attachments/${attachmentId}`)
  },

  // Authenticated image fetch → object URL the caller must revoke.
  async attachmentUrl(noteId, attachmentId) {
    const blob = await api.getBlob(`/notes/${noteId}/attachments/${attachmentId}`)
    return URL.createObjectURL(blob)
  },
}
