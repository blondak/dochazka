import { ref, computed, nextTick, onMounted, onUnmounted } from 'vue'
import type { FileUploadUploaderEvent } from 'primevue/fileupload'

interface Person {id:number;name:string;number:string;team:string;roles:string[];attendance_enabled:boolean;archived_on?:string|null;sid?:string|null;login?:string;starts?:string;ends?:string|null}
interface Row {day:string;kind:string;from:string;to:string}
interface RequestRow {id:number;kind:string;begins:string;ends:string;note:string;status:string;reason:string;has_proof:boolean}
interface Sheet {rows:Row[];seconds:number;pending:number;missing_proofs:number;approved:boolean;issues:string[];vacation_conflict?:boolean;requests:RequestRow[];fingerprint:string}
interface Device {id:number;name:string;seen:string|null;revoked:boolean;registered:boolean;warnings:string|null}
interface Payroll {people:{id:number;name:string;approved:boolean}[];versions:{version:number;created:string}[];changed:boolean}
interface Passkey {id:string;label:string;created:string}
interface Confirmation {
    title: string
    description: string
    label: string
    destructive?: boolean
    reasonRequired?: boolean
    reasonLabel?: string
    action: (reason: string) => Promise<void>
}
type Editor = {kind:'employee'; id?:number} | {kind:'device'} | {kind:'request'}
interface AttendanceView {
    account: number
    tab: string
    person: number
    month: string
    showArchived: boolean
    editor: Editor | null
}
const validMonth = /^(?!0000)\d{4}-(0[1-9]|1[0-2])$/
const local = new Intl.DateTimeFormat('sv-SE',{timeZone:'Europe/Prague',year:'numeric',month:'2-digit',day:'2-digit'})
const today=local.format(new Date())
const freshPerson=()=>({id:0,name:'',number:'',login:'',roles:['employee'],attendance_enabled:true,team:'',sid:'',starts:today,ends:''})
const labels:Record<string,string>={employee:'Zaměstnanec',manager:'Vedoucí',admin:'Správce',payroll:'Mzdová účetní',PRACE:'Práce',PRESTAVKA:'Přestávka',DOVOLENA:'Dovolená',LEKAR:'Lékař',pending:'Čeká na schválení',approved:'Schváleno',rejected:'Zamítnuto'}
const options = (values: string[]) => values.map(value => ({ value, label: labels[value] }))
const roles = options(['employee', 'manager', 'payroll', 'admin'])
const roleNames = (assigned:string[]) => assigned.map(role => labels[role]).join(', ')
const requestKinds = options(['LEKAR', 'DOVOLENA', 'PRACE', 'PRESTAVKA'])
const freshRequest = () => ({kind:'LEKAR',from:'',to:'',fromTime:'',toTime:'',note:''})
const tabIcons: Record<string, string> = {
    'Zaměstnanci': 'pi pi-users', 'Zařízení': 'pi pi-desktop', 'První kroky': 'pi pi-list-check',
    'Výkaz': 'pi pi-calendar', 'Žádosti': 'pi pi-file-edit', 'Uzávěrka': 'pi pi-check-square',
}
const tabSlugs: Record<string, string> = {
    'Zaměstnanci': 'zamestnanci', 'Zařízení': 'zarizeni', 'První kroky': 'prvni-kroky',
    'Výkaz': 'vykaz', 'Žádosti': 'zadosti', 'Uzávěrka': 'uzaverka',
}
const monthlyTabs = ['Výkaz', 'Žádosti', 'Uzávěrka']
const requestSeverities: Record<string, string> = {pending:'warn', approved:'success', rejected:'danger'}
const date = (s: string) => new Date(s.replace(' ', 'T').replace(/([+-]\d{2})$/, '$1:00'))
class ApiError extends Error {
    constructor(message: string, readonly status: number) {
        super(message)
    }
}
async function api<T>(op:string,data:object={}):Promise<T>{
    const r=await fetch('/api/v1/'+op,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-Dochazka':'web'},body:JSON.stringify(data)})
        .catch(() => { throw new Error('Spojení s docházkou se nezdařilo. Zkontrolujte připojení a zkuste to znovu.') })
    const body=await r.json().catch(() => null)
    if(!r.ok || !body) {
        throw new ApiError(body?.error || 'Server požadavek nedokončil. Zkuste to znovu.', r.status)
    }
    return body
}
export function setupAttendance() {
    const user=ref<Person|null>(null), people=ref<Person[]>([]), devices=ref<Device[]>([]), payroll=ref<Payroll|null>(null)
    const person=ref(0), month=ref(today.slice(0,7)),sheet=ref<Sheet|null>(null),tab=ref('Výkaz')
    const preview=ref(''), proofVisible=ref(false)
    const settingsVisible = ref(false)
    const confirmationVisible = ref(false)
    const confirmation = ref<Confirmation|null>(null)
    const decisionReason = ref('')
    const passwords = ref({current:'', next:'', repeat:''})
    const passkeyAvailable = ref(false)
    const passkeyList = ref<Passkey[]>([])
    const newPasskey = ref({label:'', password:''})
    const photoVisible = ref(false)
    const photoPreview = ref('')
    const adjusted = ref(false)
    const scanMessage = ref('')
    const cameraInput = ref<HTMLInputElement | null>(null)
    const mobileCamera = window.matchMedia('(pointer: coarse)').matches
    let originalPhoto: HTMLCanvasElement | null = null
    let photoRequest = 0
    let cameraRequest = 0
    const busy=ref(false),error=ref(''),notice=ref(''), login=ref(''),password=ref(''),activation=ref(false),code=ref('')
    const starting=ref(true), setupAvailable=ref(false)
    const initial=ref({token:'',name:'',login:'',password:'',repeat:''})
    const form=ref(freshRequest()), edit=ref<Person>(freshPerson()), deviceName=ref(''), secret=ref('')
    const editor = ref<Editor|null>(null), showArchived = ref(false)
    let popPending = false
    const directoryPeople = computed(() => showArchived.value ? people.value : people.value.filter(p => !p.archived_on))
    const hasRole = (role:string) => user.value?.roles.includes(role) ?? false
    const ownSheet = computed(() => user.value?.id === person.value)
    const selectedPerson = computed(() => people.value.find(p => p.id === person.value))
    const managesPerson = computed(() => hasRole('manager') && user.value?.team === selectedPerson.value?.team)
    const canReview = computed(() => managesPerson.value && !ownSheet.value && selectedPerson.value?.attendance_enabled)
    const canSubmitRequest = computed(() => (ownSheet.value || managesPerson.value) && selectedPerson.value?.attendance_enabled)
    const scopePeople = computed(() => people.value.filter(p => {
        if (p.id === user.value?.id) return p.attendance_enabled
        return hasRole('admin') || (hasRole('payroll') && tab.value === 'Výkaz')
            || (hasRole('manager') && p.team === user.value?.team)
    }))
    const inActionDialog = computed(() =>
        settingsVisible.value || photoVisible.value || confirmationVisible.value || editor.value,
    )
    const approvalBlockers = computed(() => {
        if (!sheet.value) return []
        const blockers = []
        if (sheet.value.pending) blockers.push('Vyřiďte čekající žádosti.')
        if (sheet.value.missing_proofs) blockers.push('Zaměstnanec musí doložit propustky.')
        if (sheet.value.vacation_conflict ?? sheet.value.issues.some(issue => issue.includes('Dovolená se překrývá'))) {
            blockers.push('Opravte překryv evidence s dovolenou.')
        }
        return blockers
    })
    const tabs = computed(() => [
        ...(hasRole('admin') ? ['Zaměstnanci', 'Zařízení', 'První kroky'] : []),
        ...(hasRole('payroll') ? ['Uzávěrka', 'Výkaz'] : []),
        ...(hasRole('admin') || hasRole('manager') || (hasRole('employee') && user.value?.attendance_enabled)
            ? (hasRole('payroll') ? ['Žádosti'] : ['Výkaz', 'Žádosti']) : []),
    ])
    const hours=(seconds:number)=>Math.floor(seconds/3600)+' h '+Math.floor(seconds%3600/60)+' min'
    const time=(s:string)=>s?new Intl.DateTimeFormat('cs-CZ',{timeZone:'Europe/Prague',hour:'2-digit',minute:'2-digit'}).format(date(s)):''
    const moment=(s:string|null)=>s?date(s).toLocaleString('cs-CZ',{timeZone:'Europe/Prague'}):'—'
    const day=(s:string)=>new Date(s+'T12:00:00').toLocaleDateString('cs-CZ')
    function requestPeriod(request: RequestRow) {
        if (request.kind === 'DOVOLENA') {
            const firstDay = date(request.begins).toLocaleDateString('cs-CZ', {timeZone:'Europe/Prague'})
            const lastDay = new Date(date(request.ends).getTime()-1)
                .toLocaleDateString('cs-CZ', {timeZone:'Europe/Prague'})
            return firstDay === lastDay ? firstDay : firstDay + ' – ' + lastDay
        }
        return moment(request.begins) + ' – ' + moment(request.ends)
    }
    function canUploadProof(request: RequestRow) {
        return ownSheet.value && selectedPerson.value?.attendance_enabled && request.kind === 'LEKAR' && request.status === 'approved'
            && !request.has_proof && date(request.ends).getTime() <= Date.now()
    }
    const scope=()=>({employee:person.value,month:month.value})
    async function refresh(){
        if(!user.value)return
        try {
            if(tab.value==='Zařízení' || tab.value==='První kroky')devices.value=await api('devices')
            else if(tab.value==='Uzávěrka') payroll.value=await api('payroll',scope())
            else if(tab.value==='Zaměstnanci')people.value=await api('people')
            else if (['Výkaz', 'Žádosti'].includes(tab.value) && person.value) sheet.value=await api('sheet',scope())
        } catch (error) {
            sheet.value = null
            payroll.value = null
            if(tab.value==='Zařízení' || tab.value==='První kroky') devices.value = []
            throw error
        }
    }
    async function setupUser(u:Person){
        user.value=u;people.value=await api('people');person.value=u.id
        form.value = freshRequest()
        tab.value = hasRole('admin') && people.value.every(p => !p.attendance_enabled) ? 'První kroky' : tabs.value[0] ?? 'Účet'
        month.value = today.slice(0,7)
        const previous = history.state?.attendance as AttendanceView | undefined
        const available = previous?.account === u.id ? previous : view()
        applyView(viewFromUrl(available))
        if (previous?.account === u.id) showEditor(previous.editor)
        recordView(true)
        await refresh()
    }
    async function run(f:()=>Promise<void>){
        if(busy.value)return
        busy.value=true;error.value='';notice.value=''
        try {
            await f()
        } catch (e) {
            if (user.value && e instanceof ApiError && e.status === 401) {
                user.value = null
                people.value = []
                devices.value = []
                sheet.value = null
                payroll.value = null
                secret.value = ''
                proofVisible.value = false
                photoVisible.value = false
                settingsVisible.value = false
                editor.value = null
                confirmationVisible.value = false
                error.value = 'Přihlášení vypršelo. Přihlaste se znovu.'
            } else {
                error.value = e instanceof DOMException && e.name === 'NotAllowedError'
                    ? 'Ověření passkey bylo zrušeno nebo vypršel čas.' : (e as Error).message
            }
        } finally {
            busy.value=false
            if (popPending) {
                popPending = false
                await restoreView()
            }
        }
    }
    function signIn(){return run(async()=>{
        if (activation.value) {
            await api('activate', {code:code.value, password:password.value})
            activation.value = false
            code.value = ''
            notice.value = 'Heslo bylo nastaveno. Přihlaste se.'
        } else {
            await setupUser(await api('login', {login:login.value, password:password.value}))
        }
        password.value=''
    })}
    function createAdmin(){return run(async()=>{
        const {token,name,login:username,password:secret,repeat}=initial.value
        if(secret!==repeat) throw new Error('Hesla se neshodují.')
        await api('setup',{token,name,login:username,password:secret})
        initial.value={token:'',name:'',login:'',password:'',repeat:''}
        setupAvailable.value=false
        login.value=username.trim()
        notice.value='Správce byl založen. Přihlaste se nastaveným heslem.'
    })}
    function signOut(){return run(async()=>{
        await api('logout')
        history.replaceState({...history.state, attendance:null}, '')
        location.reload()
    })}
    function showSettings(){return run(async()=>{
        settingsVisible.value = true
        if(passkeyAvailable.value) await refreshPasskeys()
    })}
    function clearSettings(){
        passwords.value = {current:'', next:'', repeat:''}
        newPasskey.value = {label:'', password:''}
    }
    function changePassword(){return run(async()=>{
        if(passwords.value.next !== passwords.value.repeat) throw new Error('Nová hesla se neshodují.')
        await api('change-password', {current_password:passwords.value.current, new_password:passwords.value.next})
        passwords.value = {current:'', next:'', repeat:''}
        settingsVisible.value = false
        notice.value = 'Heslo bylo změněno.'
    })}
    async function refreshPasskeys(){
        passkeyList.value = (await api<{keys:Passkey[]}>('passkeys')).keys
    }
    function addPasskey(){return run(async()=>{
        const {publicKey} = await api<{publicKey:PublicKeyCredentialCreationOptionsJSON}>('passkey-create-options', newPasskey.value)
        newPasskey.value.password = ''
        const key = await navigator.credentials.create({publicKey:PublicKeyCredential.parseCreationOptionsFromJSON(publicKey)})
        if(!(key instanceof PublicKeyCredential)) throw new Error('Passkey nebyla vytvořena.')
        await api('passkey-create', key.toJSON().response)
        newPasskey.value.label = ''
        await refreshPasskeys()
        notice.value = 'Passkey byla přidána.'
    })}
    function removePasskey(key:Passkey) {
        openConfirmation({
            title: 'Odebrat passkey', description: 'Přístupový klíč „' + key.label + '“ přestane fungovat pro tento účet.',
            label: 'Odebrat passkey', destructive: true,
            action: async () => {
                await api('passkey-remove', {id:key.id})
                passkeyList.value = passkeyList.value.filter(item => item.id !== key.id)
                confirmationVisible.value = false
                notice.value = 'Passkey byla odebrána.'
            },
        })
    }
    function signInWithPasskey(){return run(async()=>{
        const {publicKey} = await api<{publicKey:PublicKeyCredentialRequestOptionsJSON}>('passkey-login-options')
        const key = await navigator.credentials.get({publicKey:PublicKeyCredential.parseRequestOptionsFromJSON(publicKey)})
        if(!(key instanceof PublicKeyCredential)) throw new Error('Ověření bylo zrušeno.')
        await setupUser(await api('passkey-login', {id:key.id, ...key.toJSON().response}))
    })}
    function clearScope() {
        sheet.value = null
        payroll.value = null
    }
    function view(): AttendanceView {
        return {
            account: user.value?.id ?? 0, tab: tab.value, person: person.value, month: month.value,
            showArchived: showArchived.value, editor: editor.value ? {...editor.value} : null,
        }
    }
    function viewFromUrl(fallback: AttendanceView): AttendanceView {
        const parameters = new URLSearchParams(location.search)
        const slug = parameters.get('sekce')
        const section = tabs.value.find(name => tabSlugs[name] === slug)
        const period = parameters.get('mesic')
        return {
            ...fallback, tab: section ?? fallback.tab,
            month: period && validMonth.test(period) ? period : fallback.month,
        }
    }
    function recordView(replace = false) {
        if (popPending || !user.value) return
        const attendance = view()
        const url = new URL(location.href)
        const slug = tabSlugs[attendance.tab]
        if (slug) url.searchParams.set('sekce', slug)
        else url.searchParams.delete('sekce')
        if (monthlyTabs.includes(attendance.tab)) url.searchParams.set('mesic', attendance.month)
        else url.searchParams.delete('mesic')
        if (url.href === location.href && JSON.stringify(history.state?.attendance) === JSON.stringify(attendance)) return
        const state = {...history.state, attendance}
        if (replace) history.replaceState(state, '', url)
        else history.pushState(state, '', url)
    }
    function applyView(next: AttendanceView) {
        if (tabs.value.includes(next.tab)) tab.value = next.tab
        if (validMonth.test(next.month)) month.value = next.month
        person.value = scopePeople.value.some(p => p.id === next.person) ? next.person
            : scopePeople.value.find(p => p.attendance_enabled)?.id ?? scopePeople.value[0]?.id ?? 0
        showArchived.value = next.showArchived
    }
    function showEditor(next: Editor | null) {
        if (next?.kind === 'employee' && tab.value === 'Zaměstnanci') {
            const {id} = next
            const data = people.value.find(p => p.id === id)
            if (id !== undefined && !data) {
                editor.value = null
                return
            }
            edit.value = data ? {...data, roles:[...data.roles]} : freshPerson()
        } else if (next?.kind === 'device' && tab.value === 'Zařízení') {
            deviceName.value = ''
        } else if (next?.kind === 'request' && tab.value === 'Žádosti' && canSubmitRequest.value) {
            form.value = freshRequest()
        } else {
            next = null
        }
        editor.value = next
    }
    function openEditor(next: Editor) {
        error.value = ''
        showEditor(next)
        recordView()
    }
    function dismissEditor() {
        if (!editor.value) return
        editor.value = null
        if (!popPending) history.back()
    }
    async function renderView(next: AttendanceView, record: boolean) {
        const before = view()
        applyView(next)
        const scopeChanged = before.tab !== tab.value || before.person !== person.value || before.month !== month.value
        if (scopeChanged) {
            editor.value = null
            settingsVisible.value = false
            confirmationVisible.value = false
            photoVisible.value = false
            proofVisible.value = false
            secret.value = ''
            clearScope()
        }
        if (record) recordView()
        if (scopeChanged) await refresh()
        showEditor(next.editor)
        if (before.tab !== tab.value && !editor.value && !popPending) {
            await nextTick()
            document.getElementById('page-title')?.focus()
        }
    }
    function restoreView() {
        const entry = history.state?.attendance as AttendanceView | undefined
        if (!user.value || entry?.account !== user.value.id) return
        if (busy.value || starting.value) {
            popPending = true
            return
        }
        if (entry.tab === tab.value && entry.person === person.value && entry.month === month.value) {
            showArchived.value = entry.showArchived
            showEditor(entry.editor)
            return
        }
        return run(() => renderView(entry, false))
    }
    function navigate(next: Partial<Pick<AttendanceView,'tab'|'person'|'month'|'showArchived'>>) {
        return run(() => renderView({...view(), ...next, editor:null}, true))
    }
    function moveMonth(offset: number) {
        const next = new Date(month.value+'-01T00:00:00Z')
        next.setUTCMonth(next.getUTCMonth() + offset)
        return selectMonth(next.toISOString().slice(0,7))
    }
    function selectMonth(value: string) {
        if (validMonth.test(value)) return navigate({month:value})
    }
    function selectPerson(id: number) {
        return navigate({person:id})
    }
    function changeTab(t:string, employee?:number) {
        return navigate({tab:t, person:employee ?? person.value})
    }
    function setShowArchived(value: boolean) {
        showArchived.value = value
        recordView()
    }
    async function save(op:string,data:object,msg:string){
        const result=await api<{code?:string}>(op,data).catch(async error => {
            if (error instanceof ApiError && error.status === 409) {
                confirmationVisible.value = false
                await refresh().catch(() => {})
            }
            throw error
        })
        confirmationVisible.value = false
        secret.value = result.code || ''
        notice.value = msg
        if (op === 'employee' || op === 'register') dismissEditor()
        await refresh()
    }
    function submit(op:string,data:object,msg:string) {
        return run(() => save(op,data,msg))
    }
    function editPerson(data?:Person) {
        openEditor({kind:'employee', id:data?.id})
    }
    function addDevice() {
        openEditor({kind:'device'})
    }
    function newRequest() {
        openEditor({kind:'request'})
    }
    function approveSheet() {
        if (!sheet.value || approvalBlockers.value.length) return
        const input = {...scope(), fingerprint:sheet.value.fingerprint}
        const reasonRequired = sheet.value.issues.length > 0
        openConfirmation({
            title: 'Schválit výkaz', label: 'Potvrdit schválení',
            description: [selectedPerson.value?.name, month.value].filter(Boolean).join(' · '),
            reasonRequired,
            reasonLabel: reasonRequired ? 'Jak jste vyřešili nesrovnalosti?' : 'Poznámka ke schválení (nepovinná)',
            action: note => save('approve', {...input, note}, 'Výkaz byl schválen.'),
        })
    }
    function archivePerson(data:Person) {
        const archived = !data.archived_on
        const label = archived ? 'Archivovat zaměstnance' : 'Zrušit archivaci'
        openConfirmation({
            title: label, label,
            description: archived
                ? 'Účet „' + data.name + '“ ztratí přístup. Uzavřené výkazy a žádosti zůstanou dostupné.'
                : 'Účet „' + data.name + '“ se vrátí do aktivního adresáře.',
            destructive: archived,
            action: () => save('employee-archive', {id:data.id, archived}, archived ? 'Zaměstnanec byl archivován.' : 'Archivace byla zrušena.'),
        })
    }
    function openConfirmation(config: Confirmation) {
        error.value = ''
        notice.value = ''
        decisionReason.value = ''
        confirmation.value = config
        confirmationVisible.value = true
    }
    function clearConfirmation() {
        confirmation.value = null
        decisionReason.value = ''
    }
    function confirmAction() {
        const current = confirmation.value
        if (!current) return
        const reason = decisionReason.value.trim()
        if (current.reasonRequired && !reason) return
        return run(() => current.action(reason))
    }
    function decide(request: RequestRow, status: 'approved' | 'rejected') {
        const label = status === 'approved' ? 'Schválit žádost'
            : request.status === 'approved' ? 'Odvolat schválení' : 'Zamítnout žádost'
        openConfirmation({
            title: label,
            label,
            description: [selectedPerson.value?.name, labels[request.kind], requestPeriod(request)].filter(Boolean).join(' · '),
            destructive: status === 'rejected',
            reasonRequired: status === 'rejected',
            action: reason => save('decide', {id:request.id, status, reason}, 'Rozhodnutí bylo uloženo.'),
        })
    }
    function invite(data:Person) {
        openConfirmation({
            title:'Vydat pozvánku', label:'Vydat pozvánku',
            description:'Pro účet „' + data.name + '“ vznikne jednorázový kód s platností 48 hodin. Předchozí kód přestane platit.',
            action:() => save('invite', {id:data.id}, 'Předejte jednorázový kód zaměstnanci.'),
        })
    }
    function revoke(device:Device) {
        openConfirmation({
            title:'Odebrat přístup stanice', label:'Odebrat přístup',
            description:'Stanice „' + device.name + '“ přestane odesílat docházku.',
            destructive:true,
            action:() => save('revoke', {id:device.id}, 'Přístup odebrán.'),
        })
    }
    function closeMonth() {
        const data = scope()
        openConfirmation({
            title:'Uzavřít měsíc', label:'Uzavřít měsíc',
            description:'Uložíte schválené výkazy za období ' + data.month + ' jako novou verzi mzdového exportu.',
            action:() => save('close', data, 'Uzávěrka je připravena ke stažení.'),
        })
    }
    function submitRequest() {
        return run(async () => {
            const {kind, from, to, fromTime, toTime, note} = form.value
            const dayPattern = /^\d{4}-\d{2}-\d{2}$/
            if (!dayPattern.test(from) || !dayPattern.test(to)) throw new Error('Vyplňte platná data začátku a konce.')
            const allDay = kind === 'DOVOLENA'
            if (!allDay && (!fromTime || !toTime)) throw new Error('Vyplňte čas začátku a konce.')
            const begins = allDay ? from : from + 'T' + fromTime
            const ends = allDay ? to : to + 'T' + toTime
            if (allDay ? ends < begins : ends <= begins) throw new Error('Zkontrolujte pořadí začátku a konce.')
            await api('request', {employee:person.value, kind, note, from:begins, to:ends})
            const requestMonth = from.slice(0,7)
            if (requestMonth === month.value) {
                dismissEditor()
            } else {
                month.value = requestMonth
                editor.value = null
                clearScope()
                recordView(true)
            }
            form.value = freshRequest()
            notice.value = 'Žádost byla odeslána.'
            await refresh()
        })
    }
    function selectProof(id:number, event:FileUploadUploaderEvent){
        return prepareProof(id, Array.isArray(event.files) ? event.files[0] : event.files)
    }
    function openCamera(id:number){
        cameraRequest = id
        cameraInput.value?.click()
    }
    function captureProof(event:Event){
        const input = event.target as HTMLInputElement
        const file = input.files?.[0]
        input.value = ''
        if(file) return prepareProof(cameraRequest, file)
    }
    function prepareProof(id:number, file?:File){return run(async()=>{
        if (!file?.size) throw new Error('Vyberte fotografii propustky.')
        if (file.size > 25 * 1024 * 1024) throw new Error('Vyberte fotografii menší než 25 MB.')
        const { preparePhoto } = await import('./document-scan')
        originalPhoto = await preparePhoto(file).catch(() => {
            throw new Error('Fotografii nelze otevřít. Pořiďte nový snímek nebo zkuste soubor JPEG či PNG.')
        })
        photoRequest = id
        showOriginal()
        photoVisible.value = true
    })}
    function showOriginal(){
        if(!originalPhoto) return
        photoPreview.value = originalPhoto.toDataURL('image/jpeg', .9)
        adjusted.value = false
        scanMessage.value = ''
    }
    function straightenProof(){return run(async()=>{
        if(!originalPhoto) return
        scanMessage.value = 'Hledám okraje dokumentu…'
        try {
            const { straighten } = await import('./document-scan')
            const corrected = await straighten(originalPhoto)
            if(corrected){
                photoPreview.value = corrected.toDataURL('image/jpeg', .9)
                adjusted.value = true
                scanMessage.value = ''
            } else {
                scanMessage.value = 'Okraje se nepodařilo najít. Můžete uložit celý snímek nebo pořídit nový.'
            }
        } catch {
            scanMessage.value = 'Narovnání není dostupné. Můžete uložit celý snímek.'
        }
    })}
    function clearPhoto(){
        originalPhoto = null
        photoPreview.value = ''
        scanMessage.value = ''
        photoRequest = 0
    }
    function submitProof(){return run(async()=>{
        await api('request-proof-upload', {id:photoRequest, photo:photoPreview.value.split(',')[1]})
        photoVisible.value = false
        notice.value = 'Propustka byla uložena.'
        await refresh()
    })}
    function showProof(id:number){return run(async()=>{
        const proof=await api<{jpeg:string}>('request-proof',{id})
        preview.value='data:image/jpeg;base64,'+proof.jpeg
        proofVisible.value=true
    })}
    function download(version:number){return run(async()=>{
        const {csv}=await api<{csv:string}>('download',{month:month.value,version})
        const a=document.createElement('a');a.href=URL.createObjectURL(new Blob([csv],{type:'text/csv;charset=utf-8'}))
        a.download='dochazka-'+month.value+'-v'+version+'.csv';a.click();setTimeout(()=>URL.revokeObjectURL(a.href),1000)
    })}
    onMounted(async()=>{
        window.addEventListener('popstate', restoreView)
        try {
            if(window.isSecureContext && typeof PublicKeyCredential !== 'undefined'
                && typeof PublicKeyCredential.parseCreationOptionsFromJSON === 'function'){
                passkeyAvailable.value = (await api<{available:boolean}>('passkey-status').catch(() => ({available:false}))).available
            }
            setupAvailable.value=(await api<{available:boolean}>('setup-status')).available
            if(!setupAvailable.value) await setupUser(await api('me'))
        } catch(e) {
            if (!(e instanceof ApiError && e.status === 401)) error.value=(e as Error).message
        } finally {
            starting.value=false
            if (popPending) {
                popPending = false
                await restoreView()
            }
        }
    })
    onUnmounted(() => window.removeEventListener('popstate', restoreView))
    return {
        starting, setupAvailable, initial, createAdmin, submitRequest, submitProof, showProof, preview, proofVisible,
        settingsVisible, passwords, changePassword, showSettings, clearSettings, signInWithPasskey,
        passkeyAvailable, passkeyList, newPasskey, addPasskey, removePasskey,
        photoVisible, photoPreview, adjusted, scanMessage, cameraInput, mobileCamera, selectProof, openCamera,
        captureProof, straightenProof, showOriginal, clearPhoto,
        user, people, devices, payroll, person, month, sheet, tab, tabs, busy, error, notice, login, password,
        activation, code, form, edit, deviceName, secret, labels, roles, requestKinds, requestSeverities,
        tabIcons, time, moment, day, hours, requestPeriod, canUploadProof, scope, run, refresh, signIn, signOut, roleNames, hasRole,
        ownSheet, canReview, selectedPerson, inActionDialog, approvalBlockers, selectPerson, selectMonth, moveMonth,
        managesPerson, canSubmitRequest, scopePeople, editor, dismissEditor, showArchived, setShowArchived, directoryPeople,
        changeTab, submit, download, editPerson, addDevice, archivePerson, confirmationVisible, confirmation, decisionReason,
        decide, confirmAction, clearConfirmation, invite, revoke, closeMonth, newRequest, approveSheet,
    }
}
