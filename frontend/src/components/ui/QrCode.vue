<script setup>
import { computed } from 'vue'

// QR code FACTICE : motif deterministe genere a partir d'une chaine. Non
// scannable — purement visuel, pour la maquette. Le vrai QR sera genere par le
// backend / une lib dediee au branchement.
const props = defineProps({
  value: { type: String, required: true },
  size: { type: Number, default: 160 },
})

const N = 25 // modules par cote

function hash(str) {
  let h = 2166136261
  for (let i = 0; i < str.length; i++) {
    h ^= str.charCodeAt(i)
    h = Math.imul(h, 16777619)
  }
  return h >>> 0
}

// Motif "finder" (grands carres) dans 3 coins, comme un vrai QR.
function isFinder(r, c) {
  const inBox = (br, bc) => r >= br && r < br + 7 && c >= bc && c < bc + 7
  return inBox(0, 0) || inBox(0, N - 7) || inBox(N - 7, 0)
}
function finderOn(r, c) {
  const local = (br, bc) => {
    const lr = r - br
    const lc = c - bc
    if (lr === 0 || lr === 6 || lc === 0 || lc === 6) return true // bord
    if (lr >= 2 && lr <= 4 && lc >= 2 && lc <= 4) return true // coeur
    return false
  }
  if (r < 7 && c < 7) return local(0, 0)
  if (r < 7 && c >= N - 7) return local(0, N - 7)
  if (r >= N - 7 && c < 7) return local(N - 7, 0)
  return false
}

const modules = computed(() => {
  const base = hash(props.value)
  const cells = []
  for (let r = 0; r < N; r++) {
    for (let c = 0; c < N; c++) {
      if (isFinder(r, c)) {
        if (finderOn(r, c)) cells.push([r, c])
        continue
      }
      // zone de separation autour des finders : laissee vide
      const nearFinder =
        (r < 8 && c < 8) || (r < 8 && c >= N - 8) || (r >= N - 8 && c < 8)
      if (nearFinder) continue
      const on = ((hash(`${base}:${r}:${c}`) >>> (r % 5)) & 1) === 1
      if (on) cells.push([r, c])
    }
  }
  return cells
})

const cell = computed(() => props.size / N)
</script>

<template>
  <svg
    :width="size"
    :height="size"
    :viewBox="`0 0 ${size} ${size}`"
    class="rounded-lg bg-white"
    role="img"
    aria-label="QR code factice"
  >
    <rect :width="size" :height="size" fill="white" />
    <rect
      v-for="([r, c], i) in modules"
      :key="i"
      :x="c * cell"
      :y="r * cell"
      :width="cell"
      :height="cell"
      fill="#0f172a"
    />
  </svg>
</template>
