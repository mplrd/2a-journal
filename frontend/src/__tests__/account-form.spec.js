import { describe, it, expect, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { createPinia, setActivePinia } from 'pinia'
import PrimeVue from 'primevue/config'
import AccountForm from '@/components/account/AccountForm.vue'
import { AccountType, AccountStage } from '@/constants/enums'
import fr from '@/locales/fr.json'
import en from '@/locales/en.json'

const stubs = {
  Dialog: {
    template: '<div v-if="visible" data-testid="account-form-dialog"><slot /><slot name="footer" /></div>',
    props: ['visible', 'header', 'modal', 'closable', 'style'],
    emits: ['update:visible'],
  },
  InputText: {
    template: '<input :data-testid="$attrs[\'data-testid\']" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
    props: ['modelValue', 'maxlength'],
    emits: ['update:modelValue'],
  },
  InputNumber: {
    template: '<input type="number" :data-testid="$attrs[\'data-testid\']" :value="modelValue" @input="$emit(\'update:modelValue\', Number($event.target.value))" />',
    props: ['modelValue', 'min', 'max', 'mode', 'locale', 'maxFractionDigits'],
    emits: ['update:modelValue'],
  },
  Select: {
    template: '<select :data-testid="$attrs[\'data-testid\']" :value="modelValue" @change="$emit(\'update:modelValue\', $event.target.value)"><option v-for="o in options" :key="o.value" :value="o.value">{{ o.label }}</option></select>',
    props: ['modelValue', 'options', 'optionLabel', 'optionValue'],
    emits: ['update:modelValue'],
  },
  Button: {
    template: '<button :data-testid="$attrs[\'data-testid\']" @click="$emit(\'click\')">{{ label }}</button>',
    props: ['label', 'severity', 'loading'],
    emits: ['click'],
  },
  Checkbox: {
    template: '<input type="checkbox" :data-testid="$attrs[\'data-testid\']" :checked="modelValue" @change="$emit(\'update:modelValue\', $event.target.checked)" />',
    props: ['modelValue', 'binary'],
    emits: ['update:modelValue'],
  },
}

function createWrapper({ account = null, visible = true } = {}) {
  setActivePinia(createPinia())

  const i18n = createI18n({
    legacy: false,
    locale: 'fr',
    fallbackLocale: 'en',
    messages: { fr, en },
  })

  return mount(AccountForm, {
    props: { visible, account, loading: false },
    global: { plugins: [i18n, PrimeVue], stubs },
  })
}

describe('AccountForm — risk management fields visibility', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
  })

  it('hides DD fields by default for a new non-PF account (BROKER_DEMO)', () => {
    const wrapper = createWrapper()

    // Default form is BROKER_DEMO (non-PF) → DD hidden behind toggle
    expect(wrapper.find('[data-testid="risk-params-toggle"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="account-max-drawdown"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="account-daily-drawdown"]').exists()).toBe(false)
  })

  it('shows DD fields when toggle is checked on non-PF account', async () => {
    const wrapper = createWrapper()

    const toggle = wrapper.find('[data-testid="risk-params-toggle"]')
    await toggle.setValue(true)

    expect(wrapper.find('[data-testid="account-max-drawdown"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="account-daily-drawdown"]').exists()).toBe(true)
  })

  it('shows DD fields directly (no toggle) on PF account', async () => {
    const wrapper = createWrapper({
      account: {
        id: 1,
        name: 'PF',
        account_type: AccountType.PROP_FIRM,
        stage: AccountStage.CHALLENGE,
        currency: 'EUR',
        initial_capital: 100000,
        broker: '',
        max_drawdown: null,
        daily_drawdown: null,
        profit_target: null,
        profit_split: null,
      },
    })

    expect(wrapper.find('[data-testid="account-max-drawdown"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="account-daily-drawdown"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="risk-params-toggle"]').exists()).toBe(false)
  })

  it('auto-reveals DD fields when loading a non-PF account that already has DD set', () => {
    const wrapper = createWrapper({
      account: {
        id: 2,
        name: 'Live',
        account_type: AccountType.BROKER_LIVE,
        stage: null,
        currency: 'EUR',
        initial_capital: 5000,
        broker: '',
        max_drawdown: 500,
        daily_drawdown: 200,
        profit_target: null,
        profit_split: null,
      },
    })

    // Pre-existing DD values must remain visible/editable, not hidden
    expect(wrapper.find('[data-testid="account-max-drawdown"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="account-daily-drawdown"]').exists()).toBe(true)
    // Toggle is still present and reflects the on-state
    const toggle = wrapper.find('[data-testid="risk-params-toggle"]')
    expect(toggle.exists()).toBe(true)
    expect(toggle.element.checked).toBe(true)
  })

  it('switching from non-PF to PF reveals DD fields automatically', async () => {
    const wrapper = createWrapper()

    // Initially BROKER_DEMO → hidden
    expect(wrapper.find('[data-testid="account-max-drawdown"]').exists()).toBe(false)

    // Switch to PROP_FIRM (via the form ref since stubs don't propagate easily)
    wrapper.vm.form.account_type = AccountType.PROP_FIRM
    await wrapper.vm.$nextTick()

    expect(wrapper.find('[data-testid="account-max-drawdown"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="risk-params-toggle"]').exists()).toBe(false)
  })
})
