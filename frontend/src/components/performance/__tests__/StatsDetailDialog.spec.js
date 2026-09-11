import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import StatsDetailDialog from '../StatsDetailDialog.vue'
import fr from '@/locales/fr.json'
import en from '@/locales/en.json'

function mountDialog(slots = {}, props = {}) {
  const i18n = createI18n({
    legacy: false,
    locale: 'fr',
    fallbackLocale: 'en',
    messages: { fr, en },
  })

  return mount(StatsDetailDialog, {
    props: { visible: true, dimension: 'direction', data: [], ...props },
    slots,
    global: {
      plugins: [i18n],
      stubs: {
        Dialog: {
          props: ['visible', 'header'],
          template: '<div v-if="visible" class="dialog-stub" :data-header="header"><slot /></div>',
        },
        DataTable: { template: '<div class="datatable-stub"><slot /></div>' },
        Column: true,
      },
    },
  })
}

describe('StatsDetailDialog', () => {
  it('renders the extra content it is given below the table', () => {
    const wrapper = mountDialog({ default: '<section class="extra-table">extra</section>' })

    const dialog = wrapper.find('.dialog-stub')
    const children = Array.from(dialog.element.children).map((el) => el.className)
    expect(children).toEqual(['datatable-stub', 'extra-table'])
  })

  it('renders the table alone when given nothing else', () => {
    const wrapper = mountDialog()

    const dialog = wrapper.find('.dialog-stub')
    expect(Array.from(dialog.element.children).map((el) => el.className)).toEqual(['datatable-stub'])
  })

  it('titles itself after its dimension by default', () => {
    const wrapper = mountDialog()

    expect(wrapper.find('.dialog-stub').attributes('data-header')).toBe('Par direction')
  })

  it('takes the title it is given over the dimension one', () => {
    const wrapper = mountDialog({}, { header: 'Répartition gains / pertes' })

    expect(wrapper.find('.dialog-stub').attributes('data-header')).toBe('Répartition gains / pertes')
  })
})
