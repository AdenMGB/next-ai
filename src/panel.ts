import { createApp } from 'vue'
import AiPanel from './components/AiPanel.vue'

function mountPanel() {
	if (document.getElementById('whiteboardai-panel-root')) {
		return
	}

	const root = document.createElement('div')
	root.id = 'whiteboardai-panel-root'
	document.body.appendChild(root)
	createApp(AiPanel).mount(root)
}

if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', mountPanel)
} else {
	mountPanel()
}
