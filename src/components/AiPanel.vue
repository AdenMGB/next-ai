<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import { loadState } from '@nextcloud/initial-state'
import { showError, showInfo, showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import { compactElements, readWhiteboardScene, resolveWhiteboardFileId } from '../lib/sceneContext'
import { applyClipboardPayload } from '../lib/syntheticPaste'

const status = ref(loadState('whiteboardai', 'panel-status', {
	configured: false,
	model: 'openai/gpt-4o-mini',
	maxElements: 80,
}))

const open = ref(false)
const prompt = ref('')
const loading = ref(false)
const contextCount = ref(0)
const fileId = ref(null)
const visible = ref(false)
const messages = ref([])
const chatEl = ref(null)
let observer = null

const canSend = computed(() => status.value.configured && prompt.value.trim().length > 0 && !loading.value)

function historyKey() {
	return `whiteboardai-chat-${fileId.value || 'none'}`
}

function loadHistory() {
	try {
		const raw = sessionStorage.getItem(historyKey())
		messages.value = raw ? JSON.parse(raw) : []
		if (!Array.isArray(messages.value)) {
			messages.value = []
		}
	} catch {
		messages.value = []
	}
}

function saveHistory() {
	try {
		sessionStorage.setItem(historyKey(), JSON.stringify(messages.value.slice(-40)))
	} catch {
		// ignore quota errors
	}
}

function clearChat() {
	messages.value = []
	saveHistory()
}

async function scrollChat() {
	await nextTick()
	if (chatEl.value) {
		chatEl.value.scrollTop = chatEl.value.scrollHeight
	}
}

async function refreshStatus() {
	try {
		const { data } = await axios.get(generateOcsUrl('/apps/whiteboardai/api/status'))
		const payload = data?.ocs?.data ?? data
		if (payload) {
			status.value = {
				configured: Boolean(payload.configured),
				model: payload.model ?? status.value.model,
				maxElements: payload.maxElements ?? status.value.maxElements,
			}
		}
	} catch {
		// keep initial state
	}
}

async function refreshContext() {
	fileId.value = resolveWhiteboardFileId()
	loadHistory()
	if (!fileId.value) {
		contextCount.value = 0
		return
	}
	const scene = await readWhiteboardScene(fileId.value)
	const elements = Array.isArray(scene?.elements) ? scene.elements : []
	contextCount.value = compactElements(elements, status.value.maxElements).length
}

function historyForApi() {
	return messages.value
		.filter((m) => m.role === 'user' || m.role === 'assistant')
		.map((m) => ({ role: m.role, content: String(m.content || '') }))
		.slice(-20)
}

async function send() {
	if (!canSend.value) {
		return
	}

	const text = prompt.value.trim()
	prompt.value = ''
	messages.value = [...messages.value, { role: 'user', content: text }]
	saveHistory()
	await scrollChat()

	loading.value = true
	try {
		await refreshContext()
		const scene = fileId.value ? await readWhiteboardScene(fileId.value) : null
		const elements = compactElements(
			Array.isArray(scene?.elements) ? scene.elements : [],
			status.value.maxElements,
		)

		// Exclude the message we just added — server gets prior history + current prompt
		const prior = historyForApi().slice(0, -1)

		const { data } = await axios.post(generateOcsUrl('/apps/whiteboardai/api/generate'), {
			prompt: text,
			fileId: fileId.value,
			history: prior,
			scene: { elements },
		})

		const payload = data?.ocs?.data ?? data
		if (payload?.error) {
			throw new Error(payload.error)
		}

		const mode = payload?.mode === 'replace' ? 'replace' : 'append'
		const clipboard = payload?.clipboard
		if (!clipboard?.elements) {
			throw new Error('Empty generation response')
		}

		const assistantText = payload?.assistantMessage
			|| `Ready — ${clipboard.elements.length} shape(s). Paste with Ctrl+V / Cmd+V.`

		const result = await applyClipboardPayload(clipboard, mode)
		let note = assistantText
		if (result === 'copied') {
			showSuccess('Shapes copied — press Ctrl+V / Cmd+V on the board')
			note = `${assistantText}\n\n(Copied to clipboard — paste onto the board.)`
		} else {
			showInfo('Downloaded .excalidraw — drag it onto the board')
			note = `${assistantText}\n\n(Downloaded a .excalidraw file as fallback.)`
		}

		messages.value = [...messages.value, { role: 'assistant', content: note }]
		saveHistory()
		await refreshContext()
		await scrollChat()
	} catch (error) {
		console.error(error)
		const message = error instanceof Error ? error.message : 'Generation failed'
		messages.value = [...messages.value, { role: 'assistant', content: `Error: ${message}` }]
		saveHistory()
		showError(message)
		await scrollChat()
	} finally {
		loading.value = false
	}
}

function onKeydown(event) {
	if (event.key === 'Enter' && !event.shiftKey) {
		event.preventDefault()
		send()
	}
}

function detectWhiteboard() {
	visible.value = Boolean(document.querySelector('.excalidraw, .whiteboard'))
	if (visible.value) {
		refreshContext()
	}
}

watch(fileId, () => {
	loadHistory()
})

onMounted(async () => {
	await refreshStatus()
	detectWhiteboard()
	observer = new MutationObserver(() => detectWhiteboard())
	observer.observe(document.body, { childList: true, subtree: true })
})

onUnmounted(() => {
	observer?.disconnect()
})
</script>

<template>
	<div v-if="visible" :class="$style.root">
		<NcButton
			type="primary"
			:aria-expanded="open ? 'true' : 'false'"
			:class="$style.toggle"
			class="transition-all duration-200 transform hover:scale-105 active:scale-95 focus:outline-none focus:ring-2 focus:ring-offset-2"
			@click="open = !open">
			{{ open ? 'Close AI' : 'Whiteboard AI' }}
		</NcButton>

		<div v-if="open" :class="$style.panel" role="dialog" aria-label="Whiteboard AI chat">
			<header :class="$style.header">
				<strong>Whiteboard AI</strong>
				<span :class="$style.meta">{{ status.model }}</span>
			</header>

			<p v-if="!status.configured" :class="$style.warning">
				Ask an admin to set the shared OpenRouter API key in Administration → Whiteboard AI.
			</p>

			<template v-else>
				<p :class="$style.meta">
					Scene context: {{ contextCount }} element{{ contextCount === 1 ? '' : 's' }}
					<button type="button" :class="$style.link" @click="refreshContext">Refresh</button>
					<button type="button" :class="$style.link" @click="clearChat">Clear chat</button>
				</p>

				<div ref="chatEl" :class="$style.chat" aria-live="polite">
					<p v-if="messages.length === 0" :class="$style.empty">
						Chat to draw or edit the board. After each reply, press Ctrl+V / Cmd+V on the canvas.
					</p>
					<div
						v-for="(msg, idx) in messages"
						:key="idx"
						:class="[ $style.bubble, msg.role === 'user' ? $style.user : $style.assistant ]">
						{{ msg.content }}
					</div>
				</div>

				<NcTextArea
					:model-value="prompt"
					:placeholder="'Message the AI… (Enter to send)'"
					:rows="3"
					label="Message"
					@update:model-value="prompt = $event"
					@keydown="onKeydown" />

				<div :class="$style.actions">
					<NcButton
						type="primary"
						:disabled="!canSend"
						class="transition-all duration-200 transform hover:scale-105 active:scale-95"
						@click="send">
						{{ loading ? 'Thinking…' : 'Send' }}
					</NcButton>
				</div>
			</template>
		</div>
	</div>
</template>

<style module>
.root {
	position: fixed;
	right: 1rem;
	bottom: 1rem;
	z-index: 10050;
	display: flex;
	flex-direction: column;
	align-items: flex-end;
	gap: 0.75rem;
	pointer-events: none;
}

.toggle,
.panel {
	pointer-events: auto;
}

.panel {
	width: min(24rem, calc(100vw - 2rem));
	max-height: min(70vh, 36rem);
	padding: 1rem;
	border-radius: 0.75rem;
	background: var(--color-main-background, #fff);
	color: var(--color-main-text, #222);
	border: 1px solid var(--color-border, #ddd);
	box-shadow: 0 10px 30px rgba(0, 0, 0, 0.18);
	display: flex;
	flex-direction: column;
	gap: 0.75rem;
}

.header {
	display: flex;
	justify-content: space-between;
	align-items: baseline;
	gap: 0.5rem;
}

.meta {
	font-size: 0.8rem;
	opacity: 0.8;
}

.warning {
	margin: 0;
	font-size: 0.9rem;
}

.chat {
	flex: 1;
	min-height: 8rem;
	max-height: 18rem;
	overflow-y: auto;
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
	padding: 0.25rem;
}

.empty {
	margin: 0;
	font-size: 0.85rem;
	opacity: 0.75;
}

.bubble {
	padding: 0.5rem 0.75rem;
	border-radius: 0.75rem;
	font-size: 0.9rem;
	line-height: 1.4;
	white-space: pre-wrap;
	max-width: 95%;
}

.user {
	align-self: flex-end;
	background: var(--color-primary-element, #0082c9);
	color: var(--color-primary-element-text, #fff);
}

.assistant {
	align-self: flex-start;
	background: var(--color-background-dark, #f0f0f0);
	color: var(--color-main-text, #222);
}

.actions {
	display: flex;
	flex-wrap: wrap;
	gap: 0.5rem;
}

.link {
	margin-left: 0.5rem;
	background: none;
	border: none;
	color: var(--color-primary-element, #0082c9);
	cursor: pointer;
	text-decoration: underline;
	padding: 0;
}
</style>
