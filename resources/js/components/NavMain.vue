<script setup lang="ts">
import { SidebarGroup, SidebarGroupLabel, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavSection } from '@/types';
import { Link } from '@inertiajs/vue3';

defineProps<{
    sections: NavSection[];
}>();
</script>

<template>
    <SidebarGroup v-for="section in sections" :key="section.label" class="py-1">
        <SidebarGroupLabel class="h-7 px-3 text-[11px] font-semibold uppercase tracking-wider text-slate-400">
            {{ section.label }}
        </SidebarGroupLabel>
        <SidebarMenu class="gap-0.5">
            <SidebarMenuItem v-for="item in section.items" :key="item.title">
                <SidebarMenuButton
                    as-child
                    :is-active="item.isActive"
                    :tooltip="item.title"
                    class="relative h-9 rounded-md px-3 text-sm font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900 data-[active=true]:bg-[#265f92]/10 data-[active=true]:font-semibold data-[active=true]:text-[#265f92] data-[active=true]:before:absolute data-[active=true]:before:inset-y-1.5 data-[active=true]:before:left-0 data-[active=true]:before:w-[3px] data-[active=true]:before:rounded-full data-[active=true]:before:bg-[#265f92]"
                >
                    <Link :href="item.href" :aria-current="item.isActive ? 'page' : undefined">
                        <component :is="item.icon" v-if="item.icon" class="h-4 w-4 shrink-0" aria-hidden="true" />
                        <span>{{ item.title }}</span>
                    </Link>
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
</template>
