import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { useSettingsStore } from '@/stores/settings'

vi.mock('@/services/settings', () => ({
  settingsService: {
    list: vi.fn(),
    update: vi.fn(),
  },
}))

import { settingsService } from '@/services/settings'

describe('settings store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('fetches and stores settings list', async () => {
    settingsService.list.mockResolvedValueOnce({
      data: [
        { key: 'broker_auto_sync_enabled', type: 'BOOL', value: false, source: 'env' },
      ],
    })

    const store = useSettingsStore()
    await store.fetchSettings()

    expect(store.settings).toHaveLength(1)
    expect(store.settings[0].key).toBe('broker_auto_sync_enabled')
  })

  it('replaces full list after an update', async () => {
    const updated = [
      { key: 'broker_auto_sync_enabled', type: 'BOOL', value: true, source: 'db' },
    ]
    settingsService.update.mockResolvedValueOnce({ data: updated })

    const store = useSettingsStore()
    store.settings = [{ key: 'broker_auto_sync_enabled', type: 'BOOL', value: false, source: 'env' }]

    await store.update('broker_auto_sync_enabled', true)
    expect(store.settings[0].value).toBe(true)
    expect(store.settings[0].source).toBe('db')
  })
})
