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
}
