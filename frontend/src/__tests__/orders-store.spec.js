import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { useOrdersStore } from '@/stores/orders'
import { ordersService } from '@/services/orders'
import { OrderStatus } from '@/constants/enums'

vi.mock('@/services/orders', () => ({
  ordersService: {
    list: vi.fn(),
    create: vi.fn(),
    get: vi.fn(),
    remove: vi.fn(),
    cancel: vi.fn(),
    execute: vi.fn(),
  },
}))

describe('orders store', () => {
  let store

  beforeEach(() => {
    setActivePinia(createPinia())
    store = useOrdersStore()
    vi.restoreAllMocks()
  })

  it('initial state is empty', () => {
    expect(store.orders).toEqual([])
    expect(store.loading).toBe(false)
    expect(store.error).toBeNull()
    expect(store.filters).toEqual({})
  })

  it('fetchOrders loads orders', async () => {
    const mockOrders = [
      { id: 1, symbol: 'NASDAQ', status: OrderStatus.PENDING },
      { id: 2, symbol: 'DAX', status: OrderStatus.CANCELLED },
    ]
    ordersService.list.mockResolvedValue({ success: true, data: mockOrders })

    await store.fetchOrders()

    expect(store.orders).toEqual(mockOrders)
    expect(store.loading).toBe(false)
    expect(store.error).toBeNull()
  })

  it('fetchOrders sets error on failure', async () => {
    const error = new Error('Network error')
    error.messageKey = 'error.network'
    ordersService.list.mockRejectedValue(error)

    await expect(store.fetchOrders()).rejects.toThrow()

    expect(store.error).toBe('error.network')
    expect(store.orders).toEqual([])
  })

  it('createOrder adds to list', async () => {
    const newOrder = { id: 1, symbol: 'NASDAQ', status: OrderStatus.PENDING }
    ordersService.create.mockResolvedValue({ success: true, data: newOrder })

    await store.createOrder({ symbol: 'NASDAQ' })

    expect(store.orders).toHaveLength(1)
    expect(store.orders[0].symbol).toBe('NASDAQ')
  })

  it('cancelOrder updates in list', async () => {
    store.orders = [{ id: 1, symbol: 'NASDAQ', status: OrderStatus.PENDING }]
    const cancelled = { id: 1, symbol: 'NASDAQ', status: OrderStatus.CANCELLED }
    ordersService.cancel.mockResolvedValue({ success: true, data: cancelled })

    await store.cancelOrder(1)

    expect(store.orders[0].status).toBe(OrderStatus.CANCELLED)
  })

  it('executeOrder updates in list', async () => {
    store.orders = [{ id: 1, symbol: 'NASDAQ', status: OrderStatus.PENDING }]
    const executed = { id: 1, symbol: 'NASDAQ', status: OrderStatus.EXECUTED }
    ordersService.execute.mockResolvedValue({ success: true, data: executed })

    await store.executeOrder(1)

    expect(store.orders[0].status).toBe(OrderStatus.EXECUTED)
  })

  it('deleteOrder removes from list', async () => {
    store.orders = [
      { id: 1, symbol: 'NASDAQ' },
      { id: 2, symbol: 'DAX' },
    ]
    ordersService.remove.mockResolvedValue({ success: true })

    await store.deleteOrder(1)

    expect(store.orders).toHaveLength(1)
    expect(store.orders[0].id).toBe(2)
  })

  it('deleteOrder sets error on failure', async () => {
    store.orders = [{ id: 1, symbol: 'NASDAQ' }]
    const error = new Error('Forbidden')
    error.messageKey = 'orders.error.forbidden'
    ordersService.remove.mockRejectedValue(error)

    await expect(store.deleteOrder(1)).rejects.toThrow()

    expect(store.error).toBe('orders.error.forbidden')
    expect(store.orders).toHaveLength(1)
  })

  it('loading is true during operations', async () => {
    let resolvePromise
    ordersService.list.mockReturnValue(
      new Promise((resolve) => {
        resolvePromise = resolve
      }),
    )

    const promise = store.fetchOrders()
    expect(store.loading).toBe(true)

    resolvePromise({ success: true, data: [] })
    await promise

    expect(store.loading).toBe(false)
  })

  it('setFilters updates filters', () => {
    store.setFilters({ status: OrderStatus.PENDING, symbol: 'NASDAQ' })

    expect(store.filters).toEqual({ status: OrderStatus.PENDING, symbol: 'NASDAQ' })
  })
})
