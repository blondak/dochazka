<script setup lang="ts">
defineProps<{
    person:number; month:string; people:{id:number;name:string}[]; selectedName?:string;
    showPerson:boolean; editablePerson:boolean; busy:boolean; canShowAllPeople:boolean; showAllPeople:boolean
}>()
defineEmits<{
    'select-person':[id:number]; 'show-all-people':[value:boolean]; 'edit-person':[]; 'select-month':[month:string]; 'move-month':[offset:number]
}>()

function allowTabToPersonFilter(event: KeyboardEvent) {
    // PrimeVue handles Tab in the search field. Let the browser move to the checkbox in the footer.
    if (event.key === 'Tab' && !event.shiftKey) event.stopImmediatePropagation()
}
</script>

<template>
    <div class="fields period-selector">
        <div v-if="showPerson && (people.length || canShowAllPeople)" class="person-controls">
            <div class="field">
                <span id="scope-person-label" class="field-label scope-caption">Osoba</span>
                <PvSelect v-if="people.length > 1 || canShowAllPeople" :model-value="person || null" input-id="scope-person" :options="people"
                    option-label="name" option-value="id" :disabled="busy" aria-labelledby="scope-person-label"
                    placeholder="Vyberte osobu" filter filter-placeholder="Vyhledat osobu"
                    :pt="{pcFilter:{root:{'aria-label':'Vyhledat osobu',onKeydownCapture:canShowAllPeople ? allowTabToPersonFilter : undefined}}}"
                    @update:model-value="$emit('select-person', $event)">
                    <template v-if="canShowAllPeople" #footer>
                        <label class="check-field person-filter">
                            <PvCheckbox :model-value="showAllPeople" binary @update:model-value="$emit('show-all-people', $event)" />
                            Všechny osoby
                        </label>
                    </template>
                </PvSelect>
                <span v-else>{{ selectedName }}</span>
            </div>
            <PvButton v-if="editablePerson && selectedName" icon="pi pi-user-edit" outlined
                :aria-label="'Upravit zaměstnance · ' + selectedName" v-tooltip.top="'Upravit zaměstnance'"
                :disabled="busy" @click="$emit('edit-person')" />
        </div>
        <div class="field period-controls">
            <label for="scope-month" class="scope-caption">Období</label>
            <div class="month-controls">
                <PvButton aria-label="Předchozí měsíc" icon="pi pi-chevron-left" outlined :disabled="busy" @click="$emit('move-month', -1)" />
                <PvDatePicker :model-value="month" input-id="scope-month" view="month" date-format="yy-mm" update-model-type="string"
                    :manual-input="false" :disabled="busy" @update:model-value="$emit('select-month', $event)" />
                <PvButton aria-label="Následující měsíc" icon="pi pi-chevron-right" outlined :disabled="busy" @click="$emit('move-month', 1)" />
            </div>
        </div>
    </div>
</template>
