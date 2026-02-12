import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { useSymbolsStore } from '@/stores/symbols'
import { symbolsService } from '@/services/symbols'

vi.mock('@/services/symbols', () => ({
  symbolsService: {
    list: vi.fn(),
  },
}))

const mockSymbols = [
  { id: 1, code: 'NASDAQ', name: 'NASDAQ 100', type: 'INDEX', point_value: '1.00000', currency: 'USD' },
  { id: 2, code: 'DAX', name: 'DAX 40', type: 'INDEX', point_value: '1.00000', currency: 'EUR' },
  { id: 3, code: 'EURUSD', name: 'EUR/USD', type: 'FOREX', point_value: '0.00010', currency: 'USD' },
]

describe('symbols store', () => {
  let store

  beforeEach(() => {
    setActivePinia(createPinia())
    store = useSymbolsStore()
    vi.clearAllMocks()
  })

  it('initial state is empty', () => {
    expect(store.symbols).toEqual([])
    expect(store.loading).toBe(false)
    expect(store.loaded).toBe(false)
    expect(store.error).toBeNull()
  })

  it('fetchSymbols loads symbols from API', async () => {
    symbolsService.list.mockResolvedValue({ success: true, data: mockSymbols })

    await store.fetchSymbols()

    expect(store.symbols).toEqual(mockSymbols)
    expect(store.loading).toBe(false)
    expect(store.loaded).toBe(true)
    expect(symbolsService.list).toHaveBeenCalledOnce()
  })

  it('fetchSymbols does not reload if already loaded', async () => {
    symbolsService.list.mockResolvedValue({ success: true, data: mockSymbols })

    await store.fetchSymbols()
    await store.fetchSymbols()

    expect(symbolsService.list).toHaveBeenCalledOnce()
  })

  it('fetchSymbols force reloads when requested', async () => {
    symbolsService.list.mockResolvedValue({ success: true, data: mockSymbols })

    await store.fetchSymbols()
    await store.fetchSymbols(true)

    expect(symbolsService.list).toHaveBeenCalledTimes(2)
  })

  it('symbolOptions returns formatted options for Select', async () => {
    symbolsService.list.mockResolvedValue({ success: true, data: mockSymbols })

    await store.fetchSymbols()

    expect(store.symbolOptions).toEqual([
      { label: 'NASDAQ', value: 'NASDAQ' },
      { label: 'DAX', value: 'DAX' },
      { label: 'EURUSD', value: 'EURUSD' },
    ])
  })

  it('loading is true during fetch', async () => {
    let resolvePromise
    symbolsService.list.mockReturnValue(
      new Promise((resolve) => {
        resolvePromise = resolve
      }),
    )

    const promise = store.fetchSymbols()
    expect(store.loading).toBe(true)

    resolvePromise({ success: true, data: mockSymbols })
    await promise

    expect(store.loading).toBe(false)
  })

  it('fetchSymbols sets error on failure', async () => {
    const error = new Error('Network error')
    error.messageKey = 'error.network'
    symbolsService.list.mockRejectedValue(error)

    await expect(store.fetchSymbols()).rejects.toThrow()

    expect(store.error).toBe('error.network')
    expect(store.loaded).toBe(false)
    expect(store.symbols).toEqual([])
  })
})
