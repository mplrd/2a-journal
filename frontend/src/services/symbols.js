import { api } from './api'

export const symbolsService = {
  async list(params = {}) {
    const query = new URLSearchParams(params).toString()
    return api.get(`/symbols${query ? `?${query}` : ''}`)
  },

  async get(id) {
    return api.get(`/symbols/${id}`)
  },

  async create(data) {
    return api.post('/symbols', data)
  },

  async update(id, data) {
    return api.put(`/symbols/${id}`, data)
  },

  async remove(id) {
    return api.delete(`/symbols/${id}`)
  },
}
