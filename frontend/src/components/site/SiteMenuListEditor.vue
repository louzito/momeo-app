<script setup>
import { nextTick, ref } from 'vue'
import SiteLinkEditor from './SiteLinkEditor.vue'
const props = defineProps({ items: { type: Array, required: true }, pages: Array, child: Boolean, disabled: Boolean })
const emit = defineEmits(['change', 'promote'])
const dragged = ref(null)
let sequence = 0
function add() {
  props.items.push({ _key: `new-${Date.now()}-${sequence++}`, label: '', link: { type: 'route', target: 'services' }, hidden: false, children: [] })
  emit('change')
}
async function move(from, to, button) {
  if (to < 0 || to >= props.items.length || from === to) return
  const item = props.items.splice(from, 1)[0]
  props.items.splice(to, 0, item)
  emit('change')
  await nextTick()
  if (button?.disabled) button.closest('li')?.querySelector('input')?.focus()
  else button?.focus()
}
function drop(index) { if (props.disabled) return; if (dragged.value !== null) move(dragged.value, index); dragged.value = null }
function indent(index) {
  const item = props.items.splice(index, 1)[0]
  props.items[index - 1].children.push(item)
  emit('change')
}
function promote(parentIndex, index) {
  const item = props.items[parentIndex].children.splice(index, 1)[0]
  props.items.splice(parentIndex + 1, 0, item)
  emit('change')
}
</script>
<template>
  <ol class="space-y-3" :aria-label="child ? 'Sous-menu' : 'Entrées du menu'">
    <li v-for="(item, index) in items" :key="item._key" class="rounded-xl border border-slate-200 bg-white p-4" @dragover.prevent @drop.prevent.stop="drop(index)">
      <div class="mb-3 flex flex-wrap gap-2">
        <span :draggable="!disabled" class="cursor-grab p-2" title="Glisser pour déplacer dans cette liste" @dragstart.stop="dragged = index; $event.dataTransfer.setData('text/plain', String(index))" @dragend="dragged = null" aria-hidden="true">⠿</span>
        <button type="button" class="btn-ghost" :disabled="index === 0" :aria-label="`Monter ${item.label || 'le lien'}`" @click="move(index, index - 1, $event.currentTarget)">Monter</button>
        <button type="button" class="btn-ghost" :disabled="index === items.length - 1" :aria-label="`Descendre ${item.label || 'le lien'}`" @click="move(index, index + 1, $event.currentTarget)">Descendre</button>
        <button v-if="!child && index > 0 && !item.children.length && items[index - 1].children.length < 30" type="button" class="btn-ghost" @click="indent(index)">Placer sous le lien précédent</button>
        <button v-if="child" type="button" class="btn-ghost" @click="emit('promote', index)">Sortir du sous-menu</button>
        <button type="button" class="btn-ghost text-rose-700" :aria-label="`Supprimer ${item.label || 'le lien'}`" @click="items.splice(index, 1); emit('change')">Supprimer</button>
      </div>
      <label class="block">Libellé<input v-model="item.label" class="input mt-1" required maxlength="100" @input="emit('change')" /></label>
      <SiteLinkEditor v-model="item.link" class="mt-3" :pages="pages" @update:model-value="emit('change')" />
      <label class="my-3 flex items-center gap-2"><input v-model="item.hidden" type="checkbox" @change="emit('change')" />Masquer ce lien{{ !child ? ' et son sous-menu' : '' }}</label>
      <SiteMenuListEditor v-if="!child" :items="item.children" :pages="pages" :disabled="disabled" child class="mt-3 pl-3" @change="emit('change')" @promote="promote(index, $event)" />
    </li>
  </ol>
  <button v-if="items.length < 30" type="button" class="btn-outline mt-3" @click="add">{{ child ? 'Ajouter un lien enfant' : 'Ajouter un lien' }}</button>
</template>
