<script setup>
import { computed } from 'vue'
import SlotCard from './SlotCard.vue'
import { isoDay, formatDate } from '@/utils/format'

// Calendrier reutilisable : regroupe les creneaux par jour, grise les creneaux
// complets et les creneaux non compatibles avec le type de saut choisi.
const props = defineProps({
  slots: { type: Array, default: () => [] },
  selectedSlotId: { type: String, default: null },
  // Si fourni, filtre la compatibilite d'affichage (creneaux incompatibles grises).
  jumpTypeId: { type: String, default: null },
})
const emit = defineEmits(['select'])

const days = computed(() => {
  const groups = {}
  for (const slot of props.slots) {
    const key = isoDay(slot.start)
    ;(groups[key] ||= []).push(slot)
  }
  return Object.entries(groups)
    .sort(([a], [b]) => new Date(a) - new Date(b))
    .map(([key, slots]) => ({
      key,
      date: slots[0].start,
      slots: slots.sort((a, b) => new Date(a.start) - new Date(b.start)),
    }))
})

function isCompatible(slot) {
  if (!props.jumpTypeId) return true
  return slot.compatibleJumpTypeIds.includes(props.jumpTypeId)
}
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-center gap-4 text-xs text-slate-500">
      <span class="flex items-center gap-1.5"><span class="h-3 w-3 rounded border border-slate-200 bg-white" /> Disponible</span>
      <span class="flex items-center gap-1.5"><span class="h-3 w-3 rounded border border-brand-500 bg-brand-50" /> Selectionne</span>
      <span class="flex items-center gap-1.5"><span class="h-3 w-3 rounded border border-slate-200 bg-slate-100 opacity-50" /> Complet / indisponible</span>
    </div>

    <div v-if="days.length" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
      <div v-for="day in days" :key="day.key" class="card p-4">
        <p class="mb-3 text-sm font-semibold capitalize text-slate-800">
          {{ formatDate(day.date, { weekday: true, short: true }) }}
        </p>
        <div class="grid grid-cols-2 gap-2">
          <SlotCard
            v-for="slot in day.slots"
            :key="slot.id"
            :slot="slot"
            :selected="slot.id === selectedSlotId"
            :compatible="isCompatible(slot)"
            @select="emit('select', $event)"
          />
        </div>
      </div>
    </div>

    <div v-else class="rounded-2xl border border-dashed border-slate-300 p-10 text-center text-sm text-slate-500">
      Aucun creneau disponible pour le moment.
    </div>
  </div>
</template>
