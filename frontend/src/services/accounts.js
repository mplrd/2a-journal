import { api } from './api'

export const accountsService = {
  async list() {
    return api.get('/accounts')
  },

  async get(id) {
    return api.get(`/accounts/${id}`)
  },

  async create(data) {
    return api.post('/accounts', data)
  },

  async update(id, data) {
    return api.put(`/accounts/${id}`, data)
  },

  async remove(id) {
    return api.delete(`/accounts/${id}`)
  },

  async ddStatus() {
    return api.get('/accounts/dd-status')
  },

  async listAdjustments(id) {
    return api.get(`/accounts/${id}/adjustments`)
  },

  async addAdjustment(id, data) {
    return api.post(`/accounts/${id}/adjustments`, data)
  },

  async deleteAdjustment(id, adjustmentId) {
    return api.delete(`/accounts/${id}/adjustments/${adjustmentId}`)
  },
}
