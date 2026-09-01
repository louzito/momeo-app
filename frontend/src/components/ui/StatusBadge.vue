<script setup>
import { computed } from 'vue'
import { statusMeta } from '@/utils/status'

const props = defineProps({
  status: { type: String, required: true },
})
const meta = computed(() => statusMeta(props.status))
</script>

<template>
  <span class="chip" :class="meta.classes">
    <span class="h-1.5 w-1.5 rounded-full" :class="meta.dot" />
    {{ meta.label }}
  </span>
</template>
