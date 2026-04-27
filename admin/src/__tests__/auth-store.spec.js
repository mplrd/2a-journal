import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { useAuthStore } from '@/stores/auth'

vi.mock('@/services/auth', () => ({
  authService: {
    login: vi.fn(),
    logout: vi.fn(),
    me: vi.fn(),
  },
}))

vi.mock('@/services/api', () => ({
  api: {
    setTokens: vi.fn(),
    clearTokens: vi.fn(),
    getAccessToken: vi.fn(),
    refreshAccessToken: vi.fn(),
  },
}))

import { authService } from '@/services/auth'
import { api } from '@/services/api'

function buildJwtWithRole(role) {
  const payload = btoa(JSON.stringify({ sub: 1, role }))
  return `header.${payload}.sig`
}

describe('auth store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('logs in successfully when the JWT carries an ADMIN role', async () => {
    const token = buildJwtWithRole('ADMIN')
    authService.login.mockResolvedValueOnce({
      data: { access_token: token, user: { id: 1, email: 'a@b.com', role: 'ADMIN' } },
    })

    const store = useAuthStore()
    await store.login({ email: 'a@b.com', password: 'pwd' })

    expect(api.setTokens).toHaveBeenCalledWith(token)
    expect(store.role).toBe('ADMIN')
    expect(store.user.email).toBe('a@b.com')
  })

  it('rejects a non-admin login and clears the access token', async () => {
    const token = buildJwtWithRole('USER')
    authService.login.mockResolvedValueOnce({
      data: { access_token: token, user: { id: 2, email: 'u@b.com', role: 'USER' } },
    })

    const store = useAuthStore()
    await expect(
      store.login({ email: 'u@b.com', password: 'pwd' })
    ).rejects.toMatchObject({ code: 'NOT_ADMIN' })

    expect(api.clearTokens).toHaveBeenCalled()
    expect(store.role).toBeNull()
  })

  it('logout clears state even if the API call fails', async () => {
    authService.logout.mockRejectedValueOnce(new Error('network'))

    const store = useAuthStore()
    store.user = { id: 1 }
    store.role = 'ADMIN'

    await store.logout()

    expect(store.user).toBeNull()
    expect(store.role).toBeNull()
    expect(api.clearTokens).toHaveBeenCalled()
  })
})
