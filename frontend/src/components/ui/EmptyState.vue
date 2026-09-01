<script setup>
defineProps({
  icon: { type: String, default: '🪂' },
  title: { type: String, required: true },
  message: { type: String, default: '' },
})
</script>

<template>
  <div class="flex flex-col items-center justify-center rounded-2xl border border-dashed border-slate-300 bg-white/60 px-6 py-14 text-center">
    <div class="mb-3 text-4xl">{{ icon }}</div>
    <h3 class="text-lg font-semibold text-slate-800">{{ title }}</h3>
    <p v-if="message" class="mt-1 max-w-sm text-sm text-slate-500">{{ message }}</p>
    <div class="mt-5"><slot /></div>
  </div>
</template>
