import assert from 'node:assert/strict'
import { readdir, readFile } from 'node:fs/promises'
import test from 'node:test'

test('le bundle de production ne contient aucune fixture ni API mock', async () => {
  const assets = new URL('../../dist/assets/', import.meta.url)
  const files = (await readdir(assets)).filter((name) => name.endsWith('.js'))
  assert.ok(files.length > 0, 'le build doit produire au moins un asset JavaScript')
  const bundle = (await Promise.all(files.map((name) => readFile(new URL(name, assets), 'utf8')))).join('\n')
  for (const marker of ['client@todatempo.test', 'mockApi', 'paid_demo', 'card_demo']) {
    assert.doesNotMatch(bundle, new RegExp(marker), `marqueur mock trouvé dans le bundle: ${marker}`)
  }
})
