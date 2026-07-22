export type CompactElement = {
	id: string
	type: string
	x: number
	y: number
	width: number
	height: number
	text?: string
	strokeColor?: string
	backgroundColor?: string
	angle?: number
	startBinding?: unknown
	endBinding?: unknown
}

export type WhiteboardRecord = {
	id: number
	elements?: unknown[]
	files?: Record<string, unknown>
	appState?: Record<string, unknown>
}

const DB_NAME = 'WhiteboardDatabase'
const STORE_NAME = 'whiteboards'

function openWhiteboardDb(): Promise<IDBDatabase> {
	return new Promise((resolve, reject) => {
		const request = indexedDB.open(DB_NAME)
		request.onerror = () => reject(request.error ?? new Error('Failed to open WhiteboardDatabase'))
		request.onsuccess = () => resolve(request.result)
	})
}

export async function readWhiteboardScene(fileId: number): Promise<WhiteboardRecord | null> {
	if (!Number.isFinite(fileId) || fileId <= 0) {
		return null
	}

	try {
		const db = await openWhiteboardDb()
		return await new Promise((resolve, reject) => {
			const tx = db.transaction(STORE_NAME, 'readonly')
			const store = tx.objectStore(STORE_NAME)
			const request = store.get(fileId)
			request.onerror = () => reject(request.error ?? new Error('Failed to read whiteboard scene'))
			request.onsuccess = () => {
				resolve((request.result as WhiteboardRecord | undefined) ?? null)
			}
		})
	} catch (error) {
		console.warn('[whiteboardai] Could not read IndexedDB scene', error)
		return null
	}
}

export function compactElements(elements: unknown[], maxElements = 120): CompactElement[] {
	const compacted: CompactElement[] = []

	for (const element of elements) {
		if (!element || typeof element !== 'object') {
			continue
		}
		const el = element as Record<string, unknown>
		if (el.isDeleted === true) {
			continue
		}
		if (typeof el.type !== 'string') {
			continue
		}

		const item: CompactElement = {
			id: typeof el.id === 'string' ? el.id : '',
			type: el.type,
			x: Number(el.x) || 0,
			y: Number(el.y) || 0,
			width: Number(el.width) || 0,
			height: Number(el.height) || 0,
		}

		if (typeof el.text === 'string' && el.text !== '') {
			item.text = el.text.slice(0, 500)
		}
		if (typeof el.strokeColor === 'string') {
			item.strokeColor = el.strokeColor
		}
		if (typeof el.backgroundColor === 'string') {
			item.backgroundColor = el.backgroundColor
		}
		if (el.angle !== undefined) {
			item.angle = Number(el.angle) || 0
		}
		if (el.startBinding !== undefined || el.endBinding !== undefined) {
			item.startBinding = el.startBinding ?? null
			item.endBinding = el.endBinding ?? null
		}

		compacted.push(item)
		if (compacted.length >= maxElements) {
			break
		}
	}

	return compacted
}

export function resolveWhiteboardFileId(): number | null {
	const host = document.querySelector('.whiteboard')
	if (host) {
		const fromData = host.getAttribute('data-fileid') || host.getAttribute('data-file-id')
		if (fromData && Number(fromData) > 0) {
			return Number(fromData)
		}
	}

	const vueFileId = document.querySelector('[fileid], [data-fileid]')
	if (vueFileId) {
		const attr = vueFileId.getAttribute('fileid')
			|| vueFileId.getAttribute('data-fileid')
			|| vueFileId.getAttribute('data-file-id')
		if (attr && Number(attr) > 0) {
			return Number(attr)
		}
	}

	const params = new URLSearchParams(window.location.search)
	for (const key of ['fileid', 'fileId', 'openfile']) {
		const value = params.get(key)
		if (value && Number(value) > 0) {
			return Number(value)
		}
	}

	const match = window.location.pathname.match(/\/f\/(\d+)/)
	if (match) {
		return Number(match[1])
	}

	return null
}
