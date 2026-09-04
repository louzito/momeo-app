<script setup>
import { ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'
import { useCheckoutGuard } from '@/composables/useCheckoutGuard'
import { useTenantContext } from '@/composables/useTenantContext'
import api from '@/api'
import CheckoutLayout from '@/components/CheckoutLayout.vue'
import SlotCalendar from '@/components/SlotCalendar.vue'
import Spinner from '@/components/ui/Spinner.vue'

const router = useRouter()
const { cart } = useCheckoutGuard()
const { tenant, slug } = useTenantContext()
const { slot } = storeToRefs(cart)

const slots = ref([])
const loading = ref(true)
const waitlistOpen = ref(false)
const waitlistLoading = ref(false)
const waitlistError = ref('')
const waitlistSuccess = ref(false)
const today = new Date().toISOString().slice(0, 10)
const waitlist = ref({ firstName: '', lastName: '', email: '', periodStart: today, periodEnd: today, consent: false })

watch(
  tenant,
  async (t) => {
    if (!t || !cart.jumpType) return
    loading.value = true
    slots.value = await api.getSlots(t.id, { jumpTypeId: cart.jumpType.id })
    loading.value = false
  },
  { immediate: true },
)

function next() {
  if (!cart.slot) return
  router.push({ name: 'checkout-eligibility', params: { slug: slug.value } })
}

async function joinWaitlist() {
  waitlistError.value = ''
  waitlistLoading.value = true
  try {
    await api.joinWaitlist(tenant.value.id, {
      serviceCode: cart.jumpType.id,
      ...waitlist.value,
      periodStart: `${waitlist.value.periodStart}T00:00:00`,
      periodEnd: `${waitlist.value.periodEnd}T23:59:59`,
    })
    waitlistSuccess.value = true
  } catch (error) {
    waitlistError.value = error.message
  } finally {
    waitlistLoading.value = false
  }
}
</script>

<template>
  <CheckoutLayout
    v-if="cart.jumpType"
    step="schedule"
    title="Choisissez votre creneau"
    :subtitle="`Disponibilites pour « ${cart.jumpType.name} ».`"
  >
    <Spinner v-if="loading" label="Chargement des creneaux…" />
    <template v-else>
      <SlotCalendar
        :slots="slots"
        :selected-slot-id="slot?.id"
        :jump-type-id="cart.jumpType.id"
        @select="cart.setSlot"
      />

      <div class="mt-6 rounded-2xl border border-slate-200 bg-slate-50 p-5">
        <button v-if="!waitlistOpen" class="font-medium text-brand-700 underline" @click="waitlistOpen = true">Aucune date ne convient ? M’inscrire sur la liste d’attente</button>
        <div v-else-if="waitlistSuccess" class="text-sm text-emerald-700">Inscription enregistrée. Nous vous préviendrons si une place se libère, sans la réserver automatiquement.</div>
        <form v-else class="space-y-4" @submit.prevent="joinWaitlist">
          <div><h3 class="font-display text-lg font-bold text-slate-900">Être alerté d’une disponibilité</h3><p class="text-sm text-slate-500">Indiquez la période qui vous intéresse.</p></div>
          <div class="grid gap-3 sm:grid-cols-2">
            <label class="text-sm font-medium">Prénom<input v-model.trim="waitlist.firstName" required maxlength="100" class="input mt-1 w-full" /></label>
            <label class="text-sm font-medium">Nom<input v-model.trim="waitlist.lastName" required maxlength="100" class="input mt-1 w-full" /></label>
            <label class="text-sm font-medium sm:col-span-2">Email<input v-model.trim="waitlist.email" required type="email" maxlength="180" class="input mt-1 w-full" /></label>
            <label class="text-sm font-medium">Du<input v-model="waitlist.periodStart" required type="date" :min="today" class="input mt-1 w-full" /></label>
            <label class="text-sm font-medium">Au<input v-model="waitlist.periodEnd" required type="date" :min="waitlist.periodStart" class="input mt-1 w-full" /></label>
          </div>
          <label class="flex items-start gap-3 text-sm"><input v-model="waitlist.consent" required type="checkbox" class="mt-1" /><span>J’accepte explicitement de recevoir par email les alertes de disponibilité pour cette prestation. Je pourrai me désinscrire depuis chaque email.</span></label>
          <p v-if="waitlistError" class="text-sm text-red-600">{{ waitlistError }}</p>
          <button class="btn-primary" :disabled="waitlistLoading || !waitlist.consent">{{ waitlistLoading ? 'Inscription…' : 'M’inscrire' }}</button>
        </form>
      </div>

      <div class="mt-8 flex justify-end">
        <button class="btn-primary px-8" :disabled="!cart.slot" @click="next">
          Continuer
        </button>
      </div>
    </template>
  </CheckoutLayout>
</template>
