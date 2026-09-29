<script setup>
import SiteSections from './SiteSections.vue'
defineProps({ page: { type: Object, required: true }, editor: Boolean })
</script>
<template>
  <article class="section py-8" style="container-type: inline-size; container-name: site-page">
    <h1 class="sr-only">{{ page.title }}</h1>
    <SiteSections :blocks="page.document.blocks" :media="page.media" :links="page.links" :editor="editor" />
  </article>
</template>
