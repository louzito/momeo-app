<script setup>
import { ref, onMounted, computed } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import api from '@/api'
import { useTenantContext } from '@/composables/useTenantContext'
import { useBeneficiaryStore } from '@/stores/beneficiary'
import Spinner from '@/components/ui/Spinner.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import { formatDate, formatMoney } from '@/utils/format'

const route = useRoute()
const router = useRouter()
// Cheque cadeau reel : plus d'identite de tenant portee par le cheque -> le
// tenant courant (URL /{slug}/...) EST le centre qui l'a vendu.
const { tenant, tenantStore } = useTenantContext()
const beneficiaryStore = useBeneficiaryStore()

const code = route.params.code
const voucher = ref(null)
const loading = ref(true)
const extending = ref(false)
const extended = ref(false)

onMounted(async () => {
  try {
    await tenantStore.loadDefaultTenant()
    voucher.value = await api.getVoucherByCode(code)
  } finally {
    loading.value = false
  }
})

const extension = computed(() => tenant.value?.extensionOption)

async function extend() {
  extending.value = true
  try {
    const res = await api.extendVoucher(code)
    voucher.value = res.voucher
    extended.value = true
    await beneficiaryStore.refreshVouchers()
  } finally {
    extending.value = false
  }
}

function goSchedule() {
  router.push({ name: 'beneficiary-schedule', params: { code } })
}
</script>

<template>
  <Spinner v-if="loading" />

  <div v-else-if="voucher" class="section py-12">
    <div class="mx-auto max-w-xl">
      <RouterLink :to="{ name: 'beneficiary-dashboard' }" class="text-sm text-slate-400 hover:text-brand-600">← Mes cheques</RouterLink>

      <div class="card mt-4 overflow-hidden">
        <div class="border-b border-dashed border-slate-200 bg-rose-50 p-6">
          <div class="flex items-center justify-between">
            <p class="font-mono text-xl font-bold tracking-wider text-slate-900">{{ voucher.code }}</p>
            <StatusBadge :status="voucher.status" />
          </div>
          <p class="mt-1 text-sm text-slate-500">{{ voucher.jumpTypeName }} · {{ tenant?.name }}</p>
        </div>

        <div class="p-6">
          <template v-if="voucher.status === 'expired'">
            <p class="text-slate-600">
              Ce cheque cadeau d'une valeur de
              <strong>{{ formatMoney(voucher.amount, voucher.currency) }}</strong> a expire le
              <strong>{{ formatDate(voucher.expiresAt, { short: true }) }}</strong>. Il n'est plus reservable en l'etat,
              mais reste visible pour votre suivi.
            </p>

            <div v-if="extension?.available" class="mt-6 rounded-2xl border border-amber-200 bg-amber-50 p-5">
              <p class="font-semibold text-amber-800">💡 Prolongation possible</p>
              <p class="mt-1 text-sm text-amber-700">
                Reactivez ce cheque pour <strong>{{ formatMoney(extension.price, voucher.currency) }}</strong> et gagnez
                <strong>{{ extension.addedMonths }} mois</strong> de validite supplementaires.
              </p>
              <button class="btn-accent mt-4" :disabled="extending" @click="extend">
                {{ extending ? 'Traitement…' : `Prolonger pour ${formatMoney(extension.price, voucher.currency)}` }}
              </button>
            </div>
            <p v-else class="mt-6 rounded-xl bg-slate-50 p-4 text-sm text-slate-500">
              Ce centre ne propose pas de prolongation en ligne. Contactez-le directement.
            </p>
          </template>

          <template v-else>
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-5 text-emerald-800">
              <p class="font-semibold">✓ Cheque reactive !</p>
              <p class="mt-1 text-sm">
                Nouvelle date d'expiration : <strong>{{ formatDate(voucher.expiresAt, { short: true }) }}</strong>.
              </p>
            </div>
            <button class="btn-primary mt-6 w-full" @click="goSchedule">Choisir un creneau maintenant</button>
          </template>
        </div>
      </div>
    </div>
  </div>
</template>
