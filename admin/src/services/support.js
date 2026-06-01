import { api } from './api'

export const supportService = {
  async list(filters = {}) {
    const params = new URLSearchParams()
    for (const [key, value] of Object.entries(filters)) {
      // Multi-value filters (type/status/priority) arrive as arrays → CSV.
      if (Array.isArray(value)) {
        if (value.length) params.append(key, value.join(','))
        continue
      }
      if (value == null || value === '') continue
      params.append(key, value)
    }
    const query = params.toString()
    return api.get(`/admin/support/tickets${query ? `?${query}` : ''}`)
  },

  async get(id) {
    return api.get(`/admin/support/tickets/${id}`)
  },

  async reply(id, { body, attachments = [] }) {
    const formData = new FormData()
    formData.append('body', body)
    for (const file of attachments) {
      formData.append('attachments[]', file)
    }
    return api.upload(`/admin/support/tickets/${id}/messages`, formData)
  },

  async updateStatus(id, status) {
    return api.patch(`/admin/support/tickets/${id}/status`, { status })
  },

  async updatePriority(id, priority) {
    return api.patch(`/admin/support/tickets/${id}/priority`, { priority })
  },

  async attachmentUrl(ticketId, attachmentId) {
    const blob = await api.getBlob(`/admin/support/tickets/${ticketId}/attachments/${attachmentId}`)
    return URL.createObjectURL(blob)
  },
}
