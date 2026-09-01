<script setup>
import { ref, onMounted, computed } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import { useAdminStore } from '@/stores/admin'
import api from '@/api'
import Spinner from '@/components/ui/Spinner.vue'

const route = useRoute()
const router = useRouter()
const admin = useAdminStore()

const isNew = computed(() => !route.params.id)
const isLegacyService = computed(() => String(route.params.id || '').startsWith('jump_'))
const loading = ref(!isNew.value)
const saving = ref(false)
const error = ref('')

// --- Image du produit (upload reel vers Sylius) -------------------------------
const imageFile = ref(null) // fichier choisi, uploade a l'enregistrement
const imagePreview = ref('') // apercu local (URL.createObjectURL)

function onImagePicked(e) {
  const f = e.target.files?.[0]
  if (!f) return
  if (!f.type.startsWith('image/')) { error.value = 'Choisissez un fichier image (JPG, PNG, WebP...).'; return }
  if (f.size > 8 * 1024 * 1024) { error.value = 'Image trop lourde (8 Mo max).'; return }
  error.value = ''
  imageFile.value = f
  if (imagePreview.value) URL.revokeObjectURL(imagePreview.value)
  imagePreview.value = URL.createObjectURL(f)
}

const form = ref({
  name: '',
  summary: '',
  description: '',
  basePrice: 0,
  durationMin: 60,
  capacityPerSlot: 1,
  requirementsText: '',
  image: '',
  popular: false,
})

onMounted(async () => {
  if (!isNew.value) {
    const jt = await api.getJumpType(admin.tenantId, route.params.id)
    form.value = {
      ...form.value,
      ...jt,
      requirementsText: (jt.requirements || []).map((item) => item.label).join('\n'),
    }
    loading.value = false
  }
})

async function save() {
  saving.value = true
  error.value = ''
  try {
    const requirements = form.value.requirementsText
      .split(/\r?\n/)
      .map((label) => label.trim())
      .filter(Boolean)
      .map((label, index) => ({ key: `requirement_${index + 1}`, label }))
    const payload = { ...form.value, requirements }
    let code = route.params.id
    if (isNew.value) {
      const created = await api.createJumpType(admin.tenantId, payload)
      code = created.id
    } else {
      await api.updateJumpType(admin.tenantId, code, payload)
    }
    // Upload de l'image apres le produit (remplace l'ancienne image "main").
    if (imageFile.value && api.uploadJumpImage) {
      await api.uploadJumpImage(admin.tenantId, code, imageFile.value)
    }
    router.push({ name: 'admin-products' })
  } catch (e) {
    error.value = e?.message || 'Echec de l\'enregistrement.'
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="mx-auto max-w-3xl">
    <RouterLink :to="{ name: 'admin-products' }" class="text-sm text-slate-400 hover:text-brand-600">← Retour aux prestations</RouterLink>
    <h1 class="mt-2 font-display text-2xl font-bold text-slate-900">
      {{ isNew ? 'Nouvelle prestation' : 'Modifier la prestation' }}
    </h1>

    <div v-if="isLegacyService" class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
      Cette prestation provient de l'ancien catalogue. Ses controles de securite restent actifs tant qu'elle n'est pas migree vers le format Momeo.
    </div>

    <Spinner v-if="loading" />

    <form v-else @submit.prevent="save" class="mt-6 space-y-6">
      <!-- Infos produit -->
      <section class="card p-6">
        <h2 class="mb-4 font-semibold text-slate-800">Informations</h2>
        <div class="space-y-4">
          <div>
            <label class="label">Nom de la prestation</label>
            <input v-model="form.name" class="input" placeholder="Soin visage éclat" required />
          </div>
          <div>
            <label class="label">Résumé court</label>
            <input v-model="form.summary" class="input" placeholder="Un soin personnalisé pour raviver l’éclat de la peau." />
          </div>
          <div>
            <label class="label">Description</label>
            <textarea v-model="form.description" rows="3" class="input" />
          </div>
          <div>
            <label class="label">Photo de la prestation</label>
            <div class="mt-1 flex items-center gap-4">
              <img v-if="imagePreview || form.image"
                :src="imagePreview || form.image"
                alt="Aperçu de la prestation"
                class="h-20 w-28 rounded-xl border border-slate-200 object-cover"
              />
              <div v-else class="flex h-20 w-28 items-center justify-center rounded-xl bg-amber-50 text-2xl text-amber-700">✦</div>
              <div>
                <label class="btn-outline cursor-pointer px-4 py-2 text-sm">
                  {{ imageFile ? 'Changer de fichier' : 'Choisir une image…' }}
                  <input type="file" accept="image/*" class="hidden" @change="onImagePicked" />
                </label>
                <p class="mt-1 text-xs text-slate-400">
                  {{ imageFile ? `${imageFile.name} — envoyee a l'enregistrement.` : 'JPG / PNG / WebP, 8 Mo max. Remplace l\'image actuelle.' }}
                </p>
              </div>
            </div>
          </div>
          <div class="grid gap-4 sm:grid-cols-3">
            <div>
              <label class="label">Prix de base ({{ admin.currency }})</label>
              <input v-model.number="form.basePrice" type="number" min="0" class="input" />
            </div>
            <div>
              <label class="label">Durée (min)</label>
              <input v-model.number="form.durationMin" type="number" min="5" step="5" class="input" />
            </div>
            <div>
              <label class="label">Capacité / créneau</label>
              <input v-model.number="form.capacityPerSlot" type="number" min="1" class="input" />
            </div>
          </div>
          <div>
            <label class="label">Conditions a confirmer par le client</label>
            <textarea
              v-model="form.requirementsText"
              rows="4"
              class="input"
              placeholder="Ex. Je confirme ne pas etre allergique aux produits utilises.&#10;Ex. Je viendrai sans maquillage."
              :disabled="isLegacyService"
            />
            <p class="mt-1 text-xs text-slate-400">Une condition par ligne. Elles seront affichees avant la confirmation du rendez-vous.</p>
          </div>
          <label class="flex items-center gap-2">
            <input v-model="form.popular" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-brand-600" />
            <span class="text-sm text-slate-700">Mettre en avant (badge « Populaire »)</span>
          </label>
        </div>
      </section>

      <div v-if="error" class="rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">⚠️ {{ error }}</div>

      <div class="flex items-center justify-end gap-3">
        <RouterLink :to="{ name: 'admin-products' }" class="btn-ghost">Annuler</RouterLink>
        <button class="btn-primary px-8" :disabled="saving">{{ saving ? 'Enregistrement…' : 'Enregistrer' }}</button>
      </div>
    </form>
  </div>
</template>
