<script setup>
import { RouterLink } from 'vue-router'
import { formatMoney } from '@/utils/format'

defineProps({
  jumpType: { type: Object, required: true },
  currency: { type: String, default: 'USD' },
  slug: { type: String, required: true },
})
</script>

<template>
  <RouterLink
    :to="{ name: 'jump-detail', params: { slug, jumpTypeId: jumpType.id } }"
    class="group card flex flex-col overflow-hidden transition hover:-translate-y-1 hover:shadow-soft"
  >
    <div class="relative h-44 overflow-hidden bg-slate-100">
      <img
        v-if="jumpType.image"
        :src="jumpType.image"
        :alt="jumpType.name"
        loading="lazy"
        class="h-full w-full object-cover transition duration-500 group-hover:scale-105"
      />
      <div v-else class="flex h-full items-center justify-center text-3xl text-slate-300" aria-hidden="true">✦</div>
      <span
        v-if="jumpType.popular"
        class="absolute left-3 top-3 chip bg-accent-500 text-white shadow"
      >
        ★ Populaire
      </span>
    </div>
    <div class="flex flex-1 flex-col p-5">
      <h3 class="font-display text-lg font-bold text-slate-900">{{ jumpType.name }}</h3>
      <p class="mt-1 flex-1 text-sm leading-relaxed text-slate-500">{{ jumpType.summary }}</p>
      <div class="mt-4 flex items-center justify-between">
        <div>
          <span class="text-xs text-slate-400">a partir de</span>
          <p class="text-xl font-bold text-brand-700">
            {{ formatMoney(jumpType.basePrice, currency) }}
          </p>
        </div>
        <span class="btn-outline group-hover:border-brand-500 group-hover:bg-brand-600 group-hover:text-white">
          Decouvrir
        </span>
      </div>
    </div>
  </RouterLink>
</template>
