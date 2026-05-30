import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { authService } from '@/services/auth'
import { api } from '@/services/api'
import { useAccountsStore } from '@/stores/accounts'
import { useSymbolsStore } from '@/stores/symbols'
import { usePositionsStore } from '@/stores/positions'
import { useOrdersStore } from '@/stores/orders'
import { useTradesStore } from '@/stores/trades'
import { useBillingStore } from '@/stores/billing'

// Cross-tab channel: lets a tab that just verified its email tell the other
// open tabs to refresh their cached profile, so the verification banner
// disappears everywhere without a manual reload. Module-level (one per
// document) and guarded for environments without BroadcastChannel.
const EMAIL_VERIFIED = 'email-verified'
let authChannel = null

function getAuthChannel() {
  if (authChannel === null && typeof BroadcastChannel !== 'undefined') {
    authChannel = new BroadcastChannel('auth')
  }
  return authChannel
}

export const useAuthStore = defineStore('auth', () => {
  const user = ref(null)
  const loading = ref(false)
  const error = ref(null)
  const initialized = ref(false)

  const isAuthenticated = computed(() => !!api.getAccessToken())
  const fullName = computed(() => {
    if (!user.value) return ''
    return [user.value.first_name, user.value.last_name].filter(Boolean).join(' ')
  })

  function setAuthData(data) {
    api.setTokens(data.access_token)
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
      useAccountsStore().$reset()
      useSymbolsStore().$reset()
      usePositionsStore().$reset()
      useOrdersStore().$reset()
      useTradesStore().$reset()
      useBillingStore().reset()
    }
  }

  async function updateLocale(locale) {
    try {
      const response = await authService.updateLocale(locale)
      user.value = response.data
    } catch {
      // Non-blocking — locale is still applied client-side
    }
  }

  async function updateProfile(data) {
    try {
      const response = await authService.updateProfile(data)
      user.value = response.data
    } catch {
      // Non-blocking — settings are still applied client-side
    }
  }

  async function uploadProfilePicture(file) {
    const response = await authService.uploadProfilePicture(file)
    user.value = response.data
    return response
  }

  async function resendVerification() {
    return authService.resendVerification()
  }

  async function changePassword(data) {
    return authService.changePassword(data)
  }

  async function deleteAccount(data) {
    const response = await authService.deleteAccount(data)
    user.value = null
    api.clearTokens()
    useAccountsStore().$reset()
    useSymbolsStore().$reset()
    usePositionsStore().$reset()
    useOrdersStore().$reset()
    useTradesStore().$reset()
    useBillingStore().reset()
    return response
  }

  // Start listening for verification signals from other tabs. Safe to call
  // once at app startup; the listener survives route changes.
  function startCrossTabSync() {
    const channel = getAuthChannel()
    if (!channel) return
    channel.onmessage = (event) => {
      if (event.data?.type === EMAIL_VERIFIED && api.getAccessToken()) {
        fetchProfile()
      }
    }
  }

  // Tell the other open tabs that this account's email is now verified.
  // The sending tab does not receive its own message, so callers still
  // refresh their own profile separately.
  function notifyEmailVerified() {
    getAuthChannel()?.postMessage({ type: EMAIL_VERIFIED })
  }

  async function initSession() {
    try {
      const response = await api.refreshAccessToken()
      if (response) {
        await fetchProfile()
      }
    } catch {
      // No valid session — user stays unauthenticated
      user.value = null
      api.clearTokens()
    } finally {
      initialized.value = true
    }
  }

  return {
    user,
    loading,
    error,
    initialized,
    isAuthenticated,
    fullName,
    register,
    login,
    logout,
    fetchProfile,
    updateLocale,
    updateProfile,
    uploadProfilePicture,
    resendVerification,
    changePassword,
    deleteAccount,
    initSession,
    startCrossTabSync,
    notifyEmailVerified,
  }
})
