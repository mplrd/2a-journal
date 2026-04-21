import { api } from './api'

export const featuresService = {
  async get() {
    return api.get('/features', { auth: false })
  },
}
