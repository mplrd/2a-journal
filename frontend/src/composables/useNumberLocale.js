import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { toNumberLocale } from '@/utils/numberLocale'

// Exposes the InputNumber `locale` string derived from the active i18n locale,
// reactive to language switches. Use it to bind `:locale="numberLocale"` on any
// PrimeVue InputNumber so decimal input follows the user's language (see
// toNumberLocale for why this fixes the FR paste/typing of decimals).
export function useNumberLocale() {
  const { locale } = useI18n()
  const numberLocale = computed(() => toNumberLocale(locale.value))
  return { numberLocale }
}
