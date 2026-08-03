import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { api } from '@/services/api'

describe('api service', () => {
  beforeEach(() => {
    api.clearTokens()
    vi.restoreAllMocks()
  })

  it('sets and retrieves access token in memory', () => {
    api.setTokens('access-123')

    expect(api.getAccessToken()).toBe('access-123')
  })

  it('clears access token from memory', () => {
    api.setTokens('access-123')
    api.clearTokens()

    expect(api.getAccessToken()).toBeNull()
  })

  it('does not use localStorage for tokens', () => {
    api.setTokens('access-123')

    expect(localStorage.getItem('access_token')).toBeNull()
    expect(localStorage.getItem('refresh_token')).toBeNull()
  })

  it('sends GET request without body', async () => {
    const mockResponse = { success: true, data: { status: 'ok' } }
    vi.spyOn(globalThis, 'fetch').mockResolvedValue({
      ok: true,
      status: 200,
      json: () => Promise.resolve(mockResponse),
    })

    const result = await api.get('/health', { auth: false })

    expect(result).toEqual(mockResponse)
    const [url, options] = fetch.mock.calls[0]
    expect(url).toContain('/health')
    expect(options.method).toBe('GET')
    expect(options.body).toBeUndefined()
  })

  it('sends POST request with JSON body', async () => {
    const mockResponse = { success: true, data: {} }
    vi.spyOn(globalThis, 'fetch').mockResolvedValue({
      ok: true,
      status: 200,
      json: () => Promise.resolve(mockResponse),
    })

    await api.post('/auth/login', { email: 'test@test.com', password: 'Test1234' }, { auth: false })

    const [, options] = fetch.mock.calls[0]
    expect(options.method).toBe('POST')
    expect(JSON.parse(options.body)).toEqual({ email: 'test@test.com', password: 'Test1234' })
  })

  it('includes credentials in all requests', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue({
      ok: true,
      status: 200,
      json: () => Promise.resolve({ success: true, data: {} }),
    })

    await api.get('/health', { auth: false })

    const [, options] = fetch.mock.calls[0]
    expect(options.credentials).toBe('include')
  })

  it('includes Authorization header when authenticated', async () => {
    api.setTokens('my-token')
    vi.spyOn(globalThis, 'fetch').mockResolvedValue({
      ok: true,
      status: 200,
      json: () => Promise.resolve({ success: true, data: {} }),
    })

    await api.get('/auth/me')

    const [, options] = fetch.mock.calls[0]
    expect(options.headers['Authorization']).toBe('Bearer my-token')
  })

  it('does not include Authorization header for guest requests', async () => {
    api.setTokens('my-token')
    vi.spyOn(globalThis, 'fetch').mockResolvedValue({
      ok: true,
      status: 200,
      json: () => Promise.resolve({ success: true, data: {} }),
    })

    await api.get('/health', { auth: false })

    const [, options] = fetch.mock.calls[0]
    expect(options.headers['Authorization']).toBeUndefined()
  })

  it('throws error with messageKey on API error', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue({
      ok: false,
      status: 401,
      json: () => Promise.resolve({
        success: false,
        error: { code: 'INVALID_CREDENTIALS', message_key: 'auth.error.invalid_credentials' },
      }),
    })

    try {
      await api.post('/auth/login', {}, { auth: false })
      expect.unreachable('Should have thrown')
    } catch (err) {
      expect(err.status).toBe(401)
      expect(err.code).toBe('INVALID_CREDENTIALS')
      expect(err.messageKey).toBe('auth.error.invalid_credentials')
    }
  })

  it('handles 401 TOKEN_MISSING without double body read', async () => {
    api.setTokens('bad-token')
    vi.spyOn(globalThis, 'fetch').mockResolvedValue({
      ok: false,
      status: 401,
      json: () => Promise.resolve({
        success: false,
        error: { code: 'TOKEN_MISSING', message_key: 'auth.error.token_missing' },
      }),
    })

    try {
      await api.get('/accounts')
      expect.unreachable('Should have thrown')
    } catch (err) {
      expect(err.status).toBe(401)
      expect(err.code).toBe('TOKEN_MISSING')
      expect(err.messageKey).toBe('auth.error.token_missing')
    }
  })

  describe('getBlob', () => {
    it('returns the blob on success', async () => {
      const blob = new Blob(['x'])
      vi.spyOn(globalThis, 'fetch').mockResolvedValue({
        ok: true,
        status: 200,
        blob: () => Promise.resolve(blob),
      })

      const result = await api.getBlob('/support/tickets/1/attachments/2')
      expect(result).toBe(blob)
    })

    it('throws error.network when fetch itself rejects', async () => {
      vi.spyOn(globalThis, 'fetch').mockRejectedValue(new TypeError('Failed to fetch'))

      await expect(api.getBlob('/x')).rejects.toMatchObject({
        messageKey: 'error.network',
        status: 0,
      })
    })

    it('surfaces the JSON message_key on an HTTP error', async () => {
      vi.spyOn(globalThis, 'fetch').mockResolvedValue({
        ok: false,
        status: 404,
        text: () => Promise.resolve(JSON.stringify({
          success: false,
          error: { code: 'NOT_FOUND', message_key: 'support.error.attachment_not_found' },
        })),
      })

      await expect(api.getBlob('/x')).rejects.toMatchObject({
        messageKey: 'support.error.attachment_not_found',
        status: 404,
      })
    })

    it('falls back to error.internal when the error body is not JSON', async () => {
      vi.spyOn(globalThis, 'fetch').mockResolvedValue({
        ok: false,
        status: 500,
        text: () => Promise.resolve('<html>oops</html>'),
      })

      await expect(api.getBlob('/x')).rejects.toMatchObject({
        messageKey: 'error.internal',
        status: 500,
      })
    })
  })

  // The access token lives 15 minutes and is only renewed reactively. A user who
  // spends that long filling a form (support message, note, import) would
  // otherwise lose everything on submit: upload() and getBlob() used to throw the
  // 401 straight through instead of refreshing like request() does.
  describe('401 TOKEN_EXPIRED recovery', () => {
    let originalLocation

    beforeEach(() => {
      originalLocation = window.location
      Object.defineProperty(window, 'location', {
        value: { href: '', pathname: '/support' },
        writable: true,
        configurable: true,
      })
    })

    afterEach(() => {
      Object.defineProperty(window, 'location', {
        value: originalLocation,
        writable: true,
        configurable: true,
      })
    })

    function expiredBody() {
      return JSON.stringify({
        success: false,
        error: { code: 'TOKEN_EXPIRED', message_key: 'auth.error.token_expired' },
      })
    }

    it('upload refreshes the token and replays the request', async () => {
      api.setTokens('expired-token')
      const fetchSpy = vi.spyOn(globalThis, 'fetch')
        .mockResolvedValueOnce({ ok: false, status: 401, text: () => Promise.resolve(expiredBody()) })
        .mockResolvedValueOnce({
          ok: true,
          status: 200,
          json: () => Promise.resolve({ success: true, data: { access_token: 'fresh-token' } }),
        })
        .mockResolvedValueOnce({
          ok: true,
          status: 201,
          text: () => Promise.resolve(JSON.stringify({ success: true, data: { id: 7 } })),
        })

      const result = await api.upload('/support/tickets/33/messages', new FormData())

      expect(result).toEqual({ success: true, data: { id: 7 } })
      expect(fetchSpy).toHaveBeenCalledTimes(3)
      expect(String(fetchSpy.mock.calls[1][0])).toContain('/auth/refresh')
      expect(fetchSpy.mock.calls[2][1].headers['Authorization']).toBe('Bearer fresh-token')
      expect(api.getAccessToken()).toBe('fresh-token')
    })

    it('upload leaves a 401 that is not TOKEN_EXPIRED untouched', async () => {
      api.setTokens('bad-token')
      const fetchSpy = vi.spyOn(globalThis, 'fetch').mockResolvedValue({
        ok: false,
        status: 401,
        text: () => Promise.resolve(JSON.stringify({
          success: false,
          error: { code: 'TOKEN_INVALID', message_key: 'auth.error.token_invalid' },
        })),
      })

      await expect(api.upload('/notes', new FormData())).rejects.toMatchObject({
        status: 401,
        code: 'TOKEN_INVALID',
        messageKey: 'auth.error.token_invalid',
      })
      expect(fetchSpy).toHaveBeenCalledTimes(1)
    })

    it('upload still reports an oversized body as upload.error.too_large', async () => {
      api.setTokens('good-token')
      vi.spyOn(globalThis, 'fetch').mockResolvedValue({
        ok: false,
        status: 413,
        text: () => Promise.resolve('<html>413</html>'),
      })

      await expect(api.upload('/imports/preview', new FormData())).rejects.toMatchObject({
        status: 413,
        messageKey: 'upload.error.too_large',
      })
    })

    it('upload clears the session and redirects to /login when the refresh fails', async () => {
      api.setTokens('expired-token')
      vi.spyOn(globalThis, 'fetch')
        .mockResolvedValueOnce({ ok: false, status: 401, text: () => Promise.resolve(expiredBody()) })
        .mockResolvedValueOnce({ ok: false, status: 401, json: () => Promise.resolve({}) })

      await expect(api.upload('/notes', new FormData())).rejects.toThrow()
      expect(api.getAccessToken()).toBeNull()
      expect(window.location.href).toBe('/login')
    })

    it('getBlob refreshes the token and replays the request', async () => {
      api.setTokens('expired-token')
      const blob = new Blob(['pdf'])
      const fetchSpy = vi.spyOn(globalThis, 'fetch')
        .mockResolvedValueOnce({ ok: false, status: 401, text: () => Promise.resolve(expiredBody()) })
        .mockResolvedValueOnce({
          ok: true,
          status: 200,
          json: () => Promise.resolve({ success: true, data: { access_token: 'fresh-token' } }),
        })
        .mockResolvedValueOnce({ ok: true, status: 200, blob: () => Promise.resolve(blob) })

      const result = await api.getBlob('/support/tickets/1/attachments/2')

      expect(result).toBe(blob)
      expect(fetchSpy).toHaveBeenCalledTimes(3)
      expect(fetchSpy.mock.calls[2][1].headers['Authorization']).toBe('Bearer fresh-token')
    })

    it('getBlob leaves a 404 untouched', async () => {
      api.setTokens('good-token')
      const fetchSpy = vi.spyOn(globalThis, 'fetch').mockResolvedValue({
        ok: false,
        status: 404,
        text: () => Promise.resolve(JSON.stringify({
          success: false,
          error: { code: 'NOT_FOUND', message_key: 'support.error.attachment_not_found' },
        })),
      })

      await expect(api.getBlob('/x')).rejects.toMatchObject({ status: 404 })
      expect(fetchSpy).toHaveBeenCalledTimes(1)
    })

    // The server rotates the refresh token and deletes the old one on use, so two
    // concurrent refreshes would leave the loser holding a revoked token.
    it('coalesces concurrent refreshes into a single /auth/refresh call', async () => {
      api.setTokens('expired-token')
      let refreshCalls = 0

      vi.spyOn(globalThis, 'fetch').mockImplementation((url, options) => {
        if (String(url).includes('/auth/refresh')) {
          refreshCalls += 1
          return new Promise((resolve) => setTimeout(() => resolve({
            ok: true,
            status: 200,
            json: () => Promise.resolve({ success: true, data: { access_token: 'fresh-token' } }),
          }), 10))
        }
        if (options?.headers?.['Authorization'] === 'Bearer expired-token') {
          return Promise.resolve({
            ok: false,
            status: 401,
            json: () => Promise.resolve(JSON.parse(expiredBody())),
            text: () => Promise.resolve(expiredBody()),
          })
        }
        return Promise.resolve({
          ok: true,
          status: 200,
          json: () => Promise.resolve({ success: true, data: {} }),
          text: () => Promise.resolve(JSON.stringify({ success: true, data: {} })),
        })
      })

      await Promise.all([
        api.get('/accounts'),
        api.get('/symbols'),
        api.upload('/notes', new FormData()),
      ])

      expect(refreshCalls).toBe(1)
      expect(api.getAccessToken()).toBe('fresh-token')
    })
  })
})
