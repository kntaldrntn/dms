import { type SidebarCounts } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

// Module scope: survives page visits. sidebarCounts is a deferred prop, so every visit starts without it;
// showing the last known counts until the fresh ones arrive keeps the badges from blinking out and back.
const lastCounts = ref<SidebarCounts | null>(null);

export function useSidebarCounts() {
    const page = usePage();

    watch(
        () => page.props.sidebarCounts as SidebarCounts | null | undefined,
        (counts) => {
            if (counts !== undefined) lastCounts.value = counts;
        },
        { immediate: true },
    );

    return computed(() => lastCounts.value);
}
