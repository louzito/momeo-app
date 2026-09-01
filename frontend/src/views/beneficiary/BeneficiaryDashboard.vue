<script setup>
import { onMounted, computed } from 'vue'
import { RouterLink } from 'vue-router'
import { storeToRefs } from 'pinia'
import { useBeneficiaryStore } from '@/stores/beneficiary'
import VoucherCard from '@/components/VoucherCard.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import Spinner from '@/components/ui/Spinner.vue'

const store = useBeneficiaryStore()
const { vouchers, loading, profile } = storeToRefs(store)

const available = computed(() => vouchers.value.filter((v) => v.status === 'active'))
const others = computed(() => vouchers.value.filter((v) => v.status !== 'active'))

onMounted(() => store.refreshVouchers())
</script>

<template>
  <div class="section py-10">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="font-display text-3xl font-bold text-slate-900">Mes cheques cadeaux</h1>
        <p class="mt-1 text-slate-500">Bonjour {{ profile?.firstName }} · {{ profile?.email }}</p>
      </div>
      <button class="btn-ghost" @click="store.logout()">Se deconnecter</button>
    </div>

    <Spinner v-if="loading && !vouchers.length" />

    <template v-else>
      <section class="mt-8">
        <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-slate-400">Disponibles</h2>
        <div v-if="available.length" class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
          <VoucherCard v-for="v in available" :key="v.code" :voucher="v" />
        </div>
        <EmptyState v-else icon="🎁" title="Aucun cheque disponible" message="Vos cheques utilises ou expires apparaissent ci-dessous.">
          <RouterLink :to="{ name: 'home' }" class="btn-primary">Decouvrir les centres</RouterLink>
        </EmptyState>
      </section>

      <section v-if="others.length" class="mt-10">
        <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-slate-400">Historique</h2>
        <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
          <VoucherCard v-for="v in others" :key="v.code" :voucher="v" />
        </div>
      </section>
    </template>
  </div>
</template>
