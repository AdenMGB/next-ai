<script setup lang="ts">
import { onMounted, ref } from 'vue'
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'

const configured = ref(false)
const model = ref('')
const loading = ref(true)

onMounted(async () => {
	try {
		const { data } = await axios.get(generateOcsUrl('/apps/whiteboardai/api/status'))
		const payload = data?.ocs?.data ?? data
		configured.value = Boolean(payload?.configured)
		model.value = payload?.model ?? ''
	} catch {
		configured.value = false
	} finally {
		loading.value = false
	}
})
</script>

<template>
	<NcContent app-name="whiteboardai">
		<NcAppContent :class="$style.content">
			<div :class="$style.card">
				<h2>Whiteboard AI</h2>
				<p>
					Open any <strong>.whiteboard</strong> file in Nextcloud Files to use the floating AI chat.
					After each reply, press <strong>Ctrl+V</strong> / <strong>Cmd+V</strong> on the canvas to paste shapes.
					Optional: run the Talk join-bot container to auto-transcribe calls into <code>/Talk transcripts/</code>.
				</p>

				<NcNoteCard v-if="loading" type="info">
					Checking configuration…
				</NcNoteCard>
				<NcNoteCard v-else-if="configured" type="success">
					OpenRouter is configured{{ model ? ` (model: ${model})` : '' }}.
					Admins can change the key and model under Administration settings → Whiteboard AI.
				</NcNoteCard>
				<NcNoteCard v-else type="warning">
					An admin must set the OpenRouter API key and model under
					Administration settings → Whiteboard AI before generation works.
				</NcNoteCard>
			</div>
		</NcAppContent>
	</NcContent>
</template>

<style module>
.content {
	display: flex;
	justify-content: center;
	margin: 1rem;
}

.card {
	max-width: 40rem;
	display: flex;
	flex-direction: column;
	gap: 1rem;
	padding: 1rem;
	border-radius: 0.75rem;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
}

.card h2 {
	margin: 0;
}

.card p {
	margin: 0;
	line-height: 1.5;
}
</style>
