import { api } from './api'

export const tradesService = {
  async list(filters = {}) {
    const params = new URLSearchParams()
    for (const [key, value] of Object.entries(filters)) {
      if (value == null || value === '') continue
      if (Array.isArray(value)) {
        // Serialize arrays as PHP expects them: key[]=v1&key[]=v2.
        // URLSearchParams.append(key, array) would stringify to a comma-joined
        // value that PHP would read as a single string.
        if (value.length === 0) continue
        for (const v of value) {
          params.append(`${key}[]`, v)
        }
      } else {
        params.append(key, value)
      }
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

  async update(id, data) {
    return api.put(`/trades/${id}`, data)
  },

  async close(id, data) {
    return api.post(`/trades/${id}/close`, data)
  },

  async markBeHit(id) {
    return api.post(`/trades/${id}/be-hit`)
  },

  async remove(id) {
    return api.delete(`/trades/${id}`)
  },
}
