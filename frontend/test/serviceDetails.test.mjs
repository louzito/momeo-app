import test from 'node:test'
import assert from 'node:assert/strict'
import { applicableServiceOptions, serviceStartingPrice, formatDuration, serviceCharacteristics } from '../src/utils/serviceDetails.js'

test('durées lisibles sans valeur inventée', () => {
  for (const [minutes, expected] of [[45, '45 min'], [60, '1 h'], [90, '1 h 30 min'], [120, '2 h'], [undefined, ''], [0, '']]) {
    assert.equal(formatDuration(minutes), expected)
  }
})

test('soin moderne : durée configurée, aucune contrainte physique héritée', () => {
  assert.deepEqual(serviceCharacteristics({ legacyEligibility: false, configuredDetails: { durationMin: 90, heightMinCm: 140 } }), [
    { label: 'Durée', value: '1 h 30 min' },
  ])
})

test('contraintes historiques explicites conservées sans défauts techniques', () => {
  assert.deepEqual(serviceCharacteristics({ legacyEligibility: true, durationMin: 20, eligibility: { heightMinCm: 140 } }), [])
  assert.deepEqual(serviceCharacteristics({ legacyEligibility: true, configuredDetails: {
    ageMin: 18, weightMaxKg: 100, heightMinCm: 140, medicalCertificateRequired: true, waiverRequired: true, bmiMax: null,
  } }), [
    { label: 'Âge minimum', value: '18 ans' },
    { label: 'Poids maximum', value: '100 kg' },
    { label: 'Taille minimum', value: '140 cm' },
    { label: 'Certificat médical', value: 'Requis' },
    { label: 'Décharge de responsabilité', value: 'À signer' },
  ])
})

test('prix de départ avec tous les frais obligatoires applicables, sans options facultatives', () => {
  const service = { id: 'soin', basePrice: 49.90 }
  const options = [
    { id: 'order', scope: 'PER_ORDER', mandatory: true, price: 2.10 },
    { id: 'service', scope: 'PER_JUMP', linkedJumpTypeIds: ['soin'], mandatory: true, price: 5 },
    { id: 'other', scope: 'PER_JUMP', linkedJumpTypeIds: ['autre'], mandatory: true, price: 10 },
    { id: 'optional', scope: 'PER_JUMP', price: 20 },
  ]
  assert.equal(serviceStartingPrice(service, options), 57)
  assert.deepEqual(applicableServiceOptions(service, options).map((option) => option.id), ['order', 'service', 'optional'])
})
