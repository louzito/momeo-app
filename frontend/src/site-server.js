import { createSSRApp } from 'vue'
import { renderToString } from 'vue/server-renderer'
import SitePageContent from './components/site/SitePageContent.vue'

// One isolated process/request, JSON over stdin; no HTTP or browser state.
let input = ''
for await (const chunk of process.stdin) {
  input += chunk
  if (input.length > 2_000_000) throw new Error('Page trop volumineuse')
}
const { page, base } = JSON.parse(input)
const app = createSSRApp(SitePageContent, { page })
app.provide('siteServerBase', base)
process.stdout.write(await renderToString(app))
