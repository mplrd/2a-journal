// Maps the app i18n locale to the BCP-47 locale string passed to PrimeVue
// InputNumber. The mapping matters because PrimeVue derives the decimal/grouping
// separators from this locale: in any 'fr*' locale it accepts BOTH ',' and '.'
// as the decimal separator (typing and paste), which is what lets traders paste
// FTMO values like "23 924,6" without corruption (#22) and shows the French
// number format (#24). Any other locale falls back to en-US ('.' decimal).
export function toNumberLocale(i18nLocale) {
  if (typeof i18nLocale === 'string' && i18nLocale.toLowerCase().startsWith('fr')) {
    return 'fr-FR'
  }
  return 'en-US'
}
