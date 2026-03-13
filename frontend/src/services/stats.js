import { api } from './api'

function buildQueryString(filters) {
  const params = new URLSearchParams()
  for (const [key, value] of Object.entries(filters)) {
    if (value === null || value === undefined || value === '') continue
    if (Array.isArray(value)) {
      if (value.length > 0) params.append(key, value.join(','))
    } else {
      params.append(key, value)
    }
  }
  return params.toString()
}

export const statsService = {
  async getDashboard(filters = {}) {
    const query = buildQueryString(filters)
    return api.get(`/stats/overview${query ? `?${query}` : ''}`)
  },

  async getCharts(filters = {}) {
    const query = buildQueryString(filters)
    return api.get(`/stats/charts${query ? `?${query}` : ''}`)
  },

  async getBySymbol(filters = {}) {
    const query = buildQueryString(filters)
    return api.get(`/stats/by-symbol${query ? `?${query}` : ''}`)
  },

  async getByDirection(filters = {}) {
    const query = buildQueryString(filters)
    return api.get(`/stats/by-direction${query ? `?${query}` : ''}`)
  },

  async getBySetup(filters = {}) {
    const query = buildQueryString(filters)
    return api.get(`/stats/by-setup${query ? `?${query}` : ''}`)
  },

  async getByPeriod(filters = {}, group = 'month') {
    const allFilters = { ...filters, group }
    const query = buildQueryString(allFilters)
    return api.get(`/stats/by-period${query ? `?${query}` : ''}`)
  },

  async getRrDistribution(filters = {}) {
    const query = buildQueryString(filters)
    return api.get(`/stats/rr-distribution${query ? `?${query}` : ''}`)
  },

  async getHeatmap(filters = {}) {
    const query = buildQueryString(filters)
    return api.get(`/stats/heatmap${query ? `?${query}` : ''}`)
  },
}
