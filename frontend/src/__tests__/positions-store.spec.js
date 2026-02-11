import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { usePositionsStore } from '@/stores/positions'
import { positionsService } from '@/services/positions'
import { Direction, PositionType } from '@/constants/enums'

vi.mock('@/services/positions', () => ({
  positionsService: {
    list: vi.fn(),
    get: vi.fn(),
    update: vi.fn(),
    remove: vi.fn(),
    transfer: vi.fn(),
    getHistory: vi.fn(),
  },
}))

describe('positions store', () => {
  let store

  beforeEach(() => {
    setActivePinia(createPinia())
    store = usePositionsStore()
    vi.restoreAllMocks()
  })

  it('initial state is empty', () => {
    expect(store.positions).toEqual([])
    expect(store.loading).toBe(false)
    expect(store.error).toBeNull()
    expect(store.filters).toEqual({})
  })

  it('fetchPositions loads positions', async () => {
    const mockPositions = [
      { id: 1, symbol: 'NASDAQ', direction: Direction.BUY, position_type: PositionType.TRADE },
      { id: 2, symbol: 'DAX', direction: Direction.SELL, position_type: PositionType.ORDER },
    ]
    positionsService.list.mockResolvedValue({ success: true, data: mockPositions })

    await store.fetchPositions()

    expect(store.positions).toEqual(mockPositions)
    expect(store.loading).toBe(false)
    expect(store.error).toBeNull()
  })

  it('fetchPositions sets error on failure', async () => {
    const error = new Error('Network error')
    error.messageKey = 'error.network'
    positionsService.list.mockRejectedValue(error)

    await expect(store.fetchPositions()).rejects.toThrow()

    expect(store.error).toBe('error.network')
    expect(store.positions).toEqual([])
  })

  it('updatePosition replaces in list', async () => {
    store.positions = [
      { id: 1, symbol: 'NASDAQ', entry_price: '18500.00000' },
    ]
    const updated = { id: 1, symbol: 'NASDAQ', entry_price: '19000.00000' }
    positionsService.update.mockResolvedValue({ success: true, data: updated })

    await store.updatePosition(1, { entry_price: 19000 })

    expect(store.positions[0].entry_price).toBe('19000.00000')
  })

  it('deletePosition removes from list', async () => {
    store.positions = [
      { id: 1, symbol: 'NASDAQ' },
      { id: 2, symbol: 'DAX' },
    ]
    positionsService.remove.mockResolvedValue({ success: true })

    await store.deletePosition(1)

    expect(store.positions).toHaveLength(1)
    expect(store.positions[0].id).toBe(2)
  })

  it('deletePosition sets error on failure', async () => {
    store.positions = [{ id: 1, symbol: 'NASDAQ' }]
    const error = new Error('Forbidden')
    error.messageKey = 'positions.error.forbidden'
    positionsService.remove.mockRejectedValue(error)

    await expect(store.deletePosition(1)).rejects.toThrow()

    expect(store.error).toBe('positions.error.forbidden')
    expect(store.positions).toHaveLength(1)
  })

  it('transferPosition updates in list', async () => {
    store.positions = [
      { id: 1, symbol: 'NASDAQ', account_id: 10 },
    ]
    const transferred = { id: 1, symbol: 'NASDAQ', account_id: 20 }
    positionsService.transfer.mockResolvedValue({ success: true, data: transferred })

    await store.transferPosition(1, 20)

    expect(store.positions[0].account_id).toBe(20)
    expect(positionsService.transfer).toHaveBeenCalledWith(1, { account_id: 20 })
  })

  it('loading is true during operations', async () => {
    let resolvePromise
    positionsService.list.mockReturnValue(
      new Promise((resolve) => {
        resolvePromise = resolve
      }),
    )

    const promise = store.fetchPositions()
    expect(store.loading).toBe(true)

    resolvePromise({ success: true, data: [] })
    await promise

    expect(store.loading).toBe(false)
  })

  it('setFilters updates filters', () => {
    store.setFilters({ position_type: PositionType.TRADE, symbol: 'NASDAQ' })

    expect(store.filters).toEqual({ position_type: PositionType.TRADE, symbol: 'NASDAQ' })
  })
})
