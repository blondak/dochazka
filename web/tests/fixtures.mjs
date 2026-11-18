export async function documentPhoto(page) {
    const encoded = await page.evaluate(() => {
        const canvas = document.createElement('canvas')
        canvas.width = 420
        canvas.height = 320
        const context = canvas.getContext('2d')
        context.fillStyle = '#333'
        context.fillRect(0, 0, 420, 320)
        context.fillStyle = '#fff'
        context.beginPath()
        for (const [x,y] of [[85,45],[330,70],[310,263],[105,280]]) context.lineTo(x,y)
        context.fill()
        return canvas.toDataURL('image/png').split(',')[1]
    })
    return {name:'synthetic-proof.png', mimeType:'image/png', buffer:Buffer.from(encoded,'base64')}
}
