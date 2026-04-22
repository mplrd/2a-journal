import { api } from './api'

export const billingService = {
  async getStatus() {
    return api.get('/billing/status')
  },

  async createCheckoutSession() {
    return api.post('/billing/checkout')
  },

  async createPortalSession() {
    return api.post('/billing/portal')
  },

  async cancel() {
    return api.post('/billing/cancel')
  },

  async reactivate() {
    return api.post('/billing/reactivate')
  },
}
