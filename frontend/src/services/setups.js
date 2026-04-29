import { api } from './api'

export const setupsService = {
  async list() {
    return api.get('/setups')
  },

  async create(data) {
    return api.post('/setups', data)
  },

  async update(id, data) {
    return api.put(`/setups/${id}`, data)
  },

  async remove(id) {
    return api.delete(`/setups/${id}`)
  },
}
