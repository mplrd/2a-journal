import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { authService } from '@/services/auth'
import { api } from '@/services/api'

export const useAuthStore = defineStore('auth', () => {
  const user = ref(null)
  const loading = ref(false)
  const error = ref(null)

  const isAuthenticated = computed(() => !!api.getAccessToken())
  const fullName = computed(() => {
    if (!user.value) return ''
    return [user.value.first_name, user.value.last_name].filter(Boolean).join(' ')
  })

  function setAuthData(data) {
    api.setTokens(data.access_token, data.refresh_token)
    if (data.user) {
      user.value = data.user
    }
  }

  async function register(data) {
    loading.value = true
    error.value = null
    try {
      const response = await authService.register(data)
      setAuthData(response.data)
      return response
    } catch (err) {
      error.value = err.messageKey || 'error.internal'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function login(data) {
    loading.value = true
    error.value = null
    try {
      const response = await authService.login(data)
      setAuthData(response.data)
      return response
    } catch (err) {
      error.value = err.messageKey || 'error.internal'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function fetchProfile() {
    try {
      const response = await authService.me()
      user.value = response.data
      return response
    } catch {
      user.value = null
    }
  }

  async function logout() {
    try {
      await authService.logout()
    } catch {
      // Logout even if API call fails
    } finally {
      user.value = null
      api.clearTokens()
    }
  }

  function initFromStorage() {
    if (api.getAccessToken()) {
      fetchProfile()
    }
  }

  return {
    user,
    loading,
    error,
    isAuthenticated,
    fullName,
    register,
    login,
    logout,
    fetchProfile,
    initFromStorage,
  }
})
