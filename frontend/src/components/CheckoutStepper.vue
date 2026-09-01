<script setup>
import { computed } from 'vue'

// Indicateur d'avancement du tunnel. Les etapes different selon le type d'achat.
const props = defineProps({
  current: { type: String, required: true }, // cle de l'etape courante
  kind: { type: String, default: 'direct' }, // 'direct' | 'gift'
})

const STEPS = {
  direct: [
    { key: 'options', label: 'Options' },
    { key: 'schedule', label: 'Creneau' },
    { key: 'details', label: 'Coordonnees' },
    { key: 'summary', label: 'Recap' },
    { key: 'payment', label: 'Paiement' },
  ],
  gift: [
    { key: 'options', label: 'Options' },
    { key: 'gift', label: 'Beneficiaire' },
    { key: 'summary', label: 'Recap' },
    { key: 'payment', label: 'Paiement' },
  ],
}

const steps = computed(() => STEPS[props.kind] || STEPS.direct)
const currentIndex = computed(() => steps.value.findIndex((s) => s.key === props.current))
</script>

<template>
  <nav class="mb-8 overflow-x-auto">
    <ol class="flex min-w-max items-center gap-2">
      <li v-for="(step, i) in steps" :key="step.key" class="flex items-center gap-2">
        <div class="flex items-center gap-2">
          <span
            class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-bold transition"
            :class="
              i < currentIndex
                ? 'bg-brand-600 text-white'
                : i === currentIndex
                  ? 'bg-brand-600 text-white ring-4 ring-brand-200'
                  : 'bg-slate-200 text-slate-500'
            "
          >
            <span v-if="i < currentIndex">✓</span>
            <span v-else>{{ i + 1 }}</span>
          </span>
          <span
            class="text-sm font-medium"
            :class="i <= currentIndex ? 'text-slate-800' : 'text-slate-400'"
          >
            {{ step.label }}
          </span>
        </div>
        <span
          v-if="i < steps.length - 1"
          class="h-px w-8 sm:w-12"
          :class="i < currentIndex ? 'bg-brand-500' : 'bg-slate-200'"
        />
      </li>
    </ol>
  </nav>
</template>
