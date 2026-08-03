import { describe, it, expect, vi, beforeEach } from 'vitest'
import { api } from '@/services/api'
import { supportService } from '@/services/support'

vi.mock('@/services/api', () => ({
  api: { get: vi.fn(), patch: vi.fn(), upload: vi.fn(), getBlob: vi.fn() },
}))

describe('admin support service — multi-value filters', () => {
  beforeEach(() => vi.clearAllMocks())

  it('joins array filters into CSV query params', async () => {
    api.get.mockResolvedValue({ success: true, data: [] })
    await supportService.list({ status: ['OPEN', 'IN_PROGRESS'], type: ['BUG'] })
    expect(api.get).toHaveBeenCalledWith('/admin/support/tickets?status=OPEN%2CIN_PROGRESS&type=BUG')
  })

  it('omits empty array filters entirely', async () => {
    api.get.mockResolvedValue({ success: true, data: [] })
    await supportService.list({ status: [], type: [], search: 'crash' })
    expect(api.get).toHaveBeenCalledWith('/admin/support/tickets?search=crash')
  })

  it('still supports scalar filters and pagination', async () => {
    api.get.mockResolvedValue({ success: true, data: [] })
    await supportService.list({ page: 2, per_page: 50, empty: '' })
    expect(api.get).toHaveBeenCalledWith('/admin/support/tickets?page=2&per_page=50')
  })

  it('patches the type endpoint when reclassifying', async () => {
    api.patch.mockResolvedValue({ success: true, data: { id: 7, type: 'FEATURE' } })
    await supportService.updateType(7, 'FEATURE')
    expect(api.patch).toHaveBeenCalledWith('/admin/support/tickets/7/type', { type: 'FEATURE' })
  })
})
