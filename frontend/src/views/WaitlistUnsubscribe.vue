<script setup>
import { onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import api from '@/api'

const route = useRoute()
const status = ref('loading')
const error = ref('')
onMounted(async () => {
  try { await api.leaveWaitlist(route.params.token); status.value = 'done' }
  catch (e) { error.value = e.message; status.value = 'error' }
})
</script>

<template>
  <main class="mx-auto max-w-xl px-4 py-20 text-center">
    <h1 class="font-display text-3xl font-black text-slate-900">Liste d’attente</h1>
    <p v-if="status === 'loading'" class="mt-4 text-slate-500">Désinscription en cours…</p>
    <p v-else-if="status === 'done'" class="mt-4 text-emerald-700">Vous êtes désinscrit. Vous ne recevrez plus d’alertes pour cette demande.</p>
    <p v-else class="mt-4 text-red-600">{{ error }}</p>
  </main>
</template>
