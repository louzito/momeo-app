<script setup>
import { computed, ref, watch, nextTick, onMounted, onBeforeUnmount } from 'vue'
import { RouterLink, RouterView, useRouter, useRoute } from 'vue-router'
import { useAdminStore } from '@/stores/admin'
import { adminNavigation, adminItemLocation, isAdminItemActive } from '@/utils/adminNavigation'
import { applyBranding } from '@/composables/useBranding'

const admin = useAdminStore()
const router = useRouter()
const route = useRoute()
const sidebarOpen = ref(false)
const sidebar = ref(null)
const menuButton = ref(null)
const main = ref(null)
const mobile = ref(window.matchMedia('(max-width: 1023px)').matches)
const expanded = ref({})
const nav = computed(() => adminNavigation(router, admin.can))
const activeGroup = computed(() => nav.value.find((group) => group.items.some((item) => isAdminItemActive(item, route)))?.id)

watch(() => route.fullPath, () => {
  if (activeGroup.value) expanded.value[activeGroup.value] = true
  if (sidebarOpen.value) {
    sidebarOpen.value = false
    nextTick(() => main.value?.focus())
  }
}, { immediate: true })

const mediaQuery = window.matchMedia('(max-width: 1023px)')
function onResize(event) {
  mobile.value = event.matches
  if (!event.matches) sidebarOpen.value = false
}
onMounted(() => {
  if (admin.tenant) applyBranding(admin.tenant)
  mediaQuery.addEventListener('change', onResize)
})
const previousOverflow = document.body.style.overflow
watch([sidebarOpen, mobile], ([open, small]) => {
  document.body.style.overflow = open && small ? 'hidden' : previousOverflow
})
onBeforeUnmount(() => {
  mediaQuery.removeEventListener('change', onResize)
  document.body.style.overflow = previousOverflow
})

async function openSidebar() {
  sidebarOpen.value = true
  await nextTick()
  sidebar.value?.querySelector('button')?.focus()
}
function closeSidebar() {
  sidebarOpen.value = false
  nextTick(() => menuButton.value?.focus())
}
function onSidebarKeydown(event) {
  if (!mobile.value || !sidebarOpen.value) return
  if (event.key === 'Escape') {
    event.preventDefault()
    closeSidebar()
  }
  if (event.key !== 'Tab') return
  const elements = [...sidebar.value.querySelectorAll('a[href], button:not([disabled])')]
    .filter((element) => element.getClientRects().length)
  const first = elements[0]
  const last = elements.at(-1)
  if (event.shiftKey && document.activeElement === first) {
    event.preventDefault()
    last?.focus()
  } else if (!event.shiftKey && document.activeElement === last) {
    event.preventDefault()
    first?.focus()
  }
}

function logout() {
  admin.logout()
  router.push({ name: 'admin-login' })
}
</script>

<template>
  <div class="flex min-h-screen bg-slate-100">
    <!-- Sidebar -->
    <aside
      id="admin-sidebar"
      ref="sidebar"
      :inert="mobile && !sidebarOpen"
      :role="mobile && sidebarOpen ? 'dialog' : undefined"
      :aria-modal="mobile && sidebarOpen ? true : undefined"
      aria-label="Menu professionnel"
      class="fixed inset-y-0 left-0 z-40 flex h-dvh w-72 shrink-0 flex-col bg-slate-950 text-slate-300 transition-transform lg:sticky lg:top-0 lg:translate-x-0"
      @keydown="onSidebarKeydown"
      :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
    >
      <div class="flex h-16 items-center gap-2.5 border-b border-white/10 px-5">
        <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-amber-300 font-display text-lg font-black text-slate-950">M</span>
        <div class="min-w-0">
          <p class="truncate font-display text-sm font-bold text-white">{{ admin.tenant?.name || 'TodaTempo' }}</p>
          <p class="text-[11px] text-white/40">Espace professionnel</p>
        </div>
      </div>

      <button type="button" class="mx-3 mt-2 rounded-lg p-2 text-left text-sm text-white focus-visible:ring-2 focus-visible:ring-amber-300 lg:hidden" @click="closeSidebar">Fermer le menu</button>
      <nav aria-label="Navigation professionnelle" class="min-h-0 flex-1 space-y-1 overflow-y-auto p-3">
        <div v-for="group in nav" :key="group.id">
          <button
            type="button"
            class="flex w-full items-center justify-between rounded-xl px-3 py-2.5 text-left text-sm font-semibold hover:bg-white/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-300"
            :class="activeGroup === group.id ? 'bg-white/10 text-amber-200' : 'text-white/80'"
            :aria-expanded="!!expanded[group.id]"
            :aria-controls="`admin-group-${group.id}`"
            @click="expanded[group.id] = !expanded[group.id]"
          >
            {{ group.label }}<span aria-hidden="true">{{ expanded[group.id] ? '−' : '+' }}</span>
          </button>
          <ul v-show="expanded[group.id]" :id="`admin-group-${group.id}`" class="my-1 space-y-1 border-l border-white/20 pl-2 ml-3">
            <li v-for="item in group.items" :key="item.section || item.name">
              <RouterLink :to="adminItemLocation(item)" custom v-slot="{ href, navigate }">
                <a
                  :href="href"
                  class="block rounded-lg px-3 py-2 text-sm hover:bg-white/10 hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-300"
                  :class="isAdminItemActive(item, route) ? 'bg-white/10 font-semibold text-white' : 'text-white/70'"
                  :aria-current="isAdminItemActive(item, route) ? 'page' : undefined"
                  @click="(event) => { navigate(event); if (mobile && isAdminItemActive(item, route)) closeSidebar() }"
                >{{ item.label }}</a>
              </RouterLink>
            </li>
          </ul>
        </div>
      </nav>

      <div class="shrink-0 space-y-1 border-t border-white/10 p-3">
        <RouterLink
          v-if="admin.tenant"
          :to="{ name: 'tenant-home' }"
          class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm text-white/60 transition hover:bg-white/10 hover:text-white"
        >
          <span>🌐</span>Voir mon site
        </RouterLink>
        <button class="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-sm text-white/60 transition hover:bg-white/10 hover:text-white" @click="logout">
          <span>↩︎</span>Se déconnecter
        </button>
      </div>
    </aside>

    <!-- Overlay mobile -->
    <div v-if="sidebarOpen" class="fixed inset-0 z-30 bg-black/40 lg:hidden" @click="closeSidebar" />

    <!-- Contenu -->
    <div :inert="mobile && sidebarOpen" class="flex min-w-0 flex-1 flex-col">
      <header class="flex h-16 items-center justify-between gap-3 border-b border-slate-200 bg-white px-4 lg:px-8">
        <button ref="menuButton" type="button" aria-label="Ouvrir le menu" aria-controls="admin-sidebar" :aria-expanded="sidebarOpen" class="rounded-lg p-2 text-slate-500 hover:bg-slate-100 lg:hidden" @click="openSidebar">☰</button>
        <div class="flex items-center gap-2 text-sm text-slate-400">
          <span class="hidden sm:inline">Connecté :</span>
          <span class="font-medium text-slate-700">{{ admin.admin?.name }}</span>
        </div>
        <span class="chip bg-brand-50 text-brand-700">{{ admin.tenant?.currency }} · {{ admin.tenant?.city }}</span>
      </header>

      <main ref="main" tabindex="-1" class="flex-1 p-4 outline-none lg:p-8">
        <RouterView />
      </main>
    </div>
  </div>
</template>
