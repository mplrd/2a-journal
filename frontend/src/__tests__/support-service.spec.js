import { describe, it, expect, vi, beforeEach } from 'vitest'
import { api } from '@/services/api'
import { supportService } from '@/services/support'

vi.mock('@/services/api', () => ({
  api: {
    get: vi.fn(),
    upload: vi.fn(),
    getBlob: vi.fn(),
  },
}))

describe('support service', () => {
  beforeEach(() => vi.clearAllMocks())

  it('list builds a query string from filters', async () => {
    api.get.mockResolvedValue({ success: true, data: [] })
    await supportService.list({ status: 'OPEN', page: 2, per_page: 20, empty: '' })
    expect(api.get).toHaveBeenCalledWith('/support/tickets?status=OPEN&page=2&per_page=20')
  })

  it('create posts a multipart FormData with fields and attachments', async () => {
    api.upload.mockResolvedValue({ success: true, data: { id: 1 } })
    const file = new File(['x'], 'a.png', { type: 'image/png' })

    await supportService.create({ type: 'BUG', subject: 'S', body: 'B', attachments: [file] })

    const [path, formData] = api.upload.mock.calls[0]
    expect(path).toBe('/support/tickets')
    expect(formData).toBeInstanceOf(FormData)
    expect(formData.get('type')).toBe('BUG')
    expect(formData.get('subject')).toBe('S')
    expect(formData.get('body')).toBe('B')
    expect(formData.getAll('attachments[]')).toHaveLength(1)
  })

  it('create appends non-empty details as details[key] multipart fields', async () => {
    api.upload.mockResolvedValue({ success: true, data: { id: 1 } })

    await supportService.create({
      type: 'BUG',
      subject: 'S',
      body: 'B',
      details: { expected_behavior: '  should open  ', reproduction_steps: '', benefit: 'x' },
      attachments: [],
    })

    const [, formData] = api.upload.mock.calls[0]
    expect(formData.get('details[expected_behavior]')).toBe('should open') // trimmed
    expect(formData.has('details[reproduction_steps]')).toBe(false) // empty skipped
    expect(formData.get('details[benefit]')).toBe('x')
  })

  it('create without details sends none', async () => {
    api.upload.mockResolvedValue({ success: true, data: { id: 1 } })
    await supportService.create({ type: 'SUPPORT', subject: 'S', body: 'B', attachments: [] })
    const [, formData] = api.upload.mock.calls[0]
    expect([...formData.keys()].some((k) => k.startsWith('details['))).toBe(false)
  })

  it('reply posts FormData to the messages endpoint', async () => {
    api.upload.mockResolvedValue({ success: true, data: { id: 1 } })
    await supportService.reply(7, { body: 'hello', attachments: [] })

    const [path, formData] = api.upload.mock.calls[0]
    expect(path).toBe('/support/tickets/7/messages')
    expect(formData.get('body')).toBe('hello')
  })

  it('attachmentUrl fetches a blob and returns an object URL', async () => {
    const blob = new Blob(['img'])
    api.getBlob.mockResolvedValue(blob)
    const createSpy = vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:abc')

    const url = await supportService.attachmentUrl(3, 5)

    expect(api.getBlob).toHaveBeenCalledWith('/support/tickets/3/attachments/5')
    expect(url).toBe('blob:abc')
    createSpy.mockRestore()
  })
})
