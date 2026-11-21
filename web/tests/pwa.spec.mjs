import {chromium, test, expect} from '@playwright/test'

test('Chromium accepts the standalone manifest and its install icons', async ({baseURL}, testInfo) => {
    // Chromium disables installation in the default incognito test contexts.
    const profile = await chromium.launchPersistentContext('', {baseURL, ...testInfo.project.use.launchOptions})
    try {
        const page = await profile.newPage()
        await page.goto('/')
        await page.evaluate(() => navigator.serviceWorker.ready.then(() => true))
        const chrome = await page.context().newCDPSession(page)
        const {errors, data} = await chrome.send('Page.getAppManifest')
        expect(errors).toEqual([])
        const manifest = JSON.parse(data)
        expect(manifest).toMatchObject({id:'/', name:'Docházka', start_url:'/', scope:'/', display:'standalone'})
        expect(manifest.icons).toEqual(expect.arrayContaining([
            expect.objectContaining({sizes:'192x192', purpose:'any'}),
            expect.objectContaining({sizes:'512x512', purpose:'any'}),
            expect.objectContaining({purpose:'maskable'}),
        ]))
        const appleIcon = await page.locator('link[rel="apple-touch-icon"]').getAttribute('href')
        for (const icon of [...manifest.icons, {src:appleIcon, sizes:'180x180'}]) {
            const size = await page.evaluate(async src => {
                const image = new Image()
                image.src = src
                await image.decode()
                return image.naturalWidth + 'x' + image.naturalHeight
            }, icon.src)
            expect(size).toBe(icon.sizes)
        }
        await expect.poll(async () => (await chrome.send('Page.getInstallabilityErrors')).installabilityErrors).toEqual([])
    } finally {
        await profile.close()
    }
})

test('offline navigation hides records and can retry the same day after reconnecting', async ({page, context}) => {
    const path = '/?sekce=denni-prehled&den=2026-10-01'
    await page.goto(path)
    await page.waitForFunction(() => navigator.serviceWorker.controller !== null)
    const proofPath = '/api/v1/request-proof'
    const fetchProof = async path => (await fetch(path, {method:'POST', body:'{"id":1}'})).json()
    await context.route('**' + proofPath, route => route.fulfill({json:{jpeg:'synteticky-doklad'}, headers:{'Cache-Control':'no-store'}}))
    expect(await page.evaluate(fetchProof, proofPath)).toEqual({jpeg:'synteticky-doklad'})
    await context.unroute('**' + proofPath)
    await context.setOffline(true)
    await expect(page.evaluate(fetchProof, proofPath)).rejects.toThrow()
    await expect(page.getByText('Jste bez připojení.', {exact:false})).toBeVisible()
    await page.reload()
    await expect(page.getByRole('heading', {name:'Docházka bez připojení'})).toBeVisible()
    expect(new URL(page.url()).search).toBe(new URL(path, 'http://localhost').search)
    expect(await page.evaluate(() => caches.keys())).toEqual([])
    await context.setOffline(false)
    await page.getByRole('link', {name:'Zkusit znovu'}).click()
    await expect(page.locator('#app')).toBeVisible()
    await expect(page.getByRole('heading', {name:'Docházka bez připojení'})).toHaveCount(0)
    expect(new URL(page.url()).search).toBe(new URL(path, 'http://localhost').search)
})
