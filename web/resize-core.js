/**
 * Image Resize web app: sizing and naming rules.
 *
 * Kept free of browser APIs so it can be tested with Node.js. The rules match
 * the PHP command-line tool: shrink to fit a box, never enlarge, mirror the
 * folder layout, and keep both files when two names would clash.
 */

export const INPUT_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'avif', 'heic', 'heif']

export const OUTPUT_TYPES = {
	jpg: 'image/jpeg',
	png: 'image/png',
	webp: 'image/webp',
}

export function extensionOf(name) {
	const dot = name.lastIndexOf('.')
	return dot > 0 ? name.slice(dot + 1).toLowerCase() : ''
}

export function isImage(name) {
	const base = name.split('/').pop()
	return !base.startsWith('.') && INPUT_EXTENSIONS.includes(extensionOf(base))
}

/**
 * Parses "800x600", "800 × 600" or "1080" (a square). Returns null if invalid.
 */
export function parseSize(text) {
	const match = String(text).trim().match(/^(\d+)\s*(?:[x×*]\s*(\d+))?$/i)
	if (!match) {
		return null
	}
	const width = Number(match[1])
	const height = match[2] ? Number(match[2]) : width
	return width >= 1 && height >= 1 ? { width, height } : null
}

/**
 * Scales width × height down to fit inside the box, keeping the aspect ratio.
 * Never enlarges.
 */
export function fitWithin(width, height, maxWidth, maxHeight) {
	const scale = Math.min(1, maxWidth / width, maxHeight / height)
	return {
		width: Math.max(1, Math.round(width * scale)),
		height: Math.max(1, Math.round(height * scale)),
	}
}

/**
 * The output extension. "keep" keeps JPG, PNG and WebP; other formats
 * (GIF, BMP, HEIC, AVIF) become JPG, since browsers can't save them.
 */
export function outputExtension(name, format) {
	if (format !== 'keep') {
		return format
	}
	const ext = extensionOf(name)
	if (ext === 'jpeg') {
		return 'jpg'
	}
	return OUTPUT_TYPES[ext] ? ext : 'jpg'
}

/**
 * The output path for one image, keeping its folder. If two images would get
 * the same name (photo.png and photo.jpg both becoming photo.jpg), the second
 * one gets its original extension added: photo-png.jpg.
 */
export function targetPath(relativePath, outExt, claimed) {
	const slash = relativePath.lastIndexOf('/')
	const dir = slash >= 0 ? relativePath.slice(0, slash + 1) : ''
	const file = relativePath.slice(slash + 1)
	const dot = file.lastIndexOf('.')
	const stem = dot > 0 ? file.slice(0, dot) : file
	const ext = extensionOf(file)

	let candidate = `${dir}${stem}.${outExt}`
	if (claimed.has(candidate.toLowerCase())) {
		candidate = `${dir}${stem}-${ext}.${outExt}`
	}
	for (let copy = 2; claimed.has(candidate.toLowerCase()); copy++) {
		candidate = `${dir}${stem}-${ext}-${copy}.${outExt}`
	}
	claimed.add(candidate.toLowerCase())
	return candidate
}

export function formatBytes(bytes) {
	if (bytes < 1024) {
		return `${bytes} B`
	}
	const units = ['KB', 'MB', 'GB']
	let value = bytes / 1024
	let unit = 0
	while (value >= 1024 && unit < units.length - 1) {
		value /= 1024
		unit++
	}
	return `${value >= 100 ? Math.round(value) : value.toFixed(1)} ${units[unit]}`
}

/**
 * Percentage saved, e.g. 2_000_000 -> 500_000 gives 75.
 */
export function savedPercent(before, after) {
	return before > 0 ? Math.round((1 - after / before) * 100) : 0
}
