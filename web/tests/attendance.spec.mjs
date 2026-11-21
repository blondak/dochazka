import { readFile } from 'node:fs/promises'
import { expect, test } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'
import { documentPhoto } from './fixtures.mjs'

const fixedTime = new Date('2026-11-17T12:00:00+01:00')
const employee = {id:1, name:'Jana Testová', number:'100', roles:['employee'], attendance_enabled:true, org_unit_id:1, org_unit_path:'Vývoj', managed:true}
const manager = {id:2, name:'Karel Vedoucí', number:'200', roles:['manager'], attendance_enabled:true, org_unit_id:1, org_unit_path:'Vývoj', managed:true}
const accountant = {id:3, name:'Klára Mzdová', number:'300', roles:['payroll'], attendance_enabled:false, org_unit_id:2, org_unit_path:'Mzdy', managed:false}
const administrator = {id:4, name:'Alois Správce', number:'400', roles:['admin'], attendance_enabled:false, org_unit_id:3, org_unit_path:'IT', managed:false}
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
        approval_blockers:[], approval_note_required:false, rows:[], seconds:0, pending:0, missing_proofs:0, approved:false, issues:[],
        vacation_conflict:false, fingerprint:'test-fingerprint', requests:[], ...overrides,
    }
}

async function navigate(page, section) {
    await page.getByRole('button', {name:'Menu', exact:true}).click()
    const nav = page.getByRole('navigation', {name:'Sekce docházky'})
    await nav.getByRole('button', {name:section, exact:true}).click()
    await expect(nav).toBeHidden()
}

async function openApp(page, user, endpoints = {}, path = '/', now = fixedTime) {
    await page.clock.setFixedTime(now)
    const handlers = {
        'setup-status': () => ({available:false}),
        'passkey-status': () => ({available:false}),
        me: () => user || {status:401, body:{error:'Přihlaste se.'}},
        people: () => [user],
        devices: () => [],
        'employee-model-choices': () => ({models:[],assignments:[]}),
        'attendance-accounts': () => [],
        'request-preview': () => ({seconds:14400,days:['2026-11-23']}),
        'org-units': () => [{id:3, name:'IT', path:'IT', parent_id:null}],
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
    await navigate(page, 'Žádosti')
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
    await navigate(page, 'Žádosti')
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

test('approved visits offer actions only when the employee can complete them', async ({page}) => {
    const visit = {...pending, status:'approved', needs_actual:true, requires_proof:true}
    const requests = [visit,
        {...visit, id:51, begins:'2026-11-17T11:00:00+01:00', ends:'2026-11-17T13:00:00+01:00'},
        {...visit, id:52, begins:'2026-11-24T09:00:00+01:00', ends:'2026-11-24T10:00:00+01:00'}]
    await openApp(page, employee, {sheet:() => sheet({requests,missing_proofs:3})}, '/?sekce=zadosti')
    const articles = page.getByRole('article')
    await expect(articles.nth(2)).toContainText('po návratu')
    await expect(articles.nth(2).getByRole('button')).toHaveCount(0)
    await expect(articles.first().getByRole('button', {name:'Doplnit čas a propustku'})).toBeVisible()
    await articles.nth(1).getByRole('button', {name:'Už jsem zpět'}).click()
    await expect(page.getByLabel('Skutečný návrat')).toHaveValue('2026-11-17T12:00')
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
                approval_blockers:decision ? [] : ['Vyřiďte čekající žádosti.'],
                requests:[{...pending, status:decision?.status || 'pending'}]}),
        decide:data => { decision = data; return {ok:true} },
    })
    await expect(page.getByText('5:30')).toBeVisible()
    await expect(page.getByText('Váš výkaz může schválit jiný vedoucí ve vaší nebo nadřazené jednotce.')).toBeVisible()
    await expect(page.getByRole('button', {name:'Schválit výkaz'})).toHaveCount(0)
    await navigate(page, 'Žádosti')
    await expect(page.getByText('Vaše žádosti může vyřídit jiný vedoucí ve vaší nebo nadřazené jednotce.')).toBeVisible()
    await expect(page.getByRole('button', {name:'Schválit', exact:true})).toHaveCount(0)
    await navigate(page, 'Výkaz')
    await page.getByRole('combobox', {name:'Osoba'}).click()
    await page.getByRole('option', {name:employee.name}).click()
    await expect(page.getByRole('alert')).toContainText('Výkaz je dočasně nedostupný.')
    await expect(page.getByText('5:30')).toHaveCount(0)
    unavailable = false
    await page.getByRole('button', {name:'Načíst znovu'}).click()
    await expect(page.getByText('7:30')).toBeVisible()
    await expect(page.getByText('Vyřiďte čekající žádosti.')).toBeVisible()
    await expect(page.getByRole('button', {name:'Schválit výkaz'})).toHaveCount(0)
    await page.getByRole('button', {name:'Vyřídit žádosti'}).click()
    await page.getByRole('button', {name:'Zamítnout', exact:true}).click()
    const dialog = page.getByRole('dialog', {name:'Zamítnout žádost'})
    await expect(dialog).toContainText(employee.name)
    await expect(dialog).toContainText(pending.note)
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
    await expect(page.getByText('Žádné čekající žádosti za zvolené období.')).toBeVisible()
    const filter = page.getByRole('checkbox', {name:'Čeká na schválení (0)'})
    await filter.uncheck()
    await expect(page.getByRole('article').getByText('Zamítnuto', {exact:true})).toBeVisible()
    await expect(page).toHaveURL(/sekce=zadosti/)
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
    await expect(page.getByText('2:00')).toBeVisible()
    await page.getByRole('button', {name:'Schválit výkaz'}).click()
    const approval = page.getByRole('dialog', {name:'Schválit výkaz'})
    await expect(approval).toContainText(employee.name)
    await approval.getByRole('button', {name:'Potvrdit schválení'}).click()
    await expect(page.getByRole('alert')).toContainText('Výkaz se změnil.')
    await expect(page.getByText('3:00')).toBeVisible()
    await page.getByRole('button', {name:'Schválit výkaz'}).click()
    await approval.getByLabel('Poznámka ke schválení (nepovinná)').fill('Zkontrolováno v dialogu.')
    await approval.getByRole('button', {name:'Potvrdit schválení'}).click()
    await expect(page.getByRole('status')).toContainText('Výkaz byl schválen.')
})

test('a manager browses the team attendance by day and opens the matching timesheet', async ({page}) => {
    await page.setViewportSize({width:320,height:720})
    let todayLoads = 0
    const holidayInputs = []
    let openedSheet
    await openApp(page, manager, {
        people:() => todayLoads ? [manager,employee] : [manager],
        sheet:data => { openedSheet = data; return sheet() },
        'holiday-control':data => {
            holidayInputs.push(data)
            return {token:'preview',added:0,changed:0,removed:0,skipped:{missing_model:1,without_shift:0,occupied:1,unchanged:0}}
        },
        'daily-attendance':({day = '2026-11-17'}) => {
            const closed = day === '2026-11-17' && ++todayLoads > 1
            return {day, updated:'2026-11-17T12:00:00+01:00', people:[
                {id:employee.id, name:employee.name, status:closed ? 'finished' : 'present',
                    from:`${day}T08:00:00+01:00`, to:closed ? `${day}T16:00:00+01:00` : null,
                    worked_seconds:closed ? 27000 : null},
                {id:manager.id, name:manager.name, status:'absent', approved_absences:['Dovolená'], from:null, to:null, worked_seconds:null},
            ]}
        },
    }, '/?mesic=2026-10')
    await navigate(page, 'Denní přehled')
    await assertFits(page)
    const holidayIcon = page.getByRole('button', {name:'Kontrolní chod', exact:true}).locator('.p-button-icon')
    expect(await holidayIcon.evaluate(icon => getComputedStyle(icon, '::before').content)).not.toBe('none')
    await expect(page.getByText('17. 11. 2026', {exact:true})).toBeVisible()
    const employeeRow = page.getByRole('row').filter({hasText:employee.name})
    await expect(page.getByText('Schválená žádost · Dovolená')).toBeVisible()
    await expect(employeeRow).toContainText('Aktivní')
    await expect(employeeRow.getByRole('cell', {name:'08:00', exact:true})).toBeVisible()
    await expect(employeeRow.getByRole('cell', {name:'—', exact:true})).toHaveCount(2)
    await expect(page.getByRole('row').filter({hasText:manager.name})).toContainText('Bez aktivní relace')
    await page.getByRole('button', {name:'Obnovit', exact:true}).click()
    await expect(employeeRow).toContainText('Ukončena')
    await expect(employeeRow.getByRole('cell', {name:'16:00', exact:true})).toBeVisible()
    await expect(employeeRow).toContainText('7:30')
    await expect(page).toHaveURL(/sekce=denni-prehled/)
    const previous = page.getByRole('button', {name:'Předchozí den'})
    const next = page.getByRole('button', {name:'Následující den'})
    await expect(next).toBeDisabled()
    await previous.click()
    await expect(page).toHaveURL(/den=2026-11-16/)
    await expect(employeeRow).toContainText('Otevřená relace')
    await next.click()
    await expect(employeeRow).toContainText('Ukončena')
    await page.goBack()
    await expect(employeeRow).toContainText('Otevřená relace')
    await page.goForward()
    await expect(employeeRow).toContainText('Ukončena')
    await previous.click()
    await page.getByRole('button', {name:'Dnes',exact:true}).click()
    await expect(page).not.toHaveURL(/den=/)
    await page.goto('/?sekce=denni-prehled&den=2026-10-31')
    await expect(employeeRow).toContainText('Otevřená relace')
    await expect(page.getByText('31. 10. 2026', {exact:true})).toBeVisible()
    await page.getByRole('button', {name:'Kontrolní chod', exact:true}).click()
    const dialog = page.getByRole('dialog', {name:'Kontrolní chod'})
    await expect(dialog.getByText('Vaše organizační větev')).toBeVisible()
    await expect(dialog.getByLabel('Od', {exact:true})).toHaveValue('2026-10-31')
    await expect(dialog.getByLabel('Do včetně')).toHaveValue('2026-10-31')
    await dialog.getByLabel('Od', {exact:true}).fill('2026-11-16')
    await dialog.getByLabel('Do včetně').fill('2026-11-16')
    await dialog.getByRole('button', {name:'Zobrazit náhled'}).click()
    await expect(dialog.getByRole('status')).toContainText('Bez platného pracovního modelu · 1')
    await expect(dialog.getByRole('status')).toContainText('Existující docházka nebo žádost · 1')
    await expect(dialog.getByRole('button', {name:'Provést změny'})).toHaveCount(0)
    await dialog.getByRole('button', {name:'Obnovit náhled'}).click()
    const expectedRange = {from:'2026-11-16', to:'2026-11-16'}
    await expect.poll(() => holidayInputs).toEqual([expectedRange, expectedRange])
    await page.keyboard.press('Escape')
    await employeeRow.getByRole('button', {name:'Otevřít výkaz · ' + employee.name}).click()
    await expect.poll(() => openedSheet).toEqual({employee:employee.id,month:'2026-10'})
    await expect(page).toHaveURL(/sekce=vykaz/)
    await page.goBack()
    await expect(page).toHaveURL(/den=2026-10-31/)
    await expect(employeeRow).toContainText('Otevřená relace')
})

test('the monthly table hides empty weekends and shows the selected day at desktop and mobile widths', async ({page}) => {
    await page.setViewportSize({width:1600,height:900})
    await openApp(page, employee, {
        sheet:() => sheet({seconds:7200, rows:[
            {day:'2026-10-25', kind:'PRACE', from:'2026-10-25T02:00:00+02:00', to:'2026-10-25T03:00:00+01:00'},
            {day:'2026-10-26', kind:'DOVOLENA', from:'', to:''},
            {day:'2026-10-27', kind:'LEKAR', from:'2026-10-27T09:00:00+01:00', to:'2026-10-27T09:30:00+01:00'},
        ]}),
    }, '/?sekce=vykaz&mesic=2026-10')
    const month = page.getByRole('table', {name:'Dny v měsíci'})
    const detail = page.getByRole('table', {name:'Úseky vybraného dne'})
    await expect(month.getByRole('row')).toHaveCount(24)
    const sunday = month.getByRole('row', {name:/25\. 10\./})
    await expect(sunday).toContainText('2:00')
    await expect(sunday).toHaveClass(/weekend-day/)
    await expect(sunday.getByRole('cell').first()).toHaveCSS('box-shadow', /4px 0px 0px 0px inset/)
    const totals = page.getByRole('table', {name:'Souhrn za měsíc'})
    await expect(totals.getByRole('row', {name:/Přítomnost/})).toContainText('2:00')
    await expect(totals.getByRole('row', {name:/Dovolená/})).toContainText('1')
    await expect(totals.getByRole('row', {name:/Lékař/})).toContainText('0:30')
    await expect(month.getByRole('row', {name:/31\. 10\./})).toHaveCount(0)
    await expect(month.getByRole('row').filter({hasText:'26. 10.'})).toContainText('0:00')
    await expect(detail).toContainText('Práce')
    expect((await page.getByRole('main').boundingBox()).width).toBeGreaterThan(1160)
    expect((await detail.boundingBox()).x).toBeGreaterThan((await month.boundingBox()).x)

    const header = page.getByRole('banner')
    const title = header.getByRole('heading', {name:'Výkaz', exact:true})
    for (const width of [1080, 320]) {
        await page.setViewportSize({width,height:780})
        const alignment = await title.evaluate(heading => {
            const text = document.createRange()
            text.selectNodeContents(heading.lastElementChild)
            const center = element => {
                const rect = element.getBoundingClientRect()
                return rect.top + rect.height / 2
            }
            return {
                lines:text.getClientRects().length,
                menuOffset:Math.abs(center(heading) - center(heading.previousElementSibling)),
                iconOffset:Math.abs(center(heading) - center(heading.firstElementChild)),
            }
        })
        expect(alignment.lines).toBe(1)
        expect(alignment.menuOffset).toBeLessThan(1)
        expect(alignment.iconOffset).toBeLessThan(1)
    }

    await page.setViewportSize({width:390,height:780})
    const vacationCell = month.getByRole('row', {name:/26\. 10\./}).getByRole('cell', {name:'0:00'})
    await vacationCell.scrollIntoViewIfNeeded()
    await vacationCell.click()
    await expect(page.getByRole('heading', {name:/Detail dne.*26\. 10\./})).toBeFocused()
    await expect(month.getByRole('row', {name:/26\. 10\./}).getByRole('cell').first()).toHaveCSS('box-shadow', /4px 0px 0px 0px inset/)
    await expect(sunday).not.toHaveClass(/selected-day/)
    await expect(detail).toContainText('Dovolená')
    await expect(detail).not.toContainText('Práce')
    const monthBox = await month.boundingBox()
    expect((await detail.boundingBox()).y).toBeGreaterThan(monthBox.y + monthBox.height)
    await month.getByRole('button', {name:/30\. 10\./}).press('Enter')
    await expect(detail).toContainText('Pro tento den nejsou evidované úseky.')
    await assertFits(page)
})

test('work row selections stay within a day and the manager confirms the original sheet indexes', async ({page}) => {
    let merged
    const row = (day, from, to) => ({
        day:'2026-11-' + day, kind:'PRACE', from:'2026-11-' + day + 'T' + from + ':00+01:00',
        to:'2026-11-' + day + 'T' + to + ':00+01:00',
    })
    await openApp(page, manager, {
        people: () => [manager, employee],
        sheet: () => sheet({rows: merged ? [row('17','08:00','12:00')] : [
            row('16','08:00','10:00'), row('17','08:00','10:00'), row('17','10:05','12:00'),
        ]}),
        'merge-work-rows': input => { merged = input; return {ok:true} },
    })
    await page.getByRole('combobox', {name:'Osoba'}).click()
    await page.getByRole('option', {name:employee.name}).click()
    const boxes = page.getByRole('checkbox', {name:/Vybrat pracovní úsek/})
    const toolbar = page.getByRole('toolbar',{name:'Nástroje výkazu'})
    await expect(toolbar.getByLabel('Období')).toHaveValue('2026-11')
    await expect(toolbar.getByRole('combobox',{name:'Osoba'})).toBeVisible()
    await expect(toolbar.getByText('Výkaz čeká na schválení')).toBeVisible()
    await expect(toolbar.getByText('Čekající žádosti (0)')).toBeVisible()
    const arrangement = await toolbar.evaluate(el => {
        const controls = Array.from(el.querySelectorAll('.p-toolbar-start .p-button, .p-select, .p-datepicker-input'))
        const boxes = controls.map(node => node.getBoundingClientRect())
        return {heights:boxes.map(box => box.height), tops:boxes.map(box => box.top),
            dividers:['.period-controls','.sheet-actions'].map(selector => getComputedStyle(el.querySelector(selector)).borderInlineStartWidth)}
    })
    expect(Math.max(...arrangement.heights)-Math.min(...arrangement.heights)).toBeLessThan(1)
    expect(Math.max(...arrangement.tops)-Math.min(...arrangement.tops)).toBeLessThan(1)
    expect(arrangement.dividers).toEqual(['1px','1px'])
    await expect(toolbar.getByRole('button',{name:'Spojit vybrané úseky'})).toHaveCount(0)
    const detail = page.getByRole('region',{name:/Detail dne/})
    const merge = detail.getByRole('button', {name:'Spojit vybrané úseky'})
    const intervals = await detail.getByRole('table',{name:'Úseky vybraného dne'}).boundingBox()
    expect((await merge.boundingBox()).y).toBeGreaterThanOrEqual(intervals.y + intervals.height)
    await expect(merge).toBeDisabled()
    await expect(boxes).toHaveCount(2)
    await boxes.nth(0).check()
    const month = page.getByRole('table', {name:'Dny v měsíci'})
    await month.getByRole('button', {name:/16\. 11\./}).click()
    await expect(boxes).toHaveCount(0)
    await month.getByRole('button', {name:/17\. 11\./}).click()
    await expect(boxes.nth(0)).not.toBeChecked()
    await boxes.nth(0).check()
    await boxes.nth(1).check()
    await merge.click()
    const dialog = page.getByRole('dialog', {name:'Spojit pracovní úseky'})
    await expect(dialog).toContainText('08:00 – 12:00')
    expect(merged).toBeUndefined()
    await dialog.getByRole('button', {name:'Potvrdit spojení'}).click()
    await expect(page.getByRole('status').filter({hasText:'Vybrané pracovní úseky byly spojeny.'})).toBeVisible()
    expect(merged).toEqual({employee:employee.id, month:'2026-11', fingerprint:'test-fingerprint', rows:[1,2]})
})

test('administrator changes the global automatic merge limit', async ({page}) => {
    let saved
    await openApp(page, administrator, {
        people: () => [administrator, employee],
        'attendance-merge-settings': () => ({gap_minutes:0}),
        'attendance-merge-limit': input => { saved = input; return input },
    })
    await navigate(page, 'Nastavení docházky')
    const limit = page.getByRole('spinbutton', {name:'Maximální mezera v minutách'})
    await expect(limit).toHaveValue('0')
    await limit.fill('5')
    await page.getByRole('button', {name:'Uložit limit'}).click()
    await expect(page.getByRole('status').filter({hasText:'Limit automatického spojování byl uložen.'})).toBeVisible()
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
    const header = page.getByRole('banner')
    const profile = header.getByRole('button', {name:employee.name, exact:true})
    const menu = page.getByRole('menu', {name:'Uživatelský účet'})
    await expect(profile).toHaveAttribute('aria-expanded','false')
    await expect(page.getByText('Nastavení účtu',{exact:true})).toBeHidden()
    const logout = header.getByRole('button',{name:'Odhlásit',exact:true})
    await expect(logout).toHaveText('')
    await logout.hover()
    await expect(page.getByRole('tooltip')).toHaveText('Odhlásit')
    await profile.press('Enter')
    await expect(menu).toBeVisible()
    await expect(profile).toHaveAttribute('aria-controls', await menu.getAttribute('id'))
    await page.keyboard.press('Escape')
    await expect(menu).toBeHidden()
    await expect(profile).toBeFocused()
    await profile.click()
    await menu.getByRole('menuitem', {name:'Nastavení účtu'}).click()
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
        station:'PC-10', device:10, session:'boot.1', from:'2026-11-17T18:00:00+01:00', to:'2026-11-18T01:00:00+01:00',
    }
    await openApp(page, administrator, {
        people: () => [administrator, employee],
        sheet: () => sheet({sessions:[{...session, service:!!change?.service}], seconds: change?.service ? 0 : 3600}),
        'service-session-change': input => { change = input; return {ok:true} },
    })
    await navigate(page, 'Výkaz')
    const month = page.getByRole('table', {name:'Dny v měsíci'})
    const sessions = page.getByRole('table', {name:'Relace počítače'})
    await expect(month.getByRole('button', {name:/17\. 11\./})).toHaveAttribute('aria-current', 'date')
    await expect(sessions.getByText('PC-10')).toBeVisible()
    await month.getByRole('button', {name:/18\. 11\./}).click()
    await expect(sessions.getByText('PC-10')).toBeVisible()
    await month.getByRole('button', {name:/19\. 11\./}).click()
    await expect(sessions.getByText('PC-10')).toHaveCount(0)
    await month.getByRole('button', {name:/17\. 11\./}).click()
    await page.getByRole('button', {name:'Označit jako servisní', exact:true}).click()
    await page.getByRole('dialog').getByRole('button', {name:'Označit jako servisní', exact:true}).click()
    await expect(page.getByText('PC-10', {exact:true})).toHaveCount(0)
    await page.getByRole('checkbox', {name:'Zobrazit servisní relace', exact:true}).check()
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
    await navigate(page, 'Uzávěrka')
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
        devices:() => [{id:9, name:'Testovací PC', seen:'', agent_version:'0.0.6', revoked:!!revoked, warnings:''}],
        revoke:data => { revoked = data; return {ok:true} },
    })
    await assertFits(page)
    const edit = page.getByRole('button', {name:'Upravit ' + employee.name})
    await edit.hover()
    await expect(page.getByRole('tooltip')).toHaveText('Upravit')
    await edit.click()
    await expect(page.getByRole('textbox', {name:'Jméno', exact:true})).toBeFocused()
    await page.getByRole('tab', {name:'Docházka'}).click()
    await expect(page.getByRole('textbox', {name:'Doménový SID'})).not.toBeEditable()
    await page.getByRole('dialog', {name:'Upravit zaměstnance'}).getByRole('button', {name:'Zrušit',exact:true}).click()
    await page.getByRole('button', {name:'Pozvánka pro ' + employee.name}).click()
    const dialog = page.getByRole('dialog', {name:'Vydat pozvánku'})
    await dialog.getByRole('button', {name:'Vydat pozvánku'}).click()
    await expect(page.getByText('single-use-admin-code')).toBeVisible()
    expect(invitation).toEqual({id:employee.id})
    await page.getByRole('button', {name:'Skrýt kód'}).click()
    await expect(page.getByText('single-use-admin-code')).toHaveCount(0)
    await navigate(page, 'Zařízení')
    await expect(page.getByRole('columnheader', {name:'Verze agenta'})).toBeVisible()
    await expect(page.getByRole('row', {name:/Testovací PC/}).getByRole('cell', {name:'0.0.6', exact:true})).toBeVisible()
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
    await editor.getByRole('combobox', {name:'Organizační jednotka'}).press('ArrowDown')
    await page.getByRole('treeitem', {name:'IT', exact:true}).click()
    await editor.getByRole('tab', {name:'Přístup'}).click()
    await editor.getByLabel('Uživatelské jméno').fill('vedouci-uctarna')
    await editor.getByRole('checkbox', {name:'Zaměstnanec',exact:true}).uncheck()
    await editor.getByRole('checkbox', {name:'Vedoucí',exact:true}).check()
    await editor.getByRole('checkbox', {name:'Mzdová účetní',exact:true}).check()
    await editor.getByRole('tab', {name:'Docházka'}).click()
    await editor.getByRole('checkbox', {name:'Zpracovávat docházku'}).uncheck()
    await editor.getByRole('tab', {name:'Základní údaje'}).click()
    await expect(editor.getByLabel('Osobní číslo (nepovinné)')).toBeVisible()
    await expect(employeeNumber).not.toHaveAttribute('required')
    await editor.getByRole('button', {name:'Uložit zaměstnance'}).click()
    await expect(editor).toBeHidden()
    expect(created.roles).toEqual(['manager','payroll'])
    expect(created.attendance_enabled).toBe(false)
    expect(created.number).toBe('')
    expect(created.org_unit_id).toBe(3)
    await expect(page.getByRole('cell', {name:employee.name})).toHaveCount(0)
    await directory.getByRole('checkbox', {name:'Zobrazit archivované'}).check()
    await page.getByRole('button', {name:'Zrušit archivaci · ' + employee.name}).click()
    await page.getByRole('dialog', {name:'Zrušit archivaci'}).getByRole('button', {name:'Zrušit archivaci'}).click()
    expect(archiveChanged).toEqual({id:employee.id, archived:false})

    await navigate(page, 'Zařízení')
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
    await expect(page.getByText('3:00')).toBeVisible()
    await page.getByRole('combobox', {name:'Osoba'}).click()
    await page.getByRole('option', {name:employee.name}).click()
    await expect(page.getByText('2:00')).toBeVisible()
    await page.getByRole('button', {name:'Předchozí měsíc'}).click()
    await expect(page.getByText('1:00')).toBeVisible()
    await expect(page).toHaveURL(/\?sekce=vykaz&mesic=2026-10$/)
    await navigate(page, 'Žádosti')
    await page.getByRole('button', {name:'Nová žádost nebo oprava'}).click()
    const requestEditor = page.getByRole('dialog', {name:/Nová žádost nebo oprava/})
    await expect(requestEditor).toBeVisible()
    await page.goBack()
    await expect(requestEditor).toBeHidden()
    await expect(page.getByRole('heading', {name:'Žádosti', exact:true})).toBeVisible()
    await page.goBack()
    await expect(page.getByText('1:00')).toBeVisible()
    await page.goBack()
    await expect(page.getByText('2:00')).toBeVisible()
    await expect(page).toHaveURL(/\?sekce=vykaz&mesic=2026-11$/)
    await page.goBack()
    await expect(page.getByText('3:00')).toBeVisible()
    await page.goForward()
    await expect(page.getByText('2:00')).toBeVisible()
    await page.reload()
    await expect(page.getByText('2:00')).toBeVisible()
    await page.goto('/?sekce=zadosti&mesic=2026-09')
    await expect(page.getByRole('heading', {name:'Žádosti', exact:true})).toBeVisible()
    await expect(page.getByLabel('Období')).toHaveValue('2026-09')
    await page.goto('/?sekce=zarizeni&mesic=neplatne')
    await expect(page.getByText('3:00')).toBeVisible()
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
    await expect(page.getByRole('table', {name:'Dny v měsíci'})).toBeVisible()
    await page.getByRole('button', {name:'Upravit zaměstnance · ' + employee.name}).click()
    const profile = page.getByRole('dialog', {name:'Upravit zaměstnance'})
    await expect(profile.getByRole('textbox', {name:'Jméno',exact:true})).toHaveValue(employee.name)
    await profile.getByRole('button', {name:'Zrušit'}).click()
    await expect(profile).toBeHidden()
    await expect(page.getByRole('heading', {name:'Výkaz',exact:true})).toBeVisible()
    await page.goBack()
    await page.getByRole('button', {name:'Otevřít žádosti · ' + employee.name}).click()
    await expect(page.getByRole('heading', {name:'Žádosti za období'})).toBeVisible()
    await expect(page.getByRole('button', {name:'Nová žádost nebo oprava'})).toHaveCount(0)
    expect(calls).toEqual([employee.id, employee.id])
})

test('the selected day and break action advance together after Prague midnight', async ({page}) => {
    const lead = {...manager, attendance_enabled:false}
    await openApp(page, lead, {people:() => [lead,employee], sheet:() => sheet()})
    const nextDay = page.getByRole('table', {name:'Dny v měsíci'}).getByRole('button', {name:/18\. 11\./})
    await nextDay.click()
    const editBreak = page.getByRole('button', {name:'Upravit přestávku'})
    await expect(editBreak).toHaveCount(0)
    await page.clock.setFixedTime(new Date('2026-11-18T00:01:00+01:00'))
    await page.evaluate(() => document.dispatchEvent(new Event('visibilitychange')))
    await expect(nextDay).toHaveAttribute('aria-current', 'date')
    await expect(editBreak).toBeVisible()
})

test('person search includes technical accounts only when the filter is enabled', async ({page}) => {
    const teamLead = {...manager, attendance_enabled:false}
    const technical = {...accountant, managed:true}
    let attendanceEnabled = true
    await openApp(page, teamLead, {
        people:() => [teamLead,{...employee,attendance_enabled:attendanceEnabled},technical,administrator],
        sheet:() => sheet(),
    })
    const selector = page.getByRole('combobox', {name:'Osoba'})
    const search = page.getByRole('searchbox', {name:'Vyhledat osobu'})
    const all = page.getByRole('checkbox', {name:'Všechny osoby'})
    const account = page.getByRole('option', {name:technical.name})
    await selector.click()
    await expect(all).not.toBeChecked()
    await expect(page.getByRole('option', {name:employee.name})).toBeVisible()
    await search.fill('Klára')
    await expect(account).toHaveCount(0)
    await search.press('Tab')
    await expect(all).toBeFocused()
    await all.press('Space')
    await expect(search).toHaveValue('Klára')
    await expect(account).toBeVisible()
    await search.clear()
    await expect(page.getByRole('option', {name:administrator.name})).toHaveCount(0)
    await account.click()
    await expect(page.getByText('Tomuto účtu se docházka nezpracovává.')).toBeVisible()
    await navigate(page, 'Žádosti')
    await selector.click()
    await expect(all).toBeChecked()
    await all.uncheck()
    await expect(account).toHaveCount(0)
    await page.keyboard.press('Escape')
    await expect(selector).toContainText(employee.name)
    await expect(page.getByRole('heading', {name:'Žádosti za období'})).toBeVisible()

    attendanceEnabled = false
    await page.reload()
    await expect(selector).toContainText('Vyberte osobu')
    await selector.click()
    await expect(all).not.toBeChecked()
    await all.check()
    await expect(account).toBeVisible()
})

test('administrator creates a unit and confirms the destination before moving it', async ({page}) => {
    const units = [{id:1, parent_id:null, name:'Provoz', path:'Provoz'}]
    await openApp(page, administrator, {
        people:() => [administrator,staff],
        'org-units':() => units,
        'org-unit':data => {
            const unit = {...data, id:data.id ?? 2, path:data.parent_id ? 'Provoz › ' + data.name : data.name}
            const existing = units.find(item => item.id === unit.id)
            if (existing) Object.assign(existing, unit)
            else units.push(unit)
            return {id:unit.id}
        },
    }, '/?sekce=organizace')
    await page.getByRole('button', {name:'Nová jednotka'}).click()
    await page.getByLabel('Název jednotky').fill('Sklad')
    await page.getByRole('button', {name:'Uložit jednotku'}).click()
    await page.getByRole('button', {name:'Upravit Sklad'}).click()
    await page.getByRole('combobox', {name:'Nadřazená jednotka'}).click()
    await page.getByRole('option', {name:'Provoz'}).click()
    await expect(page.getByRole('dialog')).toContainText('Provoz › Sklad')
    await page.getByRole('button', {name:'Potvrdit přesun'}).click()
    await expect(page.getByRole('dialog')).toBeHidden()
    expect(units[1].parent_id).toBe(1)
    await expect(page.getByRole('button', {name:'Upravit Sklad'})).toHaveCount(0)
})

test('first steps disappears as soon as the last linked agenda contains data', async ({page}) => {
    let devices = []
    await openApp(page, administrator, {
        people:() => [administrator, staff],
        devices:() => devices,
        register:() => {
            devices = [{id:1, name:'Testovací PC', seen:null, revoked:false, registered:false, warnings:null}]
            return {code:'test-code'}
        },
    })
    await navigate(page, 'První kroky')
    await page.getByRole('button', {name:'Otevřít zařízení'}).click()
    await page.getByRole('button', {name:'Přidat zařízení'}).click()
    await page.getByLabel('Název stanice').fill('Testovací PC')
    await page.getByRole('button', {name:'Vydat kód'}).click()
    await page.getByRole('button', {name:'Menu', exact:true}).click()
    const nav = page.getByRole('navigation', {name:'Sekce docházky'})
    await expect(nav.getByRole('button', {name:'První kroky'})).toHaveCount(0)
    await page.goto('/?sekce=prvni-kroky')
    await expect(page.getByRole('heading', {name:'Zaměstnanci', exact:true})).toBeVisible()
})

test('a manager edits a prefilled interval and adds work on a hidden Saturday', async ({page}) => {
    await page.setViewportSize({width:390,height:780})
    const rows = [{day:'2026-11-17', kind:'PRACE', from:'2026-11-17T08:00:03+01:00', to:'2026-11-17T17:05:09+01:00'}]
    let saved
    await openApp(page, manager, {
        people:() => [manager, employee],
        sheet:() => sheet({rows, attendance_start:'2026-11-13', attendance_end:'2026-11-19'}),
        'save-work-row': input => {
            saved = input
            const row = {day:input.day, kind:'PRACE', from:input.day + 'T' + input.from + '+01:00', to:input.day + 'T' + input.to + '+01:00'}
            if (input.row === undefined) rows.push(row)
            else rows[input.row] = row
            return {ok:true}
        },
    })
    await page.getByRole('combobox', {name:'Osoba'}).click()
    await page.getByRole('option', {name:employee.name}).click()
    const month = page.getByRole('table', {name:'Dny v měsíci'})
    await expect(month.getByRole('row')).toHaveCount(6)
    await expect(month.getByRole('button', {name:/14\. 11\./})).toHaveCount(0)
    const detail = page.getByRole('region', {name:/Detail dne/})
    await detail.getByRole('button', {name:/Upravit pracovní úsek/}).click()
    let dialog = page.getByRole('dialog', {name:'Upravit pracovní úsek'})
    await expect(dialog.getByLabel('Začátek')).toHaveValue('08:00:03')
    await expect(dialog.getByLabel('Konec')).toHaveValue('17:05:09')
    await expect(dialog.getByRole('button', {name:'Uložit úsek'})).toBeDisabled()
    await dialog.getByLabel('Konec').fill('16:00:00')
    await dialog.getByLabel('Důvod úpravy').fill('Pozdní odhlášení')
    await dialog.getByRole('button', {name:'Uložit úsek'}).click()
    await expect(dialog).toBeHidden()
    expect(saved).toMatchObject({day:'2026-11-17', row:0, from:'08:00:03', to:'16:00:00', reason:'Pozdní odhlášení'})

    await page.getByRole('toolbar', {name:'Nástroje výkazu'}).getByRole('button', {name:'Přidat záznam do výkazu'}).click()
    dialog = page.getByRole('dialog', {name:'Přidat záznam do výkazu'})
    const picker = dialog.getByLabel('Den', {exact:true})
    await expect(picker).toHaveValue('2026-11-17')
    const alignment = await picker.evaluate(input => {
        const field = input.getBoundingClientRect()
        const button = input.closest('.p-datepicker').querySelector('button').getBoundingClientRect()
        return Math.max(Math.abs(field.y - button.y), Math.abs(field.height - button.height))
    })
    expect(alignment).toBeLessThan(1)
    await picker.fill('2026-11-14')
    await picker.press('Tab')
    await dialog.getByLabel('Začátek').fill('09:00:00')
    await dialog.getByLabel('Konec').fill('12:05:00')
    await dialog.getByLabel('Důvod úpravy').fill('Práce na pobočce')
    await dialog.getByRole('button', {name:'Zapsat konto'}).click()
    await expect(dialog).toBeHidden()
    expect(saved.row).toBeUndefined()
    await expect(month.getByRole('row', {name:/14\. 11\./})).toContainText('3:05')
    await expect(detail).toHaveAccessibleName(/14\. 11\./)
})

test('the day detail lists request status and the month marks pending and rejected requests', async ({page}) => {
    await openApp(page, employee, {
        sheet:() => sheet({requests:[
            {...pending, kind:'DOVOLENA', begins:'2026-11-13T23:00:00Z', ends:'2026-11-15T23:00:00Z'},
            {...pending, id:51, status:'rejected'},
            {...pending, id:52, status:'approved', begins:'2026-11-17T08:00:00Z', ends:'2026-11-17T09:00:00Z'},
        ]}),
    })
    const month = page.getByRole('table', {name:'Dny v měsíci'})
    const saturday = month.getByRole('row', {name:/14\. 11\./})
    const sunday = month.getByRole('row', {name:/15\. 11\./})
    const monday = month.getByRole('row', {name:/16\. 11\./})
    await expect(saturday.getByRole('img', {name:'Čekající žádost'})).toBeVisible()
    await expect(sunday.getByRole('img', {name:'Čekající žádost'})).toBeVisible()
    await expect(monday.getByRole('img', {name:'Čekající žádost'})).toHaveCount(0)
    await expect(monday.getByRole('img', {name:'Zamítnutá žádost'})).toBeVisible()
    const requests = page.getByRole('table', {name:'Žádosti vybraného dne'})
    await saturday.getByRole('button').click()
    await expect(requests.getByRole('row', {name:/Dovolená/})).toContainText('Čeká na schválení')
    await monday.getByRole('button').click()
    await expect(requests.getByRole('row', {name:/Lékař/})).toContainText('Zamítnuto')
    await month.getByRole('button', {name:/17\. 11\./}).click()
    await expect(requests.getByRole('row', {name:/Lékař/})).toContainText('Schváleno')
})

test('a planned weekend with missing attendance is visible and review icons explain each day', async ({page}) => {
    await openApp(page, employee, {sheet:() => sheet({
        planned_shifts:{'2026-11-14':[{from:'2026-11-14T08:00:00+01:00',to:'2026-11-14T16:00:00+01:00'}]},
        day_issues:{
            '2026-11-14':{status:'missing', reasons:['Plánovaná směna bez záznamu o práci.']},
            '2026-11-16':{status:'review', reasons:['Chybí odhlášení.', 'Chybí povinný doklad.']},
        },
    })})
    const month = page.getByRole('table', {name:'Dny v měsíci'})
    const saturday = month.getByRole('row', {name:/14\. 11\./})
    const missing = saturday.getByRole('img', {name:'Plánovaná směna bez záznamu o práci.'})
    await expect(missing).toHaveClass(/day-attention-missing/)
    await missing.hover()
    await expect(page.getByRole('tooltip')).toHaveText('Plánovaná směna bez záznamu o práci.')
    await saturday.getByRole('button').click()
    const detail = page.getByRole('region', {name:/Detail dne/})
    await expect(detail).toContainText('Chybí docházka')
    const monday = month.getByRole('row', {name:/16\. 11\./})
    await expect(monday.getByRole('img', {name:/Chybí odhlášení/})).toHaveClass(/day-attention-review/)
    await monday.getByRole('button').click()
    await expect(detail).toContainText('Chybí odhlášení.')
    await expect(detail).toContainText('Chybí povinný doklad.')
    await expect(month.getByRole('row', {name:/17\. 11\./}).getByRole('img')).toHaveCount(0)
})

test('administrator creates a custom account and assigns a four-hour work model', async ({page}) => {
    const catalog = []
    const workModels = []
    let assignment
    await openApp(page, administrator, {
        people:() => [administrator,staff],
        'attendance-accounts':() => catalog,
        'attendance-account':data => { catalog.push(data); return {ok:true} },
        'work-models':() => workModels,
        'work-model':data => {
            workModels.push({id:1,name:data.name,versions:[{effective_on:data.effective_on,week:data.week}],assignments:[]})
            return {id:1}
        },
        'employee-model-choices':() => ({models:workModels,assignments:[]}),
        employee:data => { assignment = data; return {id:employee.id} },
    })
    await navigate(page, 'Konta docházky')
    await page.getByRole('button', {name:'Nové konto'}).click()
    const accountDialog = page.getByRole('dialog', {name:'Nové konto'})
    await expect(accountDialog.getByRole('tab', {name:'Přestávky'})).toHaveCount(0)
    await accountDialog.getByLabel('Kód konta').fill('SKOLENI')
    await accountDialog.getByLabel('Název konta').fill('Školení')
    await accountDialog.getByRole('tab', {name:'Započítání'}).click()
    await accountDialog.getByRole('combobox', {name:'Započítání'}).click()
    await page.getByRole('option', {name:'Práce',exact:true}).click()
    await accountDialog.getByRole('checkbox', {name:'Vyžadovat doklad'}).check()
    await accountDialog.getByRole('tab', {name:'Přestávky'}).click()
    await accountDialog.getByRole('checkbox', {name:'Upravit pravidlo přestávky'}).check()
    await accountDialog.getByRole('tab', {name:'Mzdy'}).click()
    const payrollCode = accountDialog.getByLabel('Nový mzdový kód (nepovinný)')
    await payrollCode.fill('chybný kód')
    await accountDialog.getByRole('tab', {name:'Základní údaje'}).click()
    await expect(accountDialog.getByLabel('Název konta')).toHaveValue('Školení')
    await accountDialog.getByRole('button', {name:'Uložit konto'}).click()
    await expect(accountDialog.getByRole('tab', {name:'Mzdy'})).toHaveAttribute('aria-selected','true')
    await expect(payrollCode).toBeFocused()
    await payrollCode.fill('SKOL-1')
    await accountDialog.getByRole('button', {name:'Uložit konto'}).click()
    await expect(page.getByRole('table', {name:'Konta docházky'}).getByRole('row', {name:/Školení/})).toContainText('Povinný')
    expect(catalog[0]).toMatchObject({name:'Školení',payroll_code:'SKOL-1',break_rule:{enabled:true,minutes:30}})
    await page.getByRole('button', {name:'Upravit konto Školení'}).click()
    const existingAccount = page.getByRole('dialog', {name:'Upravit konto'})
    await expect(existingAccount.getByRole('tab', {name:'Základní údaje'})).toHaveAttribute('aria-selected','true')
    await existingAccount.getByRole('button', {name:'Zrušit',exact:true}).click()
    await navigate(page, 'Pracovní modely')
    await page.getByRole('button', {name:'Nový pracovní model'}).click()
    await page.getByLabel('Název modelu').fill('Dopolední úvazek')
    await page.getByLabel('Účinnost modelu').fill('2026-11-01')
    await page.getByRole('button', {name:'Uložit model'}).click()
    const modelsTable = page.getByRole('table', {name:'Pracovní modely'})
    await expect(modelsTable).toContainText('20:00')
    await modelsTable.getByRole('row', {name:/Dopolední úvazek/}).getByRole('button', {expanded:false}).click()
    await expect(modelsTable).toContainText('08:00–12:00')
    await navigate(page,'Zaměstnanci')
    await page.getByRole('button', {name:'Upravit ' + staff.name,exact:true}).click()
    const dialog = page.getByRole('dialog', {name:'Upravit zaměstnance'})
    await dialog.getByRole('tab', {name:'Docházka'}).click()
    await dialog.getByRole('combobox', {name:'Pracovní model'}).click()
    await page.getByRole('option', {name:'Dopolední úvazek'}).click()
    await dialog.getByRole('button', {name:'Uložit zaměstnance'}).click()
    await expect(dialog).toBeHidden()
    expect(assignment).toMatchObject({id:employee.id, work_model:{model:1,effective_on:'2026-11-17'}})
})

test('a work model copies a day to the next day or to selected weekdays', async ({page}) => {
    let saved
    await openApp(page, administrator, {
        'work-models':() => [],
        'work-model':data => { saved = data; return {id:1} },
    })
    await navigate(page, 'Pracovní modely')
    await page.getByRole('button', {name:'Nový pracovní model'}).click()
    const editor = page.getByRole('dialog', {name:'Nový pracovní model'})
    await expect(editor.getByRole('table', {name:'Týdenní rozvrh'}).getByRole('row')).toHaveCount(8)
    await editor.getByLabel('Název modelu').fill('Provoz s přestávkou')
    await editor.getByLabel('Pondělí – začátek').fill('07:15')
    await editor.getByLabel('Pondělí – konec').fill('15:45')
    await expect(editor.getByLabel('Pondělí – přestávka od')).toHaveCount(0)
    await editor.getByLabel('Pondělí – režim přestávky').click()
    await page.getByRole('option', {name:'Pevná přestávka od–do'}).click()
    await editor.getByLabel('Pondělí – přestávka od').fill('11:30')
    await editor.getByLabel('Pondělí – přestávka do').fill('12:00')
    await editor.getByRole('button', {name:'Kopírovat do dalšího dne (Pondělí → Úterý)'}).click()
    await expect(editor.getByLabel('Úterý – přestávka od')).toHaveValue('11:30')
    await editor.getByLabel('Úterý – začátek').fill('07:30')
    await expect(editor.getByLabel('Pondělí – začátek')).toHaveValue('07:15')

    await page.setViewportSize({width:320,height:720})
    await assertFits(page)
    const copyButton = editor.getByRole('button', {name:'Kopírovat do vybraných dnů (Pondělí)'})
    const copy = page.getByRole('dialog', {name:'Kopírovat nastavení dne'})
    await copyButton.click()
    await copy.getByRole('checkbox', {name:'Středa'}).check()
    await page.keyboard.press('Escape')
    await expect(copy).toBeHidden()
    await expect(editor.getByLabel('Středa – začátek')).toHaveValue('08:00')

    await copyButton.click()
    const confirm = copy.getByRole('button', {name:'Kopírovat nastavení', exact:true})
    await expect(confirm).toBeDisabled()
    await expect(copy.getByRole('checkbox', {name:'Pondělí'})).toHaveCount(0)
    await copy.getByRole('checkbox', {name:'Čtvrtek'}).check()
    await copy.getByRole('checkbox', {name:'Sobota'}).check()
    await confirm.click()
    await expect(copy).toBeHidden()
    await expect(editor.getByRole('checkbox', {name:'Sobota – pracovní den'})).toBeChecked()
    await editor.getByRole('button', {name:'Kopírovat do dalšího dne (Neděle → Pondělí)'}).click()
    await expect(editor.getByRole('checkbox', {name:'Pondělí – pracovní den'})).not.toBeChecked()
    await editor.getByRole('button', {name:'Uložit model'}).click()
    await expect(editor).toBeHidden()

    const defaultDay = {break_mode:'account',start:'08:00',end:'12:00',break_start:null,break_end:null}
    const copiedDay = {break_mode:'fixed',start:'07:15',end:'15:45',break_start:'11:30',break_end:'12:00'}
    expect(saved.week).toEqual([null, {...copiedDay,start:'07:30'}, defaultDay, copiedDay, defaultDay, copiedDay, null])
})

test('vacation request previews hours and a manager sees them when deciding', async ({page}) => {
    let submitted
    await openApp(page, manager, {
        people:() => [manager,employee],
        sheet:() => sheet({requests:submitted ? [{id:1, kind:'DOVOLENA', begins:'2026-11-16T00:00:00+01:00',
            ends:'2026-11-17T00:00:00+01:00',status:'pending',scheduled_seconds:14400}] : []}),
        'request-preview':() => ({seconds:14400, days:['2026-11-16']}),
        request:input => { submitted = input; return {id:1} },
        decide:() => ({ok:true}),
    })
    await navigate(page, 'Žádosti')
    await page.setViewportSize({width:320,height:720})
    const toolbar = page.getByRole('toolbar', {name:'Nástroje žádostí'})
    await expect(toolbar.getByLabel('Období')).toHaveValue('2026-11')
    await assertFits(page)
    await toolbar.getByRole('combobox', {name:'Osoba'}).click()
    await page.getByRole('option', {name:employee.name}).click()
    await toolbar.getByRole('button', {name:'Nová žádost nebo oprava'}).click()
    await page.getByRole('combobox', {name:'Druh'}).click()
    await page.getByRole('option', {name:'Dovolená'}).click()
    await page.getByLabel('První den', {exact:true}).fill('2026-11-16')
    await page.getByLabel('Poslední den včetně').fill('2026-11-16')
    await expect(page.getByText('Plánované čerpání 4:00')).toBeVisible()
    await page.getByRole('button', {name:'Odeslat žádost'}).click()
    expect(submitted).toMatchObject({employee:employee.id,kind:'DOVOLENA'})
    await navigate(page, 'Výkaz')
    await page.getByRole('table', {name:'Dny v měsíci'}).getByRole('button', {name:/16\. 11\./}).click()
    const daily = page.getByRole('table', {name:'Žádosti vybraného dne'})
    await expect(daily).toContainText('4:00')
    await daily.getByRole('button', {name:'Schválit'}).click()
    await expect(page.getByRole('dialog', {name:'Schválit žádost'})).toBeVisible()
})


test('an empty Saturday is selected when it is today and a manager can enter vacation for another date', async ({page}) => {
    let saved
    await openApp(page, manager, {
        people:() => [manager, employee],
        sheet:() => sheet({rows:[{day:'2026-11-13',kind:'PRACE',from:'2026-11-13T08:00:00+01:00',to:'2026-11-13T12:00:00+01:00'}]}),
        'request-preview':data => data.from === '2026-11-14'
            ? {status:422,body:{error:'Ve vybraném období není žádná směna k čerpání.'}}
            : {seconds:14400,days:[data.from]},
        'save-work-row':data => {saved = data; return {ok:true}},
    }, '/', new Date('2026-11-14T12:00:00+01:00'))
    await page.getByRole('combobox', {name:'Osoba'}).click()
    await page.getByRole('option', {name:employee.name}).click()
    await expect(page.getByRole('heading', {name:/Detail dne.*14\. 11/})).toBeVisible()
    const bar = page.getByRole('toolbar', {name:'Nástroje výkazu'})
    const layout = await bar.evaluate(el => {
        const grid = document.getElementById('month-days-title').closest('.p-card').getBoundingClientRect()
        const rect = el.closest('.p-card').getBoundingClientRect()
        return {width:Math.abs(grid.width - rect.width), above:rect.bottom <= grid.top}
    })
    expect(layout.width).toBeLessThan(1)
    expect(layout.above).toBe(true)
    await bar.getByRole('button', {name:'Přidat záznam do výkazu'}).click()
    const dialog = page.getByRole('dialog', {name:'Přidat záznam do výkazu'})
    await dialog.getByRole('combobox', {name:'Konto'}).click()
    await page.getByRole('option', {name:'Dovolená'}).click()
    await expect(dialog.getByLabel('Začátek')).toHaveCount(0)
    await expect(dialog.getByText('Ve vybraném období není žádná směna k čerpání.')).toBeVisible()
    await dialog.getByLabel('Důvod úpravy').fill('Dohodnutá dovolená')
    await expect(dialog.getByRole('button', {name:'Zapsat konto'})).toBeDisabled()
    await dialog.getByLabel('Den', {exact:true}).fill('2026-11-16')
    await dialog.getByLabel('Den', {exact:true}).press('Tab')
    await expect(dialog.getByText('Plánované čerpání 4:00')).toBeVisible()
    await dialog.getByRole('button', {name:'Zapsat konto'}).click()
    await expect(dialog).toBeHidden()
    expect(saved).toEqual({kind:'DOVOLENA',day:'2026-11-16',reason:'Dohodnutá dovolená',employee:employee.id,month:'2026-11',fingerprint:'test-fingerprint'})
})

test('unit link filters the directory including descendants and employee fields align', async ({page}) => {
    const units = [{id:1,name:'Provoz',path:'Provoz',parent_id:null},{id:2,name:'Dílna',path:'Provoz › Dílna',parent_id:1},
        {id:3,name:'IT',path:'IT',parent_id:null}]
    await openApp(page, administrator, {people:() => [administrator,{...staff,org_unit_id:2}], 'org-units':() => units}, '/?sekce=organizace')
    await page.getByRole('link', {name:'Zaměstnanci jednotky Provoz',exact:true}).click()
    const table = page.getByRole('table', {name:'Zaměstnanci'})
    await expect(table).toContainText(staff.name)
    await expect(table).not.toContainText(administrator.name)
    await expect(page).toHaveURL(/jednotka=1/)
    await page.reload()
    await expect(table).not.toContainText(administrator.name)
    await page.getByRole('button', {name:'Zrušit filtr jednotky'}).click()
    await expect(table).toContainText(administrator.name)
    await page.goBack()
    await expect(table).not.toContainText(administrator.name)
    await page.getByRole('button',{name:'Upravit ' + staff.name,exact:true}).click()
    const dialog = page.getByRole('dialog')
    const general = await dialog.getByRole('tabpanel').locator('.employee-fields').evaluate(el =>
        ['#employee-name','.p-treeselect'].map(selector => el.querySelector(selector).getBoundingClientRect().height))
    await dialog.getByRole('tab',{name:'Docházka'}).click()
    const sizes = await dialog.getByRole('tabpanel').locator('.employee-fields').evaluate(el => {
        const rect = selector => el.querySelector(selector).getBoundingClientRect()
        const sid = rect('.sid-field input'), start = rect('#employee-starts'), end = rect('#employee-ends')
        return {heights:[sid.height,start.height,end.height],
            datesY:Math.abs(start.y-end.y), sidWidth:sid.width, startWidth:start.width, datesBelow:start.top >= sid.bottom}
    })
    const heights = [...general,...sizes.heights]
    expect(Math.max(...heights)-Math.min(...heights)).toBeLessThan(1)
    expect(sizes.datesY).toBeLessThan(1)
    expect(sizes.datesBelow).toBe(true)
    expect(sizes.sidWidth).toBeGreaterThan(2*sizes.startWidth)
})

test('administrator selects a holiday account and adds and removes a company day', async ({page}) => {
    const options = [{code:'SVATEK',name:'Svátek',behavior:'calendar',active:true},{code:'VOLNO',name:'Firemní volno',behavior:'calendar',active:true},
        {code:'DOVOLENA',name:'Dovolená',behavior:'shift_absence',active:true}, {code:'LEKAR',name:'Lékař',behavior:'timed_absence',active:true,requires_proof:true}]
    const settings = {holiday_accounts:[{effective_on:'0001-01-01',account:'SVATEK'}], days:[], holidays:[]}
    await openApp(page, administrator, {
        'attendance-accounts':() => options, 'calendar-settings':() => settings,
        'calendar-holiday-account':row => {settings.holiday_accounts.push(row); return {ok:true}},
        'calendar-holiday':row => {settings.holidays=[{...row,id:1}]; return {id:1}},
        'calendar-holiday-remove':({id}) => {
            const rule = settings.holidays.find(rule => rule.id === id)
            if (rule.valid_from > '2026-11-17') settings.holidays = settings.holidays.filter(rule => rule.id !== id)
            else rule.valid_to = '2026-11-17'
            return {ok:true}
        },
        'calendar-day':row => {settings.days=row.remove ? [] : [row]; return {ok:true}},
    }, '/?sekce=svatky')
    await page.getByRole('button', {name:'Přidat pravidlo svátku'}).click()
    const rule = page.getByRole('dialog', {name:'Přidat pravidlo svátku'})
    await rule.getByLabel('Kód svátku').fill('VELKY_PATEK')
    await rule.getByLabel('Název svátku').fill('Velký pátek')
    await expect(rule.getByRole('combobox', {name:'Docházkové konto svátku'})).toHaveText('Výchozí podle data')
    await rule.getByRole('combobox', {name:'Docházkové konto svátku'}).click()
    await page.getByRole('option', {name:'Lékař'}).click()
    await rule.getByRole('combobox', {name:'Určení data'}).click()
    await page.getByRole('option', {name:'Podle Velikonoc'}).click()
    await rule.getByLabel(/Posun ve dnech/).fill('-2')
    await rule.getByLabel('Platnost od').fill('2016-01-01')
    await rule.getByRole('button',{name:'Uložit pravidlo'}).click()
    await expect(page.getByRole('table',{name:'České svátky',exact:true})).toContainText('Bez omezení')
    expect(settings.holidays[0]).toMatchObject({easter_offset:-2, account:'LEKAR', month:null, day:null, valid_from:'2016-01-01', valid_to:null})
    const editRule = page.getByRole('button', {name:'Upravit svátek Velký pátek', exact:true})
    await editRule.click()
    const editor = page.getByRole('dialog', {name:'Upravit pravidlo svátku'})
    const holidayAccount = editor.getByRole('combobox', {name:'Docházkové konto svátku'})
    await expect(holidayAccount).toHaveText('Lékař')
    await holidayAccount.click()
    await page.getByRole('option', {name:'Výchozí podle data', exact:true}).click()
    await editor.getByRole('button', {name:'Uložit pravidlo'}).click()
    await expect(page.getByRole('table', {name:'České svátky', exact:true})).toContainText('Výchozí podle data')
    expect(settings.holidays[0].account).toBeNull()
    await editRule.click()
    await expect(holidayAccount).toHaveText('Výchozí podle data')
    await editor.getByRole('button', {name:'Zrušit', exact:true}).click()
    const removeRule = page.getByRole('button', {name:'Odebrat svátek Velký pátek', exact:true})
    await removeRule.click()
    const removal = page.getByRole('dialog', {name:'Odebrat pravidlo svátku'})
    await expect(removal).toContainText('Pravidlo bude platit do dneška včetně.')
    await removal.getByRole('button', {name:'Odebrat', exact:true}).click()
    await expect(page.getByRole('table', {name:'České svátky', exact:true})).toContainText('17. 11. 2026')
    await expect(removeRule).toBeHidden()
    await expect(editRule).toBeVisible()
    await page.getByRole('button', {name:'Nastavit konto'}).click()
    await page.getByRole('combobox', {name:'Konto pro svátky'}).click()
    await page.getByRole('option',{name:'Firemní volno'}).click()
    await page.getByRole('button', {name:'Uložit nastavení'}).click()
    await expect(page.getByRole('table',{name:'Konto pro české svátky'})).toContainText('Firemní volno')
    await page.getByRole('button', {name:'Přidat den volna'}).click()
    await page.getByLabel('Datum volna').fill('2026-12-31')
    await page.getByLabel('Název dne').fill('Silvestrovské volno')
    await page.getByRole('combobox',{name:'Konto dne volna'}).click()
    await page.getByRole('option',{name:'Dovolená'}).click()
    await page.getByRole('button', {name:'Uložit den volna'}).click()
    const table = page.getByRole('table',{name:'Firemní dny volna'})
    await expect(table).toContainText('31. 12. 2026')
    await expect(table).toContainText('Dovolená')
    await table.getByRole('button',{name:'Odebrat Silvestrovské volno'}).click()
    await page.getByRole('dialog').getByRole('button',{name:'Odebrat',exact:true}).click()
    await expect(table).toContainText('Žádné firemní dny volna.')
})


test('generated attendance retains its origin and a manager cannot edit or revoke the calendar entry', async ({page}) => {
    await openApp(page, manager, {
        people:() => [manager,employee],
        sheet:() => sheet({rows:[{day:'2026-11-17',kind:'PRACE',generated_holiday:true,
            from:'2026-11-17T08:00:00+01:00',to:'2026-11-17T12:00:00+01:00'}],
            calendar_days:{'2026-11-17':{name:'Den boje za svobodu a demokracii',account:'PRACE'}},
            requests:[{...pending,id:3,status:'approved',generated_holiday:true,requires_proof:true,
                begins:'2026-11-17T08:00:00+01:00',ends:'2026-11-17T12:00:00+01:00'}]}),
    })
    await page.getByRole('combobox', {name:'Osoba'}).click()
    await page.getByRole('option', {name:employee.name}).click()
    const rows = page.getByRole('table',{name:'Úseky vybraného dne'})
    await expect(rows).toContainText('Zápis z kalendáře')
    const holiday = page.getByRole('region',{name:/Detail dne/}).getByText('Den boje za svobodu a demokracii',{exact:true})
    await expect(holiday).toBeVisible()
    await page.setViewportSize({width:320,height:740})
    await holiday.scrollIntoViewIfNeeded()
    await expect(holiday).toBeInViewport({ratio:1})
    await expect(rows.getByRole('button',{name:/Upravit pracovní úsek/})).toHaveCount(0)
    await expect(page.getByRole('table',{name:'Žádosti vybraného dne'})).toContainText('zápis z kalendáře')
    await expect(page.getByRole('button',{name:'Odvolat schválení'})).toHaveCount(0)
    await navigate(page,'Žádosti')
    await expect(page.getByText('Čeká na propustku')).toBeVisible()
    await expect(page.getByRole('button',{name:'Odvolat schválení'})).toHaveCount(0)
})

test('a manager changes recognized duration, keeping the interval and internal rounding visible', async ({page}) => {
    let update
    const row = {day:'2026-11-17',kind:'PRACE',from:'2026-11-17T08:00:00+01:00',to:'2026-11-17T12:00:00+01:00'}
    await openApp(page, manager, {
        people:() => [manager,employee],
        sheet:() => sheet({rows:[update ? {...row,seconds:update.seconds,calculated_seconds:14400} : row],
            internal_by_day:{'2026-11-17':{PRACE:{seconds:update ? 12600 : 14400,recognized_seconds:update?.seconds ?? 14400}}}}),
        'adjust-recognized-work':data => { update = data; return {ok:true} },
    })
    await page.getByRole('combobox',{name:'Osoba'}).click()
    await page.getByRole('option',{name:employee.name}).click()
    await page.getByRole('button',{name:/Upravit uznanou dobu 08:00/}).click()
    const dialog = page.getByRole('dialog',{name:'Upravit uznanou dobu'})
    await expect(dialog.getByLabel('Uznaná doba (H:MM)')).toHaveValue('4:00')
    await dialog.getByLabel('Uznaná doba (H:MM)').fill('3:45')
    await dialog.getByLabel('Důvod').fill('Ověřená doba práce')
    await dialog.getByRole('button',{name:'Uložit uznanou dobu'}).click()
    await expect(dialog).toBeHidden()
    const table = page.getByRole('table',{name:'Úseky vybraného dne'})
    for (const value of ['08:00', '12:00']) await expect(table.getByText(value,{exact:true})).toBeVisible()
    await expect(table.getByRole('cell',{name:'3:45 Upraveno z 4:00'})).toBeVisible()
    await expect(page.getByText('Práce · Interně započteno 3:30')).toBeVisible()
    expect(update).toMatchObject({employee:employee.id, row:0, seconds:13500, fingerprint:'test-fingerprint'})
})

test('the timesheet toolbar opens a control run for the selected person and period', async ({page}) => {
    const runs = []
    await openApp(page, manager, {
        people:() => [manager,employee], sheet:() => sheet(),
        'holiday-control':data => {
            runs.push(data)
            return {token:'preview',added:1,changed:0,removed:0}
        },
    })
    const toolbar = page.getByRole('toolbar', {name:'Nástroje výkazu'})
    const controlRun = toolbar.getByRole('button', {name:'Kontrolní chod'})
    await expect(controlRun).toHaveCount(0)
    await toolbar.getByRole('combobox', {name:'Osoba'}).click()
    await page.getByRole('option', {name:employee.name}).click()
    await expect(toolbar.getByRole('button', {name:'Přidat záznam do výkazu'})).toBeVisible()
    await expect(controlRun).toHaveText('')
    await expect(controlRun).toHaveAttribute('title', 'Kontrolní chod')
    await controlRun.focus()
    await expect(page.getByRole('tooltip')).toHaveText('Kontrolní chod')
    await controlRun.press('Enter')
    const dialog = page.getByRole('dialog', {name:'Kontrolní chod'})
    const holidays = dialog.getByRole('checkbox', {name:'Kontrola svátků a firemního volna'})
    const preview = dialog.getByRole('button', {name:'Zobrazit náhled'})
    const confirm = dialog.getByRole('button', {name:'Provést změny'})
    await expect(dialog.getByText(employee.name, {exact:true})).toBeVisible()
    await expect(dialog.getByLabel('Od', {exact:true})).toHaveValue('2026-11-01')
    const end = dialog.getByLabel('Do včetně')
    await expect(end).toHaveValue('2026-11-16')
    await expect(holidays).toBeChecked()
    await preview.click()
    await expect(confirm).toBeVisible()
    await expect(dialog.getByRole('status')).toContainText('Přidá se 1 · Změní se 0 · Odebere se 0')
    await holidays.uncheck()
    await expect(confirm).toHaveCount(0)
    await expect(preview).toBeDisabled()
    await holidays.check()
    await end.fill('2026-11-15')
    await preview.click()
    await confirm.click()
    await expect(dialog).toBeHidden()
    const scope = {employee:employee.id,from:'2026-11-01'}
    expect(runs).toEqual([
        {...scope,to:'2026-11-16'},
        {...scope,to:'2026-11-15'},
        {...scope,to:'2026-11-15',token:'preview'},
    ])
})
