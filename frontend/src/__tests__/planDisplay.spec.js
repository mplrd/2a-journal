import { describe, it, expect } from 'vitest'
import { trimPrice, formatZoneRange, formatWindowTime, daysMaskToLabel } from '@/utils/planDisplay'

describe('planDisplay', () => {
  describe('trimPrice', () => {
    it('drops the DECIMAL trailing zeros coming from the API', () => {
      expect(trimPrice('24000.00000')).toBe('24000')
      expect(trimPrice('24400.50000')).toBe('24400.5')
    })
    it('keeps significant decimals and accepts numbers', () => {
      expect(trimPrice(1.2345)).toBe('1.2345')
    })
  })

  describe('formatZoneRange', () => {
    it('renders low–high normalized (min first) and trimmed', () => {
      expect(formatZoneRange({ low_price: '24000.00000', high_price: '24400.00000' })).toBe('24000–24400')
    })
    it('normalizes a reversed zone', () => {
      expect(formatZoneRange({ low_price: '24400', high_price: '24000' })).toBe('24000–24400')
    })
  })

  describe('formatWindowTime', () => {
    it('renders HH:MM–HH:MM from HH:MM:SS bounds', () => {
      expect(formatWindowTime({ start_time: '09:00:00', end_time: '17:30:00' })).toBe('09:00–17:30')
    })
  })

  describe('daysMaskToLabel', () => {
    const labels = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim']

    it('compresses a consecutive run into a range', () => {
      expect(daysMaskToLabel(0b0011111, labels, 'Tous les jours')).toBe('Lun–Ven')
    })
    it('joins non-consecutive days with commas', () => {
      // Monday (bit0) + Wednesday (bit2) + Friday (bit4)
      expect(daysMaskToLabel(0b0010101, labels, 'Tous les jours')).toBe('Lun, Mer, Ven')
    })
    it('mixes runs and singles', () => {
      // Mon-Tue (bits0,1) + Thu (bit3)
      expect(daysMaskToLabel(0b0001011, labels, 'Tous les jours')).toBe('Lun–Mar, Jeu')
    })
    it('returns the all-days label when the seven days are set', () => {
      expect(daysMaskToLabel(0b1111111, labels, 'Tous les jours')).toBe('Tous les jours')
    })
    it('returns an empty string for an empty mask', () => {
      expect(daysMaskToLabel(0, labels, 'Tous les jours')).toBe('')
    })
  })
})
