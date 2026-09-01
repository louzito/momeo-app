<script setup>
import { ref, watch } from 'vue'
import { useRouter, RouterLink } from 'vue-router'
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

      <p class="mt-6 text-sm text-slate-500">
        Aucune date ne convient ?
        <RouterLink :to="{ name: 'checkout-mode', params: { slug } }" class="font-medium text-brand-600 underline">
          Optez plutot pour un cheque cadeau
        </RouterLink>
        (le beneficiaire choisira plus tard).
      </p>

      <div class="mt-8 flex justify-end">
        <button class="btn-primary px-8" :disabled="!cart.slot" @click="next">
          Continuer
        </button>
      </div>
    </template>
  </CheckoutLayout>
</template>
