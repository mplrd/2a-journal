import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { authService } from '@/services/auth'
import { api } from '@/services/api'
import { decodeJwtPayload } from '@/utils/jwt'

class NotAdminError extends Error {
  constructor() {
    super('not-admin')
    this.code = 'NOT_ADMIN'
  }
}

export const useAuthStore = defineStore('auth', () => {
  const user = ref(null)
  const role = ref(null)
  // Mirror the access token in a reactive ref so Vue computed/watcher can
  // observe its changes. The api module's getAccessToken() returns a plain
  // module variable that Vue reactivity cannot track — the router guard
  // would see a stale `false` for isAuthenticated otherwise.
  const accessToken = ref(null)
  const loading = ref(false)
  const error = ref(null)
  const initialized = ref(false)

  const isAuthenticated = computed(() => !!accessToken.value)
  const isAdmin = computed(() => role.value === 'ADMIN')

  function applyTokenAndDecodeRole(token) {
    api.setTokens(token)
    accessToken.value = token
    const payload = decodeJwtPayload(token)
    role.value = payload?.role ?? null
  }

  function clearAuthState() {
    api.clearTokens()
    accessToken.value = null
    role.value = null
  }

  async function login(credentials) {
    loading.value = true
    error.value = null
    try {
      const response = await authService.login(credentials)
      applyTokenAndDecodeRole(response.data.access_token)

      if (role.value !== 'ADMIN') {
        // Drop the access token so subsequent calls fail clean.
        // The refresh cookie is still set; it'll expire naturally.
        clearAuthState()
        throw new NotAdminError()
      }

      user.value = response.data.user || null
      return response
    } catch (err) {
      error.value = err.code === 'NOT_ADMIN' ? 'auth.error.admin_only' : (err.messageKey || 'error.internal')
      throw err
    } finally {
      loading.value = false
    }
  }

  async function fetchProfile() {
    try {
      const response = await authService.me()
      user.value = response.data
      // Server-side role is the source of truth; refresh it from the response
      if (response.data?.role) {
        role.value = response.data.role
      }
      return response
    } catch {
      user.value = null
    }
  }

  async function logout() {
    try {
      await authService.logout()
    } catch {
      // logout best-effort
    } finally {
      user.value = null
      clearAuthState()
    }
  }

  async function loginWithSsoCode(code) {
    loading.value = true
    error.value = null
    try {
      const response = await authService.ssoExchange(code)
      applyTokenAndDecodeRole(response.data.access_token)

      // Same admin-gate as the password login path: a non-admin who somehow
      // exchanges a code (the user SPA endpoint is open to all authenticated
      // users) still must not get into the admin BO.
      if (role.value !== 'ADMIN') {
        clearAuthState()
        throw new NotAdminError()
      }

      user.value = response.data.user || null
      return response
    } catch (err) {
      error.value = err.code === 'NOT_ADMIN'
        ? 'auth.error.admin_only'
        : (err.messageKey || 'error.internal')
      throw err
    } finally {
      loading.value = false
    }
  }

  async function initSession() {
    try {
      const token = await api.refreshAccessToken()
      if (token) {
        applyTokenAndDecodeRole(token)
        if (role.value !== 'ADMIN') {
          clearAuthState()
        } else {
          await fetchProfile()
        }
      }
    } catch {
      user.value = null
      clearAuthState()
    } finally {
      initialized.value = true
    }
  }

  return {
    user,
    role,
    loading,
    error,
    initialized,
    isAuthenticated,
    isAdmin,
    login,
    loginWithSsoCode,
    logout,
    fetchProfile,
    initSession,
  }
})
