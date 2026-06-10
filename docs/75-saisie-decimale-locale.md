# 75 — Saisie décimale au format de la langue active

Ticket #22 (bug, corruption du prix collé depuis l'Excel FTMO). Résout aussi
le ticket #24 (affichage des nombres au format français).

## Problème

Tous les champs numériques (`InputNumber` de PrimeVue) étaient figés en
`locale="en-US"`. En anglais US, la virgule est le séparateur de **milliers**
et le point le séparateur **décimal**. Conséquence pour un utilisateur français :

| Action | Résultat | Cause |
|--------|----------|-------|
| Colle `23924,6` | `239246` ❌ | virgule lue comme séparateur de milliers → supprimée |
| Colle `23 924,6` (format FTMO) | `239246` ❌ | espace **et** virgule supprimés |
| Tape `23924,6` au clavier | `239246` ❌ | idem : la virgule n'est pas la décimale en en-US |
| Tape `23924.6` (point) | `23924.6` ✅ | le point est la décimale en en-US |

Le prix collé depuis l'Excel FTMO était donc corrompu (valeur ×10 et décimale
perdue), ce qui faussait tous les calculs dérivés (R:R, P&L…).

## Solution

La locale des `InputNumber` n'est plus codée en dur : elle suit la **langue
i18n active** de l'application.

- Application en **français** → locale `fr-FR`
- Application en **anglais** → locale `en-US`

PrimeVue possède un cas particulier (`isDecimalSign`) : dès que la locale
contient `fr`, il accepte **à la fois la virgule ET le point** comme séparateur
décimal, en frappe comme en collage. Sa fonction `parseValue` retire en plus les
espaces et espaces insécables. En `fr-FR` on obtient donc :

| Saisie (app en FR) | Résultat |
|--------------------|----------|
| tape `23924,6` | `23924,6` ✅ |
| tape `23924.6` (point / pavé num.) | `23924,6` ✅ |
| colle `23924,6` | `23924,6` ✅ |
| colle `23 924,6` (espace FTMO) | `23924,6` ✅ |
| colle `23924.6` | `23924,6` ✅ |
| affichage | `23 924,6` (format français) |

Seul cas non géré : coller un nombre **déjà au format anglo-saxon**
(`23,924.6`, virgule de milliers + point décimal) dans l'application en français
vide le champ au lieu de le corrompre — comportement volontaire (un champ vide
est rattrapable, une valeur corrompue ne l'est pas), et marginal pour des
données issues d'un Excel FTMO francophone.

Un utilisateur anglophone conserve le comportement attendu (point décimal,
virgule de milliers, affichage `23,924.6`).

## Choix d'implémentation

- **`src/utils/numberLocale.js`** — fonction pure `toNumberLocale(i18nLocale)` :
  `'fr*'` → `'fr-FR'`, tout le reste → `'en-US'`. Testable unitairement, sans
  dépendance à Vue.
- **`src/composables/useNumberLocale.js`** — composable `useNumberLocale()` qui
  expose un `computed` `numberLocale` dérivé de `useI18n().locale` (réactif au
  changement de langue).
- Tous les `InputNumber` passent de `locale="en-US"` à `:locale="numberLocale"`.
  Composants concernés : `TradeForm`, `OrderForm`, `PositionForm`,
  `CloseTradeDialog`, `AccountForm`, `AssetsTab`, `PreferencesTab`,
  `PricePointsInput`, `BalanceAdjustmentInput`.
- `PricePointsInput` et `BalanceAdjustmentInput` exposaient une prop `locale`
  (défaut `'en-US'`, jamais surchargée par les appelants) : elle est supprimée au
  profit du composable interne, plus aucune locale n'est codée en dur.

Aucun wrapper custom ni interception d'événements `paste`/`keydown` : la
correction s'appuie sur le comportement natif de PrimeVue, ce qui la rend plus
robuste et sans dette.

## Couverture des tests

| Test | Scénario | Statut |
|------|----------|--------|
| `numberLocale.spec.js` | `'fr'` → `'fr-FR'` | ✅ |
| `numberLocale.spec.js` | `'fr-FR'` → `'fr-FR'` | ✅ |
| `numberLocale.spec.js` | `'FR'` (casse) → `'fr-FR'` | ✅ |
| `numberLocale.spec.js` | `'en'` → `'en-US'` | ✅ |
| `numberLocale.spec.js` | `'en-US'` → `'en-US'` | ✅ |
| `numberLocale.spec.js` | `'de'` (non supportée) → `'en-US'` | ✅ |
| `numberLocale.spec.js` | `null` / `undefined` / `''` → `'en-US'` | ✅ |
| `PricePointsInput.spec.js` | montage avec i18n, sync prix ⇄ points | ✅ (22) |
| `BalanceAdjustmentInput.spec.js` | montage avec i18n, sync solde ⇄ delta | ✅ (6) |

> Note : les stubs Vitest d'`InputNumber` ne reproduisent pas le moteur de
> parsing PrimeVue, donc la non-corruption du collage `23 924,6` se vérifie
> **en navigateur réel** (application en français), pas en test unitaire.
