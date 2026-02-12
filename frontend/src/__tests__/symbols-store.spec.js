import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { useSymbolsStore } from '@/stores/symbols'
import { symbolsService } from '@/services/symbols'
import { SymbolType } from '@/constants/enums'

vi.mock('@/services/symbols', () => ({
  symbolsService: {
    list: vi.fn(),
    get: vi.fn(),
    create: vi.fn(),
    update: vi.fn(),
    remove: vi.fn(),
  },
}))

const mockSymbols = [
  { id: 1, code: 'US100.CASH', name: 'NASDAQ 100', type: SymbolType.INDEX, point_value: '20.00000', currency: 'USD' },
  { id: 2, code: 'DE40.CASH', name: 'DAX 40', type: SymbolType.INDEX, point_value: '25.00000', currency: 'EUR' },
  { id: 3, code: 'EURUSD', name: 'EUR/USD', type: SymbolType.FOREX, point_value: '10.00000', currency: 'USD' },
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
      { label: 'NASDAQ 100', value: 'US100.CASH' },
      { label: 'DAX 40', value: 'DE40.CASH' },
      { label: 'EUR/USD', value: 'EURUSD' },
    ])
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

  it('createSymbol adds to list', async () => {
    const newSymbol = { id: 4, code: 'GOLD', name: 'Gold', type: SymbolType.COMMODITY, point_value: '100.00000', currency: 'USD' }
    symbolsService.create.mockResolvedValue({ success: true, data: newSymbol })

    await store.createSymbol({ code: 'GOLD', name: 'Gold', type: SymbolType.COMMODITY, point_value: 100, currency: 'USD' })

    expect(store.symbols).toHaveLength(1)
    expect(store.symbols[0].code).toBe('GOLD')
  })

  it('createSymbol sets error on failure', async () => {
    const error = new Error('Validation')
    error.messageKey = 'symbols.error.duplicate_code'
    symbolsService.create.mockRejectedValue(error)

    await expect(store.createSymbol({})).rejects.toThrow()

    expect(store.error).toBe('symbols.error.duplicate_code')
  })

  it('updateSymbol replaces in list', async () => {
    store.symbols = [
      { id: 1, code: 'NASDAQ', name: 'Old Name', type: SymbolType.INDEX, point_value: '20.00000', currency: 'USD' },
    ]
    const updated = { id: 1, code: 'NASDAQ', name: 'New Name', type: SymbolType.INDEX, point_value: '25.00000', currency: 'USD' }
    symbolsService.update.mockResolvedValue({ success: true, data: updated })

    await store.updateSymbol(1, { code: 'NASDAQ', name: 'New Name', type: SymbolType.INDEX, point_value: 25, currency: 'USD' })

    expect(store.symbols[0].name).toBe('New Name')
    expect(store.symbols[0].point_value).toBe('25.00000')
  })

  it('deleteSymbol removes from list', async () => {
    store.symbols = [
      { id: 1, code: 'NASDAQ' },
      { id: 2, code: 'DAX' },
    ]
    symbolsService.remove.mockResolvedValue({ success: true })

    await store.deleteSymbol(1)

    expect(store.symbols).toHaveLength(1)
    expect(store.symbols[0].id).toBe(2)
  })

  it('deleteSymbol sets error on failure', async () => {
    store.symbols = [{ id: 1, code: 'NASDAQ' }]
    const error = new Error('Forbidden')
    error.messageKey = 'symbols.error.forbidden'
    symbolsService.remove.mockRejectedValue(error)

    await expect(store.deleteSymbol(1)).rejects.toThrow()

    expect(store.error).toBe('symbols.error.forbidden')
    expect(store.symbols).toHaveLength(1)
  })

  it('loading is true during operations', async () => {
    let resolvePromise
    symbolsService.list.mockReturnValue(
      new Promise((resolve) => {
        resolvePromise = resolve
      }),
    )

    const promise = store.fetchSymbols()
    expect(store.loading).toBe(true)

    resolvePromise({ success: true, data: [] })
    await promise

    expect(store.loading).toBe(false)
  })
})
