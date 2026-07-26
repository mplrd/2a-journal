import { describe, it, expect, vi, beforeEach } from 'vitest'
import { api } from '@/services/api'
import { plansService } from '@/services/plans'

vi.mock('@/services/api', () => ({
  api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
}))

describe('plans service', () => {
  beforeEach(() => vi.clearAllMocks())

  it('list calls GET /plans', async () => {
    api.get.mockResolvedValue({ success: true, data: [] })
    await plansService.list()
    expect(api.get).toHaveBeenCalledWith('/plans')
  })

  it('get fetches a single plan', async () => {
    api.get.mockResolvedValue({ success: true, data: {} })
    await plansService.get(3)
    expect(api.get).toHaveBeenCalledWith('/plans/3')
  })

  it('create posts the plan payload', async () => {
    api.post.mockResolvedValue({ success: true, data: { id: 1 } })
    const payload = { name: 'DAX', allowed_direction: 'BUY', zones: [], windows: [] }
    await plansService.create(payload)
    expect(api.post).toHaveBeenCalledWith('/plans', payload)
  })

  it('update puts to the plan id', async () => {
    api.put.mockResolvedValue({ success: true, data: {} })
    await plansService.update(4, { name: 'x' })
    expect(api.put).toHaveBeenCalledWith('/plans/4', { name: 'x' })
  })

  it('archive deletes the plan', async () => {
    api.delete.mockResolvedValue({ success: true, data: { archived: true } })
    await plansService.archive(4)
    expect(api.delete).toHaveBeenCalledWith('/plans/4')
  })
})
