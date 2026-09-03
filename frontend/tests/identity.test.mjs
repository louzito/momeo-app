import assert from 'node:assert/strict'
import { readFileSync, readdirSync } from 'node:fs'
import { join } from 'node:path'
import test from 'node:test'

const root = new URL('..', import.meta.url).pathname
const vueRoot = join(root, 'src')

function filesBelow(directory) {
  return readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
    const path = join(directory, entry.name)
    return entry.isDirectory() ? filesBelow(path) : [path]
  })
}

function withoutComments(source) {
  return source.replace(/<!--[^]*?-->/g, '').replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '')
}

test('les surfaces utilisateur portent uniquement la marque TodaTempo', () => {
  const surfaces = [join(root, 'index.html'), ...filesBelow(vueRoot).filter((file) => file.endsWith('.vue'))]
  for (const file of surfaces) {
    const source = withoutComments(readFileSync(file, 'utf8'))
    const legacyBrand = source.match(/(?:SkyBook|Mom[eé]o)/i)
    assert.equal(legacyBrand, null, `${file} contient encore la marque historique ${legacyBrand?.[0]}`)
  }
  assert.match(readFileSync(join(root, 'index.html'), 'utf8'), /TodaTempo/)
})

test('aucun vocabulaire de parachutisme ne reste dans les vues', () => {
  const forbidden = /parachut|sauteur|moniteur|carte d['’]embarquement|drop\s?zone|type de saut|altitude de saut|horaires de saut/i
  for (const file of filesBelow(vueRoot).filter((path) => path.endsWith('.vue'))) {
    const source = withoutComments(readFileSync(file, 'utf8'))
    const legacyVocabulary = source.match(forbidden)
    assert.equal(legacyVocabulary, null, `${file} contient encore « ${legacyVocabulary?.[0]} »`)
  }
})
