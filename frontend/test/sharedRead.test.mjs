import test from 'node:test'
import assert from 'node:assert/strict'
import { shareInFlight } from '../src/api/sharedRead.js'

test('lectures concurrentes partagées sans conserver les prix après résolution', async () => {
  let calls = 0
  let finish
  const read = shareInFlight(() => {
    calls++
    return new Promise((resolve) => { finish = resolve })
  })
  const first = read()
  const second = read()
  await Promise.resolve()
  assert.equal(calls, 1)
  finish({ price: 100 })
  assert.deepEqual(await Promise.all([first, second]), [{ price: 100 }, { price: 100 }])
  const next = read()
  await Promise.resolve()
  assert.equal(calls, 2)
  finish({ price: 200 })
  assert.deepEqual(await next, { price: 200 })
})

test('un échec partagé permet une nouvelle tentative', async () => {
  let calls = 0
  const read = shareInFlight(async () => {
    if (++calls === 1) throw new Error('indisponible')
    return 'ok'
  })
  const results = await Promise.allSettled([read(), read()])
  assert.ok(results.every((result) => result.status === 'rejected'))
  assert.equal(calls, 1)
  assert.equal(await read(), 'ok')
  assert.equal(calls, 2)
})
