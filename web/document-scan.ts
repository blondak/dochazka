import type { ScanInput, ScanResult } from './document-scan.worker'

function canvas(width: number, height: number) {
    const result = document.createElement('canvas')
    result.width = width
    result.height = height
    return result
}

export async function preparePhoto(file: File) {
    const url = URL.createObjectURL(file)
    try {
        const image = new Image()
        image.src = url
        await image.decode()
        const scale = Math.min(1, 2000 / Math.max(image.naturalWidth, image.naturalHeight))
        const photo = canvas(Math.max(1, Math.round(image.naturalWidth * scale)), Math.max(1, Math.round(image.naturalHeight * scale)))
        const context = photo.getContext('2d')!
        context.fillStyle = '#fff'
        context.fillRect(0, 0, photo.width, photo.height)
        context.drawImage(image, 0, 0, photo.width, photo.height)
        return photo
    } finally {
        URL.revokeObjectURL(url)
    }
}

export async function straighten(photo: HTMLCanvasElement): Promise<HTMLCanvasElement | null> {
    const ratio = Math.min(1, 850 / Math.max(photo.width, photo.height))
    const thumbnail = canvas(Math.max(1, Math.round(photo.width * ratio)), Math.max(1, Math.round(photo.height * ratio)))
    const context = thumbnail.getContext('2d')!
    context.drawImage(photo, 0, 0, thumbnail.width, thumbnail.height)
    const data: ScanInput = {
        image:photo.getContext('2d')!.getImageData(0, 0, photo.width, photo.height),
        thumbnail:context.getImageData(0, 0, thumbnail.width, thumbnail.height),
        ratio,
    }
    // OpenCV uses dynamically generated JS bindings. Keep them outside the page's execution context.
    const worker = new Worker(new URL('./document-scan.worker.ts', import.meta.url))
    try {
        const result = await new Promise<ScanResult>((resolve, reject) => {
            worker.addEventListener('message', event => resolve(event.data), {once:true})
            worker.addEventListener('error', reject, {once:true})
            worker.postMessage(data, [data.image.data.buffer as ArrayBuffer, data.thumbnail.data.buffer as ArrayBuffer])
        })
        if ('failed' in result) throw new Error('Narovnání fotografie se nezdařilo.')
        if (!result.image) return null
        const adjusted = canvas(result.image.width, result.image.height)
        adjusted.getContext('2d')!.putImageData(result.image, 0, 0)
        return adjusted
    } finally {
        worker.terminate()
    }
}
