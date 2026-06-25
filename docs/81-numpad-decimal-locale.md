# 81 - Touche décimale du pavé numérique selon la locale

## Contexte

Suite au passage des champs numériques en locale active (voir [75 - Saisie décimale](75-saisie-decimale-locale.md)), un utilisateur français a signalé que, l'app étant en `fr`, **la touche décimale du pavé numérique (`.`) n'insère plus de séparateur décimal** : seule la touche `,` fonctionne. Très gênant à la saisie au pavé num (taille, prix… dans les modales de trade).

## Diagnostic (observé en navigateur, machine FR)

- À l'appui sur le `.` du pavé : `keydown` et `keypress` se déclenchent bien (`key='.'`, `code='NumpadDecimal'`), mais **aucun event `input`** → le caractère est filtré, rien n'est inséré.
- La touche `,` insère bien le séparateur dans le même champ.
- Le champ est bien un `InputNumber` PrimeVue (`p-inputnumber-input`), app en `fr`.

Conclusion : dans une locale à virgule (fr-FR, de-DE…), le `.` du pavé n'est pas traité comme séparateur décimal côté `InputNumber` ; seul le séparateur de la locale (`,`) l'est. C'est un comportement clavier que les stubs Vitest ne reproduisent pas (cf. note projet sur les quirks PrimeVue `InputNumber`) — d'où une vérif navigateur nécessaire.

## Solution

Un **remappage global, locale-agnostique** de la touche décimale du pavé : quand on presse `NumpadDecimal` et que le séparateur décimal de la locale active n'est pas `.`, on rejoue la frappe avec **le séparateur de la locale** — exactement comme si l'utilisateur avait tapé `,`. En locale à point (`en-US`), no-op : le `.` natif fonctionne déjà.

Principe : « la touche décimale du pavé insère toujours le séparateur décimal de la locale active » — rien n'est codé en dur sur le français.

### `decimalSeparatorForLocale(locale)` — `utils/numberLocale.js`

Dérive le séparateur via `Intl.NumberFormat(...).formatToParts(1.1)`. `fr-FR → ','`, `en-US → '.'`, `de-DE → ','`. Fallback `'.'` si la locale est absente/invalide.

### `remapNumpadDecimal(event, separator)` + `installNumpadDecimalRemap(getSeparator)` — `utils/numpadDecimal.js`

```js
export function remapNumpadDecimal(event, separator) {
  if (event.code !== 'NumpadDecimal') return false
  if (!separator || separator === '.') return false           // en-US → no-op
  const el = event.target
  if (!el?.classList?.contains('p-inputnumber-input')) return false
  event.preventDefault()                                       // annule le '.'
  el.dispatchEvent(new KeyboardEvent('keypress', { key: separator, bubbles: true, cancelable: true }))
  return true
}
```

`installNumpadDecimalRemap` pose un listener `keydown` global (phase de capture) et **relit le séparateur à chaque frappe** → réactif à un changement de langue en cours de session. Renvoie une fonction de désinstallation.

### Câblage — `App.vue`

```js
const { numberLocale } = useNumberLocale()
let uninstallNumpadRemap
onMounted(() => {
  uninstallNumpadRemap = installNumpadDecimalRemap(() => decimalSeparatorForLocale(numberLocale.value))
})
onUnmounted(() => uninstallNumpadRemap?.())
```

### Portée

Limité aux `InputNumber` PrimeVue (`p-inputnumber-input`) : c'est là qu'est le souci, et la frappe synthétique ne pilote que le handler PrimeVue. Les champs natifs ne sont pas concernés (l'app n'utilise pas d'`<input type="number">`).

## Couverture des tests

| Test | Fichier | Scénario | Statut |
|---|---|---|---|
| `decimalSeparatorForLocale` fr/en/de + fallback | `numberLocale.spec.js` | dérivation multi-locale, fallback `'.'` | ✅ |
| remap en locale virgule | `numpadDecimal.spec.js` | `NumpadDecimal` → keypress `,` + `preventDefault` | ✅ |
| no-op en locale point | `numpadDecimal.spec.js` | `en-US` → aucune action | ✅ |
| séparateur arbitraire | `numpadDecimal.spec.js` | n'est pas codé en dur sur `,` | ✅ |
| autres touches / champ non-InputNumber ignorés | `numpadDecimal.spec.js` | pas d'effet de bord | ✅ |
| réactif au changement de langue + cleanup | `numpadDecimal.spec.js` | relit la locale par frappe, désinstallation | ✅ |

**Vérification finale** : le ressenti clavier réel se valide **en navigateur** (les stubs Vitest ne voient pas le comportement PrimeVue) — sur une machine en locale FR, pavé num `.` → insère `,`.
