import test from 'node:test'
import assert from 'node:assert/strict'
import { legalDocument, legalPlainText } from '../src/utils/legalContent.js'

test('existing legal text retains line breaks and literal markup', () => {
  const content = 'Éditeur\r\n\r\n<script>texte</script>\nAdresse'
  const document = legalDocument({ content })
  assert.equal(legalPlainText(document), content.replaceAll('\r\n', '\n'))
  assert.equal(document.content[2].content[0].text, '<script>texte</script>')
})

test('rich legal text preserves formatting and provides plain text for compatibility', () => {
  const contentRich = { type: 'doc', content: [
    { type: 'heading', attrs: { level: 2 }, content: [{ type: 'text', text: 'Article 1' }] },
    { type: 'paragraph', content: [{ type: 'text', text: 'Conditions', marks: [{ type: 'bold' }] }] },
    { type: 'bulletList', content: [{ type: 'listItem', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'Premier point' }] }] }] },
  ] }
  assert.equal(legalDocument({ content: 'ancien texte', contentRich }), contentRich)
  assert.equal(legalPlainText(contentRich), 'Article 1\nConditions\nPremier point')
})

test('empty editor content remains empty for publication validation', () => {
  assert.equal(legalPlainText(legalDocument({ content: '' })), '')
  assert.equal(legalPlainText({ type: 'doc', content: [{ type: 'paragraph' }] }), '')
})
