<script setup lang="ts">
import { computed } from 'vue'

/**
 * Minimal dependency-free SVG bar chart. Accessible: the data is also rendered
 * as a visually hidden table for screen readers.
 */
const props = defineProps<{
  title: string
  points: { label: string; value: number; display: string }[]
}>()

const width = 720
const height = 240
const padding = { top: 16, right: 8, bottom: 40, left: 8 }

const max = computed(() => Math.max(1, ...props.points.map((p) => p.value)))
const barWidth = computed(() => (width - padding.left - padding.right) / Math.max(1, props.points.length))
const labelEvery = computed(() => Math.ceil(props.points.length / 12))

function barHeight(value: number): number {
  return (value / max.value) * (height - padding.top - padding.bottom)
}
</script>

<template>
  <figure>
    <figcaption class="mb-2 text-sm font-semibold text-slate-700">{{ title }}</figcaption>
    <svg :viewBox="`0 0 ${width} ${height}`" class="h-auto w-full" role="img" :aria-label="title">
      <line :x1="padding.left" :x2="width - padding.right" :y1="height - padding.bottom" :y2="height - padding.bottom" stroke="#cbd5e1" />
      <g v-for="(point, index) in points" :key="point.label">
        <rect
          :x="padding.left + index * barWidth + barWidth * 0.15"
          :y="height - padding.bottom - barHeight(point.value)"
          :width="barWidth * 0.7"
          :height="barHeight(point.value)"
          rx="2"
          fill="#4338ca"
        >
          <title>{{ point.label }}: {{ point.display }}</title>
        </rect>
        <text
          v-if="index % labelEvery === 0"
          :x="padding.left + index * barWidth + barWidth / 2"
          :y="height - padding.bottom + 16"
          text-anchor="middle"
          font-size="11"
          fill="#475569"
        >
          {{ point.label.length > 7 ? point.label.slice(5) : point.label }}
        </text>
      </g>
    </svg>
    <table class="sr-only">
      <caption>{{ title }}</caption>
      <tr v-for="point in points" :key="point.label">
        <th scope="row">{{ point.label }}</th>
        <td>{{ point.display }}</td>
      </tr>
    </table>
  </figure>
</template>
