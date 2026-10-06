import { api } from './api'

/**
 * `<account-name>-<YYYY-MM-DD>.xlsx` — same rule as the server's
 * Content-Disposition, which the blob download cannot read back.
 */
export function exportFileName(accountName, day = new Date()) {
  const slug = String(accountName ?? '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
  const pad = (n) => String(n).padStart(2, '0')
  const date = `${day.getFullYear()}-${pad(day.getMonth() + 1)}-${pad(day.getDate())}`
  return `${slug || 'account'}-${date}.xlsx`
}

export const accountsService = {
  async list() {
    return api.get('/accounts')
  },

  async get(id) {
    return api.get(`/accounts/${id}`)
  },

  async create(data) {
    return api.post('/accounts', data)
  },

  async update(id, data) {
    return api.put(`/accounts/${id}`, data)
  },

  async remove(id) {
    return api.delete(`/accounts/${id}`)
  },

  async ddStatus() {
    return api.get('/accounts/dd-status')
  },

  async listAdjustments(id) {
    return api.get(`/accounts/${id}/adjustments`)
  },

  async addAdjustment(id, data) {
    return api.post(`/accounts/${id}/adjustments`, data)
  },

  async deleteAdjustment(id, adjustmentId) {
    return api.delete(`/accounts/${id}/adjustments/${adjustmentId}`)
  },

  /** Downloads the account and its closed trades as an .xlsx file. */
  async exportXlsx(account) {
    const blob = await api.getBlob(`/accounts/${account.id}/export`)
    const url = window.URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = exportFileName(account.name)
    link.click()
    window.URL.revokeObjectURL(url)
  },
}
