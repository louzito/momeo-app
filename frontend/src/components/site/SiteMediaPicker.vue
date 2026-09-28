<script setup>
import { nextTick, ref } from 'vue'
import SiteMediaLibrary from './SiteMediaLibrary.vue'
defineProps({ disabled: Boolean })
const emit = defineEmits(['select'])
const opened = ref(false)
const dialog = ref(null)
const trigger = ref(null)
async function close() { dialog.value.close(); opened.value = false; await nextTick(); trigger.value?.focus() }
function select(image) { emit('select', image); close() }
</script>
<template>
  <button ref="trigger" type="button" class="btn-outline" :disabled="disabled" @click="opened = true; dialog.showModal()">Choisir une image</button>
  <dialog ref="dialog" aria-label="Choisir une image" class="max-h-[90vh] w-[min(95vw,64rem)] rounded-xl p-5 backdrop:bg-black/40" @cancel.prevent="close">
    <div class="mb-4 flex justify-between gap-3"><h2 class="text-xl font-semibold">Choisir une image</h2><button type="button" class="btn-ghost" @click="close">Fermer</button></div>
    <SiteMediaLibrary v-if="opened" selectable @select="select" />
  </dialog>
</template>
