import { api } from './api'

export const authService = {
  async register(data) {
    return api.post('/auth/register', data, { auth: false })
  },

  async login(data) {
    return api.post('/auth/login', data, { auth: false })
  },

  async refresh(refreshToken) {
    return api.post('/auth/refresh', { refresh_token: refreshToken }, { auth: false })
  },

  async logout() {
    return api.post('/auth/logout')
  },

  async me() {
    return api.get('/auth/me')
  },
}
