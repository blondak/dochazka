import { readFileSync } from 'node:fs'
import { readFile } from 'node:fs/promises'
import { chromium, expect } from '@playwright/test'
import { documentPhoto } from './fixtures.mjs'

const {baseURL, month, credentials} = JSON.parse(readFileSync(0, 'utf8'))
const visitDate = month + '-03'
const browser = await chromium.launch({executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE})

async function login(role, options = {}) {
    const context = await browser.newContext({timezoneId:'Europe/Prague', ...options})
    const page = await context.newPage()
    page.setDefaultTimeout(10000)
    const document = await page.goto(baseURL)
    expect(document.headers()['content-security-policy']).toContain("script-src 'self';")
    await page.getByLabel('Uživatelské jméno').fill(credentials[role].login)
    await page.getByLabel('Heslo', {exact:true}).fill(credentials[role].password)
    await page.getByRole('button', {name:'Přihlásit se', exact:true}).click()
    await expect(page.getByRole('banner')).toContainText(credentials[role].name)
    return page
}

async function openPilotMonth(page) {
    for (let attempt = 0; attempt < 3; attempt++) {
        if (await page.getByLabel('Období').inputValue() === month) return
        await page.getByRole('button', {name:'Předchozí měsíc'}).click()
    }
    await expect(page.getByLabel('Období')).toHaveValue(month)
}

async function navigate(page, section) {
    await page.getByRole('button', {name:'Menu', exact:true}).click()
    const nav = page.getByRole('navigation', {name:'Sekce docházky'})
    await nav.getByRole('button', {name:section, exact:true}).click()
    await expect(nav).toBeHidden()
}

async function openRequests(page) {
    await navigate(page, 'Žádosti')
}

try {
    const docs = await browser.newPage()
    const spec = docs.waitForResponse(response => response.url() === new URL('openapi.json', baseURL).href && response.ok())
    await docs.goto(new URL('docs/', baseURL).href)
    const {info} = await (await spec).json()
    await expect(docs.getByRole('heading', {name:info.title, level:1, exact:true})).toBeVisible()
    await docs.close()

    const employee = await login('employee', {isMobile:true, hasTouch:true, viewport:{width:390,height:744}})
    await openRequests(employee)
    await employee.getByRole('button', {name:'Nová žádost nebo oprava'}).click()
    await employee.getByLabel('Datum začátku').fill(visitDate)
    await employee.getByLabel('Čas začátku (ČR)').fill('09:15')
    await employee.getByLabel('Datum konce').fill(visitDate)
    await employee.getByLabel('Čas konce (ČR)').fill('10:00')
    await employee.getByRole('button', {name:'Odeslat žádost'}).click()
    await expect(employee.getByText('Čeká na schválení', {exact:true})).toBeVisible()
    await expect(employee.getByLabel('Období')).toHaveValue(month)
    await expect(employee.getByRole('button', {name:'Vyfotit propustku'})).toHaveCount(0)

    const manager = await login('manager')
    await openPilotMonth(manager)
    await manager.getByRole('combobox', {name:'Osoba'}).click()
    await manager.getByRole('option', {name:credentials.employee.name}).click()
    await expect(manager.getByRole('table', {name:'Souhrn za měsíc'}).getByText('8:00', {exact:true})).toBeVisible()
    await openRequests(manager)
    await manager.getByRole('button', {name:'Schválit', exact:true}).click()
    const decision = manager.getByRole('dialog', {name:'Schválit žádost'})
    await expect(decision).toContainText(credentials.employee.name)
    await decision.getByRole('button', {name:'Schválit žádost'}).click()
    await expect(manager.getByText('Čeká na propustku')).toBeVisible()
    await navigate(manager, 'Výkaz')
    await expect(manager.getByText('Zaměstnanec musí přiložit povinné doklady.')).toBeVisible()
    await expect(manager.getByRole('button', {name:'Schválit výkaz'})).toHaveCount(0)

    const payroll = await login('payroll')
    await openPilotMonth(payroll)
    await expect(payroll.getByText('Měsíc lze uzavřít po schválení všech výkazů.')).toBeVisible()
    await expect(payroll.getByRole('button', {name:'Uzavřít měsíc'})).toHaveCount(0)

    await navigate(employee, 'Výkaz')
    await openRequests(employee)
    await employee.getByRole('button', {name:'Doplnit čas a propustku'}).click()
    const actual = employee.getByRole('dialog', {name:'Doplnit skutečnost'})
    await expect(actual.getByLabel('Skutečný odchod')).toHaveValue(visitDate + 'T09:15')
    const chooseFile = employee.waitForEvent('filechooser')
    await actual.getByRole('button', {name:'Vyfotit propustku'}).click()
    await (await chooseFile).setFiles(await documentPhoto(employee))
    const preview = employee.getByRole('dialog', {name:'Doložit propustku'})
    await expect(preview.getByRole('img')).toBeVisible()
    const worker = employee.waitForResponse(response => /\/assets\/document-scan\.worker-[^/]+\.js$/.test(response.url()))
    await preview.getByRole('button', {name:'Najít okraje a narovnat'}).click()
    expect((await worker).headers()['content-security-policy']).toContain("'unsafe-eval'")
    await expect(preview.getByRole('button', {name:'Použít celý snímek'})).toBeVisible({timeout:15000})
    await preview.getByRole('button', {name:'Uložit propustku'}).click()
    await actual.getByRole('button', {name:'Uložit čas a doklad'}).click()
    await expect(employee.getByText('Propustka doložena')).toBeVisible()

    await openRequests(manager)
    await manager.getByRole('button', {name:'Zobrazit propustku'}).click()
    const attachment = manager.getByRole('dialog', {name:'Propustka od lékaře'})
    await expect(attachment.getByRole('img')).toHaveAttribute('src', /^data:image\/jpeg;base64,\/9j\//)
    await manager.keyboard.press('Escape')
    await expect(attachment).toBeHidden()
    await navigate(manager, 'Výkaz')
    await manager.getByRole('button', {name:'Schválit výkaz'}).click()
    await manager.getByRole('dialog', {name:'Schválit výkaz'}).getByRole('button', {name:'Potvrdit schválení'}).click()
    await expect(manager.getByRole('status')).toContainText('Výkaz byl schválen.')

    await payroll.getByRole('button', {name:'Zobrazit výkaz · ' + credentials.employee.name}).click()
    await expect(payroll.getByRole('toolbar',{name:'Nástroje výkazu'}).getByText('Výkaz schválen', {exact:true})).toBeVisible()
    await navigate(payroll, 'Uzávěrka')
    await payroll.getByRole('button', {name:'Uzavřít měsíc'}).click()
    const close = payroll.getByRole('dialog', {name:'Uzavřít měsíc'})
    await expect(close).toContainText(month)
    await close.getByRole('button', {name:'Uzavřít měsíc'}).click()
    const download = payroll.waitForEvent('download')
    await payroll.getByRole('button', {name:'Stáhnout CSV'}).click()
    const csv = await download
    expect(csv.suggestedFilename()).toBe('dochazka-' + month + '-v1.csv')
    const content = await readFile(await csv.path(), 'utf8')
    expect(content).toContain(credentials.employee.login + ';' + visitDate + ';LEKAR;')
    expect(content.match(/;PRACE;/g)).toHaveLength(2)
    expect(content).not.toContain('base64')
    console.log('Docker browser workflow OK (employee, manager, payroll)')
} finally {
    await browser.close()
}
