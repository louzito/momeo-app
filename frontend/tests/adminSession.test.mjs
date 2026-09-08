import test from 'node:test'
import assert from 'node:assert/strict'
import { redirectExpiredAdminSession } from '../src/utils/adminSession.js'

test('expiration : purge les sessions du tenant et redirige sur le website', () => {
  const values = new Map(['todatempo.sylius.jwt.a', 'momeo.sylius.jwt.a', 'todatempo.admin.a', 'momeo.admin.a', 'todatempo.admin.b', 'todatempo.customer.jwt.a'].map((key) => [key, 'session']))
  let target
  redirectExpiredAdminSession({ storage: { removeItem: (key) => values.delete(key) }, location: { replace: (url) => { target = url } }, tenant: 'a', loginUrl: '/todatempo/fr/connexion' })
  assert.equal(target, '/todatempo/fr/connexion')
  assert.deepEqual([...values.keys()], ['todatempo.admin.b', 'todatempo.customer.jwt.a'])
})
test('redirige même quand le stockage est indisponible', () => {
  let redirected = false
  redirectExpiredAdminSession({ storage: { removeItem: () => { throw Error() } }, location: { replace: () => { redirected = true } }, tenant: 'a', loginUrl: '/fr/connexion' })
  assert.equal(redirected, true)
})
