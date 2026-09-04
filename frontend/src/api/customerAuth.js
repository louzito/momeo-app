import { API_BASE, TENANT_SLUG, tenantHeaders } from './config'

const TOKEN_KEY = `todatempo.customer.jwt.${TENANT_SLUG}`
let token = null

try { token = sessionStorage.getItem(TOKEN_KEY) } catch { token = null }

export function getCustomerToken() { return token }

export function setCustomerToken(value) {
  token = value || null
  try {
    if (token) sessionStorage.setItem(TOKEN_KEY, token)
    else sessionStorage.removeItem(TOKEN_KEY)
  } catch { /* La session reste utilisable jusqu'au rechargement. */ }
}

export async function customerRequest(method, path, body, { auth = true } = {}) {
  const headers = tenantHeaders({ Accept: 'application/ld+json' })
  if (body !== undefined) headers['Content-Type'] = 'application/ld+json'
  if (auth && token) headers.Authorization = `Bearer ${token}`
  const response = await fetch(`${API_BASE}${path}`, { method, headers, ...(body === undefined ? {} : { body: JSON.stringify(body) }) })
  const text = await response.text()
  const data = text ? JSON.parse(text) : null
  if (!response.ok) {
    if (response.status === 401 && auth) setCustomerToken(null)
    const error = new Error(data?.['hydra:description'] || data?.detail || data?.error || `API ${response.status}`)
    error.status = response.status
    throw error
  }
  return data
}

export async function loginCustomer(email, password) {
  const data = await customerRequest('POST', '/shop/customers/token', { email, password }, { auth: false })
  if (!data?.token) throw new Error("La connexion n'a retourné aucun jeton.")
  setCustomerToken(data.token)
  return getCurrentCustomer()
}

export async function getCurrentCustomer() {
  return token ? customerRequest('GET', '/shop/account/profile') : null
}

export function logoutCustomer() { setCustomerToken(null) }
