import { describe, it, expect, vi, beforeEach } from 'vitest'

vi.mock('@/services/api', () => ({
  api: {
    get: vi.fn().mockResolvedValue({ data: [] }),
    post: vi.fn().mockResolvedValue({ data: {} }),
    put: vi.fn().mockResolvedValue({ data: {} }),
    delete: vi.fn().mockResolvedValue({ data: {} }),
    upload: vi.fn().mockResolvedValue({ data: {} }),
    getBlob: vi.fn().mockResolvedValue(new Blob(['x'])),
  },
}))

import { api } from '@/services/api'
import { notesService, noteCategoriesService } from '@/services/notebook'

describe('notebook service', () => {
  beforeEach(() => vi.clearAllMocks())

  it('list builds a query string from filters', async () => {
    await notesService.list({ pinned: 1, category_id: 3 })
    expect(api.get).toHaveBeenCalledWith('/notes?pinned=1&category_id=3')
  })

  it('list omits empty filters', async () => {
    await notesService.list({})
    expect(api.get).toHaveBeenCalledWith('/notes')
  })

  it('create posts multipart with fields and attachments', async () => {
    const file = new File(['a'], 'a.png', { type: 'image/png' })
    await notesService.create({
      title: 'T', content: 'body', note_date: '2026-06-01',
      category_id: 4, is_pinned: true, attachments: [file],
    })

    expect(api.upload).toHaveBeenCalledTimes(1)
    const [path, formData] = api.upload.mock.calls[0]
    expect(path).toBe('/notes')
    expect(formData.get('title')).toBe('T')
    expect(formData.get('content')).toBe('body')
    expect(formData.get('note_date')).toBe('2026-06-01')
    expect(formData.get('category_id')).toBe('4')
    expect(formData.get('is_pinned')).toBe('1')
    expect(formData.getAll('attachments[]')).toHaveLength(1)
  })

  it('create sends is_pinned=0 when not pinned and skips empty title/category', async () => {
    await notesService.create({ content: 'body', note_date: '2026-06-01', is_pinned: false })
    const [, formData] = api.upload.mock.calls[0]
    expect(formData.get('is_pinned')).toBe('0')
    expect(formData.get('title')).toBeNull()
    expect(formData.get('category_id')).toBeNull()
  })

  it('addAttachments uploads to the note attachments endpoint', async () => {
    const file = new File(['a'], 'a.png', { type: 'image/png' })
    await notesService.addAttachments(7, [file])
    const [path, formData] = api.upload.mock.calls[0]
    expect(path).toBe('/notes/7/attachments')
    expect(formData.getAll('attachments[]')).toHaveLength(1)
  })

  it('category service hits the right endpoints', async () => {
    await noteCategoriesService.create({ label: 'X' })
    expect(api.post).toHaveBeenCalledWith('/note-categories', { label: 'X' })
    await noteCategoriesService.remove(3)
    expect(api.delete).toHaveBeenCalledWith('/note-categories/3')
  })
})
