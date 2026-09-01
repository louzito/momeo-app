<script setup>
defineProps({
  label: { type: String, default: 'Chargement…' },
  center: { type: Boolean, default: true },
})
</script>

<template>
  <div :class="center ? 'flex flex-col items-center justify-center gap-3 py-16' : 'inline-flex items-center gap-2'">
    <span class="inline-block h-8 w-8 animate-spin rounded-full border-[3px] border-brand-200 border-t-brand-600" />
    <span v-if="label" class="text-sm text-slate-500">{{ label }}</span>
  </div>
</template>
