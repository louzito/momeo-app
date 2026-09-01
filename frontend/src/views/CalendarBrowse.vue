<script setup>
import { ref, watch, computed } from 'vue'
import { useRouter } from 'vue-router'
import { useTenantContext } from '@/composables/useTenantContext'
import { useCartStore } from '@/stores/cart'
import api from '@/api'
import SlotCalendar from '@/components/SlotCalendar.vue'
import Spinner from '@/components/ui/Spinner.vue'

const router = useRouter()
const cart = useCartStore()
const { tenant, jumpTypes, options, slug } = useTenantContext()

const selectedJumpTypeId = ref(null)
const slots = ref([])
const loadingSlots = ref(false)

const currentJumpType = computed(
  () => jumpTypes.value.find((j) => j.id === selectedJumpTypeId.value) || null,
)

watch(
  jumpTypes,
  (list) => {
    if (list.length && !selectedJumpTypeId.value) {
      selectedJumpTypeId.value = list[0].id
    }
  },
  { immediate: true },
)

watch([tenant, selectedJumpTypeId], async () => {
  if (!tenant.value) return
  loadingSlots.value = true
  slots.value = await api.getSlots(tenant.value.id, { jumpTypeId: null })
  loadingSlots.value = false
})

function onSelect(slot) {
  cart.startPurchase(tenant.value.id, currentJumpType.value)
  cart.ensureMandatoryOptions(options.value)
  cart.setKind('direct')
  cart.setSlot(slot)
  router.push({ name: 'checkout-options', params: { slug: slug.value } })
}
</script>

<template>
  <div class="section py-10">
    <h1 class="font-display text-3xl font-bold text-slate-900">Calendrier des creneaux</h1>
    <p class="mt-2 text-slate-500">
      Sélectionnez une prestation pour voir les disponibilités correspondantes. Les créneaux complets ou
      incompatibles sont grises.
    </p>

    <div class="mt-6 flex flex-wrap items-center gap-3">
      <label class="text-sm font-medium text-slate-600">Prestation :</label>
      <select v-model="selectedJumpTypeId" class="input max-w-xs">
        <option v-for="jt in jumpTypes" :key="jt.id" :value="jt.id">{{ jt.name }}</option>
      </select>
    </div>

    <div class="mt-8">
      <Spinner v-if="loadingSlots" label="Chargement des creneaux…" />
      <SlotCalendar
        v-else
        :slots="slots"
        :jump-type-id="selectedJumpTypeId"
        @select="onSelect"
      />
    </div>
  </div>
</template>
