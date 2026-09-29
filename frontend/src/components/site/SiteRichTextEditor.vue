<script setup>
import { watch } from 'vue'
import { EditorContent, useEditor } from '@tiptap/vue-3'
import StarterKit from '@tiptap/starter-kit'
const props = defineProps({ modelValue: Object, disabled: Boolean })
const emit = defineEmits(['update:modelValue'])
function clean(node) {
  const value = { type: node.type }
  if (node.type === 'heading') value.attrs = { level: node.attrs.level }
  if (node.text !== undefined) value.text = node.text
  if (node.content) value.content = node.content.map(clean)
  if (node.marks) value.marks = node.marks.map(mark => mark.type === 'link' ? { type: 'link', attrs: { href: mark.attrs.href } } : { type: mark.type })
  return value
}
const editor = useEditor({
  content: props.modelValue,
  editable: !props.disabled,
  extensions: [StarterKit.configure({ heading: { levels: [2, 3] }, blockquote: false, code: false, codeBlock: false, hardBreak: false, horizontalRule: false, strike: false, underline: false, trailingNode: false, link: { openOnClick: false, autolink: false, linkOnPaste: false, protocols: ['http', 'https'], isAllowedUri: url => /^https?:\/\//i.test(url) && !/[\s<>\\]/.test(url) } })],
  editorProps: { attributes: { class: 'site-rich-text min-h-32 rounded-lg border bg-white p-3', 'aria-label': 'Texte de la section', role: 'textbox', 'aria-multiline': 'true' }, handleKeyDown: (_view, event) => event.key === 'Tab' ? false : undefined },
  onUpdate: ({ editor }) => emit('update:modelValue', clean(editor.getJSON())),
})
watch(() => props.disabled, disabled => editor.value?.setEditable(!disabled))
watch(() => props.modelValue, value => { if (editor.value && JSON.stringify(clean(editor.value.getJSON())) !== JSON.stringify(value)) editor.value.commands.setContent(value, { emitUpdate: false }) }, { deep: true })
function link() {
  const href = window.prompt('Adresse du lien (https://…)', editor.value.getAttributes('link').href || '')
  if (href === null) return
  if (!href) editor.value.chain().focus().unsetLink().run()
  else if (/^https?:\/\//i.test(href) && !/[\s<>\\]/.test(href)) editor.value.chain().focus().setLink({ href }).run()
}
</script>
<template>
  <div v-if="editor" class="space-y-2">
    <div class="flex flex-wrap gap-2" role="group" aria-label="Mise en forme du texte">
      <button type="button" class="btn-outline" :disabled="disabled" @click="editor.chain().focus().setParagraph().run()">Paragraphe</button>
      <button v-for="level in [2, 3]" :key="level" type="button" class="btn-outline" :disabled="disabled" :aria-pressed="editor.isActive('heading', { level })" @click="editor.chain().focus().toggleHeading({ level }).run()">Titre {{ level }}</button>
      <button type="button" class="btn-outline" :disabled="disabled" :aria-pressed="editor.isActive('bold')" @click="editor.chain().focus().toggleBold().run()">Gras</button>
      <button type="button" class="btn-outline" :disabled="disabled" @click="editor.chain().focus().toggleBulletList().run()">Liste</button>
      <button type="button" class="btn-outline" :disabled="disabled" @click="editor.chain().focus().toggleOrderedList().run()">Liste numérotée</button>
      <button type="button" class="btn-outline" :disabled="disabled" @click="link">Lien</button>
    </div>
    <EditorContent :editor="editor" />
  </div>
</template>
