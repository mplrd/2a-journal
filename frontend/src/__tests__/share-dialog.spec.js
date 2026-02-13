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
          template: '<button @click="$emit(\'click\')"><slot />{{ label }}</button>',
          props: ['label', 'icon', 'severity', 'size'],
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
})
