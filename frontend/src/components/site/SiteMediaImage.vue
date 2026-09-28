<script setup>
import { computed } from 'vue'
import { displayImageUrl } from '@/api/config'
const props = defineProps({ media: { type: Object, required: true }, alt: { type: String, default: '' }, eager: Boolean })
const sources = computed(() => {
  const media = props.media
  if (Math.max(media.width, media.height) <= 400) return undefined
  const width = Math.max(1, Math.round(media.width * 400 / Math.max(media.width, media.height)))
  return `${displayImageUrl(media.thumbnail)} ${width}w, ${displayImageUrl(media.path)} ${media.width}w`
})
</script>
<template>
  <img :src="displayImageUrl(media.path)" :srcset="sources" sizes="(max-width: 640px) 100vw, 800px" :alt="alt" :width="media.width" :height="media.height" :loading="eager ? 'eager' : 'lazy'" decoding="async" class="h-auto w-full rounded-lg" />
</template>
