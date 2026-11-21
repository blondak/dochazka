import { ref, computed, watch, nextTick, onMounted, onUnmounted } from 'vue'
import type { FileUploadUploaderEvent } from 'primevue/fileupload'

interface Person {id:number;name:string;number:string|null;org_unit_id:number|null;org_unit_path?:string;managed?:boolean;roles:string[];attendance_enabled:boolean;archived_on?:string|null;sid?:string|null;login?:string;starts?:string;ends?:string|null}
interface Row {seconds?:number|null;calculated_seconds?:number;partial_vacation?:boolean;generated_holiday?:boolean;day:string;kind:string;from:string;to:string}
interface ComputerSession {device:number;station:string;session:string;from:string;to:string|null;service:boolean}
interface DailyAttendance {
    day:string
    updated:string
    people:{id:number;name:string;approved_absences?:string[];status:'present'|'finished'|'absent'|'untracked';worked_seconds:number|null;from?:string|null;to?:string|null}[]
}
interface HolidayControlPreview {
    token:string;added:number;changed:number;removed:number
    skipped?:{missing_model:number;without_shift:number;occupied:number;unchanged:number}
}
interface RequestRow {needs_actual?:boolean;actual_from?:string|null;actual_to?:string|null;actual_returned?:boolean;vacation_seconds?:number;vacation_estimate?:{from:string;to:string}[];vacation_consent?:boolean;generated_holiday?:boolean;id:number;kind:string;begins:string;ends:string;note:string;status:string;reason:string;has_proof:boolean;merged_work?:boolean;replaces_from?:string;replaces_to?:string;account_name?:string;behavior?:Behavior;requires_proof?:boolean;scheduled_seconds?:number|null}
interface AttendanceInterval {day:string;from:string;to:string;source:string}
interface DayIssue {status:'missing'|'review';reasons:string[]}
interface Sheet {
    approval_blockers:string[];approval_note_required:boolean;presence?:AttendanceInterval[];breaks?:AttendanceInterval[];
    day_issues?:Record<string,DayIssue>;unresolved_adjustments?:Row[]; internal_by_day?:Record<string,Record<string,{seconds:number;recognized_seconds:number}>>;rows:Row[];sessions:ComputerSession[];seconds:number;pending:number;missing_proofs:number;approved:boolean;issues:string[];requests:RequestRow[];fingerprint:string;attendance_start?:string;attendance_end?:string|null;planned_shifts?:Record<string, {from:string;to:string}[]>;account_totals?:AccountTotal[];calendar_days?:Record<string, CalendarDay>}
interface Device {id:number;name:string;seen:string|null;agent_version:string|null;revoked:boolean;registered:boolean;warnings:string|null}
interface Payroll {people:{id:number;name:string;approved:boolean}[];versions:{version:number;created:string}[];changed:boolean}
interface Passkey {id:string;label:string;created:string}
interface Confirmation {
    title: string
    description: string
    details?: string
    label: string
    destructive?: boolean
    reasonRequired?: boolean
    reasonLabel?: string
    action: (reason: string) => Promise<void>
}
interface OrgUnit {id:number;name:string;parent_id:number|null;path:string}
interface UnitNode {key:string;label:string;data:OrgUnit;children:UnitNode[]}
type Behavior = 'work' | 'timed_absence' | 'shift_absence' | 'break' | 'calendar'
interface CalendarDay {name:string;account:string}
interface HolidayRule {
    id:number;code:string;name:string;account:string|null;valid_from:string;valid_to:string|null;month:number|null;day:number|null;easter_offset:number|null
}
interface CalendarSettings {holidays:HolidayRule[];holiday_accounts:{effective_on:string;account:string}[];days:(CalendarDay & {day:string})[]}
interface AttendanceAccount {
    code:string; name:string; description:string; position:number; behavior:Behavior; requires_proof:boolean;
    rounding_rules?:{mode:string;minutes:number;effective_on:string}[];
    break_rules?:{enabled:boolean;minutes:number;preferred:number;deadline:number;effective_on:string}[];
    active:boolean; system?:boolean; payroll_versions?:{effective_on:string;payroll_code:string}[]
}
interface AccountTotal {internal_seconds?:number;code:string;name:string;seconds:number;legacy_days:number}
interface Shift {break_mode?:'account'|'fixed';start:string;end:string;break_start:string|null;break_end:string|null}
interface WorkModel {
    id:number;name:string;versions:{effective_on:string;weekly_seconds?:number;week:(Shift|null)[]}[];
}
interface WorkAssignment {model:number;effective_on:string}
interface ModelImpact {token:string;impact:{name:string;month:string;requests:{id:number;kind:string}[]}[]}
const weekdays = ['Pondělí', 'Úterý', 'Středa', 'Čtvrtek', 'Pátek', 'Sobota', 'Neděle']
const behaviors:Record<Behavior,string> = {work:'Práce', timed_absence:'Časová nepřítomnost', shift_absence:'Celosměnová nepřítomnost', break:'Přestávka',calendar:'Svátek a den pracovního klidu'}
const behaviorOptions = (['work','timed_absence','shift_absence','calendar'] as const).map(value => ({value,label:behaviors[value]}))
const defaultShift = ():Shift => ({break_mode:'account',start:'08:00',end:'12:00',break_start:null,break_end:null})
type Editor = {kind:'unit'; id?:number} | {kind:'employee'; id?:number} | {kind:'device'} | {kind:'request'}
    | {kind:'account'; code?:string} | {kind:'model'; id?:number; version?:string}
    | {kind:'holiday-rule';id?:number} | {kind:'holiday-account'} | {kind:'calendar-day';day?:string}
    | {kind:'work'; row?:number; fingerprint:string}
    | {kind:'duration';row:number;stale?:boolean;fingerprint:string} | {kind:'break';day:string;from?:string} | {kind:'actual';id:number}
    | {kind:'holiday-control';employee?:number}
interface AttendanceView {
    account: number
    tab: string
    person: number
    month: string
    dailyDay: string|null
    showAllPeople: boolean
    unitFilter: number|null
    showArchived: boolean
    editor: Editor | null
}
const validMonth = /^(?!0000)\d{4}-(0[1-9]|1[0-2])$/
const local = new Intl.DateTimeFormat('sv-SE',{timeZone:'Europe/Prague',year:'numeric',month:'2-digit',day:'2-digit'})
const calendarDay = new Intl.DateTimeFormat('cs-CZ', {timeZone:'UTC', weekday:'short', day:'numeric', month:'numeric'})
const workClock = new Intl.DateTimeFormat('cs-CZ', {timeZone:'Europe/Prague',hour:'2-digit',minute:'2-digit',second:'2-digit',hourCycle:'h23'})
const pragueToday = () => local.format(new Date())
function validDay(value: string) {
    if (!/^(?!0000)\d{4}-\d{2}-\d{2}$/.test(value)) return false
    const parsed = new Date(value+'T00:00:00Z')
    return !Number.isNaN(parsed.getTime()) && parsed.toISOString().slice(0,10) === value
}
const labels:Record<string,string>={employee:'Zaměstnanec',manager:'Vedoucí',admin:'Správce',payroll:'Mzdová účetní',PRACE:'Práce',PRESTAVKA:'Přestávka',DOVOLENA:'Dovolená',LEKAR:'Lékař',pending:'Čeká na schválení',approved:'Schváleno',rejected:'Zamítnuto'}
const options = (values: string[]) => values.map(value => ({ value, label: labels[value] }))
const roles = options(['employee', 'manager', 'payroll', 'admin'])
const roleNames = (assigned:string[]) => assigned.map(role => labels[role]).join(', ')
const defaultRequestKinds = options(['LEKAR', 'DOVOLENA', 'PRACE', 'PRESTAVKA'])
const freshRequest = () => ({kind:'LEKAR',from:'',to:'',fromTime:'',toTime:'',note:'',vacationMode:'full',vacationHours:'',consent:false})
const tabIcons: Record<string, string> = {
    'Organizační struktura': 'pi pi-sitemap', 'Zaměstnanci': 'pi pi-users', 'Zařízení': 'pi pi-desktop', 'První kroky': 'pi pi-list-check',
    'Výkaz': 'pi pi-calendar', 'Žádosti': 'pi pi-file-edit', 'Uzávěrka': 'pi pi-check-square',
    'Denní přehled': 'pi pi-calendar-clock',
    'Svátky a dny volna':'pi pi-calendar', 'Nastavení docházky': 'pi pi-sliders-h', 'Konta docházky':'pi pi-list', 'Pracovní modely':'pi pi-calendar-clock',
}
const tabSlugs: Record<string, string> = {
    'Organizační struktura': 'organizace', 'Zaměstnanci': 'zamestnanci', 'Zařízení': 'zarizeni', 'První kroky': 'prvni-kroky',
    'Výkaz': 'vykaz', 'Žádosti': 'zadosti', 'Uzávěrka': 'uzaverka',
    'Denní přehled': 'denni-prehled',
    'Svátky a dny volna':'svatky', 'Nastavení docházky': 'nastaveni-dochazky', 'Konta docházky':'konta-dochazky', 'Pracovní modely':'pracovni-modely',
}
const monthlyTabs = ['Výkaz', 'Žádosti', 'Uzávěrka']
const requestSeverities: Record<string, string> = {pending:'warn', approved:'success', rejected:'danger'}
const pendingDayReason = 'Čekající žádost'
const dayIssueStyles = {
    missing: {icon:'pi pi-exclamation-circle', label:'Chybí docházka', severity:'error'},
    review: {icon:'pi pi-exclamation-triangle', label:'Ke kontrole', severity:'warn'},
}
const dailyStatuses = {
    present: {label:'Aktivní', severity:'success'},
    finished: {label:'Ukončena', severity:'info'},
    absent: {label:'Bez aktivní relace', severity:'secondary'},
    untracked: {label:'Bez přiřazeného účtu', severity:'warn'},
}
const date = (s: string) => new Date(s.replace(' ', 'T').replace(/([+-]\d{2})$/, '$1:00'))
const durationSeconds = (row: Row) => row.seconds ?? (date(row.to).getTime() - date(row.from).getTime()) / 1000
function pragueDayRange(from:string, to:string|null) {
    const start = date(from)
    const last = to ? new Date(Math.max(start.getTime(), date(to).getTime() - 1)) : start
    return {first:local.format(start), last:local.format(last)}
}
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
    const today = ref(pragueToday())
    const freshPerson=()=>({id:0,name:'',number:'',login:'',roles:['employee'],attendance_enabled:true,org_unit_id:null,sid:'',starts:today.value,ends:''})
    const user=ref<Person|null>(null), people=ref<Person[]>([]), devices=ref<Device[]>([]), payroll=ref<Payroll|null>(null)
    const person=ref(0), month=ref(today.value.slice(0,7)),sheet=ref<Sheet|null>(null),tab=ref('Výkaz')
    const daily = ref<DailyAttendance|null>(null)
    const dailyDay = ref<string|null>(null)
    const selectedDailyDay = computed(() => dailyDay.value ?? today.value)
    const gapMinutes = ref<string|null>(null)
    const mergeRows = ref<number[]>([])
    const durationEdit = ref({value:'', maximum:0, from:'', to:'', reason:'', reset:false})
    const breakEdit = ref({day:'',from:'',to:'',reason:'',mode:'record',id:undefined as number|undefined})
    const actualEdit = ref({from:'',to:'',reason:'',photo:''})
    const holidayControl = ref<HolidayControlPreview & {from:string;to:string;latest:string;checkHolidays:boolean}>(
        {from:'',to:'',latest:'',checkHolidays:true,token:'',added:0,removed:0,changed:0},
    )
    const holidayControlHasChanges = computed(() => holidayControl.value.added + holidayControl.value.changed + holidayControl.value.removed > 0)
    const roundingEdit = ref({change:false, mode:'down', minutes:30, effective_on:today.value})
    const breakRuleEdit = ref({change:false, enabled:true, minutes:30, preferred:240, deadline:360, effective_on:today.value})
    const workEdit = ref({kind:'PRACE', day:'', from:'', to:'', untilMidnight:false, reason:''})
    const chosenDay = ref('')
    const dayHeading = ref<HTMLElement|null>(null)
    const accounts = ref<AttendanceAccount[]>([])
    const models = ref<WorkModel[]>([])
    const accountPanel = ref('general')
    const accountForm = ref<AttendanceAccount & {payroll_code:string;effective_on:string}>({
        code:'', name:'', description:'', behavior:'timed_absence', position:50, active:true, requires_proof:false,
        payroll_code:'', effective_on:today.value,
    })
    const modelForm = ref<{id?:number;name:string;effective_on:string;week:(Shift|null)[];reason?:string}>({
        name:'',effective_on:today.value,week:[],
    })
    const modelWeek = computed(() => modelForm.value.week.map((shift, index) => ({
        shift, index, day:weekdays[index], next:(index + 1) % weekdays.length,
    })))
    const modelCopy = ref<{source:number;targets:number[]}|null>(null)
    function copyModelDays(source:number, targets:number[]) {
        const shift = modelForm.value.week[source]
        for (const target of targets) modelForm.value.week[target] = shift ? {...shift} : null
    }
    function applyModelCopy() {
        if (!modelCopy.value) return
        copyModelDays(modelCopy.value.source, modelCopy.value.targets)
        modelCopy.value = null
    }
    const employeePanel = ref('general')
    const employeeModels = ref<WorkModel[]>([])
    const employeeAssignments = ref<WorkAssignment[]>([])
    const employeeModelsLoading = ref(false)
    const employeeModelEdit = ref<{model:number|null;effective_on:string;original_effective_on?:string;reason:string}>({
        model:null, effective_on:today.value, reason:'',
    })
    const employeeAssignmentReason = computed(() => !!employeeModelEdit.value.original_effective_on ||
        (!!edit.value.id && employeeModelEdit.value.model !== null && employeeModelEdit.value.effective_on < today.value))
    const expandedModels = ref<Record<number,boolean>>({})
    const selectedModelVersions = ref<Record<number,string>>({})
    const pendingModelImpact = ref('')

    const calendar = ref<CalendarSettings>({holidays:[],holiday_accounts:[], days:[]})
    const defaultHolidayAccount = {code:'default',name:'Výchozí podle data'}
    const newHolidayRule = () => ({code:'',name:'',account:defaultHolidayAccount.code,valid_from:today.value,valid_to:'',type:'fixed',month:'1',day:'1',offset:'0'})
    const holidayRuleForm = ref(newHolidayRule())
    const ruleTypes = [{value:'fixed',label:'Pevné datum'}, {value:'easter',label:'Podle Velikonoc'}]
    function holidayDate(rule:HolidayRule) {
        if (rule.easter_offset === null) return rule.day + '. ' + rule.month + '.'
        if (rule.easter_offset === 0) return 'Velikonoční neděle'
        if (rule.easter_offset === -2) return 'Velký pátek (neděle − 2 dny)'
        if (rule.easter_offset === 1) return 'Velikonoční pondělí (neděle + 1 den)'
        return 'Posun od velikonoční neděle ve dnech: ' + rule.easter_offset
    }
    function saveHolidayRule() {
        if (editor.value?.kind !== 'holiday-rule') return
        const {type,month,day,offset,valid_to,account,...data} = holidayRuleForm.value
        return submit('calendar-holiday', {...data, id:editor.value.id, account:account === defaultHolidayAccount.code ? null : account, valid_to:valid_to || null,
            month:type === 'fixed' ? Number(month) : null, day:type === 'fixed' ? Number(day) : null,
            easter_offset:type === 'easter' ? Number(offset) : null}, 'Pravidlo svátku bylo uloženo.')
    }
    function removeHolidayRule(rule:HolidayRule) {
        const future = rule.valid_from > local.format(new Date())
        openConfirmation({title:'Odebrat pravidlo svátku', label:'Odebrat', destructive:future,
            description:rule.name,
            details:future ? 'Pravidlo zatím nezačalo platit a bude odstraněno.' : 'Pravidlo bude platit do dneška včetně.',
            action:() => save('calendar-holiday-remove', {id:rule.id}, future
                ? 'Budoucí pravidlo bylo odstraněno.' : 'Platnost pravidla byla ukončena.')})
    }
    const holidayForm = ref({effective_on:today.value, account:''})
    const calendarDayForm = ref({day:'',name:'',account:''})
    const calendarAccounts = computed(() => accounts.value.filter(account => account.active))
    const holidayRuleAccounts = computed(() => [defaultHolidayAccount, ...calendarAccounts.value])
    function holidayAccount(day:string) {
        return calendar.value.holiday_accounts.filter(row => row.effective_on <= day)
            .sort((a,b) => b.effective_on.localeCompare(a.effective_on))[0]?.account ?? 'SVATEK'
    }
    function removeCalendarDay(entry:CalendarDay & {day:string}) {
        openConfirmation({title:'Odebrat den volna', label:'Odebrat', destructive:true,
            description:day(entry.day) + ' · ' + entry.name,
            action:() => save('calendar-day', {day:entry.day, remove:true}, 'Den volna byl odebrán.')})
    }
    function activeModelVersion(model:WorkModel) {
        return model.versions.find(version => version.effective_on <= today.value) ?? model.versions.at(-1)!
    }
    function displayModelVersion(model:WorkModel) {
        return model.versions.find(version => version.effective_on === selectedModelVersions.value[model.id]) ?? activeModelVersion(model)
    }
    const accountName = (code:string) => accounts.value.find(account => account.code === code)?.name ?? labels[code] ?? code
    const requestKinds = computed(() => accounts.value.length
        ? accounts.value.filter(account => account.active && account.behavior !== 'calendar').map(account => ({value:account.code,label:account.name}))
        : defaultRequestKinds)
    const workBehaviors = computed(() => new Set(accounts.value.filter(account => account.behavior === 'work').map(account => account.code).concat('PRACE')))
    function modelSeconds(week:(Shift|null)[]) {
        const minutes = (time:string) => Number(time.slice(0, 2)) * 60 + Number(time.slice(3))
        return week.reduce((total, shift) => total + (shift ? minutes(shift.end) - minutes(shift.start)
            - (shift.break_start && shift.break_end ? minutes(shift.break_end) - minutes(shift.break_start) : 0) : 0), 0) * 60
    }
    const monthDays = computed(() => {
        const last = new Date(month.value + '-01T00:00:00Z')
        last.setUTCMonth(last.getUTCMonth() + 1, 0)
        const days = Array.from({length:last.getUTCDate()}, (_, index) => {
            const key = month.value + '-' + String(index + 1).padStart(2, '0')
            const calendarDate = new Date(key + 'T00:00:00Z')
            const weekday = calendarDate.getUTCDay()
            return {
                key, label:calendarDay.format(calendarDate), weekend:weekday === 0 || weekday === 6, today:key === today.value,
                holiday:sheet.value?.calendar_days?.[key], holidaySeconds:0,
                seconds:0, internal:null as number|null, rows:[] as (Row & {sheetIndex:number})[], sessions:[] as ComputerSession[], requests:[] as RequestRow[],
            }
        })
        for (const [sheetIndex, row] of (sheet.value?.rows ?? []).entries()) {
            const entry = days[Number(row.day.slice(-2)) - 1]
            if (entry?.key !== row.day) continue
            entry.rows.push({...row, sheetIndex})
            if (row.generated_holiday) entry.holidaySeconds += durationSeconds(row)
            if (workBehaviors.value.has(row.kind)) entry.seconds += durationSeconds(row)
        }
        for (const session of sheet.value?.sessions ?? []) {
            if (!hasRole('admin') && !session.service) continue
            const {first, last} = pragueDayRange(session.from, session.to)
            for (const entry of days) {
                if (entry.key >= first && entry.key <= last) entry.sessions.push(session)
            }
        }
        for (const request of sheet.value?.requests ?? []) {
            const {first, last} = pragueDayRange(request.begins, request.ends)
            for (const entry of days) {
                if (entry.key >= first && entry.key <= last) entry.requests.push(request)
            }
        }
        for (const entry of days) {
            const amounts = sheet.value?.internal_by_day?.[entry.key]
            if (amounts) {
                entry.internal = Object.entries(amounts).reduce((total, [kind, value]) => total +
                    (workBehaviors.value.has(kind) ? value.seconds - value.recognized_seconds : 0), entry.seconds)
            }
        }
        const first = sheet.value?.attendance_start ?? selectedPerson.value?.starts
        const lastDay = sheet.value?.attendance_end === undefined
            ? [selectedPerson.value?.ends, selectedPerson.value?.archived_on].filter(Boolean).sort()[0]
            : sheet.value.attendance_end
        return days.map(entry => {
            const issue = sheet.value?.day_issues?.[entry.key]
            const pending = entry.requests.some(request => request.status === 'pending')
            const attention: DayIssue|undefined = pending
                ? {status:'review', reasons:[pendingDayReason, ...(issue?.reasons ?? [])]}
                : issue
            return {...entry, attention, planned: sheet.value?.planned_shifts?.[entry.key] ?? []}
        }).filter(entry => (!first || entry.key >= first) && (!lastDay || entry.key <= lastDay)
            && (!entry.weekend || entry.today || entry.holiday || entry.attention || entry.planned.length > 0
                || entry.rows.length > 0 || entry.sessions.length > 0 || entry.requests.length > 0))
    })
    const selectedDay = computed(() => {
        const days = monthDays.value
        const current = days.find(entry => entry.today)
        const hasRecords = (entry: typeof days[number]) => entry.rows.length > 0 || entry.sessions.length > 0 || entry.requests.length > 0
        return days.find(entry => entry.key === chosenDay.value)
            ?? current ?? days.find(hasRecords) ?? days[0]
    })
    const monthAbsences = computed(() => {
        let doctorSeconds = 0
        const vacationDays = new Set<string>()
        for (const row of sheet.value?.rows ?? []) {
            if (row.kind === 'LEKAR') doctorSeconds += durationSeconds(row)
            if (row.kind === 'DOVOLENA') vacationDays.add(row.day)
        }
        return {doctorSeconds, vacationDays:vacationDays.size}
    })
    async function selectDay(key:string) {
        if (key !== selectedDay.value?.key) mergeRows.value = []
        chosenDay.value = key
        if (window.matchMedia('(max-width: 760px)').matches) {
            await nextTick()
            dayHeading.value?.focus({preventScroll:true})
            dayHeading.value?.scrollIntoView({block:'nearest'})
        }
    }
    function selectDayFromRow(event: MouseEvent) {
        const button = (event.target as HTMLElement).closest('tr')?.querySelector<HTMLButtonElement>('button[data-sheet-day]')
        if (button?.dataset.sheetDay) void selectDay(button.dataset.sheetDay)
    }
    async function showPendingRequests() {
        const entry = monthDays.value.find(item => item.requests.some(request => request.status === 'pending'))
        if (!entry) return
        await selectDay(entry.key)
        await nextTick()
        const heading = document.getElementById('day-requests-title')
        heading?.focus({preventScroll:true})
        heading?.scrollIntoView({block:'nearest'})
    }
    let dailyTimer: number | undefined
    const preview=ref(''), proofVisible=ref(false)
    const settingsVisible = ref(false)
    const accountMenuOpen = ref(false)
    const navigationVisible = ref(false)
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
    const serviceCode = /^#service-session=([a-f0-9]{64})$/.exec(location.hash)?.[1]
    const serviceContext = ref<{station:string;from:string}|null>(null)
    const serviceDone = ref(false), showServiceSessions = ref(false)
    const serviceCredentials = ref({login:'', password:''})
    const initial=ref({token:'',name:'',login:'',password:'',repeat:''})
    const form=ref(freshRequest()), edit=ref<Person>(freshPerson()), deviceName=ref(''), secret=ref('')
    const units = ref<OrgUnit[]>([])
    const needsSetup = computed(() => !units.value.length
        || !people.value.some(p => p.attendance_enabled && !p.archived_on)
        || !devices.value.some(d => !d.revoked))
    const unitEdit = ref({id:undefined as number|undefined, name:'', parent_id:null as number|null})
    const originalUnit = computed(() => units.value.find(u => u.id === unitEdit.value.id))
    const unitTree = computed(() => {
        const nodes = new Map<number, UnitNode>()
        for (const u of units.value) nodes.set(u.id, {key:String(u.id), label:u.name, data:u, children:[]})
        const roots:UnitNode[] = []
        for (const node of nodes.values()) {
            const parent = nodes.get(node.data.parent_id ?? 0)
            if (parent) parent.children.push(node)
            else roots.push(node)
        }
        return roots
    })
    const parentOptions = computed(() => {
        const result:OrgUnit[] = []
        function visit(nodes:UnitNode[]) {
            for (const node of nodes) {
                if (node.data.id === unitEdit.value.id) continue
                result.push(node.data)
                visit(node.children)
            }
        }
        visit(unitTree.value)
        return result
    })
    const newUnitPath = computed(() => [
        units.value.find(u => u.id === unitEdit.value.parent_id)?.path, unitEdit.value.name.trim(),
    ].filter(Boolean).join(' › '))
    const employeeUnit = computed({
        get:() => edit.value.org_unit_id ? {[edit.value.org_unit_id]:true} : {},
        set:(keys:Record<string, boolean>|null) => { edit.value.org_unit_id = Number(Object.keys(keys ?? {})[0]) || null },
    })
    function deleteUnit(unit:OrgUnit) {
        openConfirmation({title:'Smazat jednotku', label:'Smazat', destructive:true, description:unit.path,
            action:() => save('org-unit-delete', {id:unit.id}, 'Jednotka byla smazána.')})
    }
    const editor = ref<Editor|null>(null), showArchived = ref(false), showAllPeople = ref(false)
    let popPending = false
    const unitFilter = ref<number|null>(null)
    const filteredUnit = computed(() => units.value.find(unit => unit.id === unitFilter.value))
    const directoryPeople = computed(() => {
        const branch = new Set<number>()
        function visit(nodes:UnitNode[], inBranch:boolean) {
            for (const node of nodes) {
                const include = inBranch || node.data.id === unitFilter.value
                if (include) branch.add(node.data.id)
                visit(node.children, include)
            }
        }
        if (unitFilter.value !== null) visit(unitTree.value, false)
        return people.value.filter(person => (showArchived.value || !person.archived_on)
            && (unitFilter.value === null || branch.has(person.org_unit_id ?? 0)))
    })
    const hasRole = (role:string) => user.value?.roles.includes(role) ?? false
    const sessionsLabel = computed(() => hasRole('admin') ? 'Relace počítače' : 'Servisní relace')
    const visibleSessions = computed(() => (selectedDay.value?.sessions ?? []).filter(session =>
        !hasRole('admin') || !session.service || showServiceSessions.value,
    ))
    const ownSheet = computed(() => user.value?.id === person.value)
    const selectedPerson = computed(() => people.value.find(p => p.id === person.value))
    const managesPerson = computed(() => hasRole('manager') && selectedPerson.value?.managed)
    const canReview = computed(() => managesPerson.value && !ownSheet.value && selectedPerson.value?.attendance_enabled)
    const canMergeDay = computed(() => canReview.value && (selectedDay.value?.rows.filter(row => row.kind === 'PRACE' && !row.generated_holiday).length ?? 0) > 1)
    const canSubmitRequest = computed(() => (ownSheet.value || managesPerson.value) && selectedPerson.value?.attendance_enabled)
    const scopePeople = computed(() => people.value.filter(p => {
        if (p.id === user.value?.id) return p.attendance_enabled
        return hasRole('admin') || (hasRole('payroll') && tab.value === 'Výkaz')
            || (hasRole('manager') && p.managed)
    }))
    const attendancePeople = computed(() => scopePeople.value.filter(p => p.attendance_enabled))
    const selectablePeople = computed(() => showAllPeople.value ? scopePeople.value : attendancePeople.value)
    const canShowAllPeople = computed(() => scopePeople.value.length > attendancePeople.value.length)
    function personInScope(id: number) {
        return selectablePeople.value.some(p => p.id === id) ? id : attendancePeople.value[0]?.id ?? 0
    }
    const inActionDialog = computed(() =>
        settingsVisible.value || photoVisible.value || confirmationVisible.value || editor.value,
    )
    const approvalBlockers = computed(() => sheet.value?.approval_blockers ?? ['Pro schválení výkazu aktualizujte centrálu.'])
    const adminTabs = ['Zaměstnanci', 'Organizační struktura', 'Zařízení', 'Konta docházky', 'Pracovní modely', 'Svátky a dny volna', 'Nastavení docházky']
    const mainTabs = computed(() => [
        ...(hasRole('admin') && needsSetup.value ? ['První kroky'] : []),
        ...(hasRole('payroll') ? ['Uzávěrka', 'Výkaz'] : []),
        ...(hasRole('admin') || hasRole('manager') || (hasRole('employee') && user.value?.attendance_enabled)
            ? (hasRole('payroll') ? ['Žádosti'] : ['Výkaz', 'Žádosti']) : []),
        ...(hasRole('manager') ? ['Denní přehled'] : []),
    ])
    const tabs = computed(() => [...(hasRole('admin') ? adminTabs : []), ...mainTabs.value])
    const hours = (seconds:number) => {
        const minutes = Math.floor(seconds / 60)
        return Math.floor(minutes / 60) + ':' + String(minutes % 60).padStart(2, '0')
    }
    const time=(s:string)=>s?new Intl.DateTimeFormat('cs-CZ',{timeZone:'Europe/Prague',hour:'2-digit',minute:'2-digit'}).format(date(s)):''
    const moment=(s:string|null)=>s?date(s).toLocaleString('cs-CZ',{timeZone:'Europe/Prague'}):'—'
    const day=(s:string)=>new Date(s+'T12:00:00').toLocaleDateString('cs-CZ')
    function requestPeriod(request: RequestRow) {
        if (request.behavior === 'shift_absence' || request.kind === 'DOVOLENA') {
            const firstDay = date(request.begins).toLocaleDateString('cs-CZ', {timeZone:'Europe/Prague'})
            const lastDay = new Date(date(request.ends).getTime()-1)
                .toLocaleDateString('cs-CZ', {timeZone:'Europe/Prague'})
            return firstDay === lastDay ? firstDay : firstDay + ' – ' + lastDay
        }
        return moment(request.begins) + ' – ' + moment(request.ends)
    }
    function canUploadProof(request: RequestRow) {
        return ownSheet.value && selectedPerson.value?.attendance_enabled && (request.requires_proof ?? request.kind === 'LEKAR') && request.status === 'approved'
            && !request.has_proof && date(request.actual_to ?? request.ends).getTime() <= Date.now()
    }
    const scope=()=>({employee:person.value,month:month.value})
    async function loadDirectory() {
        const [directory, catalog] = await Promise.all([api<Person[]>('people'), api<OrgUnit[]>('org-units')])
        people.value = directory
        units.value = catalog
    }
    async function refresh(){
        today.value = pragueToday()
        if(!user.value)return
        try {
            if (tab.value === 'Konta docházky') accounts.value = await api('attendance-accounts')
            else if (tab.value === 'Svátky a dny volna') calendar.value = await api('calendar-settings')
            else if (tab.value === 'Pracovní modely') models.value = await api('work-models')
            else if(tab.value==='Zařízení')devices.value=await api('devices')
            else if (tab.value === 'Denní přehled') {
                daily.value = await api('daily-attendance', dailyDay.value ? {day:dailyDay.value} : {})
            }
            else if(tab.value==='Nastavení docházky') {
                gapMinutes.value = String((await api<{gap_minutes:number}>('attendance-merge-settings')).gap_minutes)
            }
            else if(tab.value==='Uzávěrka') payroll.value=await api('payroll',scope())
            else if(tab.value==='Zaměstnanci' || tab.value==='Organizační struktura') await loadDirectory()
            else if (['Výkaz', 'Žádosti'].includes(tab.value)) {
                if (hasRole('manager')) people.value = await api('people')
                person.value = personInScope(person.value)
                if (!person.value) { sheet.value = null; return }
                const next = await api<Sheet>('sheet', scope())
                if (next.fingerprint !== sheet.value?.fingerprint) mergeRows.value = []
                sheet.value = next
            }
        } catch (error) {
            sheet.value = null
            payroll.value = null
            if(tab.value==='Denní přehled') daily.value = null
            if(tab.value==='Zařízení' || tab.value==='První kroky') devices.value = []
            throw error
        }
    }
    async function setupUser(u:Person){
        user.value=u
        accounts.value = await api('attendance-accounts')
        if (hasRole('admin')) {
            await Promise.all([loadDirectory(), api<Device[]>('devices').then(result => { devices.value = result })])
        } else {
            people.value = await api('people')
        }
        person.value=u.id
        showAllPeople.value = false
        form.value = freshRequest()
        tab.value = hasRole('admin') && people.value.every(p => !p.attendance_enabled) ? 'První kroky' : tabs.value[0] ?? 'Účet'
        month.value = today.value.slice(0,7)
        const previous = history.state?.attendance as AttendanceView | undefined
        const available = previous?.account === u.id && tabs.value.includes(previous.tab) ? previous : view()
        applyView(viewFromUrl(available))
        if (!['Zaměstnanci', 'Organizační struktura', 'Zařízení', 'První kroky'].includes(tab.value)) await refresh()
        if (previous?.account === u.id) showEditor(previous.editor)
        recordView(true)
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
                daily.value = null
                secret.value = ''
                proofVisible.value = false
                photoVisible.value = false
                settingsVisible.value = false
                navigationVisible.value = false
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
    async function passkeyAssertion(endpoint:string, parameters:object = {}) {
        const {publicKey} = await api<{publicKey:PublicKeyCredentialRequestOptionsJSON}>(endpoint, parameters)
        const key = await navigator.credentials.get({publicKey:PublicKeyCredential.parseRequestOptionsFromJSON(publicKey)})
        if(!(key instanceof PublicKeyCredential)) throw new Error('Ověření bylo zrušeno.')
        return {id:key.id, ...key.toJSON().response}
    }
    function signInWithPasskey(){return run(async()=>{
        await setupUser(await api('passkey-login', await passkeyAssertion('passkey-login-options')))
    })}
    function verifyServiceSession(withPasskey = false) {return run(async()=>{
        try {
            const input = withPasskey
                ? {passkey: await passkeyAssertion('service-session-passkey-options', {code:serviceCode})}
                : serviceCredentials.value
            await api('service-session-verify', {code:serviceCode, ...input})
            serviceDone.value = true
        } finally {
            serviceCredentials.value.password = ''
        }
    })}
    function openAttendance() {
        location.assign(location.pathname)
    }
    function clearScope() {
        sheet.value = null
        payroll.value = null
        daily.value = null
        mergeRows.value = []
        chosenDay.value = ''
    }
    function view(): AttendanceView {
        return {
            account: user.value?.id ?? 0, tab: tab.value, person: person.value, month: month.value,
            dailyDay:dailyDay.value, showAllPeople:showAllPeople.value,
            unitFilter:unitFilter.value, showArchived: showArchived.value, editor: editor.value ? {...editor.value} : null,
        }
    }
    function viewFromUrl(fallback: AttendanceView): AttendanceView {
        const parameters = new URLSearchParams(location.search)
        const slug = parameters.get('sekce')
        const section = tabs.value.find(name => tabSlugs[name] === slug)
        const period = parameters.get('mesic')
        const day = parameters.get('den')
        return {
            ...fallback, tab: section ?? fallback.tab,
            unitFilter:Number(parameters.get('jednotka')) || null,
            month: period && validMonth.test(period) ? period : fallback.month,
            dailyDay: (section ?? fallback.tab) === 'Denní přehled' ? day : fallback.dailyDay,
        }
    }
    function recordView(replace = false) {
        if (popPending || !user.value) return
        const attendance = view()
        const url = new URL(location.href)
        const slug = tabSlugs[attendance.tab]
        if (slug) url.searchParams.set('sekce', slug)
        else url.searchParams.delete('sekce')
        if (attendance.tab === 'Zaměstnanci' && attendance.unitFilter) url.searchParams.set('jednotka', String(attendance.unitFilter))
        else url.searchParams.delete('jednotka')
        if (monthlyTabs.includes(attendance.tab)) url.searchParams.set('mesic', attendance.month)
        else url.searchParams.delete('mesic')
        if (attendance.tab === 'Denní přehled' && attendance.dailyDay) url.searchParams.set('den', attendance.dailyDay)
        else url.searchParams.delete('den')
        if (url.href === location.href && JSON.stringify(history.state?.attendance) === JSON.stringify(attendance)) return
        const state = {...history.state, attendance}
        if (replace) history.replaceState(state, '', url)
        else history.pushState(state, '', url)
    }
    function applyView(next: AttendanceView) {
        if (tabs.value.includes(next.tab)) tab.value = next.tab
        if (validMonth.test(next.month)) month.value = next.month
        today.value = pragueToday()
        dailyDay.value = next.dailyDay && validDay(next.dailyDay) && next.dailyDay < today.value ? next.dailyDay : null
        showAllPeople.value = next.showAllPeople ?? false
        person.value = personInScope(next.person)
        unitFilter.value = tab.value === 'Zaměstnanci' ? next.unitFilter ?? null : null
        showArchived.value = next.showArchived
    }
    function showEditor(next: Editor | null) {
        modelCopy.value = null
        if (next?.kind === 'employee' && hasRole('admin') && ['Zaměstnanci', 'Výkaz'].includes(tab.value)) {
            const {id} = next
            const data = people.value.find(p => p.id === id)
            if (id !== undefined && !data) {
                editor.value = null
                return
            }
            edit.value = data ? {...data, roles:[...data.roles]} : freshPerson()
            employeePanel.value = 'general'
            employeeModelEdit.value = {model:null, effective_on:today.value, reason:''}
            pendingModelImpact.value = ''
            employeeModels.value = []
            employeeAssignments.value = []
        } else if (next?.kind === 'unit' && tab.value === 'Organizační struktura') {
            const {id} = next
            const unit = units.value.find(u => u.id === id)
            if (next.id !== undefined && !unit) { editor.value = null; return }
            unitEdit.value = {id:unit?.id, name:unit?.name ?? '', parent_id:unit?.parent_id ?? null}
        } else if (next?.kind === 'account' && tab.value === 'Konta docházky') {
            const {code} = next
            const existing = accounts.value.find(account => account.code === code)
            if (code && !existing) { editor.value = null; return }
            accountPanel.value = 'general'
            roundingEdit.value = {change:false, mode:'down', minutes:30, ...existing?.rounding_rules?.find(rule => rule.effective_on <= today.value), effective_on:today.value}
            breakRuleEdit.value = {change:false, enabled:true, minutes:30, preferred:240, deadline:360, ...existing?.break_rules?.find(rule => rule.effective_on <= today.value), effective_on:today.value}
            accountForm.value = {...(existing ?? {code:'',name:'',description:'',behavior:'timed_absence',position:50,active:true,requires_proof:false}),
                payroll_code:'',effective_on:today.value}
        } else if (next?.kind === 'holiday-rule' && tab.value === 'Svátky a dny volna') {
            const {id} = next
            const rule = calendar.value.holidays?.find(row => row.id === id)
            if (id !== undefined && !rule) { editor.value = null; return }
            holidayRuleForm.value = rule ? {code:rule.code, name:rule.name, account:rule.account ?? defaultHolidayAccount.code, valid_from:rule.valid_from, valid_to:rule.valid_to ?? '',
                type:rule.easter_offset === null ? 'fixed' : 'easter', month:String(rule.month ?? 1), day:String(rule.day ?? 1),
                offset:String(rule.easter_offset ?? 0)} : newHolidayRule()
        } else if (next?.kind === 'holiday-account' && tab.value === 'Svátky a dny volna') {
            holidayForm.value = {effective_on:today.value, account:holidayAccount(today.value)}
        } else if (next?.kind === 'calendar-day' && tab.value === 'Svátky a dny volna') {
            const {day} = next
            const existing = calendar.value.days.find(entry => entry.day === day)
            if (next.day && !existing) { editor.value = null; return }
            calendarDayForm.value = existing ? {...existing} : {day:today.value, name:'', account:holidayAccount(today.value)}
        } else if (next?.kind === 'model' && tab.value === 'Pracovní modely') {
            const {id, version:originalVersion} = next
            const existing = models.value.find(model => model.id === id)
            const version = originalVersion ? existing?.versions.find(item => item.effective_on === originalVersion) : undefined
            if ((next.id !== undefined && !existing) || (next.version && !version)) { editor.value = null; return }
            const week = (version ?? (existing && activeModelVersion(existing)))?.week ?? weekdays.map((_, index) => index < 5 ? defaultShift() : null)
            modelForm.value = {id:existing?.id,name:existing?.name ?? '',effective_on:version?.effective_on ?? today.value,
                week:week.map(shift => shift ? {...shift,break_mode:shift.break_mode ?? 'fixed'} : null), reason:''}
            pendingModelImpact.value = ''
        } else if (next?.kind === 'duration' && canReview.value) {
            const row = next.stale ? sheet.value?.unresolved_adjustments?.[next.row] : sheet.value?.rows[next.row]
            if (!row || next.fingerprint !== sheet.value?.fingerprint) { editor.value = null; return }
            durationEdit.value = {value:editableDuration(durationSeconds(row)),maximum:row.calculated_seconds ?? durationSeconds(row),
                from:row.from,to:row.to,reason:'',reset:!!next.stale}
        } else if (next?.kind === 'break' && canReview.value) {
            const {day,from} = next
            const item = sheet.value?.breaks?.find(entry => entry.day === day && (!from || entry.from === from))
            const existing = item?.source === 'recorded' ? sheet.value?.requests.find(request =>
                request.kind === 'PRESTAVKA' && request.status === 'approved' && date(request.begins).getTime() === date(item.from).getTime()) : undefined
            breakEdit.value = {day:next.day,from:item ? time(item.from) : '',to:item ? time(item.to) : '',reason:'',mode:'record',id:existing?.id}
        } else if (next?.kind === 'actual') {
            const {id} = next
            const request = sheet.value?.requests.find(item => item.id === id)
            if (!request || !request.needs_actual || !(ownSheet.value || canReview.value)) { editor.value = null; return }
            const from = date(request.actual_from ?? request.begins)
            const to = date(request.actual_to ?? request.ends)
            actualEdit.value = {from:local.format(from) + 'T' + time(from.toISOString()),to:local.format(to) + 'T' + time(to.toISOString()),reason:'',photo:''}
        } else if (next?.kind === 'holiday-control' && hasRole('manager')) {
            const dayBefore = new Date(local.format(new Date()) + 'T12:00:00Z')
            dayBefore.setUTCDate(dayBefore.getUTCDate() - 1)
            const yesterday = dayBefore.toISOString().slice(0,10)
            let from = selectedDailyDay.value < yesterday ? selectedDailyDay.value : yesterday
            let to = from
            if (next.employee) {
                const first = month.value + '-01'
                const lastDay = new Date(first+'T00:00:00Z')
                lastDay.setUTCMonth(lastDay.getUTCMonth() + 1, 0)
                const last = lastDay.toISOString().slice(0,10)
                from = first <= yesterday ? first : ''
                to = from ? (last <= yesterday ? last : yesterday) : ''
            }
            holidayControl.value = {from,to,latest:yesterday,checkHolidays:true,token:'',added:0,removed:0,changed:0}
        } else if (next?.kind === 'work' && tab.value === 'Výkaz' && canReview.value && sheet.value?.fingerprint === next.fingerprint) {
            if (next.row === undefined) {
                const active = selectedDay.value?.key ?? ''
                workEdit.value = {kind:'PRACE', day:active, from:'', to:'', untilMidnight:false, reason:''}
            } else {
                const row = sheet.value.rows[next.row]
                if (row?.kind !== 'PRACE' || row.generated_holiday) { editor.value = null; return }
                chosenDay.value = row.day
                const to = date(row.to)
                workEdit.value = {
                    kind:'PRACE', day:row.day, from:workClock.format(date(row.from)),
                    to:workClock.format(to), untilMidnight:local.format(to) !== row.day, reason:'',
                }
            }
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
            || before.dailyDay !== dailyDay.value
        if (scopeChanged) {
            editor.value = null
            settingsVisible.value = false
            navigationVisible.value = false
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
        if (entry.tab === tab.value && entry.person === person.value && entry.month === month.value && (entry.dailyDay ?? null) === dailyDay.value) {
            applyView(entry)
            showEditor(entry.editor)
            return
        }
        return run(() => renderView(entry, false))
    }
    function navigate(next: Partial<Pick<AttendanceView,'tab'|'person'|'month'|'dailyDay'|'showAllPeople'|'showArchived'|'unitFilter'>>) {
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
    function moveDailyDay(offset: number) {
        const next = new Date(selectedDailyDay.value+'T00:00:00Z')
        next.setUTCDate(next.getUTCDate() + offset)
        const value = next.toISOString().slice(0,10)
        if (validDay(value) && value <= today.value) return navigate({dailyDay:value})
    }
    function goToDailyToday() {
        return navigate({dailyDay:null})
    }
    function openUnitDirectory(id:number|null) {
        return navigate({tab:'Zaměstnanci', unitFilter:id})
    }
    function selectPerson(id: number) {
        return navigate({person:id})
    }
    function setShowAllPeople(value: boolean) {
        return navigate({showAllPeople:value})
    }
    function changeTab(t:string, employee?:number) {
        navigationVisible.value = false
        const includedFromDirectory = employee !== undefined && people.value.some(p => p.id === employee && !p.attendance_enabled)
        return navigate({tab:t, person:employee ?? person.value, showAllPeople:showAllPeople.value || includedFromDirectory, unitFilter:null})
    }
    function openDailySheet(employee: number) {
        const day = daily.value?.day
        if (!day) return
        const next = {...view(), tab:'Výkaz', person:employee, month:day.slice(0,7), unitFilter:null, editor:null}
        return run(async () => {
            if (!scopePeople.value.some(p => p.id === employee)) {
                people.value = await api('people')
                if (!scopePeople.value.some(p => p.id === employee)) throw new Error('Výkaz vybrané osoby už není dostupný.')
            }
            await renderView(next, true)
            chosenDay.value = day
        })
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
        if (op === 'save-work-row' && 'day' in data && typeof data.day === 'string') chosenDay.value = data.day
        if (editor.value && ['employee','register','org-unit','save-work-row','attendance-account','work-model','employee-work-model','calendar-holiday-account','calendar-day','calendar-holiday','adjust-recognized-work','attendance-break','request-actual','holiday-control'].includes(op)) dismissEditor()
        if (op === 'employee' && tab.value === 'Výkaz') await loadDirectory()
        await refresh()
    }
    function submit(op:string,data:object,msg:string) {
        return run(() => save(op,data,msg))
    }
    watch(() => employeePanel.value === 'attendance' && editor.value?.kind === 'employee' ? editor.value : null,
        async (current, _, cleanup) => {
            if (!current) return
            let live = true
            cleanup(() => { live = false; employeeModelsLoading.value = false })
            employeeModelsLoading.value = true
            try {
                const choices = await api<{models:WorkModel[];assignments:WorkAssignment[]}>('employee-model-choices', {employee:current.id})
                if (live) {
                    employeeModels.value = choices.models
                    employeeAssignments.value = choices.assignments
                }
            } catch (problem) {
                if (live) error.value = (problem as Error).message
            } finally {
                if (live) employeeModelsLoading.value = false
            }
        })
    function editAssignment(assignment:WorkAssignment) {
        employeeModelEdit.value = {...assignment, original_effective_on:assignment.effective_on, reason:''}
    }
    async function previewModelChange(data:object, apply:(token:string) => Promise<void>) {
        const preview = await api<ModelImpact>('work-model-change-preview', data)
        pendingModelImpact.value = preview.impact.length ? preview.impact.map(item => item.name + ' · ' + item.month +
            (item.requests.length ? ' · žádosti ' + item.requests.map(request => '#' + request.id).join(', ') : '')).join('\n') :
            'Změna se nedotkne uložených výkazů ani žádostí.'
        openConfirmation({title:'Potvrdit změnu rozvrhu', label:'Uložit změnu', description:'Zkontrolujte dopady změny.',
            details:pendingModelImpact.value, action:() => apply(preview.token)})
    }
    function submitEmployee() {
        return run(async () => {
            const employee = {...edit.value, roles:[...edit.value.roles]}
            const assignment = employee.attendance_enabled && employeeModelEdit.value.model !== null ? {...employeeModelEdit.value} : null
            const required = [
                [!employee.name.trim(), 'general', '#employee-name', 'Vyplňte jméno.'],
                [employee.attendance_enabled && !employee.number?.trim(), 'general', '#employee-number', 'Vyplňte osobní číslo.'],
                [!employee.org_unit_id, 'general', '#employee-unit', 'Vyberte organizační jednotku.'],
                [!employee.login?.trim(), 'access', '#employee-login', 'Vyplňte uživatelské jméno.'],
                [!employee.roles.length, 'access', '#role-employee', 'Vyberte alespoň jednu roli.'],
                [!employee.starts, 'attendance', '#employee-starts', 'Vyplňte začátek evidence.'],
                [!!employee.ends && employee.ends < (employee.starts ?? ''), 'attendance', '#employee-ends', 'Konec evidence předchází začátku.'],
                [!!assignment && !assignment.effective_on, 'attendance', '#employee-model-date', 'Vyplňte účinnost modelu.'],
                [!!assignment && employeeAssignmentReason.value && !assignment.reason, 'attendance', '#employee-model-reason', 'Vyplňte důvod opravy.'],
            ] as const
            const invalid = required.find(item => item[0])
            if (invalid) {
                const [, panel, selector, message] = invalid
                employeePanel.value = panel
                await nextTick()
                document.querySelector<HTMLElement>(selector)?.focus()
                throw new Error(message)
            }
            const apply = async (token?:string) => {
                try {
                    await save('employee', assignment ? {...employee,work_model:{...assignment,preview_token:token}} : employee,
                        'Zaměstnanec byl uložen.')
                } catch (problem) {
                    if (assignment) employeePanel.value = 'attendance'
                    throw problem
                }
            }
            if (assignment && employeeAssignmentReason.value) {
                await previewModelChange({...assignment, employee:employee.id, attendance_start:employee.starts,
                    attendance_end:employee.ends || null}, apply)
            } else {
                await apply()
            }
        })
    }
    function editPerson(data?:Person) {
        openEditor({kind:'employee', id:data?.id})
    }
    function editWork(row?:number) {
        if (sheet.value && canReview.value) openEditor({kind:'work', row, fingerprint:sheet.value.fingerprint})
    }
    function submitWorkCorrection() {
        const current = editor.value
        if (current?.kind !== 'work') return
        const {kind, day, from, to, untilMidnight, reason} = workEdit.value
        const times = fullShiftEntry.value ? {} : {from, to:untilMidnight ? '24:00' : to}
        return submit('save-work-row', {...scope(), row:current.row, fingerprint:current.fingerprint, kind, day, ...times, reason},
            current.row === undefined ? 'Konto bylo zapsáno do výkazu.' : 'Pracovní úsek byl upraven.')
    }
    const requestTitle = (request:RequestRow) => request.generated_holiday ? accountName(request.kind) + ' · zápis z kalendáře'
        : request.replaces_from ? 'Úprava pracovního úseku'
        : request.merged_work ? 'Spojení pracovních úseků' : request.account_name ?? accountName(request.kind)
    function addDevice() {
        openEditor({kind:'device'})
    }
    function newRequest() {
        openEditor({kind:'request'})
    }
    function approveSheet() {
        if (!sheet.value || approvalBlockers.value.length) return
        const input = {...scope(), fingerprint:sheet.value.fingerprint}
        const reasonRequired = sheet.value.approval_note_required
        openConfirmation({
            title: 'Schválit výkaz', label: 'Potvrdit schválení',
            description: [selectedPerson.value?.name, month.value].filter(Boolean).join(' · '),
            reasonRequired,
            reasonLabel: reasonRequired ? 'Jak jste vyřešili nesrovnalosti?' : 'Poznámka ke schválení (nepovinná)',
            action: note => save('approve', {...input, note}, 'Výkaz byl schválen.'),
        })
    }
    function changeServiceSession(session:ComputerSession) {
        const service = !session.service
        const label = service ? 'Označit jako servisní' : 'Zrušit servisní označení'
        openConfirmation({
            title: label, label,
            description: [session.station, moment(session.from), selectedPerson.value?.name].filter(Boolean).join(' · '),
            action: () => save('service-session-change', {
                employee:person.value, device:session.device, session:session.session, service,
            }, service ? 'Relace je označena jako servisní.' : 'Servisní označení bylo zrušeno.'),
        })
    }
    function toggleMergeRow(index:number) {
        const row = sheet.value?.rows[index]
        if (!canReview.value || row?.kind !== 'PRACE' || row.generated_holiday || row.day !== selectedDay.value?.key) return
        mergeRows.value = mergeRows.value.includes(index)
            ? mergeRows.value.filter(value => value !== index)
            : [...mergeRows.value, index]
    }
    function confirmMergeRows() {
        if (!canReview.value || !sheet.value || mergeRows.value.length < 2) return
        const rows = [...mergeRows.value].sort((a, b) => a - b)
        const first = sheet.value.rows[rows[0]]
        const last = sheet.value.rows[rows[rows.length - 1]]
        const input = {...scope(), fingerprint:sheet.value.fingerprint, rows}
        openConfirmation({
            title: 'Spojit pracovní úseky',
            description: day(first.day) + ' · ' + time(first.from) + ' – ' + time(last.to),
            label: 'Potvrdit spojení',
            action: () => save('merge-work-rows', input, 'Vybrané pracovní úseky byly spojeny.'),
        })
    }
    function saveMergeLimit() {return run(async()=>{
        const value = gapMinutes.value ?? ''
        if (!/^\d+$/.test(value)) throw new Error('Zadejte limit v celých minutách.')
        const {gap_minutes} = await api<{gap_minutes:number}>('attendance-merge-limit', {gap_minutes:Number(value)})
        gapMinutes.value = String(gap_minutes)
        notice.value = 'Limit automatického spojování byl uložen.'
    })}
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
            description: [selectedPerson.value?.name, requestTitle(request), requestPeriod(request),
                request.scheduled_seconds == null ? '' : 'Čerpání ' + hours(request.scheduled_seconds)].filter(Boolean).join(' · '),
            details:request.note && !request.merged_work ? request.note : undefined,
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
    const requestAccount = computed(() => accounts.value.find(account => account.code === form.value.kind))
    const fullShiftRequest = computed(() => requestAccount.value?.behavior === 'shift_absence' || form.value.kind === 'DOVOLENA')
    const entryAccount = computed(() => accounts.value.find(account => account.code === workEdit.value.kind))
    const fullShiftEntry = computed(() => entryAccount.value?.behavior === 'shift_absence' || workEdit.value.kind === 'DOVOLENA')
    const proofEntry = computed(() => entryAccount.value?.requires_proof ?? workEdit.value.kind === 'LEKAR')
    const proofRequest = computed(() => requestAccount.value?.requires_proof ?? form.value.kind === 'LEKAR')
    const requestEstimate = ref<{seconds:number; days:string[];intervals?:{from:string;to:string}[]}|null>(null)
    const requestEstimateError = ref('')
    const previewKey = computed(() => {
        const entry = editor.value?.kind === 'work' && fullShiftEntry.value
        if (!entry && !(editor.value?.kind === 'request' && fullShiftRequest.value)) return ''
        const {from, to, kind} = entry ? {from:workEdit.value.day, to:workEdit.value.day, kind:workEdit.value.kind} : form.value
        let partial = {}
        if (!entry) {
            try { partial = vacationOptions() } catch { return '' }
        }
        return /^\d{4}-\d{2}-\d{2}$/.test(from)
            && /^\d{4}-\d{2}-\d{2}$/.test(to) && to >= from
            ? JSON.stringify({employee:person.value, kind, from, to, ...partial}) : ''
    })
    watch(previewKey, async (key, _previous, cleanup) => {
        let current = true
        cleanup(() => { current = false })
        requestEstimate.value = null
        requestEstimateError.value = ''
        if (!key) return
        try {
            const estimate = await api<{seconds:number;days:string[];intervals?:{from:string;to:string}[]}>('request-preview', JSON.parse(key))
            if (current) requestEstimate.value = estimate
        } catch (error) {
            if (current) requestEstimateError.value = (error as Error).message
        }
    })
    async function saveAttendanceAccount(event:SubmitEvent) {
        const form = event.currentTarget as HTMLFormElement
        const invalid = form.querySelector<HTMLInputElement>('input:invalid')
        const panel = invalid?.closest<HTMLElement>('[data-account-panel]')?.dataset.accountPanel
        if (invalid && panel) {
            accountPanel.value = panel
            await nextTick()
            invalid.focus()
            invalid.reportValidity()
            return
        }
        const {payroll_code, effective_on, payroll_versions, system, rounding_rules, break_rules, ...fields} = accountForm.value
        const {change:changeRounding,...rounding} = roundingEdit.value
        const {change:changeBreak,...break_rule} = breakRuleEdit.value
        return submit('attendance-account', {...fields, ...(payroll_code ? {payroll_code,effective_on} : {}),
            ...(changeRounding ? {rounding} : {}), ...(changeBreak && fields.behavior === 'work' ? {break_rule} : {})}, 'Konto bylo uloženo.')
    }
    function editableDuration(seconds:number) {
        return hours(seconds) + (seconds % 60 ? ':' + String(seconds % 60).padStart(2,'0') : '')
    }
    function parseDuration(input:string) {
        if (!/^\d{1,3}:[0-5]\d(?::[0-5]\d)?$/.test(input)) throw new Error('Zadejte dobu ve formátu H:MM.')
        const [hours,minutes,seconds=0] = input.split(':').map(Number)
        return 3600 * hours + 60 * minutes + seconds
    }
    function submitDuration() {
        return run(async () => {
            const current = editor.value
            if (current?.kind !== 'duration' || !sheet.value) return
            const {reset, value, reason} = durationEdit.value
            await save('adjust-recognized-work', {...scope(), fingerprint:current.fingerprint, row:current.row, stale:current.stale,
                seconds:reset ? null : parseDuration(value), reason}, 'Uznaná doba byla uložena.')
        })
    }
    function submitBreak() {
        if (!sheet.value) return
        return submit('attendance-break', {...scope(), fingerprint:sheet.value.fingerprint,...breakEdit.value}, 'Přestávka byla uložena.')
    }
    function submitActual() {
        if (editor.value?.kind !== 'actual') return
        return submit('request-actual', {id:editor.value.id, ...actualEdit.value, photo:actualEdit.value.photo || undefined}, 'Skutečný interval byl uložen.')
    }
    function returnActual(request:RequestRow) {
        openConfirmation({title:'Vrátit skutečnou dobu', label:'Vrátit', description:requestTitle(request), reasonRequired:true,
            action:reason => save('request-actual', {id:request.id, return:true, reason}, 'Žádost byla vrácena k doplnění.')})
    }
    watch(() => [holidayControl.value.from,holidayControl.value.to,holidayControl.value.checkHolidays], () => { holidayControl.value.token = '' })
    function submitHolidayControl() {
        return run(async () => {
            if (editor.value?.kind !== 'holiday-control' || !holidayControl.value.checkHolidays) return
            const {from,to,token} = holidayControl.value
            const input = {employee:editor.value.employee,from,to}
            if (token && holidayControlHasChanges.value) {
                const {added, changed, removed} = holidayControl.value
                await save('holiday-control', {...input,token}, `Kontrolní chod dokončen. Přidáno ${added} · Změněno ${changed} · Odebráno ${removed}.`)
            }
            else Object.assign(holidayControl.value, await api<HolidayControlPreview>('holiday-control', input))
        })
    }
    function vacationOptions() {
        const {kind, vacationMode, vacationHours, consent} = form.value
        if (kind !== 'DOVOLENA' || vacationMode === 'full') return {}
        return {vacation_side:vacationMode.endsWith('start') ? 'start' : 'end',
            vacation_seconds:vacationMode.startsWith('custom') ? parseDuration(vacationHours) : undefined, consent}
    }
    function saveWorkModel() {
        return run(async () => {
            const {week, ...model} = modelForm.value
            const original = editor.value?.kind === 'model' ? editor.value.version : undefined
            const data = {...model, original_effective_on:original, week:week.map(shift => shift ? {
                ...shift, break_start:shift.break_start || null, break_end:shift.break_end || null,
            } : null)}
            const apply = async (token?:string) => save('work-model', {...data, preview_token:token}, 'Pracovní model byl uložen.')
            if (original || (model.id && model.effective_on < today.value)) await previewModelChange(data, apply)
            else await apply()
        })
    }
    function submitRequest() {
        return run(async () => {
            const {kind, from, to, fromTime, toTime, note} = form.value
            const dayPattern = /^\d{4}-\d{2}-\d{2}$/
            if (!dayPattern.test(from) || !dayPattern.test(to)) throw new Error('Vyplňte platná data začátku a konce.')
            const allDay = fullShiftRequest.value
            if (!allDay && (!fromTime || !toTime)) throw new Error('Vyplňte čas začátku a konce.')
            const begins = allDay ? from : from + 'T' + fromTime
            const ends = allDay ? to : to + 'T' + toTime
            if (allDay ? ends < begins : ends <= begins) throw new Error('Zkontrolujte pořadí začátku a konce.')
            await api('request', {employee:person.value, kind, note, from:begins, to:ends, ...vacationOptions()})
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
        if (editor.value?.kind === 'actual') {
            actualEdit.value.photo = photoPreview.value.split(',')[1]
            photoVisible.value = false
            return
        }
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
    function updateDate() {
        const next = pragueToday()
        if (today.value === next) return
        today.value = next
        if (['Výkaz', 'Žádosti'].includes(tab.value)) void run(refresh)
    }

    onMounted(async()=>{
        window.addEventListener('popstate', restoreView)
        document.addEventListener('visibilitychange', updateDate)
        dailyTimer = window.setInterval(() => {
            if (document.visibilityState !== 'visible') return
            updateDate()
            if (tab.value === 'Denní přehled' && dailyDay.value === null) void run(refresh)
        }, 30_000)
        if (serviceCode) history.replaceState(history.state, '', location.pathname + location.search)
        try {
            if(window.isSecureContext && typeof PublicKeyCredential !== 'undefined'
                && typeof PublicKeyCredential.parseCreationOptionsFromJSON === 'function'){
                passkeyAvailable.value = (await api<{available:boolean}>('passkey-status').catch(() => ({available:false}))).available
            }
            if (serviceCode) {
                serviceContext.value = await api('service-session-details', {code:serviceCode})
                return
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
    onUnmounted(() => {
        document.removeEventListener('visibilitychange', updateDate)
        window.removeEventListener('popstate', restoreView)
        window.clearInterval(dailyTimer)
    })
    return {
        starting, setupAvailable, initial, createAdmin, submitRequest, submitProof, showProof, preview, proofVisible,
        accountMenuOpen, settingsVisible, passwords, changePassword, showSettings, clearSettings, signInWithPasskey,
        passkeyAvailable, passkeyList, newPasskey, addPasskey, removePasskey,
        photoVisible, photoPreview, adjusted, scanMessage, cameraInput, mobileCamera, selectProof, openCamera,
        captureProof, straightenProof, showOriginal, clearPhoto,
        user, people, devices, payroll, person, month, sheet, tab, tabs, mainTabs, adminTabs, navigationVisible, busy, error, notice, login, password,
        units, unitTree, employeeUnit, unitEdit, newUnitPath, originalUnit, parentOptions, deleteUnit, openEditor,
        activation, code, form, edit, deviceName, secret, labels, roles, requestKinds, requestSeverities,
        durationSeconds, durationEdit, submitDuration, breakEdit, submitBreak, actualEdit, submitActual, returnActual,
        actualProofRequired: computed(() => editor.value?.kind === 'actual' && !!sheet.value?.requests.find(
            request => editor.value?.kind === 'actual' && request.id === editor.value.id && request.requires_proof)),
        holidayControl, holidayControlHasChanges, submitHolidayControl, roundingEdit, breakRuleEdit, workBehaviors,
        tabIcons, time, moment, day, hours, requestPeriod, canUploadProof, scope, run, refresh, signIn, signOut, roleNames, hasRole,
        serviceCode, serviceContext, serviceDone, serviceCredentials, verifyServiceSession, openAttendance,
        sessionsLabel, showServiceSessions, visibleSessions, changeServiceSession,
        daily, dailyStatuses, selectedDailyDay, moveDailyDay, goToDailyToday, openDailySheet,
        gapMinutes, saveMergeLimit, mergeRows, toggleMergeRow, confirmMergeRows,
        monthDays, selectedDay, selectDayFromRow, showPendingRequests, dayHeading, canMergeDay, monthAbsences, dayIssueStyles, pendingDayReason,
        workEdit, editWork, submitWorkCorrection, requestTitle, fullShiftEntry, proofEntry,
        calendar, calendarAccounts, defaultHolidayAccount, holidayRuleAccounts, calendarDayForm, holidayForm, removeCalendarDay, holidayRuleForm, ruleTypes, holidayDate, saveHolidayRule, removeHolidayRule,
        accounts, models, accountPanel, accountForm, modelForm, expandedModels, selectedModelVersions, displayModelVersion, activeModelVersion,
        modelWeek, modelCopy, copyModelDays, applyModelCopy,
        employeePanel, employeeModels, employeeAssignments, employeeModelsLoading, employeeModelEdit, employeeAssignmentReason,
        editAssignment, submitEmployee, pendingModelImpact, today, behaviors, behaviorOptions,
        weekdays, modelSeconds, defaultShift, saveAttendanceAccount, saveWorkModel, accountName, fullShiftRequest, proofRequest,
        requestEstimate, requestEstimateError,
        ownSheet, canReview, selectedPerson, inActionDialog, approvalBlockers, selectPerson, selectMonth, moveMonth,
        managesPerson, canSubmitRequest, selectablePeople, canShowAllPeople, showAllPeople, setShowAllPeople,
        editor, dismissEditor, showArchived, setShowArchived, directoryPeople, openUnitDirectory, unitFilter, filteredUnit,
        changeTab, submit, download, editPerson, addDevice, archivePerson, confirmationVisible, confirmation, decisionReason,
        decide, confirmAction, clearConfirmation, invite, revoke, closeMonth, newRequest, approveSheet,
    }
}
