import test from 'node:test'
import assert from 'node:assert/strict'
import { siteThemeStyle, normalizeShopColors } from '../src/utils/siteTheme.js'

test('legacy colors survive and unsafe style values cannot reach the renderer', () => {
  assert.deepEqual(normalizeShopColors({ header: '#123456', text: '#abcdef' }), { header: '#123456', textHeader: '#abcdef', footer: '#020617', textFooter: '#abcdef' })
  const unsafe = siteThemeStyle({ colors: { header: 'url(https://example.org)' }, typography: 'url(x)', branding: { brandPalette: 'unknown' } })
  assert.deepEqual(unsafe, siteThemeStyle({}))
})
test('preview theme is isolated and resetting a tenant restores default palettes', () => {
  const config = { typography: 'classic', branding: { brandPalette: 'violet', accent: 'rose' }, colors: { header: '#123456' } }
  const original = structuredClone(config)
  const draft = siteThemeStyle(config)
  assert.equal(draft['--site-font-heading'], 'Georgia, serif')
  assert.equal(draft['--sb-header-bg'], '#123456')
  assert.notEqual(draft['--brand-600'], siteThemeStyle({})['--brand-600'])
  assert.deepEqual(config, original)
})
