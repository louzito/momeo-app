<script setup>
defineProps({
  icon: { type: String, default: '⚠️' },
  code: { type: String, default: '' },
  title: { type: String, required: true },
  message: { type: String, default: '' },
  tone: { type: String, default: 'slate' }, // slate | rose | amber | brand
})

const toneRing = {
  slate: 'bg-slate-100',
  rose: 'bg-rose-100',
  amber: 'bg-amber-100',
  brand: 'bg-brand-100',
}
</script>

<template>
  <div class="section flex min-h-[60vh] flex-col items-center justify-center py-16 text-center">
    <div class="mb-5 flex h-20 w-20 items-center justify-center rounded-full text-4xl" :class="toneRing[tone]">
      {{ icon }}
    </div>
    <p v-if="code" class="font-mono text-sm font-semibold uppercase tracking-widest text-slate-400">{{ code }}</p>
    <h1 class="mt-1 font-display text-3xl font-extrabold text-slate-900 sm:text-4xl">{{ title }}</h1>
    <p v-if="message" class="mt-3 max-w-md text-slate-500">{{ message }}</p>
    <div class="mt-8 flex flex-wrap justify-center gap-3">
      <slot />
    </div>
  </div>
</template>
