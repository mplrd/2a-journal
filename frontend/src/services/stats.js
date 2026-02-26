import { api } from './api'

export const statsService = {
  async getDashboard(filters = {}) {
    const params = new URLSearchParams()
    for (const [key, value] of Object.entries(filters)) {
      if (value) params.append(key, value)
    }
    const query = params.toString()
    return api.get(`/stats/overview${query ? `?${query}` : ''}`)
  },

  async getCharts(filters = {}) {
    const params = new URLSearchParams()
    for (const [key, value] of Object.entries(filters)) {
      if (value) params.append(key, value)
    }
    const query = params.toString()
    return api.get(`/stats/charts${query ? `?${query}` : ''}`)
  },
}
