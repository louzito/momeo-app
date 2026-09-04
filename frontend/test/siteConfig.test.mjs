import test from 'node:test'
import assert from 'node:assert/strict'
import { contrastRatio, normalizeSiteConfig, publishSiteConfigDocument, readSiteConfigDocument, validateSiteConfig } from '../src/utils/siteConfig.js'

test('migre une configuration historique vers un brouillon et une publication versionnés', () => {
  const doc = readSiteConfigDocument({ name: 'Centre', home: { sections: ['gift'] } })
  assert.equal(doc.schemaVersion, 1)
  assert.equal(doc.published.name, 'Centre')
  assert.deepEqual(doc.draft.home.sections, ['gift', 'highlights', 'catalog'])
})

test('publie atomiquement un instantané et incrémente sa révision', () => {
  const current = readSiteConfigDocument({ name: 'Avant' })
  const next = publishSiteConfigDocument(current, { name: 'Après' }, '2026-09-04T12:00:00.000Z')
  assert.equal(next.revision, 1)
  assert.equal(next.published.name, 'Après')
  next.draft.name = 'Mutation'
  assert.equal(next.published.name, 'Après')
})

test('valide les contrastes, liens sociaux et textes légaux', () => {
  assert.equal(contrastRatio('#000000', '#ffffff'), 21)
  const cfg = normalizeSiteConfig({ name: 'Centre', colors: { header: '#ffffff', textHeader: '#eeeeee' }, socials: { instagram: 'http://example.test' }, legal: { terms: { enabled: true, content: '' } } })
  const errors = validateSiteConfig(cfg)
  assert.ok(errors.some((e) => /contraste du header/.test(e)))
  assert.ok(errors.some((e) => /HTTPS/.test(e)))
  assert.ok(errors.some((e) => /activé mais vide/.test(e)))
})
