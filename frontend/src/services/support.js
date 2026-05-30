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

export const supportService = {
  async list(filters = {}) {
    const query = buildQueryString(filters)
    return api.get(`/support/tickets${query ? `?${query}` : ''}`)
  },

  async get(id) {
    return api.get(`/support/tickets/${id}`)
  },

  async create({ type, subject, body, attachments }) {
    const formData = new FormData()
    formData.append('type', type)
    formData.append('subject', subject)
    formData.append('body', body)
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
