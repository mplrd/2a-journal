<script setup>
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import TabView from 'primevue/tabview'
import TabPanel from 'primevue/tabpanel'
import ProfileTab from '@/components/account/ProfileTab.vue'
import AssetsTab from '@/components/account/AssetsTab.vue'
import SetupsTab from '@/components/account/SetupsTab.vue'
import { useOnboarding } from '@/composables/useOnboarding'

const { t } = useI18n()
const route = useRoute()
const { isOnboarding, currentStep } = useOnboarding()

const showAssets = computed(() => !isOnboarding.value || currentStep.value === 'symbols')
const showSetups = computed(() => !isOnboarding.value)

const validTabs = computed(() => {
  const tabs = ['profile']
  if (showAssets.value) tabs.push('assets')
  if (showSetups.value) tabs.push('setups')
  return tabs
})

const initialTab = computed(() => {
  const tab = route.query.tab
  return validTabs.value.includes(tab) ? tab : 'profile'
})

const activeTab = ref(initialTab.value)
</script>

<template>
  <div>
    <h2 class="text-2xl font-bold text-gray-800 dark:text-gray-100 mb-6">{{ t('account.title') }}</h2>

    <TabView v-model:value="activeTab">
      <TabPanel :header="t('account.tabs.profile')" value="profile">
        <ProfileTab />
      </TabPanel>
      <TabPanel v-if="showAssets" :header="t('account.tabs.assets')" value="assets">
        <AssetsTab />
      </TabPanel>
      <TabPanel v-if="showSetups" :header="t('account.tabs.setups')" value="setups">
        <SetupsTab />
      </TabPanel>
    </TabView>
  </div>
</template>
