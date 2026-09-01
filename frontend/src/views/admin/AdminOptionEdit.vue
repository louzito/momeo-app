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
const loading = ref(true)
const saving = ref(false)
const jumps = ref([])

const form = ref({
  name: '',
  description: '',
  price: 0,
  scope: 'PER_JUMP',
  mandatory: false,
  maxQuantity: 1,
  linkedJumpTypeIds: [],
})

onMounted(async () => {
  jumps.value = await api.getJumpTypes(admin.tenantId)
  if (!isNew.value) {
    const all = await api.getOptions(admin.tenantId)
    const o = all.find((x) => x.id === route.params.id)
    if (o) form.value = { ...form.value, ...o, linkedJumpTypeIds: [...(o.linkedJumpTypeIds || [])] }
  }
  loading.value = false
})

function toggleLink(id) {
  const i = form.value.linkedJumpTypeIds.indexOf(id)
  if (i >= 0) form.value.linkedJumpTypeIds.splice(i, 1)
  else form.value.linkedJumpTypeIds.push(id)
}

async function save() {
  saving.value = true
  try {
    if (isNew.value) await api.createOption(admin.tenantId, form.value)
    else await api.updateOption(admin.tenantId, route.params.id, form.value)
    router.push({ name: 'admin-options' })
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="mx-auto max-w-2xl">
    <RouterLink :to="{ name: 'admin-options' }" class="text-sm text-slate-400 hover:text-brand-600">← Retour aux options</RouterLink>
    <h1 class="mt-2 font-display text-2xl font-bold text-slate-900">{{ isNew ? 'Nouvelle option' : 'Modifier l\'option' }}</h1>

    <Spinner v-if="loading" />

    <form v-else @submit.prevent="save" class="mt-6 space-y-6">
      <section class="card space-y-4 p-6">
        <div>
          <label class="label">Nom</label>
          <input v-model="form.name" class="input" placeholder="Massage du cuir chevelu" required />
        </div>
        <div>
          <label class="label">Description</label>
          <input v-model="form.description" class="input" placeholder="Un complément relaxant de 15 minutes." />
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
          <div>
            <label class="label">Prix ({{ admin.currency }})</label>
            <input v-model.number="form.price" type="number" min="0" class="input" />
          </div>
          <div>
            <label class="label">Portée</label>
            <select v-model="form.scope" class="input">
              <option value="PER_JUMP">Liée à une prestation</option>
              <option value="PER_ORDER">Liée à la réservation</option>
            </select>
          </div>
        </div>
        <label class="flex items-center gap-2">
          <input v-model="form.mandatory" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-brand-600" />
          <span class="text-sm text-slate-700">Option obligatoire (pré-cochée, non décochable)</span>
        </label>
      </section>

      <!-- Liaison aux prestations (stockage legacy PER_JUMP) -->
      <section v-if="form.scope === 'PER_JUMP'" class="card p-6">
        <h2 class="mb-1 font-semibold text-slate-800">Prestations liées</h2>
        <p class="mb-4 text-sm text-slate-500">Sélectionnez les prestations concernées. Sans sélection, l’option sera proposée partout.</p>
        <div class="grid gap-2 sm:grid-cols-2">
          <label
            v-for="jt in jumps"
            :key="jt.id"
            class="flex cursor-pointer items-center gap-3 rounded-xl border p-3 transition"
            :class="form.linkedJumpTypeIds.includes(jt.id) ? 'border-brand-500 bg-brand-50' : 'border-slate-200 hover:border-brand-300'"
          >
            <input
              type="checkbox"
              class="h-4 w-4 rounded border-slate-300 text-brand-600"
              :checked="form.linkedJumpTypeIds.includes(jt.id)"
              @change="toggleLink(jt.id)"
            />
            <span class="text-sm font-medium text-slate-700">{{ jt.name }}</span>
          </label>
        </div>
      </section>

      <div class="flex items-center justify-end gap-3">
        <RouterLink :to="{ name: 'admin-options' }" class="btn-ghost">Annuler</RouterLink>
        <button class="btn-primary px-8" :disabled="saving">{{ saving ? 'Enregistrement…' : 'Enregistrer' }}</button>
      </div>
    </form>
  </div>
</template>
