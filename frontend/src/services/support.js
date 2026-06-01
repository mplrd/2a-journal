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

// PHP parses `details[key]` multipart fields into $_POST['details'][key].
// Empty values are skipped; the backend re-validates and whitelists per type.
function appendDetails(formData, details = {}) {
  for (const [key, value] of Object.entries(details)) {
    const trimmed = (value ?? '').trim()
    if (trimmed !== '') {
      formData.append(`details[${key}]`, trimmed)
    }
  }
}

export const supportService = {
  async list(filters = {}) {
    const query = buildQueryString(filters)
    return api.get(`/support/tickets${query ? `?${query}` : ''}`)
  },

  async get(id) {
    return api.get(`/support/tickets/${id}`)
  },

  async create({ type, subject, body, details, attachments }) {
    const formData = new FormData()
    formData.append('type', type)
    formData.append('subject', subject)
    formData.append('body', body)
    appendDetails(formData, details)
    appendAttachments(formData, attachments)
    return api.upload('/support/tickets', formData)
  },

  async reply(id, { body, attachments }) {
    const formData = new FormData()
    formData.append('body', body)
    appendAttachments(formData, attachments)
    return api.upload(`/support/tickets/${id}/messages`, formData)
  },

  // Authenticated attachment fetch → object URL the caller must revoke.
  async attachmentUrl(ticketId, attachmentId) {
    const blob = await api.getBlob(`/support/tickets/${ticketId}/attachments/${attachmentId}`)
    return URL.createObjectURL(blob)
  },
}
