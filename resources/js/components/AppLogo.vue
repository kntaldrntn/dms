<script setup lang="ts">
import { Files } from 'lucide-vue-next';
</script>

<template>
    <div class="flex items-center gap-3">
        <!-- Modern App Icon Block -->
        <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-white shadow-sm transition-transform group-hover:scale-105">
            <!-- Using your specific blue for the icon color -->
            <Files class="h-5 w-5 text-[#265f92]" stroke-width="2.5" />
        </div>
        
        <!-- Stacked Typographic Treatment -->
        <div class="flex flex-col justify-center">
            <!-- Main Title with an emerald accent dot to match your system's buttons -->
            <span class="text-[16px] font-black tracking-wide text-white leading-none">
                DMS<span class="text-emerald-400"></span>
            </span>
            <!-- Subtext: Much cleaner than the long "Market Management System" pipe format -->
            <span class="text-[9px] font-bold uppercase tracking-[0.2em] text-white/60 leading-tight mt-1">
                City of San Fernando
            </span>
        </div>
    </div>
</template>