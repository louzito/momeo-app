<script setup>
import { onMounted, ref } from 'vue'
import { getWaitlist, unsubscribeWaitlistEntry } from '@/api/adminApi'
import { formatDateTime } from '@/utils/format'

const entries = ref([])
const loading = ref(true)
const error = ref('')
onMounted(async () => { try { entries.value = await getWaitlist() } catch (e) { error.value = e.message } finally { loading.value = false } })
async function unsubscribe(entry) { try { Object.assign(entry, await unsubscribeWaitlistEntry(entry.id)) } catch (e) { error.value = e.message } }
</script>

<template>
  <section>
    <div class="mb-6"><h1 class="font-display text-2xl font-black text-slate-900">Liste d’attente</h1><p class="text-sm text-slate-500">Demandes d’alerte, sans réservation automatique.</p></div>
    <p v-if="error" class="mb-4 rounded-xl bg-red-50 p-3 text-sm text-red-700">{{ error }}</p>
    <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white">
      <p v-if="loading" class="p-6 text-slate-500">Chargement…</p>
      <p v-else-if="!entries.length" class="p-6 text-slate-500">Aucune demande.</p>
      <div v-for="entry in entries" v-else :key="entry.id" class="flex flex-col gap-3 border-b border-slate-100 p-5 last:border-0 sm:flex-row sm:items-center sm:justify-between">
        <div><p class="font-semibold text-slate-900">{{ entry.customerName }} · {{ entry.serviceName }}</p><p class="text-sm text-slate-500">{{ entry.customerEmail }} · du {{ formatDateTime(entry.periodStart) }} au {{ formatDateTime(entry.periodEnd) }}</p></div>
        <div class="flex items-center gap-3"><span class="chip" :class="entry.status === 'active' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'">{{ entry.status === 'active' ? 'Active' : 'Désinscrite' }}</span><button v-if="entry.status === 'active'" class="text-sm font-medium text-red-600 underline" @click="unsubscribe(entry)">Désinscrire</button></div>
      </div>
    </div>
  </section>
</template>
