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

  /**
   * @param {object} credentials client_id, client_secret, access_token,
   *   account_id_ctrader, environment ('LIVE' | 'DEMO')
   */
  async createCtraderConnection(accountId, credentials) {
    return api.post('/broker/connections', {
      provider: 'CTRADER',
      account_id: accountId,
      ...credentials,
    })
  },

  /**
   * Reconfigure an existing connection in place. Only send the fields the user
   * actually edited — an omitted field keeps its stored value server-side,
   * which is how a single rotated secret gets fixed without re-entering the
   * others (and without deleting the connection, which would drop its sync
   * cursor and log history).
   */
  async updateConnection(connectionId, credentials) {
    return api.put(`/broker/connections/${connectionId}`, credentials)
  },

  async createOuinexConnection(accountId, serviceApiKey, serviceApiSecret) {
    return api.post('/broker/connections', {
      provider: 'OUINEX',
      account_id: accountId,
      service_api_key: serviceApiKey,
      service_api_secret: serviceApiSecret,
    })
  },

  async createBingxConnection(accountId, apiKey, apiSecret) {
    return api.post('/broker/connections', {
      provider: 'BINGX',
      account_id: accountId,
      api_key: apiKey,
      api_secret: apiSecret,
    })
  },
}
