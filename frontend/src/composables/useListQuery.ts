import { computed, ref, watch } from 'vue'
import { useRoute, useRouter, type LocationQueryRaw } from 'vue-router'

/**
 * Keeps list state (page, per_page, search, sort, extra filters) in the URL query string,
 * so a filtered/sorted table can be bookmarked, shared, and survives reloads / back button.
 */
export function useListQuery(defaults: { sort: string; perPage?: number; filters?: string[] }) {
  const route = useRoute()
  const router = useRouter()
  const filterKeys = defaults.filters ?? []

  const str = (key: string): string => {
    const value = route.query[key]
    return typeof value === 'string' ? value : ''
  }

  const page = computed(() => Math.max(1, Number.parseInt(str('page'), 10) || 1))
  const perPage = computed(() => Number.parseInt(str('per_page'), 10) || (defaults.perPage ?? 10))
  const sort = computed(() => str('sort') || defaults.sort)
  const search = computed(() => str('search'))
  const filters = computed(() => Object.fromEntries(filterKeys.map((key) => [key, str(key)])))

  /** Parameters sent to the API (and used as the TanStack Query key). */
  const params = computed(() => ({
    page: page.value,
    per_page: perPage.value,
    sort: sort.value,
    search: search.value || undefined,
    ...Object.fromEntries(Object.entries(filters.value).filter(([, v]) => v !== '')),
  }))

  function update(patch: Record<string, string | number | null>, resetPage = true): void {
    const query: LocationQueryRaw = { ...route.query }
    for (const [key, value] of Object.entries(patch)) {
      if (value === null || value === '') delete query[key]
      else query[key] = String(value)
    }
    if (resetPage && !('page' in patch)) delete query.page
    void router.replace({ query })
  }

  function toggleSort(column: string): void {
    const next = sort.value === column ? `-${column}` : column
    update({ sort: next })
  }

  function sortDirection(column: string): 'ascending' | 'descending' | 'none' {
    if (sort.value === column) return 'ascending'
    if (sort.value === `-${column}`) return 'descending'
    return 'none'
  }

  // Debounced search box bound to the URL.
  const searchInput = ref(search.value)
  let timer: ReturnType<typeof setTimeout> | undefined
  watch(searchInput, (value) => {
    clearTimeout(timer)
    timer = setTimeout(() => update({ search: value.trim() || null }), 300)
  })
  watch(search, (value) => {
    if (value !== searchInput.value.trim()) searchInput.value = value
  })

  return { page, perPage, sort, search, filters, params, update, toggleSort, sortDirection, searchInput }
}
