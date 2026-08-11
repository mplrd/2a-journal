import { describe, it, expect } from 'vitest'
import { parseTargets, firstTargetPrice } from '@/utils/targets'

describe('parseTargets', () => {
  it('accepts the JSON string the API sends', () => {
    expect(parseTargets('[{"id":"tp1","price":62000,"size":0.5}]')).toEqual([
      { id: 'tp1', price: 62000, size: 0.5 },
    ])
  })

  it('accepts an array that is already parsed', () => {
    const targets = [{ id: 'tp1', price: 62000 }]
    expect(parseTargets(targets)).toEqual(targets)
  })

  it('gives an empty list for anything unusable', () => {
    // A row with no objective, and a column that somehow holds broken JSON:
    // neither may throw on a list view.
    expect(parseTargets(null)).toEqual([])
    expect(parseTargets('')).toEqual([])
    expect(parseTargets('{not json')).toEqual([])
    expect(parseTargets({ id: 'tp1' })).toEqual([])
  })
})

describe('firstTargetPrice', () => {
  it('reads the nearest objective, which is the one stored first', () => {
    expect(firstTargetPrice('[{"id":"tp1","price":62000},{"id":"tp2","price":63000}]')).toBe(62000)
  })

  it('returns null when there is no objective at all', () => {
    // A pending order placed without a take profit. Null, never 0 — the
    // orders view renders it as a dash, and 0 would read as a real price.
    expect(firstTargetPrice(null)).toBeNull()
    expect(firstTargetPrice('[]')).toBeNull()
  })

  it('returns null when the stored objective carries no usable price', () => {
    expect(firstTargetPrice('[{"id":"tp1"}]')).toBeNull()
    expect(firstTargetPrice('[{"id":"tp1","price":"abc"}]')).toBeNull()
  })

  it('reads a price the API surfaced as a string', () => {
    expect(firstTargetPrice('[{"id":"tp1","price":"62000.00000"}]')).toBe(62000)
  })
})
