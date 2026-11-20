<script setup lang="ts">
import { notify } from '@/lib/notify';
import { usePage } from '@inertiajs/vue3';
import { watch } from 'vue';
import { Toaster } from 'vue-sonner';
import 'vue-sonner/style.css';

/**
 * The one toast area of the app. Shows the server's one-time messages (`redirect()->with('success'|'error', ...)`,
 * shared as `flash` by HandleInertiaRequests); pages call `notify` (lib/notify.ts) for browser-side messages.
 */
const page = usePage();

// Every full visit brings a new `flash` object, so the same message twice in a row still shows twice.
// Partial reloads (the bell's poll, deferred sidebar counts) keep the old object, so nothing repeats.
watch(
    () => page.props.flash as { success?: string | null; error?: string | null } | undefined,
    (flash) => {
        if (flash?.success) notify.success(flash.success);
        if (flash?.error) notify.error(flash.error);
    },
    { immediate: true },
);
</script>

<template>
    <!-- Top centre, below the 64px header: in the user's line of sight, and clear of the page's own top-right
         buttons (Add …) and the header's bell and account menu. `expand` lists toasts one under another instead of
         stacking them like cards, so quick successive actions each stay readable. -->
    <Toaster position="top-center" rich-colors close-button expand :visible-toasts="4" :offset="80" />
</template>
