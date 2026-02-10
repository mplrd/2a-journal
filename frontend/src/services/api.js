const BASE_URL = import.meta.env.VITE_API_URL || 'http://localhost/api'

function getAccessToken() {
  return localStorage.getItem('access_token')
}

function setTokens(accessToken, refreshToken) {
  localStorage.setItem('access_token', accessToken)
  if (refreshToken) {
    localStorage.setItem('refresh_token', refreshToken)
  }
}

function clearTokens() {
  localStorage.removeItem('access_token')
  localStorage.removeItem('refresh_token')
}

async function refreshAccessToken() {
  const refreshToken = localStorage.getItem('refresh_token')
  if (!refreshToken) {
    throw new Error('No refresh token')
  }

  const response = await fetch(`${BASE_URL}/auth/refresh`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ refresh_token: refreshToken }),
  })

  if (!response.ok) {
    clearTokens()
    throw new Error('Refresh failed')
  }

  const data = await response.json()
  setTokens(data.data.access_token, data.data.refresh_token)
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

  const options = { method, headers }
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
        response = await fetch(`${BASE_URL}${path}`, { method, headers, body: options.body })
      } catch {
        clearTokens()
        window.location.href = '/login'
        throw new Error('Session expired')
      }
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
  delete: (path, options) => request('DELETE', path, null, options),
  setTokens,
  clearTokens,
  getAccessToken,
}
