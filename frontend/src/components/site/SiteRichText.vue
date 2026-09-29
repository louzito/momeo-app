<script>
import { h } from 'vue'
// Render only the closed document vocabulary. Text is escaped by Vue; no v-html.
export default {
  props: { document: Object },
  setup(props) {
    function render(node) {
      if (node.type === 'text') {
        let value = node.text
        for (const mark of node.marks || []) {
          if (mark.type === 'bold') value = h('strong', {}, [value])
          if (mark.type === 'italic') value = h('em', {}, [value])
          if (mark.type === 'link' && /^https?:\/\//i.test(mark.attrs?.href) && !/[\s<>\\]/.test(mark.attrs.href)) value = h('a', { href: mark.attrs.href, rel: 'noopener noreferrer', class: 'underline' }, [value])
        }
        return value
      }
      const tag = { doc: 'div', paragraph: 'p', bulletList: 'ul', orderedList: 'ol', listItem: 'li', heading: node.attrs?.level === 3 ? 'h3' : 'h2' }[node.type]
      return tag ? h(tag, {}, (node.content || []).map(render)) : null
    }
    return () => h('div', { class: 'site-rich-text' }, [render(props.document || { type: 'doc' })])
  },
}
</script>
<style>
.site-rich-text p { margin: .5rem 0; }
.site-rich-text h2 { font-size: 1.5rem; font-weight: 700; margin: 1rem 0; }
.site-rich-text h3 { font-size: 1.2rem; font-weight: 600; margin: .75rem 0; }
.site-rich-text ul { list-style: disc; padding-left: 1.5rem; }
.site-rich-text ol { list-style: decimal; padding-left: 1.5rem; }
</style>
