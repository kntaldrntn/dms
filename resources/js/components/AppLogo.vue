<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';

interface Props {
    class?: string;
}

defineProps<Props>();
</script>

<template>
    <!-- Wrapper div to center both elements as a group -->
    <div class="flex w-full items-center justify-center gap-2">
        <div class="flex aspect-square size-8 items-center justify-center rounded-md bg-sidebar-primary text-sidebar-primary-foreground">
            <AppLogoIcon class="size-5 fill-current text-white dark:text-black" />
        </div>
        
        <!-- Removed 'flex-1', 'ml-1', and 'text-left', added 'text-center' -->
        <div class="grid text-center text-sm">
            <span class="mb-0.5 truncate font-semibold leading-none">DMS</span>
        </div>
    </div>
</template>
