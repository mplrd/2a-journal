<script setup>
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import Tabs from 'primevue/tabs'
import TabList from 'primevue/tablist'
import Tab from 'primevue/tab'
import TabPanels from 'primevue/tabpanels'
import TabPanel from 'primevue/tabpanel'
import Button from 'primevue/button'
import ProfileTab from '@/components/account/ProfileTab.vue'
import PreferencesTab from '@/components/account/PreferencesTab.vue'
import BillingTab from '@/components/account/BillingTab.vue'
import AssetsTab from '@/components/account/AssetsTab.vue'
import SetupsTab from '@/components/account/SetupsTab.vue'
import CustomFieldsTab from '@/components/account/CustomFieldsTab.vue'
import { useOnboarding } from '@/composables/useOnboarding'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const { isOnboarding, completeOnboarding } = useOnboarding()

const VALID_TABS = ['profile', 'billing', 'preferences', 'assets', 'setups', 'custom-fields']

const initialTab = computed(() => {
  const tab = route.query.tab
  return VALID_TABS.includes(tab) ? tab : 'profile'
})

const activeTab = ref(initialTab.value)

async function handleStartTrading() {
  await completeOnboarding()
  router.push({ name: 'dashboard' })
}
</script>

<template>
  <div>
    <!-- Onboarding banner -->
    <div
      v-if="isOnboarding"
      class="mb-4 p-4 bg-blue-50 dark:bg-blue-900/30 border border-blue-200 dark:border-blue-800 rounded-lg text-blue-800 dark:text-blue-200 flex items-center justify-between"
      data-testid="onboarding-banner"
    >
      <p>{{ t('onboarding.step_symbols_description') }}</p>
      <Button :label="t('onboarding.start_trading')" icon="pi pi-play" @click="handleStartTrading" data-testid="start-trading-btn" />
    </div>

    <h2 class="text-2xl font-bold text-gray-800 dark:text-gray-100 mb-6">{{ t('account.title') }}</h2>

    <Tabs v-model:value="activeTab">
      <TabList>
        <Tab value="profile">{{ t('account.tabs.profile') }}</Tab>
        <Tab value="billing">{{ t('account.tabs.billing') }}</Tab>
        <Tab value="preferences">{{ t('account.tabs.preferences') }}</Tab>
        <Tab value="assets">{{ t('account.tabs.assets') }}</Tab>
        <Tab value="setups">{{ t('account.tabs.setups') }}</Tab>
        <Tab value="custom-fields">{{ t('account.tabs.custom_fields') }}</Tab>
      </TabList>
      <TabPanels>
        <TabPanel value="profile">
          <ProfileTab />
        </TabPanel>
        <TabPanel value="billing">
          <BillingTab />
        </TabPanel>
        <TabPanel value="preferences">
          <PreferencesTab />
        </TabPanel>
        <TabPanel value="assets">
          <AssetsTab />
        </TabPanel>
        <TabPanel value="setups">
          <SetupsTab />
        </TabPanel>
        <TabPanel value="custom-fields">
          <CustomFieldsTab />
        </TabPanel>
      </TabPanels>
    </Tabs>
  </div>
</template>
