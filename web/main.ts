import { createApp, defineAsyncComponent, defineComponent, h, ref } from 'vue'
import PrimeVue from 'primevue/config'
import { definePreset } from '@primeuix/themes'
import Aura from '@primeuix/themes/aura'
import Button from 'primevue/button'
import Card from 'primevue/card'
import Column from 'primevue/column'
import InputText from 'primevue/inputtext'
import Menu from 'primevue/menu'
import Message from 'primevue/message'
import Select from 'primevue/select'
import Tag from 'primevue/tag'
import Tooltip from 'primevue/tooltip'
import App from './App.vue'
import 'primeicons/primeicons.css'
import './style.css'

const accessibleAura = definePreset(Aura, {
    semantic: {
        colorScheme: {
            light: {
                primary: {color:'{primary.700}', hoverColor:'{primary.800}', activeColor:'{primary.900}'},
            },
        },
    },
    components: {
        button: {
            colorScheme: {
                light: {
                    root: {
                        danger: {
                            background:'{red.700}', hoverBackground:'{red.800}', activeBackground:'{red.900}',
                            borderColor:'{red.700}', hoverBorderColor:'{red.800}', activeBorderColor:'{red.900}',
                        },
                    },
                    text: {danger: {color:'{red.700}'}},
                },
            },
        },
    },
})
const app = createApp(App)

app.use(PrimeVue, {
    theme: {preset: accessibleAura, options: {darkModeSelector: false}},
    locale: {
        firstDayOfWeek: 1,
        dayNames: ['Neděle', 'Pondělí', 'Úterý', 'Středa', 'Čtvrtek', 'Pátek', 'Sobota'],
        dayNamesShort: ['Ne', 'Po', 'Út', 'St', 'Čt', 'Pá', 'So'],
        dayNamesMin: ['Ne', 'Po', 'Út', 'St', 'Čt', 'Pá', 'So'],
        monthNames: ['Leden', 'Únor', 'Březen', 'Duben', 'Květen', 'Červen', 'Červenec', 'Srpen', 'Září', 'Říjen', 'Listopad', 'Prosinec'],
        monthNamesShort: ['Led', 'Úno', 'Bře', 'Dub', 'Kvě', 'Čvn', 'Čvc', 'Srp', 'Zář', 'Říj', 'Lis', 'Pro'],
        today: 'Dnes',
        clear: 'Vymazat',
        chooseDate: 'Vybrat datum',
        chooseMonth: 'Vybrat měsíc',
        chooseYear: 'Vybrat rok',
        prevMonth: 'Předchozí měsíc',
        nextMonth: 'Následující měsíc',
        prevYear: 'Předchozí rok',
        nextYear: 'Následující rok',
        prevDecade: 'Předchozí desetiletí',
        nextDecade: 'Následující desetiletí',
        prevHour: 'Předchozí hodina',
        nextHour: 'Následující hodina',
        prevMinute: 'Předchozí minuta',
        nextMinute: 'Následující minuta',
        aria: {
            close: 'Zavřít',
            showPassword: 'Zobrazit heslo',
            hidePassword: 'Skrýt heslo',
        },
    },
})
app.component('PvButton', Button)
app.component('PvCard', Card)
app.component('PvCheckbox', defineAsyncComponent(() => import('primevue/checkbox')))
app.component('PvColumn', Column)
app.component('PvDataTable', defineAsyncComponent(() => import('primevue/datatable')))
app.component('PvDatePicker', defineAsyncComponent(() => import('primevue/datepicker')))
app.component('PvDialog', defineAsyncComponent(() => import('primevue/dialog')))
app.component('PvDrawer', defineAsyncComponent(() => import('primevue/drawer')))
app.component('PvFileUpload', defineAsyncComponent(() => import('primevue/fileupload')))
app.component('PvInputText', InputText)
app.component('PvMenu', Menu)
app.component('PvMessage', Message)
app.component('PvPassword', defineComponent({
    inheritAttrs: false,
    props: {toggleMask:Boolean, inputProps:Object, inputId:String},
    setup(props, {attrs}) {
        const revealed = ref(false)
        return () => {
            const input = h(InputText, {...attrs, ...props.inputProps, id:props.inputId, type:revealed.value ? 'text' : 'password'})
            if (!props.toggleMask) return input
            return h('div', {class:'password-field'}, [
                input,
                h(Button, {
                    type:'button', icon:revealed.value ? 'pi pi-eye-slash' : 'pi pi-eye',
                    'aria-label':revealed.value ? 'Skrýt heslo' : 'Zobrazit heslo',
                    onClick:() => { revealed.value = !revealed.value },
                    text:true, severity:'secondary', class:'password-toggle',
                }),
            ])
        }
    },
}))
app.component('PvTreeTable', defineAsyncComponent(() => import('primevue/treetable')))
app.component('PvTreeSelect', defineAsyncComponent(() => import('primevue/treeselect')))
app.component('PvSelect', Select)
app.component('PvTag', Tag)
app.component('PvTabs', defineAsyncComponent(() => import('primevue/tabs')))
app.component('PvTabList', defineAsyncComponent(() => import('primevue/tablist')))
app.component('PvTab', defineAsyncComponent(() => import('primevue/tab')))
app.component('PvTabPanels', defineAsyncComponent(() => import('primevue/tabpanels')))
app.component('PvTabPanel', defineAsyncComponent(() => import('primevue/tabpanel')))
app.component('PvToolbar', defineAsyncComponent(() => import('primevue/toolbar')))
app.directive('tooltip', Tooltip)
app.mount('#app')

if (import.meta.env.PROD && window.isSecureContext && 'serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js', {updateViaCache:'none'}).catch(error => {
            console.warn('Offline stránku docházky se nepodařilo připravit.', error)
        })
    }, {once:true})
}
