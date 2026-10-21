<script setup lang="ts">
withDefaults(defineProps<{ framed?: boolean }>(), { framed: true });
</script>

<template>
    <!-- Three rising chevrons (blue, orange, gold) on the navy field of the DMS logo. -->
    <svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Document Management System logo">
        <rect v-if="framed" width="64" height="64" rx="12" fill="#0B2A5B" />
        <polygon points="14,26 32,12 50,26 50,35 32,21 14,35" fill="#2A7DE1" />
        <polygon points="14,38 32,24 50,38 50,47 32,33 14,47" fill="#F7941D" />
        <polygon points="14,50 32,36 50,50 50,59 32,45 14,59" fill="#FDB913" />
    </svg>
</template>
