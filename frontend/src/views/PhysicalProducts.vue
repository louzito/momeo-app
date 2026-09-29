<script setup>
import { ref, watch } from 'vue'
import api from '@/api'
import { useRoute } from 'vue-router'
import { useTenantContext } from '@/composables/useTenantContext'
import { useCartStore } from '@/stores/cart'
import { formatMoney } from '@/utils/format'
const { tenant } = useTenantContext(), cart = useCartStore(), route = useRoute()
const products = ref([]), loading = ref(true), error = ref(''), notice = ref('')
async function load() {
  if (!tenant.value) return
  loading.value = true; error.value = ''
  try { products.value = await api.getPhysicalProducts(tenant.value.id) } catch { error.value = 'Impossible de charger les produits. Réessayez.' } finally { loading.value = false }
}
watch(tenant, load, { immediate: true })
function add(product) { cart.addProduct(tenant.value.id, product); notice.value = `${product.name} ajouté au panier.` }
</script>
<template>
  <main class="section space-y-5 py-10">
    <h1 class="text-3xl font-bold">Produits</h1><p>Ajoutez vos produits au panier commun avec vos prestations et cartes cadeaux.</p>
    <RouterLink :to="{ name: 'cart' }" class="btn-primary">Voir mon panier</RouterLink>
    <p v-if="loading" role="status">Chargement des produits…</p><p v-if="notice" role="status">{{ notice }}</p>
    <p v-if="error" role="alert">{{ error }} <button class="btn-outline" @click="load">Réessayer</button></p>
    <p v-if="!loading && !error && !products.length">Aucun produit disponible.</p>
    <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
      <article v-for="product in products" :key="product.id" class="card space-y-3 p-5" :class="route.query.produit === product.id ? 'border-brand-500' : ''">
        <img v-if="product.image" :src="product.image" :alt="product.name" class="h-40 w-full rounded object-cover" />
        <h2 class="text-xl font-semibold">{{ product.name }}</h2><p>{{ product.summary }}</p><p>{{ formatMoney(product.price, tenant?.currency) }}</p>
        <p>{{ product.stock > 0 ? `${product.stock} en stock` : 'Rupture de stock' }}</p>
        <p>{{ [product.pickupEnabled ? 'Retrait au centre' : '', product.deliveryEnabled ? 'Livraison' : ''].filter(Boolean).join(' · ') }}</p>
        <button class="btn-primary" :disabled="!product.stock || !product.pickupEnabled && !product.deliveryEnabled || !!cart.checkoutPayload && !cart.lastResult || (cart.products.find(p => p.id === product.id)?.quantity || 0) >= Math.min(99, product.stock)" @click="add(product)">Ajouter au panier</button>
      </article>
    </div>
  </main>
</template>
