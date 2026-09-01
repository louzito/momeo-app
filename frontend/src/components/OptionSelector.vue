<script setup>
import { computed } from 'vue'
import { formatMoney } from '@/utils/format'

// Selecteur d'options. Separe visuellement les options PER_JUMP (rattachees au
// saut) et PER_ORDER (rattachees a la commande). Les options obligatoires sont
// affichees cochees et verrouillees.
const props = defineProps({
  options: { type: Array, default: () => [] },
  selectedIds: { type: Array, default: () => [] },
  currency: { type: String, default: 'USD' },
})
const emit = defineEmits(['toggle'])

const perJump = computed(() => props.options.filter((o) => o.scope === 'PER_JUMP'))
const perOrder = computed(() => props.options.filter((o) => o.scope === 'PER_ORDER'))
const isSelected = (id) => props.selectedIds.includes(id)
</script>

<template>
  <div class="space-y-8">
    <section v-if="perJump.length">
      <h3 class="mb-1 font-display text-base font-bold text-slate-900">Options de la prestation</h3>
      <p class="mb-4 text-sm text-slate-500">Ajoutez les complements qui vous conviennent.</p>
      <div class="grid gap-3 sm:grid-cols-2">
        <label
          v-for="opt in perJump"
          :key="opt.id"
          class="flex cursor-pointer items-start gap-3 rounded-xl border p-4 transition"
          :class="isSelected(opt.id) ? 'border-brand-500 bg-brand-50' : 'border-slate-200 bg-white hover:border-brand-300'"
        >
          <input
            type="checkbox"
            class="mt-1 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
            :checked="isSelected(opt.id)"
            :disabled="opt.mandatory"
            @change="emit('toggle', opt)"
          />
          <span class="flex-1">
            <span class="flex items-center justify-between">
              <span class="font-semibold text-slate-800">{{ opt.name }}</span>
              <span class="font-semibold text-brand-700">+{{ formatMoney(opt.price, currency) }}</span>
            </span>
            <span class="mt-0.5 block text-sm text-slate-500">{{ opt.description }}</span>
            <span v-if="opt.mandatory" class="mt-1 inline-block text-xs font-medium text-slate-400">Obligatoire</span>
          </span>
        </label>
      </div>
    </section>

    <section v-if="perOrder.length">
      <h3 class="mb-1 font-display text-base font-bold text-slate-900">Options de commande</h3>
      <p class="mb-4 text-sm text-slate-500">Ces options s'appliquent a l'ensemble de la commande.</p>
      <div class="grid gap-3 sm:grid-cols-2">
        <label
          v-for="opt in perOrder"
          :key="opt.id"
          class="flex cursor-pointer items-start gap-3 rounded-xl border p-4 transition"
          :class="isSelected(opt.id) ? 'border-brand-500 bg-brand-50' : 'border-slate-200 bg-white hover:border-brand-300'"
        >
          <input
            type="checkbox"
            class="mt-1 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
            :checked="isSelected(opt.id)"
            :disabled="opt.mandatory"
            @change="emit('toggle', opt)"
          />
          <span class="flex-1">
            <span class="flex items-center justify-between">
              <span class="font-semibold text-slate-800">{{ opt.name }}</span>
              <span class="font-semibold text-brand-700">+{{ formatMoney(opt.price, currency) }}</span>
            </span>
            <span class="mt-0.5 block text-sm text-slate-500">{{ opt.description }}</span>
            <span v-if="opt.mandatory" class="mt-1 inline-block text-xs font-medium text-slate-400">Obligatoire</span>
          </span>
        </label>
      </div>
    </section>
  </div>
</template>
