<script setup>
import { ref, onMounted } from 'vue'
import { RouterLink, RouterView, useRouter } from 'vue-router'
import { useAdminStore } from '@/stores/admin'
import { applyBranding } from '@/composables/useBranding'

const admin = useAdminStore()
const router = useRouter()
const sidebarOpen = ref(false)

onMounted(() => {
  if (admin.tenant) applyBranding(admin.tenant)
})

const nav = [
  { name: 'admin-dashboard', label: 'Tableau de bord', icon: '📊' },
  { name: 'admin-products', label: 'Prestations', icon: '✦' },
  { name: 'admin-staff', label: 'Équipe', icon: '👥' },
  { name: 'admin-options', label: 'Options & suppléments', icon: '＋' },
  { name: 'admin-agenda', label: 'Agenda', icon: '🗓️' },
  { name: 'admin-bookings', label: 'Réservations', icon: '📋' },
  { name: 'admin-clients', label: 'Clients', icon: '♡' },
  { name: 'admin-orders', label: 'Commandes', icon: '🧾' },
  { name: 'admin-vouchers', label: 'Chèques cadeaux', icon: '🎁' },
  { name: 'admin-payments', label: 'Moyens de paiement', icon: '💳' },
  { name: 'admin-settings', label: 'Configuration boutique', icon: '⚙️' },
]

function logout() {
  admin.logout()
  router.push({ name: 'admin-login' })
}
</script>

<template>
  <div class="flex min-h-screen bg-slate-100">
    <!-- Sidebar -->
    <aside
      class="fixed inset-y-0 left-0 z-40 flex w-64 flex-col bg-slate-950 text-slate-300 transition-transform lg:static lg:translate-x-0"
      :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
    >
      <div class="flex h-16 items-center gap-2.5 border-b border-white/10 px-5">
        <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-amber-300 font-display text-lg font-black text-slate-950">M</span>
        <div class="min-w-0">
          <p class="truncate font-display text-sm font-bold text-white">{{ admin.tenant?.name || 'TodaTempo' }}</p>
          <p class="text-[11px] text-white/40">Espace professionnel</p>
        </div>
      </div>

      <nav class="flex-1 space-y-1 overflow-y-auto p-3">
        <RouterLink
          v-for="item in nav"
          :key="item.name"
          :to="{ name: item.name }"
          class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium text-white/70 transition hover:bg-white/10 hover:text-white"
          active-class="bg-white/10 text-white"
          @click="sidebarOpen = false"
        >
          <span class="text-base">{{ item.icon }}</span>{{ item.label }}
        </RouterLink>
      </nav>

      <div class="space-y-1 border-t border-white/10 p-3">
        <RouterLink
          v-if="admin.tenant"
          :to="{ name: 'tenant-home', params: { slug: admin.tenant.slug } }"
          class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm text-white/60 transition hover:bg-white/10 hover:text-white"
        >
          <span>🌐</span>Voir la boutique
        </RouterLink>
        <button class="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-sm text-white/60 transition hover:bg-white/10 hover:text-white" @click="logout">
          <span>↩︎</span>Se déconnecter
        </button>
      </div>
    </aside>

    <!-- Overlay mobile -->
    <div v-if="sidebarOpen" class="fixed inset-0 z-30 bg-black/40 lg:hidden" @click="sidebarOpen = false" />

    <!-- Contenu -->
    <div class="flex min-w-0 flex-1 flex-col">
      <header class="flex h-16 items-center justify-between gap-3 border-b border-slate-200 bg-white px-4 lg:px-8">
        <button class="rounded-lg p-2 text-slate-500 hover:bg-slate-100 lg:hidden" @click="sidebarOpen = true">☰</button>
        <div class="flex items-center gap-2 text-sm text-slate-400">
          <span class="hidden sm:inline">Connecté :</span>
          <span class="font-medium text-slate-700">{{ admin.admin?.name }}</span>
        </div>
        <span class="chip bg-brand-50 text-brand-700">{{ admin.tenant?.currency }} · {{ admin.tenant?.city }}</span>
      </header>

      <main class="flex-1 p-4 lg:p-8">
        <RouterView />
      </main>
    </div>
  </div>
</template>
