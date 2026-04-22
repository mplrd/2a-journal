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

  async createCtraderConnection(accountId, clientId, clientSecret, accessToken, accountIdCtrader) {
    return api.post('/broker/connections', {
      provider: 'CTRADER',
      account_id: accountId,
      client_id: clientId,
      client_secret: clientSecret,
      access_token: accessToken,
      account_id_ctrader: accountIdCtrader,
    })
  },
}
