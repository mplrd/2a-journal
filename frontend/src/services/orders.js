import { api } from './api'

function buildQueryString(filters) {
  const params = new URLSearchParams()
  for (const [key, value] of Object.entries(filters)) {
    if (value === null || value === undefined || value === '') continue
    if (Array.isArray(value)) {
      if (value.length === 0) continue
      for (const v of value) params.append(`${key}[]`, v)
    } else {
      params.append(key, value)
    }
  }
  return params.toString()
}

export const ordersService = {
  async list(filters = {}) {
    const query = buildQueryString(filters)
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
