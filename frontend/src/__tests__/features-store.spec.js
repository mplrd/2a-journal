import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { useFeaturesStore } from '@/stores/features'
import { featuresService } from '@/services/features'

vi.mock('@/services/features', () => ({
  featuresService: {
    get: vi.fn(),
  },
}))

describe('features store', () => {
  let store

  beforeEach(() => {
    setActivePinia(createPinia())
    store = useFeaturesStore()
    vi.clearAllMocks()
  })

  it('defaults brokerAutoSync to false before load', () => {
    expect(store.brokerAutoSync).toBe(false)
    expect(store.loaded).toBe(false)
  })

  it('load sets brokerAutoSync from API', async () => {
    featuresService.get.mockResolvedValue({ success: true, data: { broker_auto_sync: true } })

    await store.load()

    expect(store.brokerAutoSync).toBe(true)
    expect(store.loaded).toBe(true)
  })

  it('load keeps brokerAutoSync false when API returns false', async () => {
    featuresService.get.mockResolvedValue({ success: true, data: { broker_auto_sync: false } })

    await store.load()

    expect(store.brokerAutoSync).toBe(false)
    expect(store.loaded).toBe(true)
  })

  it('load falls back to false on API failure', async () => {
    featuresService.get.mockRejectedValue(new Error('Network error'))

    await store.load()

    expect(store.brokerAutoSync).toBe(false)
    expect(store.loaded).toBe(true)
  })

  it('load is idempotent — does not refetch once loaded', async () => {
    featuresService.get.mockResolvedValue({ success: true, data: { broker_auto_sync: true } })
    await store.load()
    await store.load()

    expect(featuresService.get).toHaveBeenCalledTimes(1)
  })
})
