// Currencies selectable for accounts and user preferences. Fiat ISO codes plus
// crypto settlement currencies (USDT, USDC) so a broker account can be
// denominated in the currency its broker reports the balance in (avoids a
// permanent account-vs-broker currency mismatch warning).
export const CURRENCIES = ['EUR', 'USD', 'GBP', 'CHF', 'JPY', 'CAD', 'AUD', 'USDT', 'USDC']
