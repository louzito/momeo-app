// Keep the historical plain text alongside the structured editor document.
export function legalDocument(page) {
  if (page?.contentRich?.type === 'doc') return page.contentRich
  return {
    type: 'doc',
    content: String(page?.content || '').split(/\r\n|\r|\n/).map(text => ({
      type: 'paragraph',
      ...(text ? { content: [{ type: 'text', text }] } : {}),
    })),
  }
}

export function legalPlainText(document) {
  function text(node) {
    if (node.type === 'text') return node.text || ''
    const separator = ['doc', 'bulletList', 'orderedList', 'listItem'].includes(node.type) ? '\n' : ''
    return (node.content || []).map(text).join(separator)
  }
  return text(document)
}
