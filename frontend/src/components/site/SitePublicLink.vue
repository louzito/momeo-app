<script setup>
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
const props = defineProps({ link: { type: Object, required: true } })
// Internal destinations always stay relative to Vue Router's tenant base.
const safe = computed(() => props.link.external
  ? /^https?:\/\//i.test(props.link.url) && !/[\s<>\\]/.test(props.link.url)
  : !/^(?:\/|[a-z][a-z0-9+.-]*:)/i.test(props.link.url) && !props.link.url.includes('..'))
</script>
<template>
  <a v-if="safe && link.external" :href="link.url" rel="noopener noreferrer"><slot /></a>
  <RouterLink v-else-if="safe" :to="'/' + link.url"><slot /></RouterLink>
</template>
