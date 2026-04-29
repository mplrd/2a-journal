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

  async preview(file, broker, columnMapping = null, customOptions = null, customFieldsMapping = null) {
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
    if (customFieldsMapping && Object.keys(customFieldsMapping).length > 0) {
      formData.append('custom_fields_mapping', JSON.stringify(customFieldsMapping))
    }
    return api.upload('/imports/preview', formData)
  },

  async confirm(file, broker, accountId, symbolMapping = {}, columnMapping = null, customOptions = null, customFieldsMapping = null) {
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
    if (customFieldsMapping && Object.keys(customFieldsMapping).length > 0) {
      formData.append('custom_fields_mapping', JSON.stringify(customFieldsMapping))
    }
    return api.upload('/imports/confirm', formData)
  },

  async downloadTemplate(format = 'csv') {
    const token = api.getAccessToken()
    const baseUrl = import.meta.env.VITE_API_URL || 'http://localhost/api'
    const resp = await fetch(`${baseUrl}/imports/template-file?format=${format}`, {
      headers: token ? { Authorization: `Bearer ${token}` } : {},
      credentials: 'include',
    })
    const blob = await resp.blob()
    const url = window.URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = `import-template.${format}`
    link.click()
    window.URL.revokeObjectURL(url)
  },

  async getBatches() {
    return api.get('/imports/batches')
  },

  async rollback(batchId) {
    return api.post(`/imports/batches/${batchId}/rollback`)
  },
}
