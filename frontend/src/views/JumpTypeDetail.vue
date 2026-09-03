<script setup>
import { computed } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import { useTenantContext } from '@/composables/useTenantContext'
import { useCartStore } from '@/stores/cart'
import { formatMoney } from '@/utils/format'
import Spinner from '@/components/ui/Spinner.vue'

const route = useRoute()
const router = useRouter()
const cart = useCartStore()
const { tenant, jumpTypes, options, loading, slug } = useTenantContext()

const jumpType = computed(() =>
  jumpTypes.value.find((j) => j.id === route.params.jumpTypeId) || null,
)
const perJumpOptions = computed(() =>
  options.value.filter(
    (o) =>
      o.scope === 'PER_JUMP' &&
      (!o.linkedJumpTypeIds?.length || o.linkedJumpTypeIds.includes(jumpType.value?.id)),
  ),
)
const rule = computed(() => jumpType.value?.eligibility)

function book() {
  cart.startPurchase(tenant.value.id, jumpType.value)
  const applicable = options.value.filter(
    (o) =>
      o.scope !== 'PER_JUMP' ||
      !o.linkedJumpTypeIds?.length ||
      o.linkedJumpTypeIds.includes(jumpType.value?.id),
  )
  cart.ensureMandatoryOptions(applicable)
  router.push({ name: 'checkout-options', params: { slug: slug.value } })
}
</script>

<template>
  <Spinner v-if="loading || !tenant" />

  <div v-else-if="!jumpType" class="section py-20 text-center">
    <p class="text-lg text-slate-600">Cette prestation n'existe pas.</p>
    <RouterLink :to="{ name: 'tenant-home', params: { slug } }" class="btn-primary mt-4">Retour au catalogue</RouterLink>
  </div>

  <div v-else class="section py-10">
    <RouterLink :to="{ name: 'tenant-home', params: { slug } }" class="text-sm text-slate-400 hover:text-brand-600">
      ← {{ tenant.name }}
    </RouterLink>

    <div class="mt-4 grid gap-10 lg:grid-cols-2">
      <!-- Media -->
      <div>
        <div class="overflow-hidden rounded-3xl shadow-soft">
          <img :src="jumpType.image" :alt="jumpType.name" class="aspect-[4/3] w-full object-cover" />
        </div>
      </div>

      <!-- Infos -->
      <div>
        <span v-if="jumpType.popular" class="chip bg-accent-500 text-white">★ Le plus demande</span>
        <h1 class="mt-2 font-display text-4xl font-extrabold text-slate-900">{{ jumpType.name }}</h1>
        <p class="mt-3 text-lg text-slate-600">{{ jumpType.description }}</p>

        <div class="mt-6 flex items-baseline gap-2">
          <span class="text-sm text-slate-400">a partir de</span>
          <span class="font-display text-4xl font-bold text-brand-700">{{ formatMoney(jumpType.basePrice, tenant.currency) }}</span>
        </div>

        <!-- Caracteristiques configurees par le professionnel. -->
        <dl class="mt-6 grid grid-cols-2 gap-4 rounded-2xl bg-white p-5 shadow-sm sm:grid-cols-3">
          <div v-if="jumpType.legacyEligibility && jumpType.altitudeM">
            <dt class="text-xs uppercase text-slate-400">Information complémentaire</dt>
            <dd class="font-semibold text-slate-800">{{ jumpType.altitudeM.toLocaleString('fr-FR') }} m</dd>
          </div>
          <div v-if="jumpType.legacyEligibility">
            <dt class="text-xs uppercase text-slate-400">Duree sur site</dt>
            <dd class="font-semibold text-slate-800">{{ Math.round(jumpType.durationMin / 60 * 10) / 10 }} h</dd>
          </div>
          <div v-if="jumpType.legacyEligibility">
            <dt class="text-xs uppercase text-slate-400">Age</dt>
            <dd class="font-semibold text-slate-800">{{ rule.ageMin }}–{{ rule.ageMax }} ans</dd>
          </div>
          <div v-if="jumpType.legacyEligibility">
            <dt class="text-xs uppercase text-slate-400">Poids max</dt>
            <dd class="font-semibold text-slate-800">{{ rule.weightMaxKg }} kg</dd>
          </div>
          <div>
            <dt class="text-xs uppercase text-slate-400">Taille min</dt>
            <dd class="font-semibold text-slate-800">{{ rule.heightMinCm }} cm</dd>
          </div>
          <div v-if="jumpType.legacyEligibility && rule.bmiMax">
            <dt class="text-xs uppercase text-slate-400">IMC max</dt>
            <dd class="font-semibold text-slate-800">{{ rule.bmiMax }}</dd>
          </div>
          <div v-if="jumpType.legacyEligibility">
            <dt class="text-xs uppercase text-slate-400">Certificat med.</dt>
            <dd class="font-semibold text-slate-800">{{ rule.medicalCertificateRequired ? 'Requis' : 'Non requis' }}</dd>
          </div>
          <div>
            <dt class="text-xs uppercase text-slate-400">Capacite/creneau</dt>
            <dd class="font-semibold text-slate-800">{{ jumpType.capacityPerSlot }} pers.</dd>
          </div>
        </dl>

        <div v-if="perJumpOptions.length" class="mt-6">
          <p class="mb-2 text-sm font-semibold text-slate-700">Options disponibles</p>
          <div class="flex flex-wrap gap-2">
            <span v-for="o in perJumpOptions" :key="o.id" class="chip bg-slate-100 text-slate-600">
              {{ o.name }} · +{{ formatMoney(o.price, tenant.currency) }}
            </span>
          </div>
        </div>

        <div class="mt-8 flex flex-wrap gap-3">
          <button class="btn-primary px-8" @click="book">Reserver cette prestation</button>
          <RouterLink :to="{ name: 'calendar', params: { slug } }" class="btn-outline">Voir les disponibilites</RouterLink>
        </div>
        <p class="mt-3 text-xs text-slate-400">Achat direct avec date ou cheque cadeau — choix a l'etape suivante.</p>
      </div>
    </div>
  </div>
</template>
