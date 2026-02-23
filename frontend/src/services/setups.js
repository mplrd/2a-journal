import { api } from './api'

export const setupsService = {
  async list() {
    return api.get('/setups')
  },

  async create(data) {
    return api.post('/setups', data)
  },

  async remove(id) {
    return api.delete(`/setups/${id}`)
  },
}
