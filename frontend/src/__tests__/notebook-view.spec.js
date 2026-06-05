import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { createPinia, setActivePinia } from 'pinia'
import PrimeVue from 'primevue/config'
import ToastService from 'primevue/toastservice'
import { noteCategoriesService, notesService } from '@/services/notebook'
import NotebookView from '@/views/NotebookView.vue'
import fr from '@/locales/fr.json'
import en from '@/locales/en.json'

vi.mock('@/services/notebook', () => ({
  noteCategoriesService: { list: vi.fn() },
  notesService: { list: vi.fn() },
}))

const stubs = {
  Button: {
    template: '<button :data-testid="$attrs[\'data-testid\']" @click="$emit(\'click\')">{{ label }}</button>',
    props: ['label', 'icon', 'severity', 'size', 'text', 'outlined'],
    emits: ['click'],
  },
  Dialog: { template: '<div v-if="visible"><slot /></div>', props: ['visible', 'header'] },
  NoteDialog: { template: '<div data-testid="note-dialog-stub" />', props: ['visible', 'note'] },
  CategoryManagerDialog: { template: '<div />', props: ['visible'] },
  NoteCard: {
    template: '<div data-testid="note-card-stub">{{ note.content }}</div>',
    props: ['note'],
    emits: ['edit', 'delete'],
  },
}

async function mountView({ notes = [], categories = [] } = {}) {
  const pinia = createPinia()
  setActivePinia(pinia)
  noteCategoriesService.list.mockResolvedValue({ data: categories })
  notesService.list.mockResolvedValue({ data: notes })

  const i18n = createI18n({ legacy: false, locale: 'fr', fallbackLocale: 'en', messages: { fr, en } })
  const wrapper = mount(NotebookView, { global: { plugins: [pinia, i18n, PrimeVue, ToastService], stubs } })
  await flushPromises()
  return wrapper
}

describe('NotebookView', () => {
  beforeEach(() => vi.clearAllMocks())

  it('shows the empty state when there are no notes', async () => {
    const wrapper = await mountView({ notes: [] })
    expect(wrapper.find('[data-testid="notebook-empty"]').exists()).toBe(true)
  })

  it('renders one card per note', async () => {
    const wrapper = await mountView({
      notes: [
        { id: 1, content: 'a', category_id: 1, is_pinned: 0 },
        { id: 2, content: 'b', category_id: null, is_pinned: 0 },
      ],
    })
    expect(wrapper.findAll('[data-testid="note-card-stub"]')).toHaveLength(2)
  })

  it('filters notes by category when a chip is clicked', async () => {
    const wrapper = await mountView({
      categories: [{ id: 1, label: 'Money' }],
      notes: [
        { id: 1, content: 'in-cat', category_id: 1, is_pinned: 0 },
        { id: 2, content: 'no-cat', category_id: null, is_pinned: 0 },
      ],
    })

    const moneyChip = wrapper.findAll('button').find((b) => b.text() === 'Money')
    await moneyChip.trigger('click')

    const cards = wrapper.findAll('[data-testid="note-card-stub"]')
    expect(cards).toHaveLength(1)
    expect(cards[0].text()).toBe('in-cat')
  })

  it('filters to uncategorized notes', async () => {
    const wrapper = await mountView({
      categories: [{ id: 1, label: 'Money' }],
      notes: [
        { id: 1, content: 'in-cat', category_id: 1, is_pinned: 0 },
        { id: 2, content: 'no-cat', category_id: null, is_pinned: 0 },
      ],
    })

    const otherChip = wrapper.findAll('button').find((b) => b.text() === fr.notebook.uncategorized)
    await otherChip.trigger('click')

    const cards = wrapper.findAll('[data-testid="note-card-stub"]')
    expect(cards).toHaveLength(1)
    expect(cards[0].text()).toBe('no-cat')
  })
})
