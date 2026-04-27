import { api } from './api'

export const settingsService = {
  async list() {
    return api.get('/admin/settings')
  },

  async update(key, value) {
    return api.put(`/admin/settings/${encodeURIComponent(key)}`, { value })
  },
}
