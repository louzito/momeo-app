import { createApp } from 'vue'
import { createPinia } from 'pinia'

import App from './App.vue'
import router from './router'
import './assets/main.css'
// Include editorial section styles in the initial HTML stylesheet too.
import './components/site/SitePageContent.vue'

const app = createApp(App)

app.use(createPinia())
app.use(router)

app.mount('#app')
