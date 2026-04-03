import { api } from './api'

export const brokerSyncService = {
  async getConnection(accountId) {
    return api.get(`/broker/connections?account_id=${accountId}`)
  },

  async getAllConnections() {
    return api.get('/broker/connections')
  },

  async createMetaApiConnection(accountId, apiToken, metaApiAccountId) {
    return api.post('/broker/connections', {
      provider: 'METAAPI',
      account_id: accountId,
      api_token: apiToken,
      metaapi_account_id: metaApiAccountId,
    })
  },

  async sync(connectionId) {
    return api.post(`/broker/connections/${connectionId}/sync`)
  },

  async deleteConnection(connectionId) {
    return api.delete(`/broker/connections/${connectionId}`)
  },

  async getSyncLogs(connectionId) {
    return api.get(`/broker/connections/${connectionId}/logs`)
  },

  getCtraderAuthorizeUrl(accountId) {
    return `/api/broker/ctrader/authorize?account_id=${accountId}`
  },
}
