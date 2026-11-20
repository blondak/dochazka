<script lang="ts">
import { defineComponent } from 'vue'
import PeriodSelector from './PeriodSelector.vue'
import ToolbarCard from './ToolbarCard.vue'
import { setupAttendance } from './attendance-app'

export default defineComponent({components:{PeriodSelector, ToolbarCard}, setup:setupAttendance})
</script>

<template src="./screen.html"></template>
