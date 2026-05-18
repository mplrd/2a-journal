import { api } from './api'

export const webhooksService = {
  async list(accountId) {
    return api.get(`/accounts/${accountId}/webhooks`)
  },

  async create(accountId, name) {
    return api.post(`/accounts/${accountId}/webhooks`, { name })
  },

  async revoke(accountId, webhookId) {
    return api.delete(`/accounts/${accountId}/webhooks/${webhookId}`)
  },

  async events(accountId, webhookId, page = 1, perPage = 50) {
    return api.get(`/accounts/${accountId}/webhooks/${webhookId}/events?page=${page}&per_page=${perPage}`)
  },
}
