// HTTP client for the admin SPA. Mirror of the user frontend's api.js, with
// the admin-only twist that a 403 admin_only error logs the user out instead
// of redirecting to a subscription flow.
//
// Default `/api` (relative) works for any setup where the admin SPA and the
// API are served from the same origin (Apache vhost with /api alias, or a
// Railway service that proxies /api to the API). Override via VITE_API_URL
// when the admin SPA and API live on separate origins (typical Railway prod).
const BASE_URL = import.meta.env.VITE_API_URL || '/api'

let accessToken = null

function getAccessToken() {
  return accessToken
}

function setTokens(at) {
  accessToken = at
}

function clearTokens() {
  accessToken = null
}

async function refreshAccessToken() {
  const response = await fetch(`${BASE_URL}/auth/refresh`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'include',
  })

  if (!response.ok) {
    clearTokens()
    throw new Error('Refresh failed')
  }

  const data = await response.json()
  setTokens(data.data.access_token)
  return data.data.access_token
}

async function request(method, path, body = null, { auth = true, retry = true } = {}) {
  const headers = { 'Content-Type': 'application/json' }

  if (auth) {
    const token = getAccessToken()
    if (token) {
      headers['Authorization'] = `Bearer ${token}`
    }
  }

  const options = { method, headers, credentials: 'include' }
  if (body !== null) {
    options.body = JSON.stringify(body)
  }

  let response = await fetch(`${BASE_URL}${path}`, options)

  if (response.status === 401 && auth && retry) {
    const errorData = await response.json().catch(() => null)
    if (errorData?.error?.code === 'TOKEN_EXPIRED') {
      try {
        const newToken = await refreshAccessToken()
        headers['Authorization'] = `Bearer ${newToken}`
        response = await fetch(`${BASE_URL}${path}`, { method, headers, body: options.body, credentials: 'include' })
      } catch {
        clearTokens()
        if (typeof window !== 'undefined') {
          window.location.href = '/login'
        }
        throw new Error('Session expired')
      }
    } else {
      const error = new Error(errorData?.error?.message_key || 'error.internal')
      error.status = response.status
      error.code = errorData?.error?.code
      error.field = errorData?.error?.field
      error.messageKey = errorData?.error?.message_key
      throw error
    }
  }

  const data = await response.json()

  if (!response.ok) {
    const error = new Error(data.error?.message_key || 'error.internal')
    error.status = response.status
    error.code = data.error?.code
    error.field = data.error?.field
    error.messageKey = data.error?.message_key
    throw error
  }

  return data
}

export const api = {
  get: (path, options) => request('GET', path, null, options),
  post: (path, body, options) => request('POST', path, body, options),
  put: (path, body, options) => request('PUT', path, body, options),
  patch: (path, body, options) => request('PATCH', path, body, options),
  delete: (path, body = null, options) => request('DELETE', path, body, options),
  setTokens,
  clearTokens,
  getAccessToken,
  refreshAccessToken,
}
