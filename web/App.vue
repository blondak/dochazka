<script lang="ts">
import { defineComponent } from 'vue'
import { setupAttendance } from './attendance-app'

export default defineComponent({setup:setupAttendance})
</script>

<template src="./screen.html"></template>
