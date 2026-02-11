import { api } from './api'

export const positionsService = {
  async list(filters = {}) {
    const params = new URLSearchParams()
    for (const [key, value] of Object.entries(filters)) {
      if (value) params.append(key, value)
    }
    const query = params.toString()
    return api.get(`/positions${query ? `?${query}` : ''}`)
  },

  async get(id) {
    return api.get(`/positions/${id}`)
  },

  async update(id, data) {
    return api.put(`/positions/${id}`, data)
  },

  async remove(id) {
    return api.delete(`/positions/${id}`)
  },

  async transfer(id, data) {
    return api.post(`/positions/${id}/transfer`, data)
  },

  async getHistory(id) {
    return api.get(`/positions/${id}/history`)
  },
}
