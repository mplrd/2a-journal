import { api } from './api'

export const ordersService = {
  async list(filters = {}) {
    const params = new URLSearchParams()
    for (const [key, value] of Object.entries(filters)) {
      if (value) params.append(key, value)
    }
    const query = params.toString()
    return api.get(`/orders${query ? `?${query}` : ''}`)
  },

  async create(data) {
    return api.post('/orders', data)
  },

  async get(id) {
    return api.get(`/orders/${id}`)
  },

  async remove(id) {
    return api.delete(`/orders/${id}`)
  },

  async cancel(id) {
    return api.post(`/orders/${id}/cancel`)
  },

  async execute(id) {
    return api.post(`/orders/${id}/execute`)
  },
}
