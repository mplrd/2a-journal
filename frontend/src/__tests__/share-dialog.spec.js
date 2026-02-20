import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import PrimeVue from 'primevue/config'
import ToastService from 'primevue/toastservice'
import ShareDialog from '@/components/common/ShareDialog.vue'
import { positionsService } from '@/services/positions'
import fr from '@/locales/fr.json'
import en from '@/locales/en.json'

vi.mock('@/services/positions', () => ({
  positionsService: {
    list: vi.fn(),
    get: vi.fn(),
    update: vi.fn(),
    remove: vi.fn(),
    transfer: vi.fn(),
    getHistory: vi.fn(),
    shareText: vi.fn(),
    shareTextPlain: vi.fn(),
  },
}))

function createWrapper(props = {}) {
  const i18n = createI18n({
    legacy: false,
    locale: 'fr',
    fallbackLocale: 'en',
    messages: { fr, en },
  })

  return mount(ShareDialog, {
    props: {
      visible: false,
      positionId: null,
      ...props,
    },
    global: {
      plugins: [createPinia(), i18n, PrimeVue, ToastService],
      stubs: {
        Dialog: {
          template: '<div v-if="visible"><slot /></div>',
          props: ['visible', 'header', 'modal', 'closable', 'style'],
        },
        Button: {
          template: '<button v-bind="$attrs" @click="$emit(\'click\')"><slot />{{ label }}</button>',
          props: ['label', 'icon', 'severity', 'size', 'outlined'],
          inheritAttrs: false,
        },
        Textarea: {
          template: '<textarea :value="modelValue" readonly></textarea>',
          props: ['modelValue', 'readonly', 'rows'],
        },
      },
    },
  })
}

describe('ShareDialog', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.restoreAllMocks()
  })

  it('does not render content when not visible', () => {
    const wrapper = createWrapper({ visible: false })
    expect(wrapper.find('textarea').exists()).toBe(false)
  })

  it('fetches share text when opened', async () => {
    positionsService.shareText.mockResolvedValue({
      data: { text: '📈 BUY NASDAQ @ 18240' },
    })
    positionsService.shareTextPlain.mockResolvedValue({
      data: { text: 'BUY NASDAQ @ 18240' },
    })

    const wrapper = createWrapper({ positionId: 10 })
    // Trigger the watch by changing visible to true
    await wrapper.setProps({ visible: true })

    await vi.waitFor(() => {
      expect(positionsService.shareText).toHaveBeenCalledWith(10)
      expect(positionsService.shareTextPlain).toHaveBeenCalledWith(10)
    })
  })

  it('displays fetched text in textarea', async () => {
    positionsService.shareText.mockResolvedValue({
      data: { text: '📈 BUY NASDAQ @ 18240\n🛑 SL: 18190' },
    })
    positionsService.shareTextPlain.mockResolvedValue({
      data: { text: 'BUY NASDAQ @ 18240\nSL: 18190' },
    })

    const wrapper = createWrapper({ positionId: 10 })
    await wrapper.setProps({ visible: true })

    await vi.waitFor(() => {
      expect(positionsService.shareText).toHaveBeenCalled()
    })
    await new Promise((r) => setTimeout(r, 10))
    await wrapper.vm.$nextTick()

    const textarea = wrapper.find('textarea')
    expect(textarea.exists()).toBe(true)
    expect(textarea.element.value).toContain('📈 BUY NASDAQ @ 18240')
  })

  it('handles fetch error gracefully', async () => {
    positionsService.shareText.mockRejectedValue(new Error('Network'))
    positionsService.shareTextPlain.mockRejectedValue(new Error('Network'))

    const wrapper = createWrapper({ positionId: 10 })
    await wrapper.setProps({ visible: true })

    await vi.waitFor(() => {
      expect(positionsService.shareText).toHaveBeenCalled()
    })
    await new Promise((r) => setTimeout(r, 10))
    await wrapper.vm.$nextTick()

    // Should not crash
    expect(wrapper.vm).toBeTruthy()
  })

  it('emits update:visible false on cancel', async () => {
    positionsService.shareText.mockResolvedValue({ data: { text: 'test' } })
    positionsService.shareTextPlain.mockResolvedValue({ data: { text: 'test' } })

    const wrapper = createWrapper({ positionId: 10 })
    await wrapper.setProps({ visible: true })

    await vi.waitFor(() => {
      expect(positionsService.shareText).toHaveBeenCalled()
    })
    await new Promise((r) => setTimeout(r, 10))
    await wrapper.vm.$nextTick()

    const buttons = wrapper.findAll('button')
    const cancelButton = buttons.find((b) => b.text().includes('Annuler'))
    expect(cancelButton).toBeTruthy()
    await cancelButton.trigger('click')

    expect(wrapper.emitted('update:visible')).toBeTruthy()
    expect(wrapper.emitted('update:visible')[0]).toEqual([false])
  })

  it('positionsService has share methods', () => {
    expect(typeof positionsService.shareText).toBe('function')
    expect(typeof positionsService.shareTextPlain).toBe('function')
  })

  describe('Share platforms', () => {
    async function createVisibleWrapper(emojiText = '📈 BUY NASDAQ', plainText = 'BUY NASDAQ') {
      positionsService.shareText.mockResolvedValue({ data: { text: emojiText } })
      positionsService.shareTextPlain.mockResolvedValue({ data: { text: plainText } })

      const wrapper = createWrapper({ positionId: 10 })
      await wrapper.setProps({ visible: true })
      await vi.waitFor(() => expect(positionsService.shareText).toHaveBeenCalled())
      await new Promise((r) => setTimeout(r, 10))
      await wrapper.vm.$nextTick()
      return wrapper
    }

    it('renders share platform buttons', async () => {
      const wrapper = await createVisibleWrapper()
      expect(wrapper.find('[data-testid="share-whatsapp"]').exists()).toBe(true)
      expect(wrapper.find('[data-testid="share-telegram"]').exists()).toBe(true)
      expect(wrapper.find('[data-testid="share-twitter"]').exists()).toBe(true)
      expect(wrapper.find('[data-testid="share-discord"]').exists()).toBe(true)
      expect(wrapper.find('[data-testid="share-email"]').exists()).toBe(true)
    })

    it('opens WhatsApp with encoded text', async () => {
      const openSpy = vi.spyOn(window, 'open').mockImplementation(() => null)
      const wrapper = await createVisibleWrapper('📈 BUY NASDAQ')
      await wrapper.find('[data-testid="share-whatsapp"]').trigger('click')
      expect(openSpy).toHaveBeenCalledWith(
        `https://wa.me/?text=${encodeURIComponent('📈 BUY NASDAQ')}`,
        '_blank',
      )
    })

    it('opens Telegram with encoded text', async () => {
      const openSpy = vi.spyOn(window, 'open').mockImplementation(() => null)
      const wrapper = await createVisibleWrapper('📈 BUY NASDAQ')
      await wrapper.find('[data-testid="share-telegram"]').trigger('click')
      expect(openSpy).toHaveBeenCalledWith(
        `https://t.me/share/url?text=${encodeURIComponent('📈 BUY NASDAQ')}`,
        '_blank',
      )
    })

    it('opens Twitter with encoded text', async () => {
      const openSpy = vi.spyOn(window, 'open').mockImplementation(() => null)
      const wrapper = await createVisibleWrapper('📈 BUY NASDAQ')
      await wrapper.find('[data-testid="share-twitter"]').trigger('click')
      expect(openSpy).toHaveBeenCalledWith(
        `https://twitter.com/intent/tweet?text=${encodeURIComponent('📈 BUY NASDAQ')}`,
        '_blank',
      )
    })

    it('truncates Twitter text to 280 chars', async () => {
      const openSpy = vi.spyOn(window, 'open').mockImplementation(() => null)
      const longText = 'A'.repeat(300)
      const wrapper = await createVisibleWrapper(longText)
      await wrapper.find('[data-testid="share-twitter"]').trigger('click')
      const expectedText = 'A'.repeat(277) + '...'
      expect(openSpy).toHaveBeenCalledWith(
        `https://twitter.com/intent/tweet?text=${encodeURIComponent(expectedText)}`,
        '_blank',
      )
    })

    it('copies to clipboard for Discord', async () => {
      const clipboardSpy = vi.fn().mockResolvedValue(undefined)
      Object.assign(navigator, { clipboard: { writeText: clipboardSpy } })
      const wrapper = await createVisibleWrapper('📈 BUY NASDAQ')
      await wrapper.find('[data-testid="share-discord"]').trigger('click')
      expect(clipboardSpy).toHaveBeenCalledWith('📈 BUY NASDAQ')
    })

    it('opens email with mailto link', async () => {
      const openSpy = vi.spyOn(window, 'open').mockImplementation(() => null)
      const wrapper = await createVisibleWrapper('📈 BUY NASDAQ')
      await wrapper.find('[data-testid="share-email"]').trigger('click')
      const expectedUrl = `mailto:?subject=${encodeURIComponent('Position de trading')}&body=${encodeURIComponent('📈 BUY NASDAQ')}`
      expect(openSpy).toHaveBeenCalledWith(expectedUrl, '_self')
    })
  })
})
