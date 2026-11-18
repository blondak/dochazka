import { readFile } from 'node:fs/promises'
import { expect, test } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'
import { documentPhoto } from './fixtures.mjs'

const fixedTime = new Date('2026-11-17T12:00:00+01:00')
const employee = {id:1, name:'Jana Testová', number:'100', roles:['employee'], attendance_enabled:true, team:'Vývoj'}
const manager = {id:2, name:'Karel Vedoucí', number:'200', roles:['manager'], attendance_enabled:true, team:'Vývoj'}
const accountant = {id:3, name:'Klára Mzdová', number:'300', roles:['payroll'], attendance_enabled:false, team:'Mzdy'}
const administrator = {id:4, name:'Alois Správce', number:'400', roles:['admin'], attendance_enabled:false, team:'IT'}
const staff = {...employee, sid:'S-1-5-21-1-2-3-100', login:'jana', starts:'2026-01-01', ends:null}
const pending = {
    id:50,
    kind:'LEKAR',
    begins:'2026-11-16 09:00:00+01',
    ends:'2026-11-16 10:00:00+01',
    note:'Návštěva 16. listopadu',
    status:'pending',
    reason:'',
    has_proof:false,
}

function sheet(overrides = {}) {
    return {
        rows:[], seconds:0, pending:0, missing_proofs:0, approved:false, issues:[],
        vacation_conflict:false, fingerprint:'test-fingerprint', requests:[], ...overrides,
    }
}

async function openApp(page, user, endpoints = {}, path = '/') {
    await page.clock.setFixedTime(fixedTime)
    const handlers = {
        'setup-status': () => ({available:false}),
        'passkey-status': () => ({available:false}),
        me: () => user || {status:401, body:{error:'Přihlaste se.'}},
        people: () => [user],
        ...endpoints,
    }
    await page.route('**/api/v1/*', async route => {
        const endpoint = new URL(route.request().url()).pathname.split('/').pop()
        const handler = handlers[endpoint]
        expect(handler, `Unexpected API operation: ${endpoint}`).toBeDefined()
        const result = await handler(route.request().postDataJSON())
        await route.fulfill(result?.status ? {status:result.status, json:result.body} : {json:result})
    })
    await page.goto(path)
}

async function assertFits(page) {
    await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth))
        .toBeLessThanOrEqual(page.viewportSize().width)
}

async function assertAccessible(page) {
    const results = await new AxeBuilder({page}).withTags(['wcag2a','wcag2aa','wcag21a','wcag21aa']).analyze()
    expect(results.violations.map(({id,nodes}) => ({id, nodes:nodes.map(node => node.target)}))).toEqual([])
}

test('first run and invitation support keyboard, labels and small screens', async ({page}) => {
    await page.setViewportSize({width:320,height:720})
    let setupData
    let activation
    await openApp(page, null, {
        'setup-status':() => ({available:true}),
        setup:data => { setupData = data; return {ok:true} },
        activate:data => { activation = data; return {ok:true} },
    })
    await expect(page.getByRole('heading', {name:'První spuštění'})).toBeVisible()
    await assertFits(page)
    await assertAccessible(page)
    await page.getByLabel('Instalační kód', {exact:true}).fill('local-installation-token')
    await page.getByLabel('Jméno správce').fill(administrator.name)
    await page.getByLabel('Uživatelské jméno').fill('spravce')
    const password = page.getByLabel('Heslo', {exact:true})
    await password.fill('Generated-secret-1')
    const reveal = page.getByRole('button', {name:'Zobrazit heslo'}).nth(1)
    await reveal.focus()
    await page.keyboard.press('Space')
    await expect(password).toHaveAttribute('type', 'text')
    await expect(page.getByRole('button', {name:'Skrýt heslo'})).toBeFocused()
    await page.keyboard.press('Space')
    await expect(password).toHaveAttribute('type', 'password')
    await page.getByLabel('Heslo znovu').fill('Generated-secret-1')
    await page.getByRole('button', {name:'Založit správce'}).click()
    await expect(page.getByLabel('Uživatelské jméno')).toHaveValue('spravce')
    expect(setupData.login).toBe('spravce')
    await page.getByLabel('Heslo', {exact:true}).fill('Forgotten-input-2')
    await page.getByRole('button', {name:'Mám pozvánku'}).click()
    await expect(page.getByLabel('Nové heslo')).toHaveValue('')
    await page.getByLabel('Kód pozvánky').fill('single-use-code')
    await page.getByLabel('Nové heslo').fill('Generated-secret-2')
    await page.getByRole('button', {name:'Nastavit heslo'}).click()
    await expect(page.getByRole('status')).toContainText('Heslo bylo nastaveno.')
    expect(activation).toEqual({code:'single-use-code',password:'Generated-secret-2'})
})

test('employee sees a new doctor request in its month with the correct local time', async ({page}) => {
    let submitted
    await openApp(page, employee, {
        sheet:({month}) => month === '2026-12' && submitted ? sheet({
            pending:1, requests:[{...pending, begins:'2026-12-04 08:15:00+01', ends:'2026-12-04 09:30:00+01'}],
        }) : sheet(),
        request:data => { submitted = data; return {id:pending.id} },
    })
    await page.getByRole('button', {name:'Žádosti', exact:true}).click()
    await page.getByRole('button', {name:'Nová žádost nebo oprava'}).click()
    await page.getByLabel('Datum začátku').fill('2026-12-04')
    await page.getByLabel('Čas začátku (ČR)').fill('08:15')
    await page.getByLabel('Datum konce').fill('2026-12-04')
    await page.getByLabel('Čas konce (ČR)').fill('09:30')
    await page.getByRole('button', {name:'Odeslat žádost'}).click()
    await expect(page.getByLabel('Období')).toHaveValue('2026-12')
    await expect(page.getByText('Čeká na schválení', {exact:true})).toBeVisible()
    await expect(page.getByRole('dialog', {name:'Vybrat datum'})).toHaveCount(0)
    expect(submitted).toEqual({
        employee:employee.id, kind:'LEKAR', note:'', from:'2026-12-04T08:15', to:'2026-12-04T09:30',
    })
    await expect(page.getByRole('button', {name:'Vybrat fotografii'})).toHaveCount(0)
})

test('one-day vacation remains one day when the API timestamps are in UTC', async ({page}) => {
    let submitted
    const vacation = {
        ...pending, kind:'DOVOLENA', note:'',
        begins:'2026-11-22 23:00:00+00', ends:'2026-11-23 23:00:00+00',
    }
    await openApp(page, employee, {
        sheet:() => submitted ? sheet({pending:1, requests:[vacation]}) : sheet(),
        request:data => { submitted = data; return {id:vacation.id} },
    })
    await page.getByRole('button', {name:'Žádosti', exact:true}).click()
    await page.getByRole('button', {name:'Nová žádost nebo oprava'}).click()
    await page.getByRole('combobox', {name:'Druh', exact:true}).click()
    await page.getByRole('option', {name:'Dovolená', exact:true}).click()
    await page.getByLabel('První den').fill('2026-11-23')
    await page.getByLabel('Poslední den včetně').fill('2026-11-23')
    await page.getByRole('button', {name:'Odeslat žádost'}).click()
    await expect(page.getByRole('article', {name:'Dovolená · 23. 11. 2026'})).toBeVisible()
    expect(submitted).toEqual({
        employee:employee.id, kind:'DOVOLENA', note:'', from:'2026-11-23', to:'2026-11-23',
    })
})

test.describe('mobile proof capture', () => {
    test.use({hasTouch:true, isMobile:true, viewport:{width:320,height:720}})

    test('the camera photo is previewed locally before it is submitted', async ({page}) => {
        let upload
        const approved = {...pending, status:'approved'}
        const future = {...approved, id:51, begins:'2026-11-20 09:00:00+01', ends:'2026-11-20 10:00:00+01'}
        await openApp(page, employee, {
            sheet:() => sheet({missing_proofs:upload ? 1 : 2, requests:[{...approved, has_proof:!!upload},future]}),
            'request-proof-upload':data => { upload = data; return {ok:true} },
        })
        await page.getByRole('button', {name:'Otevřít žádosti'}).click()
        await expect(page.getByRole('button', {name:'Vyfotit propustku'})).toHaveCount(1)
        await assertFits(page)
        const chooser = page.waitForEvent('filechooser')
        await page.getByRole('button', {name:'Vyfotit propustku'}).click()
        await (await chooser).setFiles(await documentPhoto(page))
        const dialog = page.getByRole('dialog', {name:'Doložit propustku'})
        const preview = dialog.getByRole('img', {name:'Náhled propustky před uložením'})
        await expect(preview).toBeVisible()
        expect(upload).toBeUndefined()
        const original = await preview.getAttribute('src')
        await dialog.getByRole('button', {name:'Najít okraje a narovnat'}).click()
        await expect(dialog.getByRole('button', {name:'Použít celý snímek'})).toBeVisible({timeout:15000})
        await expect(preview).not.toHaveAttribute('src', original)
        await dialog.getByRole('button', {name:'Použít celý snímek'}).click()
        await expect(preview).toHaveAttribute('src', original)
        expect(upload).toBeUndefined()
        await dialog.getByRole('button', {name:'Uložit propustku'}).click()
        await expect(page.getByText('Propustka doložena')).toBeVisible()
        expect(upload.id).toBe(approved.id)
        expect(Buffer.from(upload.photo, 'base64').subarray(0,2)).toEqual(Buffer.from([0xff,0xd8]))
    })
})

test('manager sees only the selected sheet and decides a request from its own dialog', async ({page}) => {
    let unavailable = true
    let decision
    await openApp(page, manager, {
        people:() => [employee,manager],
        sheet:({employee:selected}) => selected === employee.id && unavailable
            ? {status:503, body:{error:'Výkaz je dočasně nedostupný.'}}
            : sheet({seconds:selected === manager.id ? 19800 : 27000, pending:decision ? 0 : 1,
                requests:[{...pending, status:decision?.status || 'pending'}]}),
        decide:data => { decision = data; return {ok:true} },
    })
    await expect(page.getByText('5 h 30 min')).toBeVisible()
    await expect(page.getByText('Váš výkaz může schválit druhý vedoucí ve vašem týmu.')).toBeVisible()
    await expect(page.getByRole('button', {name:'Schválit výkaz'})).toHaveCount(0)
    await page.getByRole('button', {name:'Žádosti', exact:true}).click()
    await expect(page.getByText('Vaše žádosti může vyřídit druhý vedoucí ve vašem týmu.')).toBeVisible()
    await expect(page.getByRole('button', {name:'Schválit', exact:true})).toHaveCount(0)
    await page.getByRole('button', {name:'Výkaz', exact:true}).click()
    await page.getByRole('combobox', {name:'Osoba'}).click()
    await page.getByRole('option', {name:employee.name}).click()
    await expect(page.getByRole('alert')).toContainText('Výkaz je dočasně nedostupný.')
    await expect(page.getByText('5 h 30 min')).toHaveCount(0)
    unavailable = false
    await page.getByRole('button', {name:'Načíst znovu'}).click()
    await expect(page.getByText('7 h 30 min')).toBeVisible()
    await page.getByRole('button', {name:'Vyřídit žádosti'}).click()
    await page.getByRole('button', {name:'Zamítnout', exact:true}).click()
    const dialog = page.getByRole('dialog', {name:'Zamítnout žádost'})
    await expect(dialog).toContainText(employee.name)
    await expect(dialog.getByRole('button', {name:'Zamítnout žádost'})).toBeDisabled()
    await dialog.getByLabel('Důvod rozhodnutí').fill('Ověřit datum')
    await assertAccessible(page)
    await page.keyboard.press('Escape')
    await expect(dialog).toBeHidden()
    expect(decision).toBeUndefined()
    await page.getByRole('button', {name:'Zamítnout', exact:true}).click()
    await expect(dialog.getByLabel('Důvod rozhodnutí')).toHaveValue('')
    await dialog.getByLabel('Důvod rozhodnutí').fill('Návštěva se nekonala')
    await dialog.getByRole('button', {name:'Zamítnout žádost'}).click()
    await expect(page.getByText('Zamítnuto', {exact:true})).toBeVisible()
    expect(decision).toEqual({id:pending.id, status:'rejected', reason:'Návštěva se nekonala'})
})

test('a stale approval refreshes the sheet before a manager can retry', async ({page}) => {
    let fingerprint = 'reviewed'
    let approved = false
    await openApp(page, manager, {
        people:() => [manager,employee],
        sheet:() => sheet({fingerprint, approved, seconds:fingerprint === 'reviewed' ? 7200 : 10800}),
        approve:input => {
            if (input.fingerprint === 'reviewed') {
                fingerprint = 'updated'
                return {status:409, body:{error:'Výkaz se změnil.'}}
            }
            expect(input.fingerprint).toBe('updated')
            expect(input.note).toBe('Zkontrolováno v dialogu.')
            approved = true
            return {ok:true}
        },
    })
    await page.getByRole('combobox', {name:'Osoba'}).click()
    await page.getByRole('option', {name:employee.name}).click()
    await expect(page.getByText('2 h 0 min')).toBeVisible()
    await page.getByRole('button', {name:'Schválit výkaz'}).click()
    const approval = page.getByRole('dialog', {name:'Schválit výkaz'})
    await expect(approval).toContainText(employee.name)
    await approval.getByRole('button', {name:'Potvrdit schválení'}).click()
    await expect(page.getByRole('alert')).toContainText('Výkaz se změnil.')
    await expect(page.getByText('3 h 0 min')).toBeVisible()
    await page.getByRole('button', {name:'Schválit výkaz'}).click()
    await approval.getByLabel('Poznámka ke schválení (nepovinná)').fill('Zkontrolováno v dialogu.')
    await approval.getByRole('button', {name:'Potvrdit schválení'}).click()
    await expect(page.getByRole('status')).toContainText('Výkaz byl schválen.')
})

test('a manager sees the current day and recorded worked time for the team', async ({page}) => {
    let loads = 0
    await openApp(page, manager, {
        people:() => [manager,employee],
        sheet:() => sheet(),
        'daily-attendance':() => ({
            day:'2026-11-17', updated:'2026-11-17T12:00:00+01:00',
            people:[
                {id:employee.id, name:employee.name, status:++loads === 1 ? 'present' : 'finished', worked_seconds:loads === 1 ? null : 27000},
                {id:manager.id, name:manager.name, status:'absent', worked_seconds:null},
            ],
        }),
    })
    await page.getByRole('button', {name:'Denní přehled', exact:true}).click()
    await expect(page.getByText('17. 11. 2026', {exact:true})).toBeVisible()
    const employeeRow = page.getByRole('row').filter({hasText:employee.name})
    await expect(employeeRow).toContainText('Přítomen')
    await expect(page.getByRole('row').filter({hasText:manager.name})).toContainText('Nepřítomen')
    await page.getByRole('button', {name:'Obnovit', exact:true}).click()
    await expect(employeeRow).toContainText('Odpracováno')
    await expect(employeeRow).toContainText('7 h 30 min')
    await expect(page).toHaveURL(/sekce=denni-prehled/)
})

test('work rows must be from the same day and a manager confirms the merged range', async ({page}) => {
    let merged
    const row = (day, from, to) => ({
        day:'2026-11-' + day, kind:'PRACE', from:'2026-11-' + day + 'T' + from + ':00+01:00',
        to:'2026-11-' + day + 'T' + to + ':00+01:00',
    })
    await openApp(page, manager, {
        people: () => [manager, employee],
        sheet: () => sheet({rows: merged ? [row('17','08:00','12:00')] : [
            row('17','08:00','10:00'), row('17','10:05','12:00'), row('18','08:00','10:00'),
        ]}),
        'merge-work-rows': input => { merged = input; return {ok:true} },
    })
    await page.getByRole('combobox', {name:'Osoba'}).click()
    await page.getByRole('option', {name:employee.name}).click()
    const boxes = page.getByRole('checkbox', {name:/Vybrat pracovní úsek/})
    const merge = page.getByRole('button', {name:'Spojit vybrané úseky'})
    await expect(merge).toBeDisabled()
    await boxes.nth(0).check()
    await expect(boxes.nth(2)).toBeDisabled()
    await boxes.nth(1).check()
    await merge.click()
    const dialog = page.getByRole('dialog', {name:'Spojit pracovní úseky'})
    await expect(dialog).toContainText('08:00 – 12:00')
    expect(merged).toBeUndefined()
    await dialog.getByRole('button', {name:'Potvrdit spojení'}).click()
    await expect(page.getByRole('status')).toContainText('Vybrané pracovní úseky byly spojeny.')
    expect(merged).toEqual({employee:employee.id, month:'2026-11', fingerprint:'test-fingerprint', rows:[0,1]})
})

test('administrator changes the global automatic merge limit', async ({page}) => {
    let saved
    await openApp(page, administrator, {
        people: () => [administrator, employee],
        'attendance-merge-settings': () => ({gap_minutes:0}),
        'attendance-merge-limit': input => { saved = input; return input },
    })
    await page.getByRole('button', {name:'Nastavení docházky', exact:true}).click()
    const limit = page.getByRole('spinbutton', {name:'Maximální mezera v minutách'})
    await expect(limit).toHaveValue('0')
    await limit.fill('5')
    await page.getByRole('button', {name:'Uložit limit'}).click()
    await expect(page.getByRole('status')).toContainText('Limit automatického spojování byl uložen.')
    expect(saved).toEqual({gap_minutes:5})
})

test('closing a passkey removal confirmation preserves the settings dialog', async ({page}) => {
    const passkey = {id:'synthetic-key', label:'Testovací telefon', created:'2026-11-17T08:00:00+01:00'}
    let removed
    await openApp(page, employee, {
        'passkey-status':() => ({available:true}),
        sheet:() => sheet(),
        passkeys:() => ({keys:[passkey]}),
        'passkey-remove':data => { removed = data; return {ok:true} },
    })
    await page.getByRole('button', {name:'Nastavení účtu'}).click()
    const settings = page.getByRole('dialog', {name:'Nastavení účtu'})
    await settings.getByRole('button', {name:'Odebrat passkey ' + passkey.label}).click()
    const confirmation = page.getByRole('dialog', {name:'Odebrat passkey', exact:true})
    await expect(confirmation).toContainText(passkey.label)
    await page.keyboard.press('Escape')
    await expect(confirmation).toBeHidden()
    await expect(settings).toBeVisible()
    expect(removed).toBeUndefined()
    await settings.getByRole('button', {name:'Odebrat passkey ' + passkey.label}).click()
    await confirmation.getByRole('button', {name:'Odebrat passkey', exact:true}).click()
    await expect(settings.getByRole('status')).toContainText('Passkey byla odebrána.')
    expect(removed).toEqual({id:passkey.id})
})

test('technician verifies a single service session without signing in to the app', async ({page}) => {
    const code = 'ab'.repeat(32)
    let verification
    await openApp(page, null, {
        'service-session-details': input => {
            expect(input).toEqual({code})
            return {station:'PC-10', from:'2026-11-17T18:00:00+01:00'}
        },
        'service-session-verify': input => { verification = input; return {ok:true} },
    }, '/#service-session=' + code)
    await expect(page.getByRole('heading', {name:'Servisní přihlášení'})).toBeVisible()
    await expect(page.getByText('PC-10', {exact:true})).toBeVisible()
    await expect(page).not.toHaveURL(/#service-session/)
    await page.getByLabel('Účet správce').fill('technik')
    await page.getByLabel('Heslo správce').fill('Test-password')
    await page.getByRole('button', {name:'Potvrdit heslem'}).click()
    await expect(page.getByText('Celá relace je označena jako servisní.')).toBeVisible()
    expect(verification).toEqual({code, login:'technik', password:'Test-password'})
    await expect(page.getByRole('button', {name:'Odhlásit', exact:true})).toHaveCount(0)
})

test('an administrator marks a past session and can filter it back into view', async ({page}) => {
    let change
    const session = {
        station:'PC-10', device:10, session:'boot.1', from:'2026-11-17T18:00:00+01:00', to:'2026-11-17T19:00:00+01:00',
    }
    await openApp(page, administrator, {
        people: () => [administrator, employee],
        sheet: () => sheet({sessions:[{...session, service:!!change?.service}], seconds: change?.service ? 0 : 3600}),
        'service-session-change': input => { change = input; return {ok:true} },
    })
    await page.getByRole('button', {name:'Výkaz', exact:true}).click()
    await page.getByRole('button', {name:'Relace počítače', exact:true}).click()
    await page.getByRole('button', {name:'Označit jako servisní', exact:true}).click()
    await page.getByRole('dialog').getByRole('button', {name:'Označit jako servisní', exact:true}).click()
    await expect(page.getByText('PC-10', {exact:true})).toHaveCount(0)
    await page.getByRole('button', {name:'Zobrazit servisní relace', exact:true}).click()
    await expect(page.getByText('PC-10', {exact:true})).toBeVisible()
    await expect(page.getByRole('button', {name:'Zrušit servisní označení'})).toBeVisible()
    expect(change).toEqual({employee:employee.id, device:10, session:'boot.1', service:true})
})

test('payroll opens a specific sheet, closes a ready month and downloads CSV', async ({page}) => {
    let closed
    let inspected
    const csv = 'osobni_cislo;datum\r\n100;2026-11-16\r\n'
    const payroll = () => ({
        people:[{...employee, approved:true}], changed:false,
        versions:closed ? [{version:1, created:'2026-11-17 12:01:00+01'}] : [],
    })
    await openApp(page, accountant, {
        people:() => [employee,accountant],
        payroll,
        sheet:scope => { inspected = scope; return sheet({approved:true}) },
        close:data => { closed = data; return payroll() },
        download:data => {
            expect(data).toEqual({month:'2026-11', version:1})
            return {csv}
        },
    })
    await page.getByRole('button', {name:'Zobrazit výkaz · ' + employee.name}).click()
    await expect(page.getByRole('heading', {name:'Výkaz', exact:true})).toBeFocused()
    expect(inspected).toEqual({employee:employee.id, month:'2026-11'})
    await page.getByRole('button', {name:'Uzávěrka', exact:true}).click()
    await page.getByRole('button', {name:'Uzavřít měsíc'}).click()
    const dialog = page.getByRole('dialog', {name:'Uzavřít měsíc'})
    await expect(dialog).toContainText('2026-11')
    await dialog.getByRole('button', {name:'Uzavřít měsíc'}).click()
    await expect(page.getByText('Export pro aktuální podklady je připravený.')).toBeVisible()
    expect(closed.month).toBe('2026-11')
    const download = page.waitForEvent('download')
    await page.getByRole('button', {name:'Stáhnout CSV'}).click()
    expect((await download).suggestedFilename()).toBe('dochazka-2026-11-v1.csv')
    expect(await readFile(await (await download).path(),'utf8')).toBe(csv)
})

test('administrator can find an employee and confirm sensitive device actions on mobile', async ({page}) => {
    await page.setViewportSize({width:320,height:720})
    let invitation
    let revoked
    await openApp(page, administrator, {
        people:() => [staff,administrator],
        invite:data => { invitation = data; return {code:'single-use-admin-code'} },
        devices:() => [{id:9, name:'Testovací PC', seen:'', revoked:!!revoked, warnings:''}],
        revoke:data => { revoked = data; return {ok:true} },
    })
    await assertFits(page)
    const edit = page.getByRole('button', {name:'Upravit ' + employee.name})
    await edit.hover()
    await expect(page.getByRole('tooltip')).toHaveText('Upravit')
    await edit.click()
    await expect(page.getByRole('textbox', {name:'Jméno', exact:true})).toBeFocused()
    await expect(page.getByRole('textbox', {name:'Doménový SID'})).not.toBeEditable()
    await page.getByRole('dialog', {name:'Upravit zaměstnance'}).getByRole('button', {name:'Zrušit',exact:true}).click()
    await page.getByRole('button', {name:'Pozvánka pro ' + employee.name}).click()
    const dialog = page.getByRole('dialog', {name:'Vydat pozvánku'})
    await dialog.getByRole('button', {name:'Vydat pozvánku'}).click()
    await expect(page.getByText('single-use-admin-code')).toBeVisible()
    expect(invitation).toEqual({id:employee.id})
    await page.getByRole('button', {name:'Skrýt kód'}).click()
    await expect(page.getByText('single-use-admin-code')).toHaveCount(0)
    await page.getByRole('button', {name:'Zařízení', exact:true}).click()
    await page.getByRole('button', {name:'Odebrat přístup'}).click()
    const revoke = page.getByRole('dialog', {name:'Odebrat přístup stanice'})
    await expect(revoke).toContainText('Testovací PC')
    expect(revoked).toBeUndefined()
    await revoke.getByRole('button', {name:'Odebrat přístup'}).click()
    await expect(page.getByText('Přístup odebrán', {exact:true})).toBeVisible()
    expect(revoked).toEqual({id:9})
    await assertFits(page)
})

test('table toolbars open creation dialogs and the directory can restore an archived account', async ({page}) => {
    await page.setViewportSize({width:390,height:780})
    let created
    let registered
    let archiveChanged
    const archived = {...staff, archived_on:'2026-11-17'}
    await openApp(page, administrator, {
        people:() => [administrator, {...archived, archived_on:archiveChanged ? null : archived.archived_on}],
        employee:data => { created = data; return {id:99} },
        'employee-archive':data => { archiveChanged = data; return {ok:true} },
        devices:() => [],
        register:data => { registered = data; return {code:'local-test-code'} },
    })
    const directory = page.getByRole('toolbar', {name:'Nástroje adresáře'})
    await directory.getByRole('button', {name:'Nový zaměstnanec'}).click()
    const editor = page.getByRole('dialog', {name:'Nový zaměstnanec'})
    await editor.getByRole('textbox', {name:'Jméno',exact:true}).fill('Vedoucí účtárny')
    const employeeNumber = editor.getByLabel('Osobní číslo')
    await expect(employeeNumber).toHaveAttribute('required', '')
    await editor.getByLabel('Uživatelské jméno').fill('vedouci-uctarna')
    await editor.getByLabel('Tým').fill('IT')
    await editor.getByRole('checkbox', {name:'Zaměstnanec',exact:true}).uncheck()
    await editor.getByRole('checkbox', {name:'Vedoucí',exact:true}).check()
    await editor.getByRole('checkbox', {name:'Mzdová účetní',exact:true}).check()
    await editor.getByRole('checkbox', {name:'Zpracovávat docházku'}).uncheck()
    await expect(editor.getByLabel('Osobní číslo (nepovinné)')).toBeVisible()
    await expect(employeeNumber).not.toHaveAttribute('required')
    await editor.getByRole('button', {name:'Uložit zaměstnance'}).click()
    await expect(editor).toBeHidden()
    expect(created.roles).toEqual(['manager','payroll'])
    expect(created.attendance_enabled).toBe(false)
    expect(created.number).toBe('')
    await expect(page.getByRole('cell', {name:employee.name})).toHaveCount(0)
    await directory.getByRole('checkbox', {name:'Zobrazit archivované'}).check()
    await page.getByRole('button', {name:'Zrušit archivaci · ' + employee.name}).click()
    await page.getByRole('dialog', {name:'Zrušit archivaci'}).getByRole('button', {name:'Zrušit archivaci'}).click()
    expect(archiveChanged).toEqual({id:employee.id, archived:false})

    await page.getByRole('button', {name:'Zařízení',exact:true}).click()
    await page.getByRole('toolbar', {name:'Nástroje zařízení'}).getByRole('button', {name:'Přidat zařízení'}).click()
    const station = page.getByRole('dialog', {name:'Přidat zařízení'})
    await station.getByLabel('Název stanice').fill('Testovací PC')
    await station.getByRole('button', {name:'Vydat kód'}).click()
    await expect(page.getByText('local-test-code')).toBeVisible()
    expect(registered).toEqual({name:'Testovací PC'})
    await assertFits(page)
})

test('browser history restores section, person and month and closes an open editor', async ({page}) => {
    await openApp(page, manager, {
        people:() => [manager,employee],
        sheet:({employee:id, month}) => sheet({
            seconds:id === manager.id ? 10800 : (month === '2026-11' ? 7200 : 3600),
        }),
    })
    await expect(page.getByText('3 h 0 min')).toBeVisible()
    await page.getByRole('combobox', {name:'Osoba'}).click()
    await page.getByRole('option', {name:employee.name}).click()
    await expect(page.getByText('2 h 0 min')).toBeVisible()
    await page.getByRole('button', {name:'Předchozí měsíc'}).click()
    await expect(page.getByText('1 h 0 min')).toBeVisible()
    await expect(page).toHaveURL(/\?sekce=vykaz&mesic=2026-10$/)
    await page.getByRole('navigation').getByRole('button', {name:'Žádosti', exact:true}).click()
    await page.getByRole('button', {name:'Nová žádost nebo oprava'}).click()
    const requestEditor = page.getByRole('dialog', {name:/Nová žádost nebo oprava/})
    await expect(requestEditor).toBeVisible()
    await page.goBack()
    await expect(requestEditor).toBeHidden()
    await expect(page.getByRole('heading', {name:'Žádosti', exact:true})).toBeVisible()
    await page.goBack()
    await expect(page.getByText('1 h 0 min')).toBeVisible()
    await page.goBack()
    await expect(page.getByText('2 h 0 min')).toBeVisible()
    await expect(page).toHaveURL(/\?sekce=vykaz&mesic=2026-11$/)
    await page.goBack()
    await expect(page.getByText('3 h 0 min')).toBeVisible()
    await page.goForward()
    await expect(page.getByText('2 h 0 min')).toBeVisible()
    await page.reload()
    await expect(page.getByText('2 h 0 min')).toBeVisible()
    await page.goto('/?sekce=zadosti&mesic=2026-09')
    await expect(page.getByRole('heading', {name:'Žádosti', exact:true})).toBeVisible()
    await expect(page.getByLabel('Období')).toHaveValue('2026-09')
    await page.goto('/?sekce=zarizeni&mesic=neplatne')
    await expect(page.getByText('3 h 0 min')).toBeVisible()
    await expect(page).toHaveURL(/\?sekce=vykaz&mesic=2026-11$/)
})

test('a technical administrator opens employee records from the directory without an own sheet', async ({page}) => {
    const calls = []
    await openApp(page, administrator, {
        people:() => [administrator,staff],
        sheet:scope => {
            calls.push(scope.employee)
            return sheet()
        },
    })
    await page.getByRole('button', {name:'Otevřít výkaz · ' + employee.name}).click()
    await expect(page.getByRole('heading', {name:'Průběh měsíce'})).toBeVisible()
    await page.goBack()
    await page.getByRole('button', {name:'Otevřít žádosti · ' + employee.name}).click()
    await expect(page.getByRole('heading', {name:'Žádosti za období'})).toBeVisible()
    await expect(page.getByRole('button', {name:'Nová žádost nebo oprava'})).toHaveCount(0)
    expect(calls).toEqual([employee.id, employee.id])
})
