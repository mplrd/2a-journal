import { api } from './api'

/**
 * Trading plans CRUD (docs/83). A plan is a reusable decision frame a robot
 * follows. Zones and windows are sent as full arrays and replaced in bulk.
 */
export const plansService = {
  async list() {
    return api.get('/plans')
  },

  async get(planId) {
    return api.get(`/plans/${planId}`)
  },

  async create(payload) {
    return api.post('/plans', payload)
  },

  async update(planId, payload) {
    return api.put(`/plans/${planId}`, payload)
  },

  async archive(planId) {
    return api.delete(`/plans/${planId}`)
  },
}
