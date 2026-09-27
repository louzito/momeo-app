<script setup>
import { computed, ref } from 'vue'
import { storeToRefs } from 'pinia'
import { useCheckoutGuard } from '@/composables/useCheckoutGuard'
import { useTenantContext } from '@/composables/useTenantContext'
import api from '@/api'
import CheckoutLayout from '@/components/CheckoutLayout.vue'
import EligibilityForm from '@/components/EligibilityForm.vue'
import Payment from './Payment.vue'
import OrderSummaryCard from '@/components/OrderSummaryCard.vue'
import CustomerDetailsForm from '@/components/CustomerDetailsForm.vue'

const { cart } = useCheckoutGuard()
const { tenant } = useTenantContext()
const { jumper } = storeToRefs(cart)

const violations = ref([])
const detailsForm = ref(null)
const processing = ref(false)
const isLegacyService = computed(() => cart.jumpType?.legacyEligibility !== false)
const requirements = computed(() => cart.jumpType?.requirements || [])

async function verify() {
  violations.value = []
  if (!detailsForm.value?.reportValidity()) return false
  if (!cart.slot) return false
  if (isLegacyService.value) {
    const res = await api.checkEligibility(tenant.value.id, cart.jumpType.id, cart.jumper)
    if (!res.eligible) {
      violations.value = res.violations
      return false
    }
  } else if (!cart.jumper.firstName?.trim() || !cart.jumper.lastName?.trim() ||
    !cart.jumper.email?.trim() || !cart.jumper.bookingTermsAccepted || !cart.jumper.privacyAccepted ||
    !requirements.value.every((item) => cart.jumper.customAnswers?.[item.key])) {
    violations.value = ['Renseignez vos coordonnées et acceptez les conditions nécessaires pour réserver.']
    return false
  }
  cart.markEligibilityChecked()
  return true
}
</script>

<template>
  <CheckoutLayout
    v-if="cart.jumpType"
    step="details"
    title="Vos coordonnées et votre paiement"
    subtitle="Vérifiez votre réservation et renseignez vos coordonnées pour confirmer."
    :show-summary="false"
  >
    <div class="grid gap-8 lg:grid-cols-[1fr_360px]">
      <form ref="detailsForm" @submit.prevent>
        <fieldset :disabled="processing || !!cart.lastResult" class="min-w-0">
          <EligibilityForm v-if="isLegacyService" v-model="jumper" :rule="cart.jumpType.eligibility" />
          <CustomerDetailsForm v-else v-model="jumper" :requirements="requirements" :booking-policy="tenant?.bookingRules?.customerPolicy || ''" />
          <p v-if="isLegacyService && tenant?.bookingRules?.customerPolicy" class="mt-5 whitespace-pre-line rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600">
            {{ tenant.bookingRules.customerPolicy }}
          </p>

          <div v-if="violations.length" role="alert" class="mt-6 rounded-2xl border border-rose-200 bg-rose-50 p-5">
            <p class="font-semibold text-rose-700">
              {{ isLegacyService ? 'Conditions de sécurité non respectées' : 'Informations incomplètes' }}
            </p>
            <ul class="mt-2 list-disc space-y-1 pl-6 text-sm text-rose-700">
              <li v-for="(v, i) in violations" :key="i">{{ v }}</li>
            </ul>
            <p v-if="isLegacyService" class="mt-3 text-sm text-rose-600">
              Corrigez les informations ci-dessus, ou
              <RouterLink :to="{ name: 'eligibility-blocked' }" class="font-medium underline">en savoir plus</RouterLink>.
            </p>
          </div>
        </fieldset>
      </form>
      <OrderSummaryCard :jump-type="cart.jumpType" :options="cart.selectedOptions" :slot="cart.slot" :currency="tenant?.currency" />
    </div>

    <Payment embedded :before-pay="verify" @processing="processing = $event" />
    <RouterLink v-if="!processing && !cart.lastResult" :to="{ name: 'checkout-schedule' }" class="btn-ghost mt-6">← Modifier la date ou les options</RouterLink>
  </CheckoutLayout>
</template>
