import { api } from './api'

export const customFieldsService = {
  async list() {
    return api.get('/custom-fields')
  },

  async get(id) {
    return api.get(`/custom-fields/${id}`)
  },

  async create(data) {
    return api.post('/custom-fields', data)
  },

  async update(id, data) {
    return api.put(`/custom-fields/${id}`, data)
  },

  async remove(id) {
    return api.delete(`/custom-fields/${id}`)
  },
}
