import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { symbolsService } from '@/services/symbols'

function keyOf(symbolId, accountId) {
  return `${symbolId}:${accountId}`
}

export const useSymbolAccountSettingsStore = defineStore('symbolAccountSettings', () => {
  const settings = ref([]) // [{ symbol_id, account_id, point_value }]
  const loading = ref(false)
  const loaded = ref(false)

  const settingsMap = computed(() => {
    const m = new Map()
    for (const row of settings.value) {
      m.set(keyOf(row.symbol_id, row.account_id), Number(row.point_value))
    }
    return m
  })

  async function fetchMatrix(force = false) {
    if (loaded.value && !force) return
    loading.value = true
    try {
      const response = await symbolsService.settings()
      settings.value = response.data?.settings ?? []
      loaded.value = true
    } finally {
      loading.value = false
    }
  }

  function getPointValue(symbolId, accountId) {
    if (symbolId == null || accountId == null) return null
    return settingsMap.value.get(keyOf(Number(symbolId), Number(accountId))) ?? null
  }

  async function save(symbolId, accountId, pointValue) {
    await symbolsService.setSetting(symbolId, accountId, pointValue)
    const existing = settings.value.find(
      (r) => r.symbol_id === symbolId && r.account_id === accountId,
    )
    if (existing) {
      existing.point_value = pointValue
    } else {
      settings.value.push({ symbol_id: symbolId, account_id: accountId, point_value: pointValue })
    }
  }

  async function clear(symbolId, accountId) {
    await symbolsService.clearSetting(symbolId, accountId)
    settings.value = settings.value.filter(
      (r) => !(r.symbol_id === symbolId && r.account_id === accountId),
    )
  }

  function reset() {
    settings.value = []
    loaded.value = false
    loading.value = false
  }

  return {
    settings,
    loading,
    loaded,
    settingsMap,
    fetchMatrix,
    getPointValue,
    save,
    clear,
    reset,
  }
})
