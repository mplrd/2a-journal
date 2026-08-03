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

// Rotating the refresh token deletes the previous one server-side, so two
// concurrent refreshes would leave the loser holding a revoked token — and a
// revoked token logs the user out. Every caller shares the in-flight promise.
let refreshPromise = null

async function refreshAccessToken() {
  if (refreshPromise) {
    return refreshPromise
  }

  refreshPromise = (async () => {
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
  })()

  try {
    return await refreshPromise
  } finally {
    refreshPromise = null
  }
}

// Shared by request(), upload() and getBlob(). The access token lives 15 minutes
// and is only renewed on rejection, so any call can hit a 401 mid-session —
// typically on submitting a form the user spent that long filling in.
// Returns a fresh token when the caller must replay its request, or null when the
// 401 is not recoverable and has to be surfaced as-is.
async function recoverExpiredToken(errorData) {
  if (errorData?.error?.code !== 'TOKEN_EXPIRED') {
    return null
  }

  try {
    return await refreshAccessToken()
  } catch {
    clearTokens()
    window.location.href = '/login'
    throw new Error('Session expired')
  }
}

// Reads a JSON envelope defensively: an edge proxy rejecting an oversized upload
// (HTTP 413) or a gateway error answers with HTML, not our envelope, and a bare
// response.json() would surface as a misleading "internal error".
async function readJson(response) {
  const raw = await response.text().catch(() => '')
  try {
    return raw ? JSON.parse(raw) : null
  } catch {
    return null
  }
}

function buildError(status, data, fallbackKey) {
  const error = new Error(data?.error?.message_key || fallbackKey)
  error.status = status
  error.code = data?.error?.code
  error.field = data?.error?.field
  error.messageKey = data?.error?.message_key || fallbackKey
  return error
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
    const newToken = await recoverExpiredToken(errorData)
    if (newToken) {
      headers['Authorization'] = `Bearer ${newToken}`
      response = await fetch(`${BASE_URL}${path}`, { method, headers, body: options.body, credentials: 'include' })
    } else {
      // Body already consumed — throw directly with parsed data
      if (response.status === 402 && !path.startsWith('/billing')) {
        redirectToSubscribe()
      }
      throw buildError(response.status, errorData, 'error.internal')
    }
  }

  const data = await response.json()

  if (!response.ok) {
    if (response.status === 402 && !path.startsWith('/billing')) {
      redirectToSubscribe()
    }
    throw buildError(response.status, data, 'error.internal')
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

  // FormData can be replayed as-is: fetch re-serialises it on each call.
  async function send() {
    try {
      return await fetch(`${BASE_URL}${path}`, {
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
  }

  let response = await send()
  let data = await readJson(response)

  if (response.status === 401) {
    const newToken = await recoverExpiredToken(data)
    if (newToken) {
      headers['Authorization'] = `Bearer ${newToken}`
      response = await send()
      data = await readJson(response)
    }
  }

  if (!response.ok) {
    const fallback = response.status === 413 ? 'upload.error.too_large' : 'error.internal'
    throw buildError(response.status, data, fallback)
  }

  return data
}

async function getBlob(path) {
  const headers = {}
  const token = getAccessToken()
  if (token) {
    headers['Authorization'] = `Bearer ${token}`
  }

  async function send() {
    try {
      return await fetch(`${BASE_URL}${path}`, {
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
  }

  let response = await send()
  // Undefined until read: the body can only be consumed once, so a 401 we could
  // not recover from must reuse what we already parsed.
  let errorBody

  if (response.status === 401) {
    errorBody = await readJson(response)
    const newToken = await recoverExpiredToken(errorBody)
    if (newToken) {
      headers['Authorization'] = `Bearer ${newToken}`
      response = await send()
      errorBody = undefined
    }
  }

  if (!response.ok) {
    // The endpoint may answer with our JSON error envelope (e.g. a 404 with
    // support.error.attachment_not_found), or with a non-JSON proxy/HTML page.
    if (errorBody === undefined) {
      errorBody = await readJson(response)
    }
    throw buildError(response.status, errorBody, 'error.internal')
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
