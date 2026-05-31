import { describe, it, expect } from 'vitest'
import { compressImageIfNeeded } from '@/utils/imageCompression'

// Note: jsdom has no real canvas, so the actual downscaling path falls back to
// returning the original file (the try/catch guard). These tests pin the
// decision logic — what is left untouched — and the never-throw contract.

function fakeFile(bytes, type = 'image/jpeg', name = 'photo.jpg') {
  const blob = new Blob([new Uint8Array(bytes)], { type })
  return new File([blob], name, { type })
}

describe('compressImageIfNeeded', () => {
  it('returns non-image files untouched', async () => {
    const pdf = fakeFile(10, 'application/pdf', 'doc.pdf')
    expect(await compressImageIfNeeded(pdf)).toBe(pdf)
  })

  it('returns small images untouched (below the compression threshold)', async () => {
    const small = fakeFile(1024, 'image/png', 'tiny.png')
    expect(await compressImageIfNeeded(small)).toBe(small)
  })

  it('tolerates a null/undefined input', async () => {
    expect(await compressImageIfNeeded(null)).toBe(null)
  })
})
