// Numpad decimal key, locale-aware.
//
// In a comma-decimal locale (fr-FR, de-DE…), PrimeVue InputNumber rejects the
// numpad '.' — only the ',' key inserts a decimal — which is jarring on a
// physical numeric keypad. We intercept the keydown and, when the active
// locale's decimal separator isn't '.', replay it as a separator keypress so
// PrimeVue inserts the right character (exactly as if the user had pressed ',').
// In a dot-locale (en-US) we do nothing: the native dot already works.
//
// Scoped to PrimeVue InputNumber inputs (`p-inputnumber-input`): that is where
// the problem is, and the synthetic keypress only drives PrimeVue's own handler.

/**
 * @param {KeyboardEvent|{code:string,target:EventTarget,preventDefault:Function}} event
 * @param {string} separator decimal separator of the active locale (',' | '.' | …)
 * @returns {boolean} true if the event was remapped
 */
export function remapNumpadDecimal(event, separator) {
  if (event.code !== 'NumpadDecimal') return false
  if (!separator || separator === '.') return false
  const el = event.target
  if (!el?.classList?.contains('p-inputnumber-input')) return false

  event.preventDefault()
  el.dispatchEvent(new KeyboardEvent('keypress', { key: separator, bubbles: true, cancelable: true }))
  return true
}

/**
 * Installs a global capture-phase keydown listener that remaps the numpad
 * decimal key. `getSeparator` is read on every keypress so a language switch
 * takes effect immediately.
 *
 * @param {() => string} getSeparator returns the active locale's decimal separator
 * @param {EventTarget} [target=document]
 * @returns {() => void} uninstall function
 */
export function installNumpadDecimalRemap(getSeparator, target = document) {
  const handler = (event) => remapNumpadDecimal(event, getSeparator())
  target.addEventListener('keydown', handler, true)
  return () => target.removeEventListener('keydown', handler, true)
}
