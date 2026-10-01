<script setup>
import { onMounted, onBeforeUnmount, ref } from 'vue'
import api from '@/api'
import { useAdminStore } from '@/stores/admin'
import { formatMoney } from '@/utils/format'

const admin = useAdminStore()
const products = ref([])
const saving = ref(false)
const error = ref('')
const taxCategories = ref([])
const imageFile = ref(null)
const imagePreview = ref('')
const imageInput = ref(null)
const createdCode = ref(null)
const editingCode = ref(null)
const loadingEdit = ref(false)
function clearImage() {
  if (imagePreview.value) URL.revokeObjectURL(imagePreview.value)
  imagePreview.value = ''; imageFile.value = null
  if (imageInput.value) imageInput.value.value = ''
}
onBeforeUnmount(clearImage)
function onImagePicked(event) {
  const file = event.target.files?.[0]
  clearImage()
  if (!file) return
  if (!file.type.startsWith('image/')) { error.value = 'Choisissez un fichier image.'; return }
  if (file.size > 8 * 1024 * 1024) { error.value = 'Image trop lourde (8 Mo max).'; return }
  error.value = ''; imageFile.value = file; imagePreview.value = URL.createObjectURL(file)
}
const emptyForm = () => ({ taxCategory: null, name: '', price: 0, stock: 0, summary: '', pickupEnabled: true, deliveryEnabled: false, deliveryFee: 0 })
const form = ref(emptyForm())
function resetForm() {
  editingCode.value = null
  createdCode.value = null
  clearImage()
  form.value = emptyForm()
  error.value = ''
}
async function edit(product) {
  if (saving.value || loadingEdit.value || createdCode.value) return
  loadingEdit.value = true; error.value = ''
  try {
    const taxCategory = await api.getProductTaxCategory(product.id)
    clearImage()
    editingCode.value = product.id
    form.value = { ...product, stock: product.onHand ?? product.stock, taxCategory }
  } catch (e) { error.value = e.message }
  finally { loadingEdit.value = false }
}
async function load() { products.value = await api.getPhysicalProducts(admin.tenantId) }
onMounted(async () => {
  try { await load(); taxCategories.value = await api.getTaxCategories() }
  catch (e) { error.value = e.message }
})
async function save() {
  if (saving.value || loadingEdit.value) return
  if (!form.value.pickupEnabled && !form.value.deliveryEnabled) { error.value = 'Activez au moins un mode de remise.'; return }
  saving.value = true; error.value = ''
  try {
    if (editingCode.value) {
      await api.updatePhysicalProduct(admin.tenantId, editingCode.value, form.value)
    } else if (!createdCode.value) {
      const created = await api.createPhysicalProduct(admin.tenantId, form.value)
      createdCode.value = created.code
    }
    if (imageFile.value) await api.uploadPhysicalProductImage(admin.tenantId, editingCode.value || createdCode.value, imageFile.value)
    resetForm()
    await load()
  }
  catch (e) { error.value = e.message }
  finally { saving.value = false }
}
async function remove(product) {
  if (saving.value || loadingEdit.value || createdCode.value) return
  error.value = ''
  try {
    await api.deletePhysicalProduct(admin.tenantId, product.id)
    if (editingCode.value === product.id) resetForm()
    await load()
  } catch (e) { error.value = e.message }
}
</script>

<template>
  <div>
    <h1 class="font-display text-2xl font-bold">Produits physiques</h1>
    <p class="mt-1 text-slate-500">Catalogue, stock et modes de remise, séparés des prestations et options.</p>
    <form class="card mt-6 grid gap-3 p-5 md:grid-cols-3" @submit.prevent="save">
      <h2 class="font-semibold md:col-span-3">{{ editingCode ? 'Modifier le produit' : 'Ajouter un produit' }}</h2>
      <fieldset :disabled="saving || loadingEdit || !!createdCode" class="contents">
        <div><label for="physical-name" class="label">Nom du produit</label><input id="physical-name" v-model.trim="form.name" required class="input" placeholder="Nom du produit" /></div>
        <div><label for="physical-price" class="label">Prix ({{ admin.currency }})</label><input id="physical-price" v-model.number="form.price" required min="0" step="0.01" type="number" class="input" /></div>
        <div><label for="physical-stock" class="label">Stock</label><input id="physical-stock" v-model.number="form.stock" required min="0" step="1" type="number" class="input" /></div>
        <div><label for="physical-tax" class="label">Taux de TVA</label><select id="physical-tax" v-model="form.taxCategory" class="input"><option :value="null">Sans TVA</option><option v-for="category in taxCategories" :key="category.id" :value="category.id">{{ category.name }}</option></select><p v-if="!taxCategories.length" class="mt-1 text-xs text-slate-500">Aucune catégorie de TVA configurée.</p></div>
        <div class="md:col-span-3"><label for="physical-summary" class="label">Description courte</label><input id="physical-summary" v-model.trim="form.summary" class="input" placeholder="Description courte" /></div>
        <label class="flex items-center gap-2"><input v-model="form.pickupEnabled" type="checkbox" /> Retrait au centre</label>
        <label class="flex items-center gap-2"><input v-model="form.deliveryEnabled" type="checkbox" /> Livraison</label>
        <div v-if="form.deliveryEnabled"><label for="physical-delivery" class="label">Frais de livraison ({{ admin.currency }})</label><input id="physical-delivery" v-model.number="form.deliveryFee" required min="0" step="0.01" type="number" class="input" /></div>
      </fieldset>
      <div class="md:col-span-3">
        <label for="physical-image" class="label">Photo du produit</label>
        <input id="physical-image" ref="imageInput" type="file" accept="image/*" :disabled="saving || loadingEdit" @change="onImagePicked" />
        <p class="mt-1 text-xs text-slate-500">Image de 8 Mo maximum, envoyée à l’enregistrement.</p>
        <img v-if="imagePreview || form.image" :src="imagePreview || form.image" alt="Aperçu du produit" class="mt-3 h-24 w-32 rounded-xl object-cover" />
      </div>
      <p v-if="createdCode" class="text-sm text-amber-700 md:col-span-3">Produit créé. Réessayez l’envoi de la photo pour terminer.</p>
      <p v-if="error" class="text-sm text-rose-600 md:col-span-3">{{ error }}</p>
      <button class="btn-primary md:col-span-3" :disabled="saving || loadingEdit">{{ saving ? 'Enregistrement…' : createdCode ? 'Terminer l’enregistrement' : editingCode ? 'Enregistrer les modifications' : 'Ajouter le produit' }}</button>
      <button v-if="editingCode" type="button" class="btn-ghost md:col-span-3" :disabled="saving || loadingEdit" @click="resetForm">Annuler</button>
    </form>
    <div class="mt-6 space-y-3">
      <div v-for="product in products" :key="product.id" class="card flex items-center justify-between p-4">
        <img v-if="product.image" :src="product.image" :alt="product.name" class="mr-3 h-16 w-16 rounded-lg object-cover" /><div><strong>{{ product.name }}</strong><p class="text-sm text-slate-500">Stock : {{ product.stock }} · {{ product.pickupEnabled ? 'retrait' : '' }} {{ product.deliveryEnabled ? 'livraison' : '' }}</p></div>
        <div class="flex items-center gap-3"><span>{{ formatMoney(product.price, admin.currency) }}</span><button class="btn-ghost" :disabled="saving || loadingEdit || !!createdCode" @click="edit(product)">Modifier</button><button class="btn-ghost text-rose-600" :disabled="saving || loadingEdit || !!createdCode" @click="remove(product)">Supprimer</button></div>
      </div>
    </div>
  </div>
</template>
