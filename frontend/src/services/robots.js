import { api } from './api'

export const robotsService = {
  async list() {
    return api.get('/robots')
  },

  async create({ name, accountId }) {
    return api.post('/robots', { name, account_id: accountId })
  },

  async setStatus(robotId, status) {
    return api.patch(`/robots/${robotId}/status`, { status })
  },

  async archive(robotId) {
    return api.delete(`/robots/${robotId}`)
  },

  async events(robotId, page = 1, perPage = 50) {
    return api.get(`/robots/${robotId}/events?page=${page}&per_page=${perPage}`)
  },
}
