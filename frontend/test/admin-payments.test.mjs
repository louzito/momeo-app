import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import vm from 'node:vm'

const source = await readFile(new URL('../src/views/admin/AdminPayments.vue', import.meta.url), 'utf8')
const script = source.split('<script setup>')[1].split('</script>')[0]
  .replace(/^import .*$/gm, '')
  .replace('import.meta.env.DEV', 'false')

function page(apiBase) {
  const context = vm.createContext({
    ref: (value) => ({ value }), computed: (get) => ({ get value() { return get() } }),
    onMounted: () => {}, useAdminStore: () => ({}),
    API_BASE: apiBase, TENANT_SLUG: 'lou-zito', URL,
    window: { location: { origin: 'https://shop.example.test' } },
    navigator: { clipboard: { writeText: async (text) => { context.copied = text } } },
    setTimeout: () => {},
  })
  vm.runInContext(script + '\n globalThis.page = { stripeWebhookUrl, copyStripeWebhookUrl, missingBankIban, isDev }', context)
  return context
}

test('webhook absolu avec préfixe hébergé, API locale ou domaine externe', () => {
  for (const [base, expected] of [
    ['/todatempo-app/backend/api/v2', 'https://shop.example.test/todatempo-app/backend/api/v2'],
    ['/api/v2/', 'https://shop.example.test/api/v2'],
    ['https://api.example.test/api/v2', 'https://api.example.test/api/v2'],
  ]) {
    assert.equal(page(base).page.stripeWebhookUrl.value, `${expected}/shop/payments/stripe/webhook/lou-zito`)
  }
})

test('Copier transmet l’URL complète au presse-papiers', async () => {
  const context = page('/api/v2')
  await context.page.copyStripeWebhookUrl()
  assert.equal(context.copied, context.page.stripeWebhookUrl.value)
})

test('avertissement virement sans IBAN, y compris pour un moyen déjà actif', () => {
  const { missingBankIban } = page('/api/v2').page
  assert.equal(missingBankIban({ code: 'bank_transfer', enabled: true, instructions: '' }), true)
  assert.equal(missingBankIban({ code: 'bank_transfer', instructions: 'IBAN : FR76 ...' }), true)
  assert.equal(missingBankIban({ code: 'bank_transfer', instructions: 'IBAN : FR76 3000 6000 0112 3456 7890 189\nBIC : AGRIFRPP' }), false)
  assert.equal(missingBankIban({ code: 'cash_on_delivery', instructions: '' }), false)
})
