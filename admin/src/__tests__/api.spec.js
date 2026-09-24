import { describe, it, expect, vi, beforeEach } from 'vitest'
import { api } from '@/services/api'

describe('api service', () => {
  beforeEach(() => {
    api.clearTokens()
    vi.restoreAllMocks()
  })

  // The startup session restore has to tell "no session" from "too many
  // attempts": the first lands on the login page silently, the second has to
  // say why.
  it('refreshAccessToken surfaces the status, code and message_key of a failure', async () => {
    api.setTokens('stale-token')
    vi.spyOn(globalThis, 'fetch').mockResolvedValue({
      ok: false,
      status: 429,
      json: () => Promise.resolve({
        success: false,
        error: { code: 'TOO_MANY_REQUESTS', message_key: 'error.rate_limit_exceeded' },
      }),
    })

    await expect(api.refreshAccessToken()).rejects.toMatchObject({
      status: 429,
      code: 'TOO_MANY_REQUESTS',
      messageKey: 'error.rate_limit_exceeded',
    })
    expect(api.getAccessToken()).toBeNull()
  })
})
