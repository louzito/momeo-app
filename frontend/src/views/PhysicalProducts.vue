<script setup>
import { computed, onMounted, ref } from 'vue'
import api from '@/api'
import { useTenantContext } from '@/composables/useTenantContext'
import { formatMoney } from '@/utils/format'
import Spinner from '@/components/ui/Spinner.vue'

const { tenant } = useTenantContext()
const products = ref([])
const quantities = ref({})
const loading = ref(true)
const processing = ref(false)
const error = ref('')
const result = ref(null)
const mode = ref('pickup')
const customer = ref({ firstName: '', lastName: '', email: '', street: '', postcode: '', city: '', countryCode: 'FR' })

onMounted(async () => {
  try { products.value = await api.getPhysicalProducts(tenant.value.id) }
  catch (e) { error.value = e?.message || 'Impossible de charger les produits.' }
  finally { loading.value = false }
})

const items = computed(() => products.value.filter((p) => (quantities.value[p.id] || 0) > 0)
  .map((p) => ({ ...p, quantity: quantities.value[p.id] })))
const supportsPickup = computed(() => items.value.length > 0 && items.value.every((p) => p.pickupEnabled))
const supportsDelivery = computed(() => items.value.length > 0 && items.value.every((p) => p.deliveryEnabled))
const deliveryFee = computed(() => mode.value === 'delivery' ? Math.max(0, ...items.value.map((p) => p.deliveryFee)) : 0)
const total = computed(() => items.value.reduce((sum, p) => sum + p.price * p.quantity, 0) + deliveryFee.value)

function add(product) {
  const current = quantities.value[product.id] || 0
  if (current < product.stock) quantities.value[product.id] = current + 1
}
function remove(product) { quantities.value[product.id] = Math.max(0, (quantities.value[product.id] || 0) - 1) }

async function checkout() {
  error.value = ''
  if (!items.value.length) return
  if ((mode.value === 'pickup' && !supportsPickup.value) || (mode.value === 'delivery' && !supportsDelivery.value)) {
    error.value = 'Ce mode de remise n’est pas disponible pour tous les articles.'; return
  }
  if (!customer.value.firstName || !customer.value.lastName || !customer.value.email ||
      (mode.value === 'delivery' && (!customer.value.street || !customer.value.postcode || !customer.value.city))) {
    error.value = 'Renseignez vos coordonnées et l’adresse de livraison.'; return
  }
  processing.value = true
  try {
    result.value = await api.createPhysicalOrder({
      items: items.value.map((p) => ({ id: p.id, quantity: p.quantity })), mode: mode.value,
      email: customer.value.email, address: customer.value, paymentMethod: 'bank_transfer',
    })
    quantities.value = {}
  } catch (e) { error.value = e?.message || 'La commande n’a pas pu être enregistrée.' }
  finally { processing.value = false }
}
</script>

<template>
  <Spinner v-if="loading" label="Chargement des produits…" />
  <div v-else class="section py-12">
    <h1 class="font-display text-3xl font-bold text-slate-900">Produits</h1>
    <p class="mt-2 text-slate-500">Articles physiques disponibles au retrait ou à la livraison.</p>

    <div v-if="result" class="mt-6 rounded-2xl border border-emerald-200 bg-emerald-50 p-5 text-emerald-800">
      Commande <strong>{{ result.number }}</strong> enregistrée. Statut : en attente de préparation.
    </div>
    <div class="mt-8 grid gap-8 lg:grid-cols-[1fr_360px]">
      <div class="grid gap-5 sm:grid-cols-2">
        <article v-for="product in products" :key="product.id" class="card overflow-hidden">
          <img v-if="product.image" :src="product.image" :alt="product.name" class="h-40 w-full object-cover" />
          <div class="p-4">
            <h2 class="font-semibold text-slate-900">{{ product.name }}</h2>
            <p class="mt-1 text-sm text-slate-500">{{ product.summary }}</p>
            <p class="mt-3 font-bold text-brand-700">{{ formatMoney(product.price, tenant.currency) }}</p>
            <p class="mt-1 text-xs" :class="product.stock ? 'text-slate-500' : 'text-rose-600'">{{ product.stock ? `${product.stock} en stock` : 'Rupture de stock' }}</p>
            <div class="mt-3 flex items-center gap-3">
              <button class="btn-outline px-3 py-1" :disabled="!quantities[product.id]" @click="remove(product)">−</button>
              <span>{{ quantities[product.id] || 0 }}</span>
              <button class="btn-primary px-3 py-1" :disabled="(quantities[product.id] || 0) >= product.stock" @click="add(product)">+</button>
            </div>
          </div>
        </article>
      </div>

      <aside class="card h-fit p-5">
        <h2 class="font-display text-xl font-bold">Votre panier</h2>
        <p v-if="!items.length" class="mt-3 text-sm text-slate-400">Votre panier est vide.</p>
        <div v-for="item in items" :key="item.id" class="mt-3 flex justify-between text-sm"><span>{{ item.name }} × {{ item.quantity }}</span><span>{{ formatMoney(item.price * item.quantity, tenant.currency) }}</span></div>
        <template v-if="items.length">
          <div class="mt-5 grid grid-cols-2 gap-2">
            <button class="btn-outline" :disabled="!supportsPickup" :class="mode === 'pickup' ? 'border-brand-600 bg-brand-50' : ''" @click="mode = 'pickup'">Retrait au centre</button>
            <button class="btn-outline" :disabled="!supportsDelivery" :class="mode === 'delivery' ? 'border-brand-600 bg-brand-50' : ''" @click="mode = 'delivery'">Livraison</button>
          </div>
          <div class="mt-4 grid gap-2 sm:grid-cols-2">
            <input v-model.trim="customer.firstName" class="input" placeholder="Prénom" />
            <input v-model.trim="customer.lastName" class="input" placeholder="Nom" />
            <input v-model.trim="customer.email" type="email" class="input sm:col-span-2" placeholder="E-mail" />
            <template v-if="mode === 'delivery'">
              <input v-model.trim="customer.street" class="input sm:col-span-2" placeholder="Adresse" />
              <input v-model.trim="customer.postcode" class="input" placeholder="Code postal" />
              <input v-model.trim="customer.city" class="input" placeholder="Ville" />
            </template>
          </div>
          <div v-if="deliveryFee" class="mt-3 flex justify-between text-sm"><span>Livraison</span><span>{{ formatMoney(deliveryFee, tenant.currency) }}</span></div>
          <div class="mt-4 flex justify-between border-t pt-4 font-bold"><span>Total</span><span>{{ formatMoney(total, tenant.currency) }}</span></div>
          <div v-if="error" class="mt-3 text-sm text-rose-600">{{ error }}</div>
          <button class="btn-primary mt-4 w-full" :disabled="processing" @click="checkout">{{ processing ? 'Enregistrement…' : 'Commander' }}</button>
        </template>
      </aside>
    </div>
  </div>
</template>
