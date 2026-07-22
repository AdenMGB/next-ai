export type ClipboardPayload = {
	type: string
	elements: unknown[]
}

/**
 * Copy Excalidraw clipboard JSON for the user to paste (Ctrl/Cmd+V).
 * Falls back to textarea copy, then .excalidraw download.
 */
export async function applyClipboardPayload(
	payload: ClipboardPayload,
	_mode: 'append' | 'replace' = 'append',
): Promise<'copied' | 'downloaded'> {
	const json = JSON.stringify({
		type: payload.type || 'excalidraw/clipboard',
		elements: payload.elements,
	})

	const copied = await copyWithFallbacks(json)
	if (copied) {
		return 'copied'
	}

	downloadExcalidraw(json)
	return 'downloaded'
}

async function copyWithFallbacks(json: string): Promise<boolean> {
	try {
		if (navigator.clipboard?.writeText) {
			await navigator.clipboard.writeText(json)
			return true
		}
	} catch (error) {
		console.warn('[whiteboardai] clipboard.writeText failed', error)
	}

	try {
		const txtArea = document.createElement('textarea')
		txtArea.value = json
		txtArea.setAttribute('readonly', '')
		txtArea.style.position = 'fixed'
		txtArea.style.left = '-9999px'
		document.body.appendChild(txtArea)
		txtArea.focus()
		txtArea.select()
		const ok = document.execCommand('copy')
		document.body.removeChild(txtArea)
		return ok
	} catch (error) {
		console.warn('[whiteboardai] execCommand copy failed', error)
		return false
	}
}

function downloadExcalidraw(json: string) {
	const blob = new Blob([json], { type: 'application/json' })
	const url = URL.createObjectURL(blob)
	const link = document.createElement('a')
	link.href = url
	link.download = `whiteboardai-${Date.now()}.excalidraw`
	document.body.appendChild(link)
	link.click()
	document.body.removeChild(link)
	URL.revokeObjectURL(url)
}
