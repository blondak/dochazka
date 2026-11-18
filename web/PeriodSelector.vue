<script setup lang="ts">
defineProps<{
    person:number; month:string; people:{id:number;name:string}[]; selectedName?:string;
    showPerson:boolean; editablePerson:boolean; busy:boolean
}>()
defineEmits<{
    'select-person':[id:number]; 'edit-person':[]; 'select-month':[month:string]; 'move-month':[offset:number]
}>()
</script>

<template>
    <div class="fields period-selector">
        <div v-if="showPerson && (people.length > 1 || selectedName)" class="person-controls">
            <div class="field">
                <span id="scope-person-label" class="field-label scope-caption">Osoba</span>
                <PvSelect v-if="people.length > 1" :model-value="person" input-id="scope-person" :options="people"
                    option-label="name" option-value="id" :disabled="busy" aria-labelledby="scope-person-label"
                    filter filter-placeholder="Vyhledat osobu" :pt="{pcFilter:{root:{'aria-label':'Vyhledat osobu'}}}"
                    @update:model-value="$emit('select-person', $event)" />
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
