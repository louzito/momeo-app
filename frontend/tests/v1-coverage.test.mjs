import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

test('la V1 ne référence aucun fournisseur mock dans son point d’entrée API', async () => {
  const [entry, http] = await Promise.all([
    readFile(new URL('../src/api/index.js', import.meta.url), 'utf8'),
    readFile(new URL('../src/api/httpApi.js', import.meta.url), 'utf8'),
  ])
  assert.doesNotMatch(entry + http, /from ['"]@\/mocks|\.\.\.mockApi/)
  assert.match(entry, /const api = httpApi/)
})

test('les surfaces V1 critiques possèdent un scénario navigateur', async () => {
  const suite = await readFile(new URL('./e2e/v1.spec.js', import.meta.url), 'utf8')
  for (const expected of [
    'résout le tenant', 'catalogue vide', 'erreur catalogue',
    'tunnel client', 'administration', 'disponibilité', 'états erreur',
  ]) assert.match(suite, new RegExp(expected, 'i'), `scénario absent: ${expected}`)
})
