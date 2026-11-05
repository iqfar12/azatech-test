<script setup lang="ts">
defineProps<{ label: string; direction: 'ascending' | 'descending' | 'none' }>()
const emit = defineEmits<{ sort: [] }>()
</script>

<template>
  <th scope="col" :aria-sort="direction">
    <button type="button" class="inline-flex items-center gap-1 font-semibold hover:underline" @click="emit('sort')">
      {{ label }}
      <span aria-hidden="true">{{ direction === 'ascending' ? '▲' : direction === 'descending' ? '▼' : '↕' }}</span>
    </button>
  </th>
</template>
