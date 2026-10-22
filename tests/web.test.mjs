// Tests for the web app's sizing and naming rules: node --test tests/web.test.mjs
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { fitWithin, formatBytes, isImage, outputExtension, parseSize, savedPercent, targetPath } from '../web/resize-core.js'

test('parses sizes', () => {
	assert.deepEqual(parseSize('800x600'), { width: 800, height: 600 })
	assert.deepEqual(parseSize(' 1080 '), { width: 1080, height: 1080 })
	assert.deepEqual(parseSize('640 × 480'), { width: 640, height: 480 })
	assert.equal(parseSize('big'), null)
	assert.equal(parseSize('0x100'), null)
})

test('fits inside the box and never enlarges', () => {
	assert.deepEqual(fitWithin(1200, 800, 500, 500), { width: 500, height: 333 })
	assert.deepEqual(fitWithin(400, 1600, 500, 500), { width: 125, height: 500 })
	assert.deepEqual(fitWithin(300, 200, 500, 500), { width: 300, height: 200 })
})

test('picks the output format', () => {
	assert.equal(outputExtension('a.png', 'jpg'), 'jpg')
	assert.equal(outputExtension('a.jpeg', 'keep'), 'jpg')
	assert.equal(outputExtension('a.webp', 'keep'), 'webp')
	assert.equal(outputExtension('a.gif', 'keep'), 'jpg')
	assert.equal(outputExtension('a.HEIC', 'keep'), 'jpg')
})

test('keeps folders and both files when names clash', () => {
	const claimed = new Set()
	assert.equal(targetPath('trip/photo.jpg', 'jpg', claimed), 'trip/photo.jpg')
	assert.equal(targetPath('trip/photo.png', 'jpg', claimed), 'trip/photo-png.jpg')
	assert.equal(targetPath('other/photo.png', 'jpg', claimed), 'other/photo.jpg')
})

test('recognises images', () => {
	assert.equal(isImage('trip/IMG_1.HEIC'), true)
	assert.equal(isImage('notes.txt'), false)
	assert.equal(isImage('trip/.DS_Store'), false)
})

test('formats sizes and savings', () => {
	assert.equal(formatBytes(900), '900 B')
	assert.equal(formatBytes(1536), '1.5 KB')
	assert.equal(formatBytes(5 * 1024 * 1024), '5.0 MB')
	assert.equal(savedPercent(2000000, 500000), 75)
})
