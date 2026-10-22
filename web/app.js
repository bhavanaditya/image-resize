/**
 * Image Resize web app.
 *
 * Resizes images in the browser with a canvas. Results are saved to a folder
 * (Chrome and Edge on a computer) or downloaded as a ZIP. Nothing is uploaded.
 */

import {
	OUTPUT_TYPES, fitWithin, formatBytes, isImage, outputExtension, parseSize, savedPercent, targetPath,
} from './resize-core.js'

const JSZIP_URL = 'https://cdn.jsdelivr.net/npm/jszip@3.10.1/dist/jszip.min.js'
const LIST_LIMIT = 300
const canSaveToFolder = typeof window.showDirectoryPicker === 'function'

const $ = id => document.getElementById(id)
const ui = {
	dropzone: $('dropzone'),
	pickFiles: $('pick-files'),
	pickFolder: $('pick-folder'),
	presets: document.querySelector('.presets'),
	size: $('size'),
	format: $('format'),
	quality: $('quality'),
	qualityValue: $('quality-value'),
	sizeError: $('size-error'),
	status: $('status'),
	summary: $('summary'),
	listWrap: $('list-wrap'),
	list: $('list'),
	resize: $('resize'),
	saveFolder: $('save-folder'),
	download: $('download'),
	clear: $('clear'),
}

/**
 * items: [{ file, path, before: { width, height, size }, after: { width, height, size, blob, path, kept } | null, error }]
 */
let items = []
let busy = false

function setStatus(text, done = false) {
	ui.status.textContent = text
	ui.status.classList.toggle('done', done)
}

function settings() {
	const size = parseSize(ui.size.value)
	return {
		size,
		format: ui.format.value,
		quality: Number(ui.quality.value),
	}
}

function plural(count, word) {
	return `${count} ${word}${count === 1 ? '' : 's'}`
}

function addFiles(files) {
	const known = new Set(items.map(item => item.path))
	for (const file of files) {
		const path = file.relativePath || file.webkitRelativePath || file.name
		if (isImage(path) && !known.has(path)) {
			items.push({ file, path, before: { size: file.size }, after: null, error: null })
			known.add(path)
		}
	}
	resetResults()
	if (!items.length) {
		setStatus('No images found. Supported: JPG, PNG, WebP, GIF, BMP and AVIF (and HEIC in Safari).')
	}
}

function resetResults() {
	for (const item of items) {
		item.after = null
		item.error = null
	}
	render()
}

function cell(main, sub, className) {
	const td = document.createElement('td')
	td.textContent = main
	if (sub) {
		const small = document.createElement('small')
		small.textContent = sub
		td.append(small)
	}
	if (className) {
		td.className = className
	}
	return td
}

function render() {
	ui.list.replaceChildren()

	for (const item of items.slice(0, LIST_LIMIT)) {
		const row = document.createElement('tr')
		const dims = item.before.width ? `${item.before.width} × ${item.before.height}` : ''
		row.append(cell(item.path), cell(formatBytes(item.before.size), dims))

		if (item.error) {
			row.append(cell(item.error, '', 'failed'))
		} else if (item.after) {
			const td = cell(formatBytes(item.after.size), '', 'after')
			const note = document.createElement('small')
			const saved = savedPercent(item.before.size, item.after.size)
			note.textContent = `${item.after.width} × ${item.after.height}` + (item.after.kept ? ' · already optimised, kept as is' : '')
			if (saved > 0) {
				const badge = document.createElement('span')
				badge.className = 'saved'
				badge.textContent = ` −${saved}%`
				td.append(badge)
			}
			td.append(note)
			row.append(td)
		} else {
			row.append(cell('—'))
		}
		ui.list.append(row)
	}

	const done = items.filter(item => item.after)
	const failed = items.filter(item => item.error)
	const hasItems = items.length > 0
	const allDone = hasItems && done.length + failed.length === items.length

	ui.listWrap.hidden = !hasItems
	ui.resize.hidden = !hasItems || allDone
	ui.resize.textContent = `Resize ${plural(items.length, 'image')}`
	ui.saveFolder.hidden = !allDone || !done.length || !canSaveToFolder
	ui.download.hidden = !allDone || !done.length
	ui.download.textContent = done.length === 1 ? 'Download image' : 'Download ZIP'
	ui.clear.hidden = !hasItems

	ui.summary.hidden = !allDone || !done.length
	if (allDone && done.length) {
		const before = done.reduce((sum, item) => sum + item.before.size, 0)
		const after = done.reduce((sum, item) => sum + item.after.size, 0)
		ui.summary.replaceChildren(...[
			[plural(done.length, 'image'), 'resized'],
			[formatBytes(before), `→ ${formatBytes(after)}`],
			[`${savedPercent(before, after)}%`, 'smaller'],
		].map(([big, small]) => {
			const box = document.createElement('div')
			const strong = document.createElement('strong')
			strong.textContent = big
			const span = document.createElement('span')
			span.textContent = small
			box.append(strong, span)
			return box
		}))
	}

	if (!hasItems || busy) {
		return
	}
	const more = items.length > LIST_LIMIT ? ` Showing the first ${LIST_LIMIT}.` : ''
	if (allDone) {
		const failNote = failed.length ? ` ${plural(failed.length, 'image')} could not be opened.` : ''
		setStatus(`Done. Save your resized images below. Your originals are unchanged.${failNote}${more}`, true)
	} else {
		setStatus(`${plural(items.length, 'image')} ready.${more}`)
	}
}

function makeCanvas(width, height) {
	const canvas = document.createElement('canvas')
	canvas.width = width
	canvas.height = height
	return canvas
}

/**
 * Draws the image at the new size. Large reductions are done in halving steps,
 * which looks noticeably sharper than one big jump.
 */
function drawResized(bitmap, width, height, background) {
	let source = bitmap
	let sourceWidth = bitmap.width
	let sourceHeight = bitmap.height

	while (sourceWidth / 2 >= width && sourceHeight / 2 >= height) {
		const step = makeCanvas(Math.round(sourceWidth / 2), Math.round(sourceHeight / 2))
		const ctx = step.getContext('2d')
		ctx.imageSmoothingQuality = 'high'
		ctx.drawImage(source, 0, 0, step.width, step.height)
		source = step
		sourceWidth = step.width
		sourceHeight = step.height
	}

	const canvas = makeCanvas(width, height)
	const ctx = canvas.getContext('2d')
	if (background) {
		ctx.fillStyle = background
		ctx.fillRect(0, 0, width, height)
	}
	ctx.imageSmoothingQuality = 'high'
	ctx.drawImage(source, 0, 0, width, height)
	return canvas
}

function toBlob(canvas, type, quality) {
	return new Promise((resolve, reject) => {
		canvas.toBlob(blob => (blob ? resolve(blob) : reject(new Error('Could not save the image.'))), type, quality)
	})
}

async function resizeOne(item, options, claimed) {
	let bitmap
	try {
		bitmap = await createImageBitmap(item.file, { imageOrientation: 'from-image' })
	} catch {
		throw new Error('Your browser can\'t open this file type.')
	}

	try {
		item.before.width = bitmap.width
		item.before.height = bitmap.height

		const { width, height } = fitWithin(bitmap.width, bitmap.height, options.size.width, options.size.height)
		let ext = outputExtension(item.path, options.format)
		// JPG has no transparency, so use a white background instead of black.
		const canvas = drawResized(bitmap, width, height, ext === 'jpg' ? '#ffffff' : null)

		let blob = await toBlob(canvas, OUTPUT_TYPES[ext], options.quality / 100)
		if (blob.type !== OUTPUT_TYPES[ext]) {
			// Older browsers can't save WebP and quietly return PNG. Use JPG instead.
			ext = 'jpg'
			blob = await toBlob(drawResized(bitmap, width, height, '#ffffff'), OUTPUT_TYPES.jpg, options.quality / 100)
		}

		// Already small and the new file would be bigger: keep the original.
		let kept = false
		const sameType = item.file.type === OUTPUT_TYPES[ext]
		if (sameType && width === bitmap.width && height === bitmap.height && blob.size >= item.file.size) {
			blob = item.file
			kept = true
		}

		item.after = { width, height, size: blob.size, blob, path: targetPath(item.path, ext, claimed), kept }
	} finally {
		bitmap.close()
	}
}

async function resizeAll() {
	const options = settings()
	if (!options.size) {
		return
	}

	busy = true
	ui.resize.disabled = true
	const claimed = new Set()
	let done = 0

	for (const item of items) {
		item.after = null
		item.error = null
	}

	// One or two at a time keeps memory use reasonable with big photos.
	let next = 0
	const worker = async () => {
		while (next < items.length) {
			const item = items[next++]
			try {
				await resizeOne(item, options, claimed)
			} catch (error) {
				item.error = error.message
			}
			done++
			setStatus(`Resizing… ${done} of ${items.length}`)
		}
	}
	await Promise.all([worker(), worker()])

	busy = false
	ui.resize.disabled = false
	render()
}

function loadScript(src) {
	return new Promise((resolve, reject) => {
		const script = document.createElement('script')
		script.src = src
		script.onload = resolve
		script.onerror = () => reject(new Error('Could not load the ZIP library. Check your connection.'))
		document.head.append(script)
	})
}

function triggerDownload(blob, name) {
	const url = URL.createObjectURL(blob)
	const link = document.createElement('a')
	link.href = url
	link.download = name
	document.body.append(link)
	link.click()
	link.remove()
	setTimeout(() => URL.revokeObjectURL(url), 60000)
}

async function download() {
	const done = items.filter(item => item.after)
	if (done.length === 1) {
		triggerDownload(done[0].after.blob, done[0].after.path.split('/').pop())
		return
	}

	ui.download.disabled = true
	try {
		if (!window.JSZip) {
			await loadScript(JSZIP_URL)
		}
		const zip = new window.JSZip()
		for (const item of done) {
			zip.file(item.after.path, item.after.blob)
		}
		// Images are already compressed, so storing them is fastest.
		const blob = await zip.generateAsync({ type: 'blob', compression: 'STORE' })
		triggerDownload(blob, 'resized-images.zip')
		setStatus('Your ZIP is downloading. Your originals are unchanged.', true)
	} catch (error) {
		setStatus(`Could not make the ZIP: ${error.message}`)
	} finally {
		ui.download.disabled = false
	}
}

async function saveToFolder() {
	let dir
	try {
		dir = await window.showDirectoryPicker({ id: 'image-resize-output', mode: 'readwrite' })
	} catch (error) {
		if (error.name !== 'AbortError') {
			setStatus(`Could not open the folder: ${error.message}`)
		}
		return
	}

	ui.saveFolder.disabled = true
	let saved = 0
	try {
		for (const item of items.filter(entry => entry.after)) {
			const parts = item.after.path.split('/')
			const name = parts.pop()
			let folder = dir
			for (const part of parts) {
				folder = await folder.getDirectoryHandle(part, { create: true })
			}
			const handle = await folder.getFileHandle(name, { create: true })
			const writable = await handle.createWritable()
			await writable.write(item.after.blob)
			await writable.close()
			saved++
		}
		setStatus(`Saved ${plural(saved, 'image')} to “${dir.name}”.`, true)
	} catch (error) {
		setStatus(`Saved ${saved}, then stopped: ${error.message}`)
	} finally {
		ui.saveFolder.disabled = false
	}
}

// Reads files from a dropped folder, keeping their paths.
async function filesFromEntry(entry, prefix = '') {
	if (entry.isFile) {
		const file = await new Promise((resolve, reject) => entry.file(resolve, reject))
		file.relativePath = prefix + file.name
		return [file]
	}
	if (!entry.isDirectory) {
		return []
	}
	const reader = entry.createReader()
	const entries = []
	for (let batch; (batch = await new Promise((resolve, reject) => reader.readEntries(resolve, reject))).length;) {
		entries.push(...batch)
	}
	const nested = await Promise.all(entries.map(child => filesFromEntry(child, `${prefix}${entry.name}/`)))
	return nested.flat()
}

async function onDrop(event) {
	event.preventDefault()
	ui.dropzone.classList.remove('over')
	const entries = [...event.dataTransfer.items].map(item => item.webkitGetAsEntry && item.webkitGetAsEntry()).filter(Boolean)
	if (entries.length) {
		const files = (await Promise.all(entries.map(entry => filesFromEntry(entry)))).flat()
		addFiles(files)
	} else {
		addFiles(event.dataTransfer.files)
	}
}

function selectPreset(size) {
	for (const chip of ui.presets.querySelectorAll('[data-size]')) {
		chip.setAttribute('aria-checked', String(chip.dataset.size === size))
	}
}

function onSettingsChange() {
	const { size } = settings()
	ui.sizeError.hidden = Boolean(size)
	ui.sizeError.textContent = size ? '' : 'Enter a size like 800x600, or one number for a square, like 1080.'
	ui.resize.disabled = !size
	const square = size && size.width === size.height ? String(size.width) : ''
	selectPreset(square)
	if (items.some(item => item.after || item.error)) {
		resetResults()
	}
}

ui.pickFiles.addEventListener('change', event => {
	addFiles(event.target.files)
	event.target.value = ''
})
ui.pickFolder.addEventListener('change', event => {
	addFiles(event.target.files)
	event.target.value = ''
})
ui.dropzone.addEventListener('dragover', event => {
	event.preventDefault()
	ui.dropzone.classList.add('over')
})
ui.dropzone.addEventListener('dragleave', () => ui.dropzone.classList.remove('over'))
ui.dropzone.addEventListener('drop', onDrop)

ui.presets.addEventListener('click', event => {
	const chip = event.target.closest('[data-size]')
	if (chip) {
		ui.size.value = `${chip.dataset.size}x${chip.dataset.size}`
		onSettingsChange()
	}
})
ui.size.addEventListener('input', onSettingsChange)
ui.format.addEventListener('change', onSettingsChange)
ui.quality.addEventListener('input', () => {
	ui.qualityValue.textContent = ui.quality.value
	onSettingsChange()
})

ui.resize.addEventListener('click', resizeAll)
ui.download.addEventListener('click', download)
ui.saveFolder.addEventListener('click', saveToFolder)
ui.clear.addEventListener('click', () => {
	items = []
	render()
	setStatus('Choose some images to get started.')
})
