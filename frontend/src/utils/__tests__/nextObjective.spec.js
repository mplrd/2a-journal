import { describe, it, expect } from 'vitest'
import { getNextObjective } from '../nextObjective'
import { ExitType } from '@/constants/enums'

const target = (id, price, size, label) => ({ id, price, size, label })

describe('getNextObjective', () => {
  describe('Step 1 — BE', () => {
    it('proposes a BE close when be_size > 0 and BE not yet reached', () => {
      const obj = getNextObjective({ be_price: 100, be_size: 0.5 })
      expect(obj).toMatchObject({ action: 'close', exit_type: ExitType.BE, exit_size: 0.5, exit_price: 100 })
    })

    it('proposes a BE mark (no lightening) when be_size is 0/absent', () => {
      const obj = getNextObjective({ be_price: 100, be_size: 0 })
      expect(obj).toMatchObject({ action: 'mark', label: 'BE' })
    })

    it('considers BE done once a BE partial exit exists', () => {
      const obj = getNextObjective({
        be_price: 100,
        be_size: 0.5,
        partial_exits: [{ exit_type: ExitType.BE }],
      })
      expect(obj).toBeNull()
    })

    // The "protect but don't lighten" case: BE configured with a size, but
    // marked reached without a partial exit. It must NOT be re-proposed.
    it('considers BE done when be_reached is set, even without a partial exit', () => {
      const obj = getNextObjective({ be_price: 100, be_size: 0.5, be_reached: 1 })
      expect(obj).toBeNull()
    })

    it('advances to the next target once BE is marked reached without lightening', () => {
      const obj = getNextObjective({
        be_price: 100,
        be_size: 0.5,
        be_reached: 1,
        targets: [target('tp1', 120, 0.5, 'TP1')],
      })
      expect(obj).toMatchObject({ action: 'close', exit_type: ExitType.TP, target_id: 'tp1' })
    })

    it('does not re-propose a be_size:0 BE once marked reached', () => {
      const obj = getNextObjective({ be_price: 100, be_size: 0, be_reached: 1 })
      expect(obj).toBeNull()
    })
  })

  describe('Step 2 — targets', () => {
    it('returns the first untaken target', () => {
      const obj = getNextObjective({
        targets: [target('tp1', 120, 0.5, 'TP1'), target('tp2', 140, 0.5, 'TP2')],
      })
      expect(obj).toMatchObject({ target_id: 'tp1', exit_price: 120, exit_size: 0.5, label: 'TP1' })
    })

    it('skips targets already taken via partial exits', () => {
      const obj = getNextObjective({
        targets: [target('tp1', 120, 0.5, 'TP1'), target('tp2', 140, 0.5, 'TP2')],
        partial_exits: [{ target_id: 'tp1' }],
      })
      expect(obj).toMatchObject({ target_id: 'tp2' })
    })

    it('parses targets when stored as a JSON string', () => {
      const obj = getNextObjective({
        targets: JSON.stringify([target('tp1', 120, 0.5, 'TP1')]),
      })
      expect(obj).toMatchObject({ target_id: 'tp1' })
    })

    it('returns null when everything is taken', () => {
      const obj = getNextObjective({
        be_price: 100,
        be_size: 0.5,
        be_reached: 1,
        targets: [target('tp1', 120, 0.5, 'TP1')],
        partial_exits: [{ exit_type: ExitType.BE }, { target_id: 'tp1' }],
      })
      expect(obj).toBeNull()
    })
  })

  it('returns null for a bare trade with no BE and no targets', () => {
    expect(getNextObjective({})).toBeNull()
  })
})
