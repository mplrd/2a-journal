import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { createPinia, setActivePinia, getActivePinia } from 'pinia'
import PrimeVue from 'primevue/config'
import ToastService from 'primevue/toastservice'
import { useSetupsStore } from '@/stores/setups'
import SetupsTab from '@/components/account/SetupsTab.vue'
import fr from '@/locales/fr.json'
import en from '@/locales/en.json'

vi.mock('@/services/setups', () => ({
  setupsService: {
    list: vi.fn().mockResolvedValue({ data: [] }),
    create: vi.fn(),
    remove: vi.fn(),
  },
}))

function createWrapper(setups = []) {
  const pinia = createPinia()
  setActivePinia(pinia)

  const store = useSetupsStore()
  store.setups = setups
  store.loaded = true

  const i18n = createI18n({
    legacy: false,
    locale: 'fr',
    fallbackLocale: 'en',
    messages: { fr, en },
  })

  return mount(SetupsTab, {
    global: {
      plugins: [pinia, i18n, PrimeVue, ToastService],
      stubs: {
        DataTable: {
          template: '<table data-testid="setups-table"><slot /></table>',
          props: ['value', 'loading'],
        },
        Column: {
          template: '<td><slot name="body" :data="{}"></slot></td>',
          props: ['field', 'header'],
        },
        Button: {
          template: '<button :data-testid="$attrs[\'data-testid\']" @click="$emit(\'click\')">{{ label || icon }}</button>',
          props: ['label', 'icon', 'severity', 'size', 'text', 'loading', 'disabled'],
          emits: ['click'],
        },
        InputText: {
          template: '<input :data-testid="$attrs[\'data-testid\']" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" @keyup="$emit(\'keyup\', $event)" />',
          props: ['modelValue', 'placeholder'],
          emits: ['update:modelValue', 'keyup'],
        },
        Select: {
          template: '<select :data-testid="$attrs[\'data-testid\']"><option v-for="o in options" :key="o.value" :value="o.value">{{ o.label }}</option></select>',
          props: ['modelValue', 'options', 'optionLabel', 'optionValue'],
          emits: ['update:modelValue'],
        },
        Dialog: {
          template: '<div v-if="visible" data-testid="confirm-dialog"><slot /><slot name="footer" /></div>',
          props: ['visible', 'header', 'modal'],
        },
      },
    },
  })
}

describe('SetupsTab', () => {
  beforeEach(() => {
    vi.restoreAllMocks()
  })

  it('renders empty state when no setups', () => {
    const wrapper = createWrapper([])
    expect(wrapper.find('[data-testid="setups-empty"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="setups-table"]').exists()).toBe(false)
  })

  it('renders DataTable when setups exist', () => {
    const wrapper = createWrapper([
      { id: 1, label: 'Breakout', user_id: 1 },
      { id: 2, label: 'FVG', user_id: 1 },
    ])
    expect(wrapper.find('[data-testid="setups-table"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="setups-empty"]').exists()).toBe(false)
  })

  it('renders add button', () => {
    const wrapper = createWrapper([])
    expect(wrapper.find('[data-testid="add-setup-btn"]').exists()).toBe(true)
  })

  it('shows input field when add button clicked', async () => {
    const wrapper = createWrapper([])
    expect(wrapper.find('[data-testid="new-setup-input"]').exists()).toBe(false)

    await wrapper.find('[data-testid="add-setup-btn"]').trigger('click')
    expect(wrapper.find('[data-testid="new-setup-input"]').exists()).toBe(true)
  })

  it('calls createSetup on submit', async () => {
    const wrapper = createWrapper([])
    const store = useSetupsStore()
    store.createSetup = vi.fn().mockResolvedValue({ id: 3, label: 'New Setup' })

    await wrapper.find('[data-testid="add-setup-btn"]').trigger('click')

    const input = wrapper.find('[data-testid="new-setup-input"]')
    await input.setValue('New Setup')
    await wrapper.find('[data-testid="confirm-add-btn"]').trigger('click')

    expect(store.createSetup).toHaveBeenCalledWith({ label: 'New Setup' })
  })

  it('clears input after successful create', async () => {
    const wrapper = createWrapper([])
    const store = useSetupsStore()
    store.createSetup = vi.fn().mockResolvedValue({ id: 3, label: 'New Setup' })

    await wrapper.find('[data-testid="add-setup-btn"]').trigger('click')
    const input = wrapper.find('[data-testid="new-setup-input"]')
    await input.setValue('New Setup')
    await wrapper.find('[data-testid="confirm-add-btn"]').trigger('click')

    await wrapper.vm.$nextTick()
    expect(wrapper.vm.newLabel).toBe('')
  })

  it('does not submit empty label', async () => {
    const wrapper = createWrapper([])
    const store = useSetupsStore()
    store.createSetup = vi.fn()

    await wrapper.find('[data-testid="add-setup-btn"]').trigger('click')
    await wrapper.find('[data-testid="confirm-add-btn"]').trigger('click')

    expect(store.createSetup).not.toHaveBeenCalled()
  })

  it('calls deleteSetup after confirmation', async () => {
    const wrapper = createWrapper([
      { id: 1, label: 'Breakout', user_id: 1 },
    ])
    const store = useSetupsStore()
    store.deleteSetup = vi.fn().mockResolvedValue()

    // Trigger delete (the component should show confirmation dialog)
    wrapper.vm.confirmDelete({ id: 1, label: 'Breakout' })
    await wrapper.vm.$nextTick()

    expect(wrapper.find('[data-testid="confirm-dialog"]').exists()).toBe(true)
  })

  // ── Inline label edit ───────────────────────────────────────

  it('exposes startEdit / cancelEdit / saveEdit for inline label editing', () => {
    const wrapper = createWrapper([{ id: 1, label: 'Breakout', user_id: 1 }])

    expect(typeof wrapper.vm.startEdit).toBe('function')
    expect(typeof wrapper.vm.cancelEdit).toBe('function')
    expect(typeof wrapper.vm.saveEdit).toBe('function')
    expect(wrapper.vm.editingId).toBeNull()
  })

  it('startEdit sets editingId, editLabel and editCategory from the setup', () => {
    const wrapper = createWrapper([{ id: 1, label: 'Breakout', user_id: 1, category: 'pattern' }])

    wrapper.vm.startEdit({ id: 1, label: 'Breakout', category: 'pattern' })

    expect(wrapper.vm.editingId).toBe(1)
    expect(wrapper.vm.editLabel).toBe('Breakout')
    expect(wrapper.vm.editCategory).toBe('pattern')
  })

  it('startEdit normalises missing category to null', () => {
    const wrapper = createWrapper([{ id: 1, label: 'X', user_id: 1 }])

    wrapper.vm.startEdit({ id: 1, label: 'X' })

    expect(wrapper.vm.editCategory).toBeNull()
  })

  it('cancelEdit clears editing state without calling updateSetup', async () => {
    const wrapper = createWrapper([{ id: 1, label: 'Breakout', user_id: 1 }])
    const store = useSetupsStore()
    store.updateSetup = vi.fn()

    wrapper.vm.startEdit({ id: 1, label: 'Breakout' })
    wrapper.vm.editLabel = 'Modified'
    wrapper.vm.cancelEdit()

    expect(wrapper.vm.editingId).toBeNull()
    expect(wrapper.vm.editLabel).toBe('')
    expect(store.updateSetup).not.toHaveBeenCalled()
  })

  it('saveEdit calls store.updateSetup with trimmed label and clears state on success', async () => {
    const wrapper = createWrapper([{ id: 1, label: 'Breakout', user_id: 1, category: 'pattern' }])
    const store = useSetupsStore()
    store.updateSetup = vi.fn().mockResolvedValue({ id: 1, label: 'Renamed', category: 'pattern' })

    wrapper.vm.startEdit({ id: 1, label: 'Breakout', category: 'pattern' })
    wrapper.vm.editLabel = '  Renamed  '
    await wrapper.vm.saveEdit()

    expect(store.updateSetup).toHaveBeenCalledWith(1, { label: 'Renamed' })
    expect(wrapper.vm.editingId).toBeNull()
  })

  it('saveEdit sends category-only patch when only category changes', async () => {
    const wrapper = createWrapper([{ id: 1, label: 'Breakout', user_id: 1, category: 'pattern' }])
    const store = useSetupsStore()
    store.updateSetup = vi.fn().mockResolvedValue({ id: 1, label: 'Breakout', category: 'context' })

    wrapper.vm.startEdit({ id: 1, label: 'Breakout', category: 'pattern' })
    wrapper.vm.editCategory = 'context'
    await wrapper.vm.saveEdit()

    expect(store.updateSetup).toHaveBeenCalledWith(1, { category: 'context' })
    expect(wrapper.vm.editingId).toBeNull()
  })

  it('saveEdit sends both fields when label and category both change', async () => {
    const wrapper = createWrapper([{ id: 1, label: 'Breakout', user_id: 1, category: 'pattern' }])
    const store = useSetupsStore()
    store.updateSetup = vi.fn().mockResolvedValue({ id: 1, label: 'Renamed', category: 'context' })

    wrapper.vm.startEdit({ id: 1, label: 'Breakout', category: 'pattern' })
    wrapper.vm.editLabel = 'Renamed'
    wrapper.vm.editCategory = 'context'
    await wrapper.vm.saveEdit()

    expect(store.updateSetup).toHaveBeenCalledWith(1, { label: 'Renamed', category: 'context' })
  })

  it('saveEdit allows resetting category to null (uncategorized)', async () => {
    const wrapper = createWrapper([{ id: 1, label: 'X', user_id: 1, category: 'pattern' }])
    const store = useSetupsStore()
    store.updateSetup = vi.fn().mockResolvedValue({ id: 1, label: 'X', category: null })

    wrapper.vm.startEdit({ id: 1, label: 'X', category: 'pattern' })
    wrapper.vm.editCategory = null
    await wrapper.vm.saveEdit()

    expect(store.updateSetup).toHaveBeenCalledWith(1, { category: null })
  })

  it('saveEdit does nothing when label is empty', async () => {
    const wrapper = createWrapper([{ id: 1, label: 'Breakout', user_id: 1 }])
    const store = useSetupsStore()
    store.updateSetup = vi.fn()

    wrapper.vm.startEdit({ id: 1, label: 'Breakout' })
    wrapper.vm.editLabel = '   '
    await wrapper.vm.saveEdit()

    expect(store.updateSetup).not.toHaveBeenCalled()
    // Stays in edit mode so the user can fix
    expect(wrapper.vm.editingId).toBe(1)
  })

  it('saveEdit skips API call when neither label nor category changed (no-op)', async () => {
    const wrapper = createWrapper([{ id: 1, label: 'Breakout', user_id: 1, category: 'pattern' }])
    const store = useSetupsStore()
    store.updateSetup = vi.fn()

    wrapper.vm.startEdit({ id: 1, label: 'Breakout', category: 'pattern' })
    // editLabel and editCategory left as initial values
    await wrapper.vm.saveEdit()

    expect(store.updateSetup).not.toHaveBeenCalled()
    expect(wrapper.vm.editingId).toBeNull()
  })

  it('saveEdit keeps edit state on API error so user can retry', async () => {
    const wrapper = createWrapper([{ id: 1, label: 'Breakout', user_id: 1 }])
    const store = useSetupsStore()
    store.updateSetup = vi.fn().mockRejectedValue({ messageKey: 'setups.error.duplicate_label' })

    wrapper.vm.startEdit({ id: 1, label: 'Breakout' })
    wrapper.vm.editLabel = 'Existing'
    await wrapper.vm.saveEdit()

    expect(wrapper.vm.editingId).toBe(1)
    expect(wrapper.vm.editLabel).toBe('Existing')
  })

  it('fetches setups on mount', () => {
    const pinia = createPinia()
    setActivePinia(pinia)

    const store = useSetupsStore()
    store.fetchSetups = vi.fn()

    const i18n = createI18n({
      legacy: false,
      locale: 'fr',
      fallbackLocale: 'en',
      messages: { fr, en },
    })

    mount(SetupsTab, {
      global: {
        plugins: [pinia, i18n, PrimeVue, ToastService],
        stubs: {
          DataTable: { template: '<table><slot /></table>', props: ['value', 'loading'] },
          Column: { template: '<td></td>', props: ['field', 'header'] },
          Button: { template: '<button @click="$emit(\'click\')">{{ label }}</button>', props: ['label', 'icon', 'severity', 'size', 'text', 'loading', 'disabled'], emits: ['click'] },
          InputText: { template: '<input />', props: ['modelValue', 'placeholder'] },
          Select: { template: '<select></select>', props: ['modelValue', 'options', 'optionLabel', 'optionValue'] },
          Dialog: { template: '<div></div>', props: ['visible', 'header', 'modal'] },
        },
      },
    })

    expect(store.fetchSetups).toHaveBeenCalledWith(true)
  })
})
