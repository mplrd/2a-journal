import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { useSupportStore } from '@/stores/support'
import { supportService } from '@/services/support'

vi.mock('@/services/support', () => ({
  supportService: {
    list: vi.fn(),
    get: vi.fn(),
    reply: vi.fn(),
    updateStatus: vi.fn(),
    updatePriority: vi.fn(),
    updateType: vi.fn(),
    attachmentUrl: vi.fn(),
  },
}))

describe('admin support store', () => {
  let store

  beforeEach(() => {
    setActivePinia(createPinia())
    store = useSupportStore()
    vi.clearAllMocks()
  })

  it('fetchTickets loads tickets and total', async () => {
    supportService.list.mockResolvedValue({
      success: true,
      data: [{ id: 1, status: 'OPEN', priority: 'NORMAL' }],
      meta: { total: 1 },
    })

    await store.fetchTickets()

    expect(store.tickets).toHaveLength(1)
    expect(store.total).toBe(1)
  })

  it('fetchTickets forwards filters and pagination', async () => {
    supportService.list.mockResolvedValue({ success: true, data: [], meta: { total: 0 } })
    store.page = 2
    store.perPage = 50
    store.setFilters({ type: 'BUG', status: 'OPEN' })

    await store.fetchTickets()

    expect(supportService.list).toHaveBeenCalledWith({
      type: 'BUG',
      status: 'OPEN',
      page: 2,
      per_page: 50,
    })
  })

  it('updateStatus updates current and the list row', async () => {
    store.tickets = [{ id: 5, status: 'OPEN', priority: 'NORMAL' }]
    supportService.updateStatus.mockResolvedValue({
      success: true,
      data: { id: 5, status: 'CLOSED', priority: 'NORMAL', messages: [] },
    })

    await store.updateStatus(5, 'CLOSED')

    expect(store.current.status).toBe('CLOSED')
    expect(store.tickets[0].status).toBe('CLOSED')
  })

  it('updatePriority syncs the list row badge', async () => {
    store.tickets = [{ id: 5, status: 'OPEN', priority: 'NORMAL' }]
    supportService.updatePriority.mockResolvedValue({
      success: true,
      data: { id: 5, status: 'OPEN', priority: 'HIGH', messages: [] },
    })

    await store.updatePriority(5, 'HIGH')

    expect(store.tickets[0].priority).toBe('HIGH')
  })

  it('updateType syncs the list row badge', async () => {
    store.tickets = [{ id: 5, type: 'BUG', status: 'OPEN', priority: 'NORMAL' }]
    supportService.updateType.mockResolvedValue({
      success: true,
      data: { id: 5, type: 'FEATURE', status: 'OPEN', priority: 'NORMAL', messages: [] },
    })

    await store.updateType(5, 'FEATURE')

    expect(supportService.updateType).toHaveBeenCalledWith(5, 'FEATURE')
    expect(store.current.type).toBe('FEATURE')
    expect(store.tickets[0].type).toBe('FEATURE')
  })

  it('reply updates the current ticket thread', async () => {
    supportService.reply.mockResolvedValue({
      success: true,
      data: { id: 5, status: 'OPEN', priority: 'NORMAL', messages: [{ id: 1 }, { id: 2 }] },
    })

    await store.reply(5, { body: 'on it', attachments: [] })

    expect(store.current.messages).toHaveLength(2)
  })

  it('fetchTickets sets error on failure', async () => {
    const err = new Error('boom')
    err.messageKey = 'error.internal'
    supportService.list.mockRejectedValue(err)

    await expect(store.fetchTickets()).rejects.toThrow()
    expect(store.error).toBe('error.internal')
  })
})
