import { api } from './api'

export const importsService = {
  async getTemplates() {
    return api.get('/imports/templates')
  },

  async getHeaders(file) {
    const formData = new FormData()
    formData.append('file', file)
    return api.upload('/imports/headers', formData)
  },

  async preview(file, broker, columnMapping = null, customOptions = null) {
    const formData = new FormData()
    formData.append('file', file)
    formData.append('broker', broker)
    if (columnMapping) {
      formData.append('column_mapping', JSON.stringify(columnMapping))
    }
    if (customOptions) {
      if (customOptions.date_format) formData.append('date_format', customOptions.date_format)
      if (customOptions.direction_buy) formData.append('direction_buy', customOptions.direction_buy)
      if (customOptions.direction_sell) formData.append('direction_sell', customOptions.direction_sell)
    }
    return api.upload('/imports/preview', formData)
  },

  async confirm(file, broker, accountId, symbolMapping = {}, columnMapping = null, customOptions = null) {
    const formData = new FormData()
    formData.append('file', file)
    formData.append('broker', broker)
    formData.append('account_id', accountId)
    formData.append('symbol_mapping', JSON.stringify(symbolMapping))
    if (columnMapping) {
      formData.append('column_mapping', JSON.stringify(columnMapping))
    }
    if (customOptions) {
      if (customOptions.date_format) formData.append('date_format', customOptions.date_format)
      if (customOptions.direction_buy) formData.append('direction_buy', customOptions.direction_buy)
      if (customOptions.direction_sell) formData.append('direction_sell', customOptions.direction_sell)
    }
    return api.upload('/imports/confirm', formData)
  },

  async getBatches() {
    return api.get('/imports/batches')
  },

  async rollback(batchId) {
    return api.post(`/imports/batches/${batchId}/rollback`)
  },
}
