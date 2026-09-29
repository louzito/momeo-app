<script setup>
import { defineAsyncComponent, inject } from 'vue'
const SiteConnectedSection = import.meta.env.SSR ? null : defineAsyncComponent(() => import('./SiteConnectedSection.vue'))
const serverBase = inject('siteServerBase', null)
import SiteMediaImage from './SiteMediaImage.vue'
import SiteRichText from './SiteRichText.vue'
import SitePublicLink from './SitePublicLink.vue'
const props = defineProps({ editor: Boolean, blocks: { type: Array, default: () => [] }, media: { type: Object, default: () => ({}) }, links: { type: Object, default: () => ({}) } })
function resolve(link) { return link ? props.links[JSON.stringify(link)] : null }
</script>
<template>
  <div class="space-y-6 break-words">
    <template v-for="block in blocks" :key="block.id">
      <section v-if="serverBase && !block.hidden && ['catalog', 'giftCard'].includes(block.type)" class="rounded-xl p-5">
        <a :href="serverBase + 'shop'">Consulter les offres et disponibilités de l’établissement</a>
      </section>
      <SiteConnectedSection v-else-if="!block.hidden && ['catalog', 'giftCard'].includes(block.type)" :block="block" :media="media" :editor="editor" />
      <section v-else-if="!block.hidden" class="rounded-xl p-5" :class="[block.variant === 'color' ? 'bg-brand-50 text-brand-900' : 'bg-white text-slate-900', block.align === 'center' ? 'text-center' : 'text-left']">
        <template v-if="block.type === 'banner' || block.type === 'image'">
          <SiteMediaImage v-if="media[block.props.mediaId]" :media="media[block.props.mediaId]" :alt="block.props.alt" :eager="block.type === 'banner'" />
          <h2 v-if="block.props.title" class="mt-4 font-display text-3xl font-bold">{{ block.props.title }}</h2>
          <p v-if="block.props.text" class="mt-3 whitespace-pre-line">{{ block.props.text }}</p>
          <SitePublicLink v-if="resolve(block.props.button?.link)" :link="resolve(block.props.button.link)" class="btn-primary mt-4">{{ block.props.button.label }}</SitePublicLink>
        </template>
        <SiteRichText v-else-if="block.type === 'text'" :document="block.props.content" />
        <div v-else-if="block.type === 'imageText'" class="site-image-text grid items-center gap-5">
          <SiteMediaImage v-if="media[block.props.image?.mediaId]" :media="media[block.props.image.mediaId]" :alt="block.props.image.alt" :class="block.props.position === 'right' ? 'site-image-right' : ''" />
          <div><h2 class="font-display text-2xl font-semibold">{{ block.props.title }}</h2><SiteRichText :document="block.props.content" /></div>
        </div>
        <div v-else-if="block.type === 'gallery'" class="site-gallery grid gap-4">
          <template v-for="(image, i) in block.props.images" :key="i"><SiteMediaImage v-if="media[image.mediaId]" :media="media[image.mediaId]" :alt="image.alt" /></template>
        </div>
        <template v-else-if="block.type === 'faq'">
          <h2 class="mb-4 font-display text-2xl font-semibold">{{ block.props.title }}</h2>
          <details v-for="(item, i) in block.props.items" :key="i" class="border-b py-3"><summary class="cursor-pointer font-semibold">{{ item.question }}</summary><SiteRichText :document="item.answer" /></details>
        </template>
        <template v-else-if="block.type === 'practical'">
          <h2 class="mb-4 font-display text-2xl font-semibold">{{ block.props.title }}</h2>
          <p class="whitespace-pre-line">{{ block.props.address }}</p><p>{{ block.props.phone }}</p><p>{{ block.props.email }}</p><p class="my-3 whitespace-pre-line">{{ block.props.hours }}</p>
          <SitePublicLink v-if="resolve(block.props.link)" :link="resolve(block.props.link)" class="underline">Comment venir</SitePublicLink>
        </template>
        <component :is="block.props.level === 3 ? 'h3' : 'h2'" v-else-if="block.type === 'heading'" class="font-display text-2xl font-semibold">{{ block.props.text }}</component>
        <SitePublicLink v-else-if="block.type === 'button' && resolve(block.props.link)" :link="resolve(block.props.link)" class="btn-primary">{{ block.props.label }}</SitePublicLink>
      </section>
    </template>
  </div>
</template>
<style scoped>
/* Container queries make the editor's 360px preview match a narrow public page. */
@container site-page (min-width: 560px) {
  .site-image-text { grid-template-columns: 1fr 1fr; }
  .site-image-right { order: 2; }
  .site-gallery { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
</style>
