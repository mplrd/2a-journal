const BASE_URL = import.meta.env.VITE_API_URL || 'http://localhost/api'

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

  // Auto-refresh on 401 with TOKEN_EXPIRED
  if (response.status === 401 && auth && retry) {
    const errorData = await response.json().catch(() => null)
    if (errorData?.error?.code === 'TOKEN_EXPIRED') {
      try {
        const newToken = await refreshAccessToken()
        headers['Authorization'] = `Bearer ${newToken}`
        response = await fetch(`${BASE_URL}${path}`, { method, headers, body: options.body, credentials: 'include' })
      } catch {
        clearTokens()
        window.location.href = '/login'
        throw new Error('Session expired')
      }
    } else {
      // Body already consumed — throw directly with parsed data
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
  delete: (path, options) => request('DELETE', path, null, options),
  setTokens,
  clearTokens,
  getAccessToken,
  refreshAccessToken,
}
