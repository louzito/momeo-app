import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'

const view = readFileSync(new URL('../src/views/admin/AdminResources.vue', import.meta.url), 'utf8')
const script = view.match(/<script setup>([\s\S]*?)<\/script>/)[1].replace(/^import .*$/gm, '')
const createForm = (api) => new Function('api', 'ref', 'onMounted', `${script}\nreturn { edit, save, editing, resources, error, saving, loading }`)(api, value => ({ value }), () => {})

test('la création ferme le formulaire et recharge les ressources compatibles', async () => {
  const resource = { code: 'resource_cabine_1', name: 'Cabine 1' }
  const form = createForm({
    async createBookableResource(payload) {
      assert.equal(payload.code, '')
      assert.equal(payload.name, 'Cabine 1')
      return resource
    },
    async getBookableResources() { return [resource] },
  })
  form.edit(null)
  form.editing.value.name = 'Cabine 1'
  await form.save()
  assert.equal(form.editing.value, null)
  assert.deepEqual(form.resources.value, [resource])
  assert.equal(form.error.value, '')
  assert.equal(form.saving.value, false)
})

for (const message of ['La capacité doit être supérieure ou égale à 1.', 'Internal Server Error']) {
  test(`une erreur API reste visible dans le formulaire : ${message}`, async () => {
    const form = createForm({ async createBookableResource() { throw new Error(message) } })
    form.edit(null)
    form.editing.value.name = 'Cabine 1'
    await form.save()
    assert.equal(form.error.value, message)
    assert.equal(form.editing.value.name, 'Cabine 1')
    assert.equal(form.saving.value, false)
    assert.match(view.match(/<form[\s\S]*?<\/form>/)[0], /<div v-if="error" role="alert"[\s\S]*?{{ error }}/)
    form.edit(null)
    assert.equal(form.error.value, '')
  })
}
