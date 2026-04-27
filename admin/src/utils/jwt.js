/**
 * Decode the payload of a JWT WITHOUT verifying the signature. Used only
 * to read the `role` claim for client-side route gating; never to trust
 * authorization decisions — those happen on the server, this is purely a
 * UX guard so non-admin users get a helpful "access denied" message
 * instead of a confusing list of failed API calls.
 */
export function decodeJwtPayload(token) {
  if (!token || typeof token !== 'string') return null
  const parts = token.split('.')
  if (parts.length !== 3) return null
  try {
    const padded = parts[1].replace(/-/g, '+').replace(/_/g, '/')
    const decoded = atob(padded)
    return JSON.parse(decoded)
  } catch {
    return null
  }
}
