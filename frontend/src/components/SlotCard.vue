<script setup>
import { computed } from 'vue'
import { formatTime } from '@/utils/format'

const props = defineProps({
  slot: { type: Object, required: true },
  selected: { type: Boolean, default: false },
  compatible: { type: Boolean, default: true },
})
const emit = defineEmits(['select'])

const isFull = computed(() => props.slot.remaining <= 0)
const disabled = computed(() => isFull.value || !props.compatible)

function onClick() {
  if (!disabled.value) emit('select', props.slot)
}
</script>

<template>
  <button
    type="button"
    :disabled="disabled"
    @click="onClick"
    class="flex w-full flex-col items-start rounded-xl border px-3 py-2 text-left transition"
    :class="[
      selected
        ? 'border-brand-500 bg-brand-50 ring-2 ring-brand-500/30'
        : 'border-slate-200 bg-white hover:border-brand-300',
      disabled ? 'cursor-not-allowed opacity-50 hover:border-slate-200' : 'hover:shadow-sm',
    ]"
  >
    <span class="text-sm font-semibold text-slate-800">{{ formatTime(slot.start) }}</span>
    <span v-if="isFull" class="text-xs font-medium text-rose-500">Complet</span>
    <span v-else-if="!compatible" class="text-xs text-slate-400">Non compatible</span>
    <span v-else class="text-xs text-emerald-600">{{ slot.remaining }} place{{ slot.remaining > 1 ? 's' : '' }}</span>
    <span class="mt-0.5 truncate text-[11px] text-slate-400">{{ slot.instructor }}</span>
  </button>
</template>
