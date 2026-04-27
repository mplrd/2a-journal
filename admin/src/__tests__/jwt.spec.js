import { describe, it, expect } from 'vitest'
import { decodeJwtPayload } from '@/utils/jwt'

describe('decodeJwtPayload', () => {
  it('extracts the payload from a valid JWT', () => {
    // Header.Payload.Signature — payload contains { sub: 1, role: "ADMIN" }
    const payload = btoa(JSON.stringify({ sub: 1, role: 'ADMIN' }))
    const token = `header.${payload}.signature`
    const decoded = decodeJwtPayload(token)
    expect(decoded).toEqual({ sub: 1, role: 'ADMIN' })
  })

  it('returns null for malformed tokens', () => {
    expect(decodeJwtPayload(null)).toBeNull()
    expect(decodeJwtPayload('')).toBeNull()
    expect(decodeJwtPayload('not-a-jwt')).toBeNull()
    expect(decodeJwtPayload('only.two')).toBeNull()
  })

  it('returns null when payload is not valid JSON', () => {
    const token = 'header.notbase64payload.signature'
    expect(decodeJwtPayload(token)).toBeNull()
  })
})
