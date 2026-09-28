<script setup>
import { computed, ref, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import api from '@/api'
import { useTenantContext } from '@/composables/useTenantContext'
import { orderJumpTypes } from '@/utils/catalog'
import { formatMoney } from '@/utils/format'
import JumpTypeCard from '@/components/JumpTypeCard.vue'
import Spinner from '@/components/ui/Spinner.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import CatalogError from '@/components/ui/CatalogError.vue'

const { tenantStore, tenant, jumpTypes, options, loading, error, slug } = useTenantContext()
const route = useRoute()
const physicalProducts = ref([])
const giftOffer = ref(null)
const productsLoading = ref(false)
const giftLoading = ref(false)
const productsError = ref('')
const giftError = ref('')
const services = computed(() => orderJumpTypes(jumpTypes.value || [], tenant.value?.shopOrder))
const products = computed(() => orderJumpTypes(physicalProducts.value, tenant.value?.shopOrder))
const giftEnabled = computed(() => tenant.value?.giftVouchersEnabled !== false && giftOffer.value?.enabled !== false)
const categories = computed(() => [
  { id: 'prestations', label: 'Prestations' },
  { id: 'produits', label: 'Produits' },
  ...(giftEnabled.value ? [{ id: 'cartes-cadeaux', label: 'Cartes cadeaux' }] : []),
])
const category = computed(() => categories.value.some((item) => item.id === route.query.categorie) ? route.query.categorie : 'prestations')
const catalogEmpty = computed(() => !productsLoading.value && !giftLoading.value && !productsError.value && !giftError.value
  && !services.value.length && !products.value.length && !giftEnabled.value)
const featured = (id) => tenant.value?.home?.featured?.includes(id) || false
const available = (product) => product.stock > 0 && (product.pickupEnabled || product.deliveryEnabled)
const retry = () => tenantStore.retryPublicCatalog().catch(() => {})

async function loadProducts() {
  const current = tenant.value
  if (!current) return
  productsLoading.value = true
  productsError.value = ''
  try {
    const result = await api.getPhysicalProducts(current.id)
    if (tenant.value === current) physicalProducts.value = result
  } catch {
    if (tenant.value === current) productsError.value = 'Impossible de charger les produits. Veuillez réessayer.'
  } finally {
    if (tenant.value === current) productsLoading.value = false
  }
}
async function loadGiftOffer() {
  const current = tenant.value
  if (!current || current.giftVouchersEnabled === false) return
  giftLoading.value = true
  giftError.value = ''
  try {
    const result = await api.getGiftCardOffer()
    if (tenant.value === current) giftOffer.value = result
  } catch {
    if (tenant.value === current) giftError.value = 'Impossible de charger les cartes cadeaux. Veuillez réessayer.'
  } finally {
    if (tenant.value === current) giftLoading.value = false
  }
}
watch(tenant, () => {
  physicalProducts.value = []
  giftOffer.value = null
  productsError.value = giftError.value = ''
  productsLoading.value = giftLoading.value = false
  loadProducts()
  loadGiftOffer()
}, { immediate: true })
</script>

<template>
  <Spinner v-if="loading" label="Chargement de la boutique…" />
  <CatalogError v-else-if="error" message="Impossible de charger la boutique de cet établissement. Veuillez réessayer." @retry="retry" />
  <div v-else class="section py-10 sm:py-14">
    <h1 class="font-display text-3xl font-bold text-slate-900 sm:text-4xl">Boutique</h1>
    <p class="mt-2 text-slate-500">Découvrez les offres de notre établissement.</p>
    <nav aria-label="Catégories de la boutique" class="my-8 flex flex-wrap gap-3">
      <RouterLink v-for="item in categories" :key="item.id"
        :to="{ name: 'shop', query: { ...route.query, categorie: item.id } }"
        :aria-current="category === item.id ? 'page' : undefined"
        :class="category === item.id ? 'btn-primary' : 'btn-outline'">
        {{ item.label }}
      </RouterLink>
    </nav>

    <EmptyState v-if="catalogEmpty" icon="🛍️" title="La boutique est actuellement vide" message="Aucune offre n’est disponible pour le moment. Revenez prochainement ou contactez l’établissement." />
    <section v-else-if="category === 'prestations'" aria-labelledby="services-title">
      <h2 id="services-title" class="mb-6 font-display text-2xl font-bold">Prestations</h2>
      <div v-if="services.length" class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
        <JumpTypeCard v-for="jt in services" :key="jt.id" :jump-type="jt" :featured="featured(jt.id)"
          :options="options" :currency="tenant.currency" :slug="slug" />
      </div>
      <EmptyState v-else icon="📋" title="Aucune prestation disponible" message="Aucune prestation n’est proposée pour le moment. Vous pouvez consulter les autres catégories." />
    </section>

    <section v-else-if="category === 'produits'" aria-labelledby="products-title">
      <h2 id="products-title" class="mb-6 font-display text-2xl font-bold">Produits</h2>
      <Spinner v-if="productsLoading" label="Chargement des produits…" />
      <CatalogError v-else-if="productsError" :message="productsError" @retry="loadProducts" />
      <div v-else-if="products.length" class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
        <article v-for="product in products" :key="product.id" class="card flex flex-col overflow-hidden">
          <div class="relative h-44 bg-slate-100">
            <img v-if="product.image" :src="product.image" :alt="product.name" loading="lazy" class="h-full w-full object-cover" />
            <div v-else class="flex h-full items-center justify-center text-3xl text-slate-300" aria-hidden="true">✦</div>
            <span v-if="featured(product.id)" class="absolute left-3 top-3 chip bg-accent-500 text-white">À la une</span>
          </div>
          <div class="flex flex-1 flex-col p-5">
            <h3 class="font-display text-lg font-bold text-slate-900">{{ product.name }}</h3>
            <p class="mt-1 flex-1 text-sm text-slate-500">{{ product.summary }}</p>
            <p class="mt-4 text-xl font-bold text-brand-700">{{ formatMoney(product.price, tenant.currency) }}</p>
            <p class="mt-2 text-sm" :class="available(product) ? 'text-slate-500' : 'text-rose-700'">
              {{ product.stock <= 0 ? 'Rupture de stock' : !available(product) ? 'Actuellement indisponible' : `${product.stock} en stock` }}
            </p>
            <p v-if="product.pickupEnabled" class="mt-1 text-sm text-slate-500">Retrait à l’établissement</p>
            <p v-if="product.deliveryEnabled" class="mt-1 text-sm text-slate-500">Livraison : {{ product.deliveryFee ? formatMoney(product.deliveryFee, tenant.currency) : 'offerte' }}</p>
            <RouterLink v-if="available(product)" :to="{ name: 'physical-products', query: { produit: product.id } }" class="btn-outline mt-4" :aria-label="`Choisir ${product.name}`">Choisir ce produit</RouterLink>
            <button v-else disabled class="btn-outline mt-4">Indisponible</button>
          </div>
        </article>
      </div>
      <EmptyState v-else icon="🛍️" title="Aucun produit disponible" message="Aucun produit n’est proposé pour le moment. Vous pouvez consulter les autres catégories." />
    </section>

    <section v-else aria-labelledby="gifts-title">
      <h2 id="gifts-title" class="mb-6 font-display text-2xl font-bold">Cartes cadeaux</h2>
      <Spinner v-if="giftLoading" label="Chargement des cartes cadeaux…" />
      <CatalogError v-else-if="giftError" :message="giftError" @retry="loadGiftOffer" />
      <article v-else-if="giftOffer?.enabled" class="card max-w-xl p-6">
        <h3 class="font-display text-xl font-bold">Offrir une carte cadeau</h3>
        <p class="mt-3 text-slate-600">Un crédit utilisable dans la boutique de {{ giftOffer.shopName }}. Le destinataire choisira ses prestations ou produits, sans date à fixer aujourd’hui.</p>
        <p class="mt-4 text-xl font-bold text-brand-700">À partir de {{ formatMoney(giftOffer.minimum / 100, giftOffer.currency) }}</p>
        <p class="mt-2 text-sm text-slate-500">Montant libre jusqu’à {{ formatMoney(giftOffer.maximum / 100, giftOffer.currency) }}. Validité : {{ giftOffer.validityMonths }} mois après paiement.</p>
        <RouterLink v-if="giftOffer.paymentMethods?.length" :to="{ name: 'gift-card-purchase' }" class="btn-primary mt-5">Choisir le montant</RouterLink>
        <p v-else class="mt-4 text-sm text-slate-600">L’achat est momentanément indisponible. Contactez l’établissement pour offrir une carte cadeau.</p>
      </article>
    </section>
  </div>
</template>
