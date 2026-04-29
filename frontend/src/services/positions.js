import { api } from './api'

function buildQueryString(filters) {
  const params = new URLSearchParams()
  for (const [key, value] of Object.entries(filters)) {
    if (value === null || value === undefined || value === '') continue
    if (Array.isArray(value)) {
      if (value.length === 0) continue
      // PHP-style array params: ?key[]=v1&key[]=v2
      for (const v of value) params.append(`${key}[]`, v)
    } else {
      params.append(key, value)
    }
  }
  return params.toString()
}

export const positionsService = {
  async list(filters = {}) {
    const query = buildQueryString(filters)
    return api.get(`/positions${query ? `?${query}` : ''}`)
  },

  async listAggregated(filters = {}) {
    const query = buildQueryString(filters)
    return api.get(`/positions/aggregated${query ? `?${query}` : ''}`)
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

  async shareText(id) {
    return api.get(`/positions/${id}/share/text`)
  },

  async shareTextPlain(id) {
    return api.get(`/positions/${id}/share/text-plain`)
  },
}
