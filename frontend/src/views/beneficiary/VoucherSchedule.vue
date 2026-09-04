<script setup>
import { computed, ref, onMounted } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import api from '@/api'
import { useTenantContext } from '@/composables/useTenantContext'
import { useBeneficiaryStore } from '@/stores/beneficiary'
import SlotCalendar from '@/components/SlotCalendar.vue'
import EligibilityForm from '@/components/EligibilityForm.vue'
import CustomerDetailsForm from '@/components/CustomerDetailsForm.vue'
import Spinner from '@/components/ui/Spinner.vue'

const route = useRoute()
const router = useRouter()
// Cheque cadeau reel : toujours consulte depuis le SITE du centre qui l'a
// vendu (URL /{slug}/...) -> le tenant courant EST le bon tenant, pas besoin
// de le resoudre depuis le cheque (qui n'en porte plus l'identite).
const { tenant, tenantStore } = useTenantContext()
const beneficiaryStore = useBeneficiaryStore()

const code = route.params.code
const voucher = ref(null)
const jumpType = ref(null)
const slots = ref([])
const selectedSlot = ref(null)
const step = ref('slot') // 'slot' | 'details'
const loading = ref(true)
const submitting = ref(false)
const violations = ref([])
const jumper = ref({
  firstName: '',
  lastName: '',
  email: '',
  phone: '',
  notes: '',
  bookingTermsAccepted: false,
  privacyAccepted: false,
  age: '',
  weightKg: '',
  heightCm: '',
  medicalCertificate: false,
  waiverAccepted: false,
  customAnswers: {},
})
const isLegacyService = computed(() => jumpType.value?.legacyEligibility !== false)
const requirements = computed(() => jumpType.value?.requirements || [])
const detailsComplete = computed(() =>
  Boolean(
    jumper.value.firstName?.trim() &&
    jumper.value.lastName?.trim() &&
    jumper.value.email?.trim() &&
    jumper.value.bookingTermsAccepted &&
    jumper.value.privacyAccepted &&
    requirements.value.every((item) => jumper.value.customAnswers?.[item.key]),
  ),
)

onMounted(async () => {
  try {
    await tenantStore.loadDefaultTenant()
    voucher.value = await api.getVoucherByCode(code)
    if (voucher.value.status === 'expired') {
      router.replace({ name: 'beneficiary-expired', params: { code } })
      return
    }
    if (['used', 'reserved', 'awaiting_payment'].includes(voucher.value.status)) {
      // Deja utilise / reserve / paiement pas encore encaisse : on renvoie au tableau de bord.
      router.replace({ name: 'beneficiary-dashboard' })
      return
    }
    // Le cheque cadeau ne porte qu'un nom complet -> pre-remplissage decoupe.
    const [first, ...rest] = (voucher.value.beneficiaryName || '').split(/\s+/)
    jumper.value.firstName = first || ''
    jumper.value.lastName = rest.join(' ')
    jumper.value.email = voucher.value.beneficiaryEmail || ''
    jumpType.value = await api.getJumpType(tenant.value.id, voucher.value.jumpTypeId)
    slots.value = await api.getSlots(tenant.value.id, { jumpTypeId: voucher.value.jumpTypeId })
  } finally {
    loading.value = false
  }
})

function pickSlot(slot) {
  selectedSlot.value = slot
  step.value = 'details'
}

async function confirm() {
  violations.value = []
  if (isLegacyService.value) {
    const res = await api.checkEligibility(tenant.value.id, voucher.value.jumpTypeId, jumper.value)
    if (!res.eligible) {
      violations.value = res.violations
      return
    }
  } else if (!detailsComplete.value) {
    violations.value = ['Renseignez vos coordonnees et acceptez les conditions necessaires pour continuer.']
    return
  }
  submitting.value = true
  try {
    const result = await api.reserveVoucher(code, {
      tenantId: tenant.value.id,
      jumpTypeId: voucher.value.jumpTypeId,
      jumpTypeName: jumpType.value.name,
      slotId: selectedSlot.value.id,
      slot: selectedSlot.value,
      jumper: {
        ...jumper.value,
        fullName: `${jumper.value.firstName || ''} ${jumper.value.lastName || ''}`.trim(),
      },
    })
    // On transmet la reservation reelle via le store pour que la page de
    // confirmation l'affiche sans reappeler l'API.
    beneficiaryStore.setLastReservation({ code, voucher: result.voucher, booking: result.booking })
    router.push({ name: 'beneficiary-confirmation', params: { code } })
  } catch (e) {
    violations.value = [e.message]
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <Spinner v-if="loading" label="Chargement de votre cheque…" />

  <div v-else-if="voucher && jumpType" class="section py-10">
    <RouterLink :to="{ name: 'beneficiary-dashboard' }" class="text-sm text-slate-400 hover:text-brand-600">← Mes cheques</RouterLink>
    <h1 class="mt-2 font-display text-3xl font-bold text-slate-900">Reservez votre prestation</h1>
    <p class="mt-1 text-slate-500">
      Cheque <span class="font-mono font-semibold">{{ voucher.code }}</span> · {{ jumpType.name }} · {{ tenant?.name }}
    </p>

    <!-- Etape 1 : creneau -->
    <div v-if="step === 'slot'" class="mt-8">
      <h2 class="mb-4 text-lg font-semibold text-slate-800">1. Choisissez un creneau</h2>
      <SlotCalendar
        :slots="slots"
        :selected-slot-id="selectedSlot?.id"
        :jump-type-id="voucher.jumpTypeId"
        @select="pickSlot"
      />
    </div>

    <!-- Etape 2 : coordonnees et conditions -->
    <div v-else class="mt-8 max-w-2xl">
      <button class="mb-4 text-sm text-brand-600 hover:underline" @click="step = 'slot'">← Changer de creneau</button>
      <h2 class="mb-4 text-lg font-semibold text-slate-800">2. Vos informations</h2>
      <EligibilityForm v-if="isLegacyService" v-model="jumper" :rule="jumpType.eligibility" />
      <CustomerDetailsForm v-else v-model="jumper" :requirements="requirements" :booking-policy="tenant?.bookingRules?.customerPolicy || ''" />
      <p v-if="isLegacyService && tenant?.bookingRules?.customerPolicy" class="whitespace-pre-line rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600">
        {{ tenant.bookingRules.customerPolicy }}
      </p>

      <div v-if="violations.length" class="mt-6 rounded-2xl border border-rose-200 bg-rose-50 p-5">
        <p class="font-semibold text-rose-700">⚠️ {{ isLegacyService ? 'Conditions de securite non respectees' : 'Informations incompletes' }}</p>
        <ul class="mt-2 list-disc space-y-1 pl-6 text-sm text-rose-700">
          <li v-for="(v, i) in violations" :key="i">{{ v }}</li>
        </ul>
      </div>

      <button class="btn-primary mt-8 w-full py-3" :disabled="submitting" @click="confirm">
        {{ submitting ? 'Reservation…' : 'Confirmer ma reservation' }}
      </button>
      <p class="mt-2 text-center text-xs text-slate-400">Aucun paiement — votre cheque cadeau couvre cette prestation.</p>
    </div>
  </div>
</template>
