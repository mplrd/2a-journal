import { api } from './api'

export const usersService = {
  async list(filters = {}) {
    const params = new URLSearchParams()
    for (const [key, value] of Object.entries(filters)) {
      if (value == null || value === '') continue
      params.append(key, value)
    }
    const query = params.toString()
    return api.get(`/admin/users${query ? `?${query}` : ''}`)
  },

  async get(id) {
    return api.get(`/admin/users/${id}`)
  },

  async suspend(id) {
    return api.post(`/admin/users/${id}/suspend`)
  },

  async unsuspend(id) {
    return api.post(`/admin/users/${id}/unsuspend`)
  },

  async resetPassword(id) {
    return api.post(`/admin/users/${id}/reset-password`)
  },

  async remove(id) {
    return api.delete(`/admin/users/${id}`)
  },
}
