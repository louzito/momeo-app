<script setup>
import SitePublicLink from './SitePublicLink.vue'
defineProps({ items: { type: Array, required: true }, horizontal: Boolean })
const emit = defineEmits(['navigate'])
</script>
<template>
  <ul class="flex min-w-0 gap-3 break-words" :class="horizontal ? 'flex-row flex-wrap' : 'flex-col'">
    <li v-for="(item, index) in items" :key="index" class="min-w-0 max-w-full">
      <SitePublicLink :link="item" class="inline-block rounded-lg px-3 py-2 text-sm hover:underline" @click="emit('navigate')">{{ item.label }}</SitePublicLink>
      <ul v-if="item.children?.length" class="ml-3 border-l border-current pl-2">
        <li v-for="(child, childIndex) in item.children" :key="childIndex"><SitePublicLink :link="child" class="inline-block px-3 py-2 text-sm hover:underline" @click="emit('navigate')">{{ child.label }}</SitePublicLink></li>
      </ul>
    </li>
  </ul>
</template>
