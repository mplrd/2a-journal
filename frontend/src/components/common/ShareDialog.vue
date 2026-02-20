<script setup>
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import Textarea from 'primevue/textarea'
import { positionsService } from '@/services/positions'

const { t } = useI18n()
const toast = useToast()

const props = defineProps({
  visible: { type: Boolean, default: false },
  positionId: { type: Number, default: null },
})

const emit = defineEmits(['update:visible'])

const text = ref('')
const textPlain = ref('')
const loading = ref(false)
const activeTab = ref('emoji')

watch(
  () => props.visible,
  async (val) => {
    if (val && props.positionId) {
      loading.value = true
      try {
        const [resEmoji, resPlain] = await Promise.all([
          positionsService.shareText(props.positionId),
          positionsService.shareTextPlain(props.positionId),
        ])
        text.value = resEmoji.data.text
        textPlain.value = resPlain.data.text
      } catch {
        text.value = ''
        textPlain.value = ''
      } finally {
        loading.value = false
      }
    }
  },
)

function currentText() {
  return activeTab.value === 'emoji' ? text.value : textPlain.value
}

function shareWhatsApp() {
  window.open(`https://wa.me/?text=${encodeURIComponent(currentText())}`, '_blank')
}

function shareTelegram() {
  window.open(`https://t.me/share/url?text=${encodeURIComponent(currentText())}`, '_blank')
}

function shareTwitter() {
  let shareText = currentText()
  if (shareText.length > 280) {
    shareText = shareText.substring(0, 277) + '...'
  }
  window.open(`https://twitter.com/intent/tweet?text=${encodeURIComponent(shareText)}`, '_blank')
}

function shareDiscord() {
  copyToClipboard()
}

function shareEmail() {
  const subject = encodeURIComponent(t('share.email_subject'))
  const body = encodeURIComponent(currentText())
  window.open(`mailto:?subject=${subject}&body=${body}`, '_self')
}

async function copyToClipboard() {
  try {
    await navigator.clipboard.writeText(currentText())
    toast.add({
      severity: 'success',
      summary: t('common.success'),
      detail: t('share.copied'),
      life: 2000,
    })
  } catch {
    // Fallback for older browsers
    const textarea = document.createElement('textarea')
    textarea.value = currentText()
    document.body.appendChild(textarea)
    textarea.select()
    document.execCommand('copy')
    document.body.removeChild(textarea)
    toast.add({
      severity: 'success',
      summary: t('common.success'),
      detail: t('share.copied'),
      life: 2000,
    })
  }
}

function close() {
  emit('update:visible', false)
}
</script>

<template>
  <Dialog
    :visible="visible"
    :header="t('share.title')"
    modal
    :closable="true"
    :style="{ width: '480px' }"
    @update:visible="close"
  >
    <div v-if="loading" class="flex justify-center py-4">
      <i class="pi pi-spin pi-spinner text-2xl"></i>
    </div>

    <div v-else>
      <div class="flex gap-2 mb-3">
        <Button
          :label="t('share.with_emojis')"
          :severity="activeTab === 'emoji' ? 'primary' : 'secondary'"
          size="small"
          @click="activeTab = 'emoji'"
        />
        <Button
          :label="t('share.without_emojis')"
          :severity="activeTab === 'plain' ? 'primary' : 'secondary'"
          size="small"
          @click="activeTab = 'plain'"
        />
      </div>

      <Textarea
        :modelValue="currentText()"
        readonly
        rows="8"
        class="w-full font-mono text-sm"
      />

      <div class="flex flex-wrap gap-2 mt-3">
        <p class="w-full text-sm text-gray-500">{{ t('share.share_via') }}</p>
        <Button
          :label="t('share.whatsapp')"
          icon="pi pi-whatsapp"
          severity="success"
          outlined
          size="small"
          data-testid="share-whatsapp"
          @click="shareWhatsApp"
        />
        <Button
          :label="t('share.telegram')"
          icon="pi pi-telegram"
          severity="info"
          outlined
          size="small"
          data-testid="share-telegram"
          @click="shareTelegram"
        />
        <Button
          :label="t('share.twitter')"
          icon="pi pi-twitter"
          severity="secondary"
          outlined
          size="small"
          data-testid="share-twitter"
          @click="shareTwitter"
        />
        <Button
          :label="t('share.discord')"
          icon="pi pi-discord"
          severity="info"
          outlined
          size="small"
          data-testid="share-discord"
          @click="shareDiscord"
        />
        <Button
          :label="t('share.email')"
          icon="pi pi-envelope"
          severity="secondary"
          outlined
          size="small"
          data-testid="share-email"
          @click="shareEmail"
        />
      </div>

      <div class="flex justify-end gap-2 mt-4">
        <Button
          :label="t('share.copy')"
          icon="pi pi-copy"
          @click="copyToClipboard"
        />
        <Button
          :label="t('common.cancel')"
          severity="secondary"
          @click="close"
        />
      </div>
    </div>
  </Dialog>
</template>
