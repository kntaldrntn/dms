<script setup lang="ts">
import { SidebarGroup, SidebarGroupLabel, SidebarMenu, SidebarMenuBadge, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem, type NavSection } from '@/types';
import { Link } from '@inertiajs/vue3';

defineProps<{
    sections: NavSection[];
}>();

const badgeTone = (item: NavItem) => (item.badge?.tone === 'alert' ? 'bg-red-100 text-red-700' : 'bg-slate-100 text-slate-600');
const dotTone = (item: NavItem) => (item.badge?.tone === 'alert' ? 'bg-red-500' : 'bg-[#265f92]');
const badgeText = (count: number) => (count > 99 ? '99+' : String(count));
</script>

<template>
    <SidebarGroup
        v-for="(section, index) in sections"
        :key="section.label"
        class="py-1"
        :class="index > 0 ? 'mt-1 border-t border-slate-100 pt-3' : ''"
    >
        <SidebarGroupLabel class="h-7 px-3 text-[11px] font-semibold uppercase tracking-wider text-slate-400">
            {{ section.label }}
        </SidebarGroupLabel>
        <SidebarMenu class="gap-0.5">
            <SidebarMenuItem v-for="item in section.items" :key="item.title">
                <SidebarMenuButton
                    as-child
                    :is-active="item.isActive"
                    :tooltip="item.badge ? `${item.title} · ${item.badge.label}` : item.title"
                    class="group/nav relative h-9 rounded-md px-3 text-sm font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900 data-[active=true]:bg-[#265f92]/10 data-[active=true]:font-semibold data-[active=true]:text-[#265f92]"
                    :class="{ 'pr-10': item.badge !== undefined }"
                >
                    <Link :href="item.href" :aria-current="item.isActive ? 'page' : undefined">
                        <!-- Icon chip: filled when active, tinted on hover. The -m-1 keeps it inside the collapsed 32px button. -->
                        <span
                            v-if="item.icon"
                            class="relative flex h-6 w-6 shrink-0 items-center justify-center rounded-md transition-colors group-data-[collapsible=icon]:-m-1"
                            :class="item.isActive ? 'bg-[#265f92] text-white shadow-sm' : 'text-slate-500 group-hover/nav:text-[#265f92]'"
                        >
                            <component :is="item.icon" class="h-4 w-4" aria-hidden="true" />
                            <!-- Collapsed sidebar: the count is hidden, so a dot says something is waiting. -->
                            <span
                                v-if="item.badge"
                                class="absolute -right-0.5 -top-0.5 hidden h-2 w-2 rounded-full ring-2 ring-white group-data-[collapsible=icon]:block"
                                :class="dotTone(item)"
                                aria-hidden="true"
                            ></span>
                        </span>
                        <span>{{ item.title }}</span>
                        <span v-if="item.badge" class="sr-only">, {{ item.badge.label }}</span>
                    </Link>
                </SidebarMenuButton>
                <!-- !top-1/2: the primitive's own top-1.5 assumes the default 32px button; ours is h-9, so centre it instead. -->
                <SidebarMenuBadge v-if="item.badge" class="!top-1/2 right-2 -translate-y-1/2" aria-hidden="true">
                    <span class="rounded-full px-1.5 py-0.5 text-[11px] font-bold leading-none" :class="badgeTone(item)">
                        {{ badgeText(item.badge.count) }}
                    </span>
                </SidebarMenuBadge>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
</template>
