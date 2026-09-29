<script setup>
import { computed } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import { useTenantContext } from '@/composables/useTenantContext'
import { useCartStore } from '@/stores/cart'
import { formatMoney } from '@/utils/format'
import { applicableServiceOptions, serviceStartingPrice, serviceCharacteristics } from '@/utils/serviceDetails'
import Spinner from '@/components/ui/Spinner.vue'
import CatalogError from '@/components/ui/CatalogError.vue'

const route = useRoute()
const router = useRouter()
const cart = useCartStore()
const { tenantStore, tenant, jumpTypes, options, loading, error, slug } = useTenantContext()
const retry = () => tenantStore.retryPublicCatalog().catch(() => {})

const jumpType = computed(() =>
  jumpTypes.value.find((j) => j.id === route.params.jumpTypeId) || null,
)
const applicableOptions = computed(() => applicableServiceOptions(jumpType.value, options.value))
const mandatoryOptions = computed(() => applicableOptions.value.filter((option) => option.mandatory))
const optionalOptions = computed(() => applicableOptions.value.filter((option) => !option.mandatory))
const startingPrice = computed(() => serviceStartingPrice(jumpType.value, options.value))
const characteristics = computed(() => serviceCharacteristics(jumpType.value))

function book() {
  if (cart.tenantId !== tenant.value.id || cart.jumpType?.id !== jumpType.value.id || cart.lastResult) {
    if (cart.startPurchase(tenant.value.id, jumpType.value) === false) return
  }
  cart.ensureMandatoryOptions(applicableOptions.value)
  router.push({ name: 'checkout-schedule', params: { slug: slug.value } })
}
</script>

<template>
  <Spinner v-if="loading" />

  <CatalogError v-else-if="error" :message="error" @retry="retry" />

  <div v-else-if="!jumpType" class="section py-20 text-center">
    <p class="text-lg text-slate-600">Cette prestation n'existe pas.</p>
    <RouterLink :to="{ name: 'tenant-home', params: { slug } }" class="btn-primary mt-4">Retour au catalogue</RouterLink>
  </div>

  <div v-else class="section py-10">
    <RouterLink :to="{ name: 'tenant-home', params: { slug } }" class="text-sm text-slate-400 hover:text-brand-600">
      ← {{ tenant.name }}
    </RouterLink>

    <div class="mt-4 grid gap-10 break-words lg:grid-cols-2">
      <!-- Media -->
      <div>
        <div class="overflow-hidden rounded-3xl shadow-soft">
          <img v-if="jumpType.image" :src="jumpType.image" :alt="jumpType.name" class="aspect-[4/3] w-full object-cover" />
          <div v-else class="flex aspect-[4/3] items-center justify-center bg-slate-100 text-5xl text-slate-300" aria-hidden="true">✦</div>
        </div>
      </div>

      <!-- Infos -->
      <div class="min-w-0">
        <span v-if="jumpType.popular" class="chip bg-accent-500 text-white">★ Le plus demandé</span>
        <h1 class="mt-2 font-display text-4xl font-extrabold text-slate-900">{{ jumpType.name }}</h1>
        <p v-if="jumpType.summary" class="mt-3 text-lg text-slate-600">{{ jumpType.summary }}</p>
        <div v-if="jumpType.description" class="mt-4">
          <h2 class="font-semibold text-slate-800">La prestation</h2>
          <p class="mt-2 whitespace-pre-line text-slate-600">{{ jumpType.description }}</p>
        </div>

        <div class="mt-6 flex flex-wrap items-baseline gap-2">
          <span class="text-sm text-slate-400">À partir de</span>
          <span class="font-display text-4xl font-bold text-brand-700">{{ formatMoney(startingPrice, tenant.currency) }}</span>
        </div>

        <div v-if="mandatoryOptions.length" class="mt-2 text-sm text-slate-600">
          <p>Frais obligatoires inclus :</p>
          <ul class="mt-1 list-inside list-disc">
            <li v-for="option in mandatoryOptions" :key="option.id">
              {{ option.name }} : {{ formatMoney(option.price, tenant.currency) }}
            </li>
          </ul>
        </div>

        <dl v-if="characteristics.length" class="mt-6 grid grid-cols-1 gap-4 rounded-2xl bg-white p-5 shadow-sm sm:grid-cols-2">
          <div v-for="item in characteristics" :key="item.label">
            <dt class="text-xs uppercase text-slate-500">{{ item.label }}</dt>
            <dd class="font-semibold text-slate-800">{{ item.value }}</dd>
          </div>
        </dl>

        <section v-if="jumpType.requirements?.length" class="mt-6">
          <h2 class="font-semibold text-slate-800">Conditions et informations pratiques</h2>
          <ul class="mt-2 list-inside list-disc space-y-2 text-slate-600">
            <li v-for="item in jumpType.requirements" :key="item.key">{{ item.label }}</li>
          </ul>
        </section>

        <div v-if="optionalOptions.length" class="mt-6">
          <h2 class="mb-2 text-sm font-semibold text-slate-700">Options disponibles</h2>
          <ul class="space-y-2 text-sm text-slate-600">
            <li v-for="option in optionalOptions" :key="option.id">
              {{ option.name }} · +{{ formatMoney(option.price, tenant.currency) }}
            </li>
          </ul>
        </div>

        <div class="mt-8 flex flex-wrap gap-3">
          <button class="btn-primary px-8" @click="book">Réserver cette prestation</button>
        </div>
        <p class="mt-3 text-xs text-slate-400">Choisissez votre créneau et vos options, puis renseignez vos coordonnées pour réserver.</p>
      </div>
    </div>
  </div>
</template>
