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

test('custom colors generate shades and independent fonts without mutating the configuration', () => {
  const config = { branding: { brandPalette: '#123456', accent: '#abcdef' }, typography: { heading: 'Lora', body: 'Roboto' } }
  const original = structuredClone(config)
  const style = siteThemeStyle(config)
  assert.equal(style['--brand-600'], '18 52 86')
  assert.equal(style['--accent-500'], '171 205 239')
  assert.notEqual(style['--brand-50'], style['--brand-900'])
  assert.match(style['--site-font-heading'], /Lora/)
  assert.match(style['--site-font-body'], /Roboto/)
  assert.deepEqual(config, original)
  assert.doesNotMatch(siteThemeStyle({ typography: { heading: 'url(x)', body: '";color:red' } })['--site-font-heading'], /url/)
})
