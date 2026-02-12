import { api } from './api'

export const tradesService = {
  async list(filters = {}) {
    const params = new URLSearchParams()
    for (const [key, value] of Object.entries(filters)) {
      if (value) params.append(key, value)
    }
    const query = params.toString()
    return api.get(`/trades${query ? `?${query}` : ''}`)
  },

  async get(id) {
    return api.get(`/trades/${id}`)
  },

  async create(data) {
    return api.post('/trades', data)
  },

  async close(id, data) {
    return api.post(`/trades/${id}/close`, data)
  },

  async remove(id) {
    return api.delete(`/trades/${id}`)
  },
}
