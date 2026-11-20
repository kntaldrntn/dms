<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

/** Philippine time with seconds for the header. */
const page = usePage();

// Ticks from the server's clock, not the PC's: office PCs often run minutes off, and the server enforces working hours.
const offset = ref(0);
const now = ref(Date.now());
let timer: ReturnType<typeof setInterval> | undefined;

onMounted(() => {
    const serverNow = Number(page.props.serverNow);
    if (serverNow) offset.value = serverNow - Date.now();
    now.value = Date.now() + offset.value;
    timer = setInterval(() => (now.value = Date.now() + offset.value), 1000);
});
onBeforeUnmount(() => clearInterval(timer));

const zone = { timeZone: 'Asia/Manila' } as const;
const timeText = computed(() => new Date(now.value).toLocaleTimeString('en-US', { ...zone, hour: 'numeric', minute: '2-digit', second: '2-digit' }));
const dateText = computed(() =>
    new Date(now.value).toLocaleDateString('en-US', { ...zone, weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' }),
);
</script>

<template>
    <!-- No aria-live: a clock announced every second would drown out everything else for screen-reader users. -->
    <div class="hidden flex-col items-end leading-tight text-white md:flex" :aria-label="`Philippine time ${timeText}, ${dateText}`">
        <span class="text-sm font-semibold tabular-nums">{{ timeText }}</span>
        <span class="text-[11px] text-white/70">{{ dateText }} · PH Time</span>
    </div>
</template>
