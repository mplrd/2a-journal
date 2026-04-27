import { api } from './api'

export const authService = {
  async login(data) {
    return api.post('/auth/login', data, { auth: false })
  },

  async logout() {
    return api.post('/auth/logout')
  },

  async me() {
    return api.get('/auth/me')
  },
}
