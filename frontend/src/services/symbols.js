import { api } from './api'

export const symbolsService = {
  async list() {
    return api.get('/symbols')
  },
}
