/// <reference lib="webworker" />

import opencvUrl from '@techstark/opencv-js/dist/opencv.js?url'
import type * as OpenCV from '@techstark/opencv-js'

type Engine = typeof OpenCV
type Point = [number, number]

export interface ScanInput {
    image: ImageData
    thumbnail: ImageData
    ratio: number
}

export type ScanResult = {image:ImageData|null} | {failed:true}

const worker = self as unknown as DedicatedWorkerGlobalScope & { cv: Engine | Promise<Engine> }

function orderCorners(points: Point[]) {
    // Order corners around the centre, starting at the top left.
    const cx = points.reduce((sum, point) => sum + point[0], 0) / 4
    const cy = points.reduce((sum, point) => sum + point[1], 0) / 4
    const ordered = points.sort((a, b) => Math.atan2(a[1] - cy, a[0] - cx) - Math.atan2(b[1] - cy, b[0] - cx))
    const start = ordered.reduce((i, [x,y], index) => x + y < ordered[i][0] + ordered[i][1] ? index : i, 0)
    return [...ordered.slice(start), ...ordered.slice(0, start)]
}

function detect(cv: Engine, thumbnail: ImageData, ratio: number): Point[] | null {
    const input = cv.matFromImageData(thumbnail)
    const edges = new cv.Mat()
    const hierarchy = new cv.Mat()
    const contours = new cv.MatVector()
    const kernel = cv.Mat.ones(5, 5, cv.CV_8U)
    try {
        cv.cvtColor(input, edges, cv.COLOR_RGBA2GRAY)
        cv.GaussianBlur(edges, edges, new cv.Size(5, 5), 0)
        cv.Canny(edges, edges, 45, 140)
        cv.morphologyEx(edges, edges, cv.MORPH_CLOSE, kernel)
        cv.findContours(edges, contours, hierarchy, cv.RETR_LIST, cv.CHAIN_APPROX_SIMPLE)
        const area = thumbnail.width * thumbnail.height
        let bestArea = area * .18
        let best: Point[] | null = null
        for (let i = 0; i < contours.size(); i++) {
            const contour = contours.get(i)
            const polygon = new cv.Mat()
            try {
                const contourArea = cv.contourArea(contour)
                if (contourArea <= bestArea || contourArea >= area * .98) continue
                cv.approxPolyDP(contour, polygon, .025 * cv.arcLength(contour, true), true)
                if (polygon.rows !== 4 || !cv.isContourConvex(polygon)) continue
                const coordinates = polygon.data32S
                best = Array.from({length:4}, (_, index): Point =>
                    [coordinates[index * 2] / ratio, coordinates[index * 2 + 1] / ratio])
                bestArea = contourArea
            } finally {
                polygon.delete()
                contour.delete()
            }
        }
        return best ? orderCorners(best) : null
    } finally {
        input.delete()
        edges.delete()
        hierarchy.delete()
        contours.delete()
        kernel.delete()
    }
}

function straighten(cv: Engine, {image, thumbnail, ratio}: ScanInput): ImageData | null {
    const corners = detect(cv, thumbnail, ratio)
    if (!corners) return null
    const [a, b, c, d] = corners
    const distance = (p: Point, q: Point) => Math.hypot(p[0] - q[0], p[1] - q[1])
    const width = Math.round(Math.max(distance(a, b), distance(c, d)))
    const height = Math.round(Math.max(distance(a, d), distance(b, c)))
    if (width < 40 || height < 40) return null

    const input = cv.matFromImageData(image)
    const output = new cv.Mat()
    const from = cv.matFromArray(4, 1, cv.CV_32FC2, corners.flat())
    const to = cv.matFromArray(4, 1, cv.CV_32FC2, [0, 0, width - 1, 0, width - 1, height - 1, 0, height - 1])
    const transformation = cv.getPerspectiveTransform(from, to)
    try {
        cv.warpPerspective(input, output, transformation, new cv.Size(width, height))
        return new ImageData(new Uint8ClampedArray(output.data), width, height)
    } finally {
        input.delete()
        output.delete()
        from.delete()
        to.delete()
        transformation.delete()
    }
}

worker.addEventListener('message', async ({data}: MessageEvent<ScanInput>) => {
    try {
        worker.importScripts(opencvUrl)
        const image = straighten(await worker.cv, data)
        const result: ScanResult = {image}
        worker.postMessage(result, image ? [image.data.buffer as ArrayBuffer] : [])
    } catch {
        worker.postMessage({failed:true} satisfies ScanResult)
    }
})
