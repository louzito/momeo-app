import test from 'node:test'
import assert from 'node:assert/strict'
import { getGoogleFonts, validFontFamily, loadThemeFonts } from '../src/utils/googleFonts.js'

test('catalogue retains Google fonts, sorts and deduplicates safe families', async () => {
  const original = globalThis.fetch
  globalThis.fetch = async () => ({ ok: true, json: async () => [{ family: 'Roboto', type: 'google' }, { family: 'Lora', type: 'google' }, { family: 'Lora', type: 'google' }, { family: 'Local Font', type: 'other' }, { family: 'url(x)', type: 'google' }] })
  try { assert.deepEqual(await getGoogleFonts(), ['Lora', 'Roboto']) }
  finally { globalThis.fetch = original }
})
test('catalogue failure is surfaced and font loader is safe for server rendering', async () => {
  const original = globalThis.fetch
  globalThis.fetch = async () => ({ ok: false })
  try { await assert.rejects(getGoogleFonts(), /indisponible/) }
  finally { globalThis.fetch = original }
  assert.equal(validFontFamily('M PLUS 1'), true)
  assert.equal(validFontFamily('Roboto"; color:red'), false)
  assert.doesNotThrow(() => loadThemeFonts({ heading: 'Lora', body: 'Inter' }))
})
