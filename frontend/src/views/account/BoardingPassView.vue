<script setup>
import { ref, onMounted } from 'vue'
import { useRoute, RouterLink } from 'vue-router'
import api from '@/api'
import BoardingPass from '@/components/BoardingPass.vue'
import Spinner from '@/components/ui/Spinner.vue'
import { useTenantContext } from '@/composables/useTenantContext'

const route = useRoute()
const { tenant } = useTenantContext()
const pass = ref(null)
const booking = ref(null)
const loading = ref(true)
const error = ref('')

onMounted(async () => {
  try {
    booking.value = await api.getBooking(route.params.bookingId)
    pass.value = await api.getBoardingPass(route.params.bookingId)
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div class="section py-10">
    <Spinner v-if="loading" />

    <div v-else-if="error" class="mx-auto max-w-md text-center">
      <p class="text-lg text-slate-600">{{ error }}</p>
      <RouterLink :to="{ name: 'account-dashboard' }" class="btn-primary mt-4">Mon compte</RouterLink>
    </div>

    <template v-else-if="pass">
      <div class="mb-6 flex items-center justify-between print:hidden">
        <RouterLink :to="{ name: 'booking-detail', params: { bookingId: booking.id } }" class="text-sm text-slate-400 hover:text-brand-600">← Reservation</RouterLink>
        <button class="btn-outline" @click="() => window.print()">⬇️ Imprimer / PDF</button>
      </div>
      <BoardingPass :pass="pass" :tenant-name="tenant?.name" />
    </template>
  </div>
</template>
