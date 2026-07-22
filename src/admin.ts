import { createApp } from 'vue'
import AdminSettings from './components/AdminSettings.vue'

const el = document.getElementById('whiteboardai-admin')
if (el) {
	createApp(AdminSettings).mount(el)
}
