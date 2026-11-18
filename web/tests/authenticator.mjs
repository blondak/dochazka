import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { chromium } from '@playwright/test'

const { login, password, origin, serviceCode } = JSON.parse(readFileSync(0, 'utf8'))
const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE })
try {
    const page = await browser.newPage()
    // Serve only the empty test page here. Fetches to the PHP API go to the test server.
    await page.route(origin + '/', route => route.fulfill({ contentType: 'text/html', body: '<title>WebAuthn</title>' }))
    await page.goto(origin)
    const devtools = await page.context().newCDPSession(page)
    await devtools.send('WebAuthn.enable')
    await devtools.send('WebAuthn.addVirtualAuthenticator', { options: {
        protocol: 'ctap2', transport: 'internal', hasResidentKey: true,
        hasUserVerification: true, isUserVerified: true, automaticPresenceSimulation: true,
    } })

    const result = await page.evaluate(async ({ login, password, serviceCode }) => {
        async function api(method, input = {}) {
            const response = await fetch('/api/v1/' + method, {
                method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Dochazka': 'web' },
                body: JSON.stringify(input),
            })
            if (!response.ok) throw new Error(method + ' ' + response.status)
            return response.json()
        }
        async function register(label) {
            const { publicKey } = await api('passkey-create-options', { password, label })
            const credential = await navigator.credentials.create({
                publicKey: PublicKeyCredential.parseCreationOptionsFromJSON(publicKey),
            })
            await api('passkey-create', credential.toJSON().response)
            return credential.id
        }
        const employee = await api('login', { login, password })
        const first = await register('Test browser')
        await api('logout')
        if (serviceCode) {
            const { publicKey } = await api('service-session-passkey-options', { code: serviceCode })
            const assertion = await navigator.credentials.get({ publicKey: PublicKeyCredential.parseRequestOptionsFromJSON(publicKey) })
            await api('service-session-verify', { code: serviceCode, passkey: { id: assertion.id, ...assertion.toJSON().response } })
            const response = await fetch('/api/v1/me', { method:'POST', body:'{}', headers:{'Content-Type':'application/json','X-Dochazka':'web'} })
            if (response.status !== 401) throw new Error('The service session grant created a web login')
        }
        const { publicKey } = await api('passkey-login-options')
        const credential = await navigator.credentials.get({
            publicKey: PublicKeyCredential.parseRequestOptionsFromJSON(publicKey),
        })
        const authenticated = await api('passkey-login', { id: credential.id, ...credential.toJSON().response })
        await api('passkey-remove', { id: first })
        const { keys } = await api('passkeys')
        // The outer HTTP test also checks removal of this key after password recovery.
        if (!serviceCode) await register('Recovery test')
        await api('logout')
        return { employee: employee.id, authenticated: authenticated.id, remaining: keys.length }
    }, { login, password, serviceCode })
    assert.equal(result.authenticated, result.employee)
    assert.equal(result.remaining, 0)
    console.log('Chromium WebAuthn registration, login and removal OK')
} finally {
    await browser.close()
}
