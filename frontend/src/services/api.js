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
      if (response.status === 402 && !path.startsWith('/billing')) {
        redirectToSubscribe()
      }
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
    if (response.status === 402 && !path.startsWith('/billing')) {
      redirectToSubscribe()
    }
    const error = new Error(data.error?.message_key || 'error.internal')
    error.status = response.status
    error.code = data.error?.code
    error.field = data.error?.field
    error.messageKey = data.error?.message_key
    throw error
  }

  return data
}

function redirectToSubscribe() {
  // Avoid loop if already on the subscribe page
  if (typeof window !== 'undefined' && !window.location.pathname.startsWith('/subscribe')) {
    window.location.href = '/subscribe'
  }
}

async function upload(path, formData) {
  const headers = {}

  const token = getAccessToken()
  if (token) {
    headers['Authorization'] = `Bearer ${token}`
  }

  let response
  try {
    response = await fetch(`${BASE_URL}${path}`, {
      method: 'POST',
      headers,
      body: formData,
      credentials: 'include',
    })
  } catch {
    // fetch() itself rejected: offline, DNS, or — most often for uploads — the
    // connection was cut by an upstream proxy that refused an oversized body
    // before sending any HTTP response. Never reaches our PHP logs.
    const error = new Error('error.network')
    error.status = 0
    error.messageKey = 'error.network'
    throw error
  }

  // Read the body defensively. An edge proxy rejecting an oversized upload
  // (HTTP 413) answers with HTML, not our JSON envelope — a bare
  // response.json() would throw and surface as a misleading "internal error".
  const raw = await response.text().catch(() => '')
  let data = null
  try {
    data = raw ? JSON.parse(raw) : null
  } catch {
    data = null
  }

  if (!response.ok) {
    const fallback = response.status === 413 ? 'upload.error.too_large' : 'error.internal'
    const error = new Error(data?.error?.message_key || fallback)
    error.status = response.status
    error.code = data?.error?.code
    error.field = data?.error?.field
    error.messageKey = data?.error?.message_key || fallback
    throw error
  }

  return data
}

async function getBlob(path) {
  const headers = {}
  const token = getAccessToken()
  if (token) {
    headers['Authorization'] = `Bearer ${token}`
  }

  let response
  try {
    response = await fetch(`${BASE_URL}${path}`, {
      method: 'GET',
      headers,
      credentials: 'include',
    })
  } catch {
    // fetch() rejected before any response: offline, DNS, dropped connection.
    const error = new Error('error.network')
    error.status = 0
    error.messageKey = 'error.network'
    throw error
  }

  if (!response.ok) {
    // The endpoint may answer with our JSON error envelope (e.g. a 404 with
    // support.error.attachment_not_found). Read it defensively — the body can
    // also be a non-JSON proxy/HTML page — and surface a real message_key
    // instead of a blanket "internal error".
    const raw = await response.text().catch(() => '')
    let data = null
    try {
      data = raw ? JSON.parse(raw) : null
    } catch {
      data = null
    }
    const error = new Error(data?.error?.message_key || 'error.internal')
    error.status = response.status
    error.code = data?.error?.code
    error.field = data?.error?.field
    error.messageKey = data?.error?.message_key || 'error.internal'
    throw error
  }

  return response.blob()
}

export const api = {
  get: (path, options) => request('GET', path, null, options),
  post: (path, body, options) => request('POST', path, body, options),
  put: (path, body, options) => request('PUT', path, body, options),
  patch: (path, body, options) => request('PATCH', path, body, options),
  delete: (path, body = null, options) => request('DELETE', path, body, options),
  upload,
  getBlob,
  setTokens,
  clearTokens,
  getAccessToken,
  refreshAccessToken,
}
