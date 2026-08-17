import { describe, it, expect } from 'vitest'
import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join, relative } from 'node:path'

/**
 * Every PrimeVue InputNumber must bind `:locale="numberLocale"`.
 *
 * Without it PrimeVue derives the decimal separator from the BROWSER locale
 * rather than from the app language, so a French user on an English-configured
 * browser cannot type "0,5" — the comma is swallowed. That is the bug behind
 * the FTMO paste corruption (#22) and the French number display (#24), and
 * `useNumberLocale` exists solely to fix it; its own docblock says to bind it
 * on any InputNumber.
 *
 * A convention held by 32 fields out of 36 is one that gets broken by copying
 * the shape of a neighbouring line, which is exactly how the four missing ones
 * appeared — all in the same view, all accepting decimals. Hence a structural
 * guard rather than a mounted one: the mount stubs InputNumber, so it would
 * never see this.
 */
const SRC = join(process.cwd(), 'src')

function vueFiles(dir, acc = []) {
  for (const entry of readdirSync(dir)) {
    const path = join(dir, entry)
    if (statSync(path).isDirectory()) vueFiles(path, acc)
    else if (entry.endsWith('.vue')) acc.push(path)
  }
  return acc
}

const occurrences = vueFiles(SRC).flatMap((file) =>
  [...readFileSync(file, 'utf8').matchAll(/<InputNumber\b[\s\S]*?\/?>/g)].map((match) => ({
    file: relative(SRC, file).replace(/\\/g, '/'),
    tag: match[0],
  })),
)

describe('InputNumber locale binding', () => {
  it('finds the InputNumber fields to check', () => {
    // Guards the guard: a regex that silently matches nothing would pass below.
    expect(occurrences.length).toBeGreaterThan(30)
  })

  it('binds the app locale on every field', () => {
    const unbound = occurrences
      .filter(({ tag }) => !/:locale=/.test(tag))
      .map(({ file, tag }) => `${file} — ${tag.replace(/\s+/g, ' ').slice(0, 80)}`)

    expect(unbound).toEqual([])
  })
})
