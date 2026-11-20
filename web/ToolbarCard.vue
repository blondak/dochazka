<script setup lang="ts">
defineProps<{label:string; toolbarClass?:string}>()
</script>

<template>
    <PvCard class="toolbar-card">
        <template #content>
            <PvToolbar :aria-label="label" :class="toolbarClass">
                <template #start><slot name="start" /></template>
                <template #end><slot name="end" /></template>
            </PvToolbar>
        </template>
    </PvCard>
</template>
