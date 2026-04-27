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
  const loading = ref(false)
  const error = ref(null)
  const initialized = ref(false)

  const isAuthenticated = computed(() => !!api.getAccessToken())
  const isAdmin = computed(() => role.value === 'ADMIN')

  function applyTokenAndDecodeRole(accessToken) {
    api.setTokens(accessToken)
    const payload = decodeJwtPayload(accessToken)
    role.value = payload?.role ?? null
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
        api.clearTokens()
        role.value = null
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
      role.value = null
      api.clearTokens()
    }
  }

  async function initSession() {
    try {
      const accessToken = await api.refreshAccessToken()
      if (accessToken) {
        applyTokenAndDecodeRole(accessToken)
        if (role.value !== 'ADMIN') {
          api.clearTokens()
          role.value = null
        } else {
          await fetchProfile()
        }
      }
    } catch {
      user.value = null
      role.value = null
      api.clearTokens()
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
    logout,
    fetchProfile,
    initSession,
  }
})
