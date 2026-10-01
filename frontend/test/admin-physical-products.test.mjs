import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import vm from 'node:vm'

const source = await readFile(new URL('../src/views/admin/AdminPhysicalProducts.vue', import.meta.url), 'utf8')
const script = source.split('<script setup>')[1].split('</script>')[0].replace(/^import .*$/gm, '')
const product = { id: 'product_sac', name: 'Sac', summary: 'Coton', description: 'Description complète', price: 12, stock: 7, onHand: 10, image: '/sac.jpg', pickupEnabled: true, deliveryEnabled: false, deliveryFee: 2 }
function setup(overrides = {}) {
  const calls = []
  const api = {
    getProductTaxCategory: async () => '/tax/standard',
    getPhysicalProducts: async () => [product],
    updatePhysicalProduct: async (...args) => calls.push(['update', ...args]),
    createPhysicalProduct: async (...args) => { calls.push(['create', ...args]); return { code: 'product_new' } },
    uploadPhysicalProductImage: async (...args) => calls.push(['image', ...args]),
    ...overrides,
  }
  const context = vm.createContext({ api, ref: value => ({ value }), onMounted: () => {}, onBeforeUnmount: () => {}, useAdminStore: () => ({ tenantId: 'centre' }), URL })
  vm.runInContext(`${script}\nglobalThis.view = { edit, save, resetForm, form, editingCode, error, imageFile, createdCode };`, context)
  return { ...context.view, calls }
}

test('modifier préremplit TVA et stock total et met à jour le même produit avec sa photo', async () => {
  const view = setup()
  await view.edit(product)
  assert.equal(view.form.value.stock, 10)
  assert.equal(view.form.value.taxCategory, '/tax/standard')
  assert.equal(view.form.value.image, '/sac.jpg')
  view.form.value.name = 'Sac bleu'
  view.form.value.price = 15
  view.imageFile.value = 'new-image'
  await view.save()
  assert.equal(view.calls[0][0], 'update')
  assert.equal(view.calls[0][2], product.id)
  assert.equal(view.calls[0][3].name, 'Sac bleu')
  assert.equal(view.calls[0][3].description, product.description)
  assert.equal(view.calls[1][0], 'image')
  assert.equal(view.calls[1][2], product.id)
  assert.equal(view.editingCode.value, null)
  assert.equal(view.form.value.name, '')
})

test('annuler abandonne les modifications sans requête de sauvegarde', async () => {
  const view = setup()
  await view.edit(product)
  view.form.value.name = 'Modification'
  view.resetForm()
  assert.equal(view.editingCode.value, null)
  assert.equal(view.form.value.name, '')
  assert.equal(product.name, 'Sac')
  assert.equal(view.calls.length, 0)
})

test('un échec de photo conserve le produit en modification pour réessayer', async () => {
  let fail = true
  const view = setup({ uploadPhysicalProductImage: async () => { if (fail) throw new Error('Photo indisponible') } })
  await view.edit(product)
  view.imageFile.value = 'new-image'
  await view.save()
  assert.equal(view.error.value, 'Photo indisponible')
  assert.equal(view.editingCode.value, product.id)
  fail = false
  await view.save()
  assert.equal(view.editingCode.value, null)
  assert.ok(view.calls.every(call => call[0] === 'update'))
})

test('les modes de remise sont obligatoires et la création reste disponible', async () => {
  const view = setup()
  await view.edit(product)
  view.form.value.pickupEnabled = false
  await view.save()
  assert.equal(view.calls.length, 0)
  assert.match(view.error.value, /mode de remise/)
  view.resetForm()
  view.form.value.name = 'Nouveau'
  await view.save()
  assert.equal(view.calls[0][0], 'create')
})

test('un échec de chargement de TVA empêche de modifier avec une TVA incorrecte', async () => {
  const view = setup({ getProductTaxCategory: async () => { throw new Error('TVA indisponible') } })
  await view.edit(product)
  assert.equal(view.editingCode.value, null)
  assert.equal(view.error.value, 'TVA indisponible')
  assert.equal(view.calls.length, 0)
})

test('le catalogue conserve séparément le stock total et le stock disponible', async () => {
  const apiSource = await readFile(new URL('../src/api/httpApi.js', import.meta.url), 'utf8')
  const mapping = apiSource.slice(apiSource.indexOf('function mapPhysicalProduct('), apiSource.indexOf('\nasync function buildAdminSession'))
  const context = vm.createContext({ imageUrl: () => '' })
  vm.runInContext(`${mapping}\nglobalThis.mapped = mapPhysicalProduct({ code: 'product_sac', defaultVariantData: { onHand: 10, onHold: 3, price: 1200 } }, 'centre');`, context)
  assert.equal(context.mapped.onHand, 10)
  assert.equal(context.mapped.stock, 7)
  assert.equal(context.mapped.price, 12)
})
