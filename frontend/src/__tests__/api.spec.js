import { describe, it, expect, vi, beforeEach } from 'vitest'
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
})
