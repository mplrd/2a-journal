// Client-side image downscaling for support attachments.
//
// Phone photos are often several MB — large enough to blow past the server
// upload limits and fail with an opaque error. We shrink only images that are
// actually heavy (small ones pass through untouched) by redrawing them onto a
// canvas at a capped resolution and re-encoding as JPEG. Compression never
// blocks an upload: any failure falls back to the original file.

const COMPRESS_THRESHOLD = 1.5 * 1024 * 1024 // only touch files heavier than this
const MAX_DIMENSION = 2000 // px on the longest side
const TARGET_TYPE = 'image/jpeg'
const QUALITY = 0.82

function scaledDimensions(width, height, max) {
  if (width <= max && height <= max) {
    return { width, height }
  }
  const ratio = Math.min(max / width, max / height)
  return { width: Math.round(width * ratio), height: Math.round(height * ratio) }
}

async function loadBitmap(file) {
  if (typeof createImageBitmap === 'function') {
    return await createImageBitmap(file)
  }
  return await new Promise((resolve, reject) => {
    const img = new Image()
    img.onload = () => resolve(img)
    img.onerror = reject
    img.src = URL.createObjectURL(file)
  })
}

function canvasToBlob(canvas, type, quality) {
  return new Promise((resolve) => {
    canvas.toBlob((blob) => resolve(blob), type, quality)
  })
}

/**
 * Returns a possibly-smaller File. Non-images and already-light files are
 * returned unchanged (same reference). Falls back to the original on any error.
 *
 * @param {File} file
 * @returns {Promise<File>}
 */
export async function compressImageIfNeeded(file) {
  if (!file || !file.type?.startsWith('image/')) {
    return file
  }
  if (file.size <= COMPRESS_THRESHOLD) {
    return file
  }

  try {
    const bitmap = await loadBitmap(file)
    const { width, height } = scaledDimensions(bitmap.width, bitmap.height, MAX_DIMENSION)

    const canvas = document.createElement('canvas')
    canvas.width = width
    canvas.height = height
    const ctx = canvas.getContext('2d')
    if (!ctx) {
      return file
    }
    ctx.drawImage(bitmap, 0, 0, width, height)
    if (typeof bitmap.close === 'function') {
      bitmap.close()
    }

    const blob = await canvasToBlob(canvas, TARGET_TYPE, QUALITY)
    if (!blob || blob.size >= file.size) {
      return file // re-encoding gained nothing — keep the original
    }

    const name = file.name.replace(/\.[^.]+$/, '') + '.jpg'
    return new File([blob], name, { type: TARGET_TYPE, lastModified: file.lastModified })
  } catch {
    return file
  }
}
