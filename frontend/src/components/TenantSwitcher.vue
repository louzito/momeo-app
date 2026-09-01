<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useTenantStore } from '@/stores/tenant'

// Selecteur de centre (tenant). Bascule donnees + branding en changeant de
// route (/t/:slug). Prop `dark` pour s'adapter a une barre sombre.
defineProps({ dark: { type: Boolean, default: false } })

const tenantStore = useTenantStore()
const router = useRouter()
const open = ref(false)

onMounted(() => tenantStore.loadTenants())

function pick(slug) {
  open.value = false
  router.push({ name: 'tenant-home', params: { slug } })
}
</script>

<template>
  <div class="relative">
    <button
      type="button"
      class="flex items-center gap-2 rounded-xl px-3 py-2 text-sm font-medium transition"
      :class="dark ? 'border border-white/15 bg-white/10 text-white hover:bg-white/20' : 'border border-slate-200 bg-white text-slate-700 hover:border-brand-300'"
      @click="open = !open"
    >
      <span class="text-base">{{ tenantStore.current?.branding?.logoEmoji || '🪂' }}</span>
      <span class="hidden max-w-[10rem] truncate sm:inline">{{ tenantStore.current?.name || 'Choisir un centre' }}</span>
      <span :class="dark ? 'text-white/60' : 'text-slate-400'">▾</span>
    </button>

    <div
      v-if="open"
      class="absolute right-0 z-40 mt-2 w-64 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-soft"
      @mouseleave="open = false"
    >
      <p class="border-b border-slate-100 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-slate-400">
        Centres de saut (demo)
      </p>
      <button
        v-for="t in tenantStore.tenants"
        :key="t.id"
        class="flex w-full items-center gap-3 px-4 py-3 text-left transition hover:bg-slate-50"
        :class="t.id === tenantStore.current?.id ? 'bg-brand-50' : ''"
        @click="pick(t.slug)"
      >
        <span class="text-xl">{{ t.branding.logoEmoji }}</span>
        <span class="min-w-0">
          <span class="block truncate text-sm font-semibold text-slate-800">{{ t.name }}</span>
          <span class="block truncate text-xs text-slate-400">{{ t.city }} · {{ t.currency }}</span>
        </span>
      </button>
    </div>
  </div>
</template>
