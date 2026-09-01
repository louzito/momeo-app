<script setup>
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'
import { useCheckoutGuard } from '@/composables/useCheckoutGuard'
import { useTenantContext } from '@/composables/useTenantContext'
import api from '@/api'
import CheckoutLayout from '@/components/CheckoutLayout.vue'
import EligibilityForm from '@/components/EligibilityForm.vue'
import CustomerDetailsForm from '@/components/CustomerDetailsForm.vue'

const router = useRouter()
const { cart } = useCheckoutGuard()
const { tenant, slug } = useTenantContext()
const { jumper } = storeToRefs(cart)

const checking = ref(false)
const violations = ref([])
const checked = ref(false)
const isLegacyService = computed(() => cart.jumpType?.legacyEligibility !== false)
const requirements = computed(() => cart.jumpType?.requirements || [])
const detailsComplete = computed(() =>
  Boolean(
    cart.jumper.firstName?.trim() &&
    cart.jumper.lastName?.trim() &&
    cart.jumper.email?.trim() &&
    cart.jumper.bookingTermsAccepted &&
    cart.jumper.privacyAccepted &&
    requirements.value.every((item) => cart.jumper.customAnswers?.[item.key]),
  ),
)

async function verify() {
  checking.value = true
  violations.value = []
  if (!isLegacyService.value) {
    checking.value = false
    checked.value = true
    if (!detailsComplete.value) {
      violations.value = ['Renseignez vos coordonnees et acceptez les conditions necessaires pour continuer.']
      return
    }
    cart.markEligibilityChecked()
    router.push({ name: 'checkout-summary', params: { slug: slug.value } })
    return
  }

  const res = await api.checkEligibility(tenant.value.id, cart.jumpType.id, cart.jumper)
  checking.value = false
  checked.value = true
  if (res.eligible) {
    cart.markEligibilityChecked()
    router.push({ name: 'checkout-summary', params: { slug: slug.value } })
  } else {
    violations.value = res.violations
  }
}
</script>

<template>
  <CheckoutLayout
    v-if="cart.jumpType"
    step="details"
    :title="isLegacyService ? 'Informations et securite' : 'Vos coordonnees'"
    :subtitle="isLegacyService
      ? 'Les controles de cette ancienne prestation restent appliques jusqu a sa migration.'
      : 'Ces informations permettront a l etablissement de confirmer et preparer votre rendez-vous.'"
  >
    <EligibilityForm v-if="isLegacyService" v-model="jumper" :rule="cart.jumpType.eligibility" />
    <CustomerDetailsForm v-else v-model="jumper" :requirements="requirements" />

    <!-- Blocage si regle non respectee -->
    <div
      v-if="violations.length"
      class="mt-6 rounded-2xl border border-rose-200 bg-rose-50 p-5"
    >
      <p class="flex items-center gap-2 font-semibold text-rose-700">
        ⚠️ {{ isLegacyService ? 'Conditions de securite non respectees' : 'Informations incompletes' }}
      </p>
      <ul class="mt-2 list-disc space-y-1 pl-6 text-sm text-rose-700">
        <li v-for="(v, i) in violations" :key="i">{{ v }}</li>
      </ul>
      <p v-if="isLegacyService" class="mt-3 text-sm text-rose-600">
        Corrigez les informations ci-dessus, ou
        <RouterLink :to="{ name: 'eligibility-blocked' }" class="font-medium underline">en savoir plus</RouterLink>.
      </p>
    </div>

    <div class="mt-8 flex items-center justify-between">
      <RouterLink :to="{ name: 'checkout-schedule', params: { slug } }" class="btn-ghost">← Retour</RouterLink>
      <button class="btn-primary px-8" :disabled="checking" @click="verify">
        {{ checking ? 'Verification…' : (isLegacyService ? 'Verifier et continuer' : 'Continuer') }}
      </button>
    </div>
  </CheckoutLayout>
</template>
