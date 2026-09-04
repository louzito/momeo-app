<script setup>
import { onMounted, ref } from 'vue'
import api from '@/api'
import { useAdminStore } from '@/stores/admin'
import { formatMoney } from '@/utils/format'

const admin = useAdminStore()
const products = ref([])
const saving = ref(false)
const error = ref('')
const form = ref({ name: '', price: 0, stock: 0, summary: '', pickupEnabled: true, deliveryEnabled: false, deliveryFee: 0 })
async function load() { products.value = await api.getPhysicalProducts(admin.tenantId) }
onMounted(() => load().catch((e) => { error.value = e.message }))
async function save() {
  if (!form.value.pickupEnabled && !form.value.deliveryEnabled) { error.value = 'Activez au moins un mode de remise.'; return }
  saving.value = true; error.value = ''
  try { await api.createPhysicalProduct(admin.tenantId, form.value); form.value = { name: '', price: 0, stock: 0, summary: '', pickupEnabled: true, deliveryEnabled: false, deliveryFee: 0 }; await load() }
  catch (e) { error.value = e.message }
  finally { saving.value = false }
}
async function remove(product) { await api.deletePhysicalProduct(admin.tenantId, product.id); await load() }
</script>

<template>
  <div>
    <h1 class="font-display text-2xl font-bold">Produits physiques</h1>
    <p class="mt-1 text-slate-500">Catalogue, stock et modes de remise, séparés des prestations et options.</p>
    <form class="card mt-6 grid gap-3 p-5 md:grid-cols-3" @submit.prevent="save">
      <input v-model.trim="form.name" required class="input" placeholder="Nom du produit" />
      <input v-model.number="form.price" required min="0" step="0.01" type="number" class="input" placeholder="Prix" />
      <input v-model.number="form.stock" required min="0" type="number" class="input" placeholder="Stock" />
      <input v-model.trim="form.summary" class="input md:col-span-3" placeholder="Description courte" />
      <label class="flex items-center gap-2"><input v-model="form.pickupEnabled" type="checkbox" /> Retrait au centre</label>
      <label class="flex items-center gap-2"><input v-model="form.deliveryEnabled" type="checkbox" /> Livraison</label>
      <input v-if="form.deliveryEnabled" v-model.number="form.deliveryFee" min="0" step="0.01" type="number" class="input" placeholder="Frais de livraison" />
      <p v-if="error" class="text-sm text-rose-600 md:col-span-3">{{ error }}</p>
      <button class="btn-primary md:col-span-3" :disabled="saving">{{ saving ? 'Enregistrement…' : 'Ajouter le produit' }}</button>
    </form>
    <div class="mt-6 space-y-3">
      <div v-for="product in products" :key="product.id" class="card flex items-center justify-between p-4">
        <div><strong>{{ product.name }}</strong><p class="text-sm text-slate-500">Stock : {{ product.stock }} · {{ product.pickupEnabled ? 'retrait' : '' }} {{ product.deliveryEnabled ? 'livraison' : '' }}</p></div>
        <div class="flex items-center gap-3"><span>{{ formatMoney(product.price, admin.currency) }}</span><button class="btn-ghost text-rose-600" @click="remove(product)">Supprimer</button></div>
      </div>
    </div>
  </div>
</template>
