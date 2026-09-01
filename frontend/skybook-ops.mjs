// =============================================================================
// SkyBook — endpoint OPS dev-only (chantier multi-centres)
// -----------------------------------------------------------------------------
// Expose POST/GET /__skybook_ops sur le serveur de dev Vite pour permettre a la
// session Claude (pilotage via Chrome, localhost UNIQUEMENT) d'executer des
// commandes d'industrialisation (docker / git / node) dans le dossier AFIFLY.
// Securite :
//   - flag OPS_ENABLED (mettre false en fin de chantier, ou supprimer le plugin)
//   - requetes acceptees depuis 127.0.0.1/::1 uniquement
//   - allowlist de prefixes de commandes
// API :
//   POST /__skybook_ops   {cmd, cwd?, timeoutMs?, async?}
//     -> sync  : {code, stdout, stderr, durationMs}
//     -> async : {job: <id>}   (pour les commandes longues type sylius:install)
//   GET  /__skybook_ops?job=<id>
//     -> {status: running|done, ...resultat}
// =============================================================================
import { exec } from 'node:child_process'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const OPS_ENABLED = true
const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const ALLOWED = ['docker', 'git', 'node', 'npm']
const jobs = new Map()
let nextJobId = 1

function isLocal(req) {
  const a = req.socket.remoteAddress || ''
  return a === '127.0.0.1' || a === '::1' || a === '::ffff:127.0.0.1'
}

function runCmd(cmd, cwd, timeoutMs) {
  return new Promise((resolve) => {
    const started = Date.now()
    exec(cmd, { cwd, timeout: timeoutMs, maxBuffer: 64 * 1024 * 1024, windowsHide: true }, (error, stdout, stderr) => {
      resolve({
        code: error ? (typeof error.code === 'number' ? error.code : 1) : 0,
        killed: !!(error && error.killed),
        stdout: String(stdout),
        stderr: String(stderr),
        durationMs: Date.now() - started,
      })
    })
  })
}

function readBody(req) {
  return new Promise((resolve, reject) => {
    let data = ''
    req.on('data', (c) => { data += c })
    req.on('end', () => resolve(data))
    req.on('error', reject)
  })
}

export default function skybookOps() {
  return {
    name: 'skybook-ops',
    configureServer(server) {
      server.middlewares.use('/__skybook_ops', async (req, res) => {
        res.setHeader('Content-Type', 'application/json')
        const deny = (code, msg) => { res.statusCode = code; res.end(JSON.stringify({ error: msg })) }
        if (!OPS_ENABLED) return deny(403, 'ops disabled')
        if (!isLocal(req)) return deny(403, 'localhost only')

        if (req.method === 'GET') {
          const id = new URL(req.url || '/', 'http://localhost').searchParams.get('job')
          if (!id || !jobs.has(Number(id))) return deny(404, 'unknown job')
          return res.end(JSON.stringify(jobs.get(Number(id))))
        }
        if (req.method !== 'POST') return deny(405, 'POST only')

        let payload
        try { payload = JSON.parse(await readBody(req) || '{}') } catch { return deny(400, 'bad json') }
        const cmd = String(payload.cmd || '').trim()
        if (!cmd) return deny(400, 'missing cmd')
        const first = cmd.split(/\s+/)[0].toLowerCase()
        if (!ALLOWED.includes(first)) return deny(403, `command not allowed: ${first}`)

        const cwd = path.resolve(ROOT, String(payload.cwd || '.'))
        if (!cwd.startsWith(ROOT)) return deny(403, 'cwd outside project')
        const timeoutMs = Math.min(Number(payload.timeoutMs) || 180000, 30 * 60 * 1000)

        if (payload.async) {
          const id = nextJobId++
          jobs.set(id, { status: 'running', cmd, startedAt: new Date().toISOString() })
          runCmd(cmd, cwd, timeoutMs).then((r) => jobs.set(id, { status: 'done', cmd, ...r }))
          return res.end(JSON.stringify({ job: id }))
        }
        const result = await runCmd(cmd, cwd, timeoutMs)
        res.end(JSON.stringify(result))
      })
    },
  }
}
