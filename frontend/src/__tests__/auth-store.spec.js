import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { useAuthStore } from '@/stores/auth'
import { api } from '@/services/api'
import { authService } from '@/services/auth'

vi.mock('@/services/auth', () => ({
  authService: {
    register: vi.fn(),
    login: vi.fn(),
    logout: vi.fn(),
    me: vi.fn(),
  },
}))

describe('auth store', () => {
  let store

  beforeEach(() => {
    setActivePinia(createPinia())
    store = useAuthStore()
    localStorage.clear()
    vi.restoreAllMocks()
  })

  it('isAuthenticated returns false when no token', () => {
    expect(store.isAuthenticated).toBe(false)
  })

  it('isAuthenticated returns true when token exists', () => {
    api.setTokens('token', 'refresh')
    // Need to re-evaluate computed
    expect(store.isAuthenticated).toBe(true)
  })

  it('login stores tokens and user', async () => {
    authService.login.mockResolvedValue({
      success: true,
      data: {
        access_token: 'access-123',
        refresh_token: 'refresh-456',
        user: { id: 1, email: 'test@test.com', first_name: 'John', last_name: 'Doe' },
      },
    })

    await store.login({ email: 'test@test.com', password: 'Test1234' })

    expect(store.user.email).toBe('test@test.com')
    expect(store.isAuthenticated).toBe(true)
    expect(store.fullName).toBe('John Doe')
    expect(api.getAccessToken()).toBe('access-123')
  })

  it('login sets error on failure', async () => {
    const error = new Error('auth.error.invalid_credentials')
    error.messageKey = 'auth.error.invalid_credentials'
    authService.login.mockRejectedValue(error)

    await expect(store.login({ email: 'bad', password: 'bad' })).rejects.toThrow()

    expect(store.error).toBe('auth.error.invalid_credentials')
    expect(store.user).toBeNull()
  })

  it('register stores tokens and user', async () => {
    authService.register.mockResolvedValue({
      success: true,
      data: {
        access_token: 'access-789',
        refresh_token: 'refresh-012',
        user: { id: 2, email: 'new@test.com', first_name: 'Jane' },
      },
    })

    await store.register({ email: 'new@test.com', password: 'Test1234', first_name: 'Jane' })

    expect(store.user.email).toBe('new@test.com')
    expect(store.isAuthenticated).toBe(true)
  })

  it('logout clears user and tokens', async () => {
    // Setup authenticated state
    api.setTokens('token', 'refresh')
    store.user = { id: 1, email: 'test@test.com' }
    authService.logout.mockResolvedValue({ success: true })

    await store.logout()

    expect(store.user).toBeNull()
    expect(api.getAccessToken()).toBeNull()
    expect(store.isAuthenticated).toBe(false)
  })

  it('logout clears state even if API call fails', async () => {
    api.setTokens('token', 'refresh')
    store.user = { id: 1, email: 'test@test.com' }
    authService.logout.mockRejectedValue(new Error('Network error'))

    await store.logout()

    expect(store.user).toBeNull()
    expect(api.getAccessToken()).toBeNull()
  })

  it('fullName handles missing parts gracefully', () => {
    store.user = { id: 1, email: 'test@test.com' }
    expect(store.fullName).toBe('')

    store.user = { id: 1, email: 'test@test.com', first_name: 'John' }
    expect(store.fullName).toBe('John')

    store.user = { id: 1, email: 'test@test.com', first_name: 'John', last_name: 'Doe' }
    expect(store.fullName).toBe('John Doe')
  })

  it('fetchProfile sets user from API', async () => {
    api.setTokens('token', 'refresh')
    authService.me.mockResolvedValue({
      success: true,
      data: { id: 1, email: 'test@test.com', first_name: 'John' },
    })

    await store.fetchProfile()

    expect(store.user.email).toBe('test@test.com')
  })

  it('fetchProfile clears user on failure', async () => {
    store.user = { id: 1, email: 'old@test.com' }
    authService.me.mockRejectedValue(new Error('Unauthorized'))

    await store.fetchProfile()

    expect(store.user).toBeNull()
  })
})
