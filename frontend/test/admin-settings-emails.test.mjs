import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { legalDocument, legalPlainText } from '../src/utils/legalContent.js'

const source = await readFile(new URL('../src/views/admin/AdminSettings.vue', import.meta.url), 'utf8')
function settings(api = {}) {
  return new Function('api', 'legalDocument', 'legalPlainText', `
    const ref = value => ({ value });
    const cfg = ref({ emails: { booking_confirmation: { subject: 'Bonjour', intro: 'Texte existant', signature: '' } } });
    const lastSaved = ref(JSON.stringify(cfg.value));
    ${source.slice(source.indexOf('const emailEditors ='), source.indexOf('const jumps ='))}
    return { cfg, emailEditors, activeEmailFields, emailDocument, updateEmail, emailEdited, insertEmailVariable, testEmail, emailTestMessages, testingEmail };
  `)(api, legalDocument, legalPlainText)
}

test('les anciens textes restent éditables et le document enrichi conserve le texte brut', () => {
  const page = settings()
  assert.equal(page.emailEdited('booking_confirmation'), false)
  const doc = page.emailDocument('booking_confirmation', 'intro')
  doc.content[0].content[0].marks = [{ type: 'bold' }]
  page.updateEmail('booking_confirmation', 'intro', doc)
  assert.equal(page.cfg.value.emails.booking_confirmation.intro, 'Texte existant')
  assert.equal(page.emailEdited('booking_confirmation'), true)
})

test('le clic sur une variable respecte le champ et la sélection de l’objet', () => {
  const page = settings()
  let inserted
  page.emailEditors.set('booking_confirmation:intro', { insertVariable: value => { inserted = value } })
  page.insertEmailVariable('booking_confirmation', '%prenom%')
  assert.equal(inserted, '%prenom%')
  const input = { value: 'Bonjour', selectionStart: 0, selectionEnd: 7, focus() {}, setSelectionRange(start, end) { assert.equal(start, 8); assert.equal(end, 8) } }
  page.activeEmailFields.value.booking_confirmation = { field: 'subject', input }
  page.insertEmailVariable('booking_confirmation', '%prenom%')
  assert.equal(page.cfg.value.emails.booking_confirmation.subject, '%prenom%')
})

test('le test transmet les modifications courantes sans destinataire fourni par le navigateur', async () => {
  const page = settings({ testEmailTemplate: async (type, template) => {
    assert.equal(type, 'booking_confirmation')
    assert.equal(template.subject, 'Brouillon')
    assert.equal(template.recipient, undefined)
    return { recipient: 'admin@example.test' }
  } })
  page.cfg.value.emails.booking_confirmation.subject = 'Brouillon'
  await page.testEmail('booking_confirmation')
  assert.match(page.emailTestMessages.value.booking_confirmation, /admin@example.test/)
  assert.equal(page.testingEmail.value, '')
})
