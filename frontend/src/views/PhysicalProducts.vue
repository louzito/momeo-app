<script setup>
import { computed, watch, ref } from 'vue'
import api from '@/api'
import { useRouter } from 'vue-router'
import { useTenantContext } from '@/composables/useTenantContext'
import { formatMoney } from '@/utils/format'
import Spinner from '@/components/ui/Spinner.vue'
import CatalogError from '@/components/ui/CatalogError.vue'
import EmptyState from '@/components/ui/EmptyState.vue'

const { tenant } = useTenantContext()
const router = useRouter()
const methods = ref([])
const paymentMethod = ref('')
const products = ref([])
const quantities = ref({})
const loading = ref(true)
const processing = ref(false)
const error = ref('')
const catalogError = ref('')
const result = ref(null)
const mode = ref('pickup')
const customer = ref({ firstName: '', lastName: '', email: '', street: '', postcode: '', city: '', countryCode: 'FR' })

async function loadProducts() {
  if (!tenant.value) return
  loading.value = true
  catalogError.value = ''
  try {
    const [catalog, available] = await Promise.all([api.getPhysicalProducts(tenant.value.id), api.getCheckoutPaymentMethods()])
    products.value = catalog
    methods.value = available.filter((m) => ['stripe_web_elements', 'bank_transfer'].includes(m.code))
    paymentMethod.value = methods.value.find((m) => m.code === 'stripe_web_elements')?.code || methods.value[0]?.code || ''
  }
  catch { catalogError.value = 'Impossible de charger les produits. Veuillez réessayer.' }
  finally { loading.value = false }
}
watch(tenant, loadProducts, { immediate: true })

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
  if (processing.value || (!result.value && !items.value.length) || !paymentMethod.value) return
  if ((mode.value === 'pickup' && !supportsPickup.value) || (mode.value === 'delivery' && !supportsDelivery.value)) {
    error.value = 'Ce mode de remise n’est pas disponible pour tous les articles.'; return
  }
  if (!customer.value.firstName || !customer.value.lastName || !customer.value.email ||
      !customer.value.street || !customer.value.postcode || !customer.value.city) {
    error.value = 'Renseignez vos coordonnées et votre adresse de facturation.'; return
  }
  processing.value = true
  try {
    result.value ||= await api.createPhysicalOrder({
      items: items.value.map((p) => ({ id: p.id, quantity: p.quantity })), mode: mode.value,
      email: customer.value.email, address: customer.value, paymentMethod: paymentMethod.value,
    })
    const destination = { name: 'checkout-shop-confirmation', params: { orderToken: result.value.orderToken } }
    if (result.value.paymentMethod === 'stripe_web_elements') {
      const confirmation = new URL(router.resolve(destination).href, window.location.origin)
      const stripe = await api.createStripeCheckoutSession({
        orderToken: result.value.orderToken, paymentId: result.value.paymentId,
        successUrl: `${confirmation}?payment=success`, cancelUrl: `${confirmation}?payment=cancelled`,
      })
      window.location.assign(stripe.url)
    } else await router.push(destination)
  } catch (e) { error.value = e?.message || 'La commande n’a pas pu être enregistrée.' }
  finally { processing.value = false }
}
</script>

<template>
  <Spinner v-if="loading" label="Chargement des produits…" />
  <CatalogError v-else-if="catalogError" :message="catalogError" @retry="loadProducts" />
  <div v-else class="section py-12">
    <h1 class="font-display text-3xl font-bold text-slate-900">Produits</h1>
    <p class="mt-2 text-slate-500">Articles physiques disponibles au retrait ou à la livraison.</p>

    <div v-if="result" class="mt-6 rounded-2xl border border-emerald-200 bg-emerald-50 p-5 text-emerald-800">
      Commande <strong>{{ result.number }}</strong> enregistrée, en attente de paiement. La préparation commencera après encaissement.
    </div>
    <EmptyState v-if="!products.length" class="mt-8" icon="🛍️" title="Aucun produit disponible" message="La boutique de cet établissement est actuellement vide." />
    <div v-else class="mt-8 grid gap-8 lg:grid-cols-[1fr_360px]">
      <div class="grid gap-5 sm:grid-cols-2">
        <article v-for="product in products" :key="product.id" class="card overflow-hidden">
          <img v-if="product.image" :src="product.image" :alt="product.name" class="h-40 w-full object-cover" />
          <div class="p-4">
            <h2 class="font-semibold text-slate-900">{{ product.name }}</h2>
            <p class="mt-1 text-sm text-slate-500">{{ product.summary }}</p>
            <p class="mt-3 font-bold text-brand-700">{{ formatMoney(product.price, tenant.currency) }}</p>
            <p class="mt-1 text-xs" :class="product.stock ? 'text-slate-500' : 'text-rose-600'">{{ product.stock ? `${product.stock} en stock` : 'Rupture de stock' }}</p>
            <div class="mt-3 flex items-center gap-3">
              <button class="btn-outline px-3 py-1" :disabled="processing || !!result || !quantities[product.id]" @click="remove(product)">−</button>
              <span>{{ quantities[product.id] || 0 }}</span>
              <button class="btn-primary px-3 py-1" :disabled="processing || !!result || (quantities[product.id] || 0) >= product.stock" @click="add(product)">+</button>
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
            <button class="btn-outline" :disabled="processing || !!result || !supportsPickup" :class="mode === 'pickup' ? 'border-brand-600 bg-brand-50' : ''" @click="mode = 'pickup'">Retrait au centre</button>
            <button class="btn-outline" :disabled="processing || !!result || !supportsDelivery" :class="mode === 'delivery' ? 'border-brand-600 bg-brand-50' : ''" @click="mode = 'delivery'">Livraison</button>
          </div>
          <div class="mt-4 grid gap-2 sm:grid-cols-2">
            <input :disabled="processing || !!result" v-model.trim="customer.firstName" class="input" placeholder="Prénom" />
            <input :disabled="processing || !!result" v-model.trim="customer.lastName" class="input" placeholder="Nom" />
            <input :disabled="processing || !!result" v-model.trim="customer.email" type="email" class="input sm:col-span-2" placeholder="E-mail" />
            <template v-if="items.length">
              <input :disabled="processing || !!result" v-model.trim="customer.street" class="input sm:col-span-2" :placeholder="mode === 'delivery' ? 'Adresse de facturation et de livraison' : 'Adresse de facturation'" />
              <input :disabled="processing || !!result" v-model.trim="customer.postcode" class="input" placeholder="Code postal" />
              <input :disabled="processing || !!result" v-model.trim="customer.city" class="input" placeholder="Ville" />
            </template>
          </div>
          <div v-if="deliveryFee" class="mt-3 flex justify-between text-sm"><span>Livraison</span><span>{{ formatMoney(deliveryFee, tenant.currency) }}</span></div>
          <div class="mt-4 flex justify-between border-t pt-4 font-bold"><span>Total</span><span>{{ formatMoney(total, tenant.currency) }}</span></div>
          <div v-if="error" class="mt-3 text-sm text-rose-600">{{ error }}</div>
          <label class="mt-4 block text-sm font-semibold" for="product-payment">Moyen de paiement</label>
          <select id="product-payment" v-model="paymentMethod" class="input mt-2 w-full" :disabled="processing || !!result">
            <option v-for="method in methods" :key="method.code" :value="method.code">{{ method.name }}</option>
          </select>
          <p v-if="!methods.length" role="alert" class="mt-3 text-sm text-rose-700">Aucun moyen de paiement n’est disponible. Contactez l’établissement.</p>
          <p v-else-if="paymentMethod === 'bank_transfer'" class="mt-3 text-sm text-slate-600">La référence et les instructions de virement seront affichées après la commande. La préparation commencera après réception du paiement.</p>
          <button class="btn-primary mt-4 w-full" :disabled="processing || !paymentMethod" @click="checkout">{{ processing ? 'Enregistrement…' : 'Commander' }}</button>
        </template>
      </aside>
    </div>
  </div>
</template>
