import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { useSupportStore } from '@/stores/support'
import { supportService } from '@/services/support'
import { TicketType, TicketStatus } from '@/constants/support'

vi.mock('@/services/support', () => ({
  supportService: {
    list: vi.fn(),
    get: vi.fn(),
    create: vi.fn(),
    reply: vi.fn(),
    attachmentUrl: vi.fn(),
  },
}))

describe('support store', () => {
  let store

  beforeEach(() => {
    setActivePinia(createPinia())
    store = useSupportStore()
    vi.restoreAllMocks()
  })

  it('initial state is empty', () => {
    expect(store.tickets).toEqual([])
    expect(store.current).toBeNull()
    expect(store.loading).toBe(false)
    expect(store.totalRecords).toBe(0)
  })

  it('fetchTickets loads tickets and total', async () => {
    supportService.list.mockResolvedValue({
      success: true,
      data: [{ id: 1, type: TicketType.BUG, status: TicketStatus.OPEN }],
      meta: { total: 1 },
    })

    await store.fetchTickets()

    expect(store.tickets).toHaveLength(1)
    expect(store.totalRecords).toBe(1)
    expect(store.error).toBeNull()
  })

  it('fetchTickets passes pagination params', async () => {
    supportService.list.mockResolvedValue({ success: true, data: [], meta: { total: 0 } })
    store.page = 3
    store.perPage = 50
    store.setFilters({ status: TicketStatus.OPEN })

    await store.fetchTickets()

    expect(supportService.list).toHaveBeenCalledWith({
      status: TicketStatus.OPEN,
      page: 3,
      per_page: 50,
    })
  })

  it('createTicket prepends to the list and sets current', async () => {
    const ticket = { id: 9, type: TicketType.SUPPORT, status: TicketStatus.OPEN, messages: [] }
    supportService.create.mockResolvedValue({ success: true, data: ticket })

    await store.createTicket({ type: TicketType.SUPPORT, subject: 's', body: 'b', attachments: [] })

    expect(store.tickets[0].id).toBe(9)
    expect(store.current.id).toBe(9)
  })

  it('reply updates current ticket', async () => {
    const updated = { id: 9, messages: [{ id: 1 }, { id: 2 }] }
    supportService.reply.mockResolvedValue({ success: true, data: updated })

    await store.reply(9, { body: 'hi', attachments: [] })

    expect(store.current.messages).toHaveLength(2)
  })

  it('fetchTickets sets error on failure', async () => {
    const err = new Error('fail')
    err.messageKey = 'support.error.not_found'
    supportService.list.mockRejectedValue(err)

    await expect(store.fetchTickets()).rejects.toThrow()
    expect(store.error).toBe('support.error.not_found')
  })

  it('$reset clears state', async () => {
    store.tickets = [{ id: 1 }]
    store.current = { id: 1 }
    store.$reset()
    expect(store.tickets).toEqual([])
    expect(store.current).toBeNull()
  })
})
