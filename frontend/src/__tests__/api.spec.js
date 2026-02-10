import { describe, it, expect, vi, beforeEach } from 'vitest'
import { api } from '@/services/api'

describe('api service', () => {
  beforeEach(() => {
    localStorage.clear()
    vi.restoreAllMocks()
  })

  it('sets and retrieves tokens from localStorage', () => {
    api.setTokens('access-123', 'refresh-456')

    expect(api.getAccessToken()).toBe('access-123')
    expect(localStorage.getItem('refresh_token')).toBe('refresh-456')
  })

  it('clears tokens from localStorage', () => {
    api.setTokens('access-123', 'refresh-456')
    api.clearTokens()

    expect(api.getAccessToken()).toBeNull()
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

  it('includes Authorization header when authenticated', async () => {
    api.setTokens('my-token', 'refresh')
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
    api.setTokens('my-token', 'refresh')
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
})
