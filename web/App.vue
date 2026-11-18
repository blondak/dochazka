<script lang="ts">
import { defineComponent } from 'vue'
import PeriodSelector from './PeriodSelector.vue'
import { setupAttendance } from './attendance-app'

export default defineComponent({components:{PeriodSelector}, setup:setupAttendance})
</script>

<template src="./screen.html"></template>
