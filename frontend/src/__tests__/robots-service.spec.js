import { describe, it, expect, vi, beforeEach } from 'vitest'
import { api } from '@/services/api'
import { robotsService } from '@/services/robots'

vi.mock('@/services/api', () => ({
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}))

describe('robots service', () => {
  beforeEach(() => vi.clearAllMocks())

  it('list calls GET /robots', async () => {
    api.get.mockResolvedValue({ success: true, data: [] })
    await robotsService.list()
    expect(api.get).toHaveBeenCalledWith('/robots')
  })

  it('get fetches a single robot detail', async () => {
    api.get.mockResolvedValue({ success: true, data: { robot: {}, webhook: {} } })
    await robotsService.get(4)
    expect(api.get).toHaveBeenCalledWith('/robots/4')
  })

  it('create posts name + account_id', async () => {
    api.post.mockResolvedValue({ success: true, data: { robot: { id: 1 } } })
    await robotsService.create({ name: 'My bot', accountId: 7 })
    expect(api.post).toHaveBeenCalledWith('/robots', { name: 'My bot', account_id: 7 })
  })

  it('regenerate posts to the regenerate endpoint', async () => {
    api.post.mockResolvedValue({ success: true, data: { url: 'x' } })
    await robotsService.regenerate(4)
    expect(api.post).toHaveBeenCalledWith('/robots/4/regenerate')
  })

  it('setStatus patches the status endpoint', async () => {
    api.patch.mockResolvedValue({ success: true, data: {} })
    await robotsService.setStatus(3, 'PAUSED')
    expect(api.patch).toHaveBeenCalledWith('/robots/3/status', { status: 'PAUSED' })
  })

  it('archive deletes the robot', async () => {
    api.delete.mockResolvedValue({ success: true, data: { archived: true } })
    await robotsService.archive(3)
    expect(api.delete).toHaveBeenCalledWith('/robots/3')
  })

  it('events builds the paginated query', async () => {
    api.get.mockResolvedValue({ success: true, data: [] })
    await robotsService.events(3, 2, 25)
    expect(api.get).toHaveBeenCalledWith('/robots/3/events?page=2&per_page=25')
  })
})
