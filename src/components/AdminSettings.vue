<script setup lang="ts">
import { computed, ref } from 'vue'
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import { loadState } from '@nextcloud/initial-state'
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'

const initial = loadState('whiteboardai', 'admin-settings', {
	configured: false,
	model: 'openai/gpt-4o-mini',
	maxElements: 80,
	rateLimitPerMinute: 20,
})

const apiKey = ref('')
const model = ref(initial.model || 'openai/gpt-4o-mini')
const maxElements = ref(initial.maxElements)
const rateLimitPerMinute = ref(initial.rateLimitPerMinute)
const configured = ref(initial.configured)
const saving = ref(false)

const canSave = computed(() => {
	const hasModel = model.value.trim().length > 0
	const hasKey = configured.value || apiKey.value.trim().length > 0
	return hasModel && hasKey && !saving.value
})

async function save() {
	if (!model.value.trim()) {
		showError('OpenRouter model is required')
		return
	}
	if (!configured.value && !apiKey.value.trim()) {
		showError('OpenRouter API key is required')
		return
	}

	saving.value = true
	try {
		const body = {
			model: model.value.trim(),
			maxElements: maxElements.value,
			rateLimitPerMinute: rateLimitPerMinute.value,
		}
		if (apiKey.value.trim() !== '') {
			body.apiKey = apiKey.value.trim()
		}

		const { data } = await axios.put(generateOcsUrl('/apps/whiteboardai/api/settings'), body)
		const payload = data?.ocs?.data ?? data
		configured.value = Boolean(payload?.configured)
		model.value = payload?.model ?? model.value
		maxElements.value = payload?.maxElements ?? maxElements.value
		rateLimitPerMinute.value = payload?.rateLimitPerMinute ?? rateLimitPerMinute.value
		apiKey.value = ''
		showSuccess('OpenRouter settings saved')
	} catch (error) {
		console.error(error)
		showError('Failed to save OpenRouter settings')
	} finally {
		saving.value = false
	}
}

async function clearKey() {
	if (!configured.value) {
		return
	}
	saving.value = true
	try {
		const { data } = await axios.put(generateOcsUrl('/apps/whiteboardai/api/settings'), {
			clearApiKey: true,
			model: model.value.trim() || 'openai/gpt-4o-mini',
		})
		const payload = data?.ocs?.data ?? data
		configured.value = Boolean(payload?.configured)
		apiKey.value = ''
		showSuccess('OpenRouter API key removed')
	} catch (error) {
		console.error(error)
		showError('Failed to clear OpenRouter API key')
	} finally {
		saving.value = false
	}
}
</script>

<template>
	<NcSettingsSection
		name="OpenRouter"
		description="Set the shared OpenRouter API key and model used by all users for whiteboard AI generation.">
		<NcNoteCard v-if="!configured" type="warning">
			Enter your OpenRouter API key and choose a model, then click Save.
		</NcNoteCard>
		<NcNoteCard v-else type="success">
			API key is saved. You can change the model anytime. Leave the key field blank to keep the current key.
		</NcNoteCard>

		<div :class="$style.form">
			<NcTextField
				:model-value="apiKey"
				type="password"
				label="OpenRouter API key"
				:placeholder="configured ? '•••••••• (leave blank to keep)' : 'sk-or-v1-...'"
				@update:model-value="apiKey = $event" />
			<NcTextField
				:model-value="model"
				label="OpenRouter model"
				placeholder="openai/gpt-4o-mini"
				@update:model-value="model = $event" />
			<p :class="$style.hint">
				Examples: openai/gpt-4o-mini, anthropic/claude-sonnet-4, google/gemini-2.5-flash
			</p>
			<NcTextField
				:model-value="String(maxElements)"
				type="number"
				label="Max elements per generation"
				@update:model-value="maxElements = Number($event) || 80" />
			<NcTextField
				:model-value="String(rateLimitPerMinute)"
				type="number"
				label="Rate limit per user / minute"
				@update:model-value="rateLimitPerMinute = Number($event) || 20" />
			<div :class="$style.actions">
				<NcButton
					type="primary"
					:disabled="!canSave"
					class="transition-all duration-200 transform hover:scale-105 active:scale-95"
					@click="save">
					{{ saving ? 'Saving…' : 'Save key & model' }}
				</NcButton>
				<NcButton
					v-if="configured"
					type="error"
					:disabled="saving"
					class="transition-all duration-200 transform hover:scale-105 active:scale-95"
					@click="clearKey">
					Remove API key
				</NcButton>
			</div>
		</div>
	</NcSettingsSection>
</template>

<style module>
.form {
	display: flex;
	flex-direction: column;
	gap: 1rem;
	max-width: 32rem;
	margin-top: 1rem;
}

.hint {
	margin: -0.5rem 0 0;
	font-size: 0.85rem;
	opacity: 0.8;
}

.actions {
	display: flex;
	flex-wrap: wrap;
	gap: 0.75rem;
}
</style>
