<script setup>
import { ref, onMounted } from 'vue'
import { useRoute, RouterLink } from 'vue-router'
import api from '@/api'
import { useBeneficiaryStore } from '@/stores/beneficiary'
import Spinner from '@/components/ui/Spinner.vue'
import { formatDate, formatTime } from '@/utils/format'

const route = useRoute()
const store = useBeneficiaryStore()
const code = route.params.code
const voucher = ref(null)
const booking = ref(null)
const loading = ref(true)

onMounted(async () => {
  try {
    // La reservation vient du store juste apres reserveVoucher() (evite un
    // aller-retour reseau) ; si absente (rechargement direct de cette page),
    // le cheque reste consultable par son code et porte sa reservation reelle
    // via `usageOrderNumber` (voir ShopGiftVoucherApiController::normalize).
    if (store.lastReservation?.code === code) {
      voucher.value = store.lastReservation.voucher
      booking.value = store.lastReservation.booking
    } else {
      voucher.value = await api.getVoucherByCode(code)
      booking.value = voucher.value.booking
    }
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <Spinner v-if="loading" />

  <div v-else class="section py-12">
    <div class="mx-auto max-w-2xl text-center">
      <div class="mx-auto mb-5 flex h-16 w-16 items-center justify-center rounded-full bg-emerald-100 text-3xl">✓</div>
      <h1 class="font-display text-3xl font-extrabold text-slate-900">Votre rendez-vous est reserve !</h1>
      <p class="mt-2 text-slate-500">Votre cheque cadeau a bien ete utilise. Aucun paiement n'a ete requis.</p>
    </div>

    <div v-if="booking" class="mx-auto mt-8 max-w-2xl space-y-4">
      <div class="card p-6">
        <dl class="grid grid-cols-2 gap-4 text-sm">
          <div>
            <dt class="text-slate-400">Prestation</dt>
            <dd class="font-semibold text-slate-800">{{ booking.jumpTypeName }}</dd>
          </div>
          <div>
            <dt class="text-slate-400">Reference</dt>
            <dd class="font-mono font-semibold text-slate-800">{{ booking.reference }}</dd>
          </div>
          <div>
            <dt class="text-slate-400">Date</dt>
            <dd class="font-semibold capitalize text-slate-800">{{ formatDate(booking.slotStart, { weekday: true, short: true }) }}</dd>
          </div>
          <div>
            <dt class="text-slate-400">Heure</dt>
            <dd class="font-semibold text-slate-800">{{ formatTime(booking.slotStart) }}</dd>
          </div>
        </dl>
      </div>

      <div class="flex flex-col gap-3 sm:flex-row">
        <RouterLink :to="{ name: 'beneficiary-dashboard' }" class="btn-primary flex-1">Retour a mes cheques</RouterLink>
      </div>
    </div>
  </div>
</template>
