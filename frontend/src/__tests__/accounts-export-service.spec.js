import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'

vi.mock('@/services/api', () => ({
  api: {
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
    delete: vi.fn(),
    getBlob: vi.fn(),
  },
}))

import { api } from '@/services/api'
import { accountsService, exportFileName } from '@/services/accounts'

describe('accounts service — Excel export', () => {
  let clicked
  let createdUrl

  beforeEach(() => {
    vi.clearAllMocks()
    vi.useFakeTimers()
    vi.setSystemTime(new Date(2026, 9, 6, 9, 30))
    clicked = []
    createdUrl = 'blob:export'
    window.URL.createObjectURL = vi.fn(() => createdUrl)
    window.URL.revokeObjectURL = vi.fn()
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function () {
      clicked.push({ href: this.href, download: this.download })
    })
  })

  afterEach(() => {
    vi.useRealTimers()
    vi.restoreAllMocks()
  })

  it('fetches the account export as a blob', async () => {
    api.getBlob.mockResolvedValue(new Blob(['PK']))

    await accountsService.exportXlsx({ id: 7, name: 'PF FTMO' })

    expect(api.getBlob).toHaveBeenCalledWith('/accounts/7/export')
  })

  it('saves the file under the account name and the day', async () => {
    api.getBlob.mockResolvedValue(new Blob(['PK']))

    await accountsService.exportXlsx({ id: 7, name: '(A) PF FTMO' })

    expect(clicked).toEqual([{ href: 'blob:export', download: 'a-pf-ftmo-2026-10-06.xlsx' }])
    expect(window.URL.revokeObjectURL).toHaveBeenCalledWith('blob:export')
  })

  it('lets a failed download reach the caller without saving anything', async () => {
    api.getBlob.mockRejectedValue(Object.assign(new Error('x'), { messageKey: 'accounts.error.not_found' }))

    await expect(accountsService.exportXlsx({ id: 7, name: 'PF' })).rejects.toMatchObject({
      messageKey: 'accounts.error.not_found',
    })
    expect(clicked).toEqual([])
  })

  it('falls back to a generic name when the account name has no letters or digits', () => {
    expect(exportFileName('€€€', new Date(2026, 9, 6))).toBe('account-2026-10-06.xlsx')
  })
})
