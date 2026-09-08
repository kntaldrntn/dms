<script setup lang="ts">
import { ChevronRight } from 'lucide-vue-next';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarMenuSub,
    SidebarMenuSubButton,
    SidebarMenuSubItem,
} from '@/components/ui/sidebar';
import { Link } from '@inertiajs/vue3';
import { type NavItem } from '@/types';

defineProps<{
    items: NavItem[];
}>();
</script>

<template>
    <SidebarGroup>
        <!-- Made the group label slightly bolder and cleaner -->
        <SidebarGroupLabel class="text-xs font-bold uppercase tracking-wider text-slate-500 mb-2">Platform</SidebarGroupLabel>
        
        <!-- Added gap-1.5 to space the larger items out -->
        <SidebarMenu class="gap-1.5">
            <template v-for="item in items" :key="item.title">
                
                <!-- If the item has children (Nested Dropdown) -->
                <Collapsible
                    v-if="item.items && item.items.length > 0"
                    as-child
                    :default-open="item.isActive"
                    class="group/collapsible"
                >
                    <SidebarMenuItem>
                        <CollapsibleTrigger as-child>
                            <!-- INCREASED: text-[15px], h-10, font-medium -->
                            <SidebarMenuButton :tooltip="item.title" class="text-[15px] font-medium h-10">
                                <!-- INCREASED: w-5 h-5 -->
                                <component :is="item.icon" v-if="item.icon" class="w-5 h-5 mr-1" />
                                <span>{{ item.title }}</span>
                                <ChevronRight
                                    class="ml-auto h-4 w-4 transition-transform duration-200 group-data-[state=open]/collapsible:rotate-90"
                                />
                            </SidebarMenuButton>
                        </CollapsibleTrigger>
                        <CollapsibleContent>
                            <SidebarMenuSub class="mt-1 gap-1">
                                <SidebarMenuSubItem v-for="subItem in item.items" :key="subItem.title">
                                    <!-- INCREASED SUB-LINKS: text-[14px], h-9 -->
                                    <SidebarMenuSubButton as-child class="text-[14px] h-9">
                                        <Link :href="subItem.href" class="flex items-center gap-2">
                                            <component :is="subItem.icon" v-if="subItem.icon" class="h-4 w-4" />
                                            <span>{{ subItem.title }}</span>
                                        </Link>
                                    </SidebarMenuSubButton>
                                </SidebarMenuSubItem>
                            </SidebarMenuSub>
                        </CollapsibleContent>
                    </SidebarMenuItem>
                </Collapsible>

                <!-- If it's just a normal link -->
                <SidebarMenuItem v-else>
                    <!-- INCREASED: text-[15px], h-10, font-medium -->
                    <SidebarMenuButton as-child :is-active="item.isActive" :tooltip="item.title" class="text-[15px] font-medium h-10">
                        <Link :href="item.href" class="flex items-center gap-2">
                            <!-- INCREASED: w-5 h-5 -->
                            <component :is="item.icon" v-if="item.icon" class="w-5 h-5 mr-1" />
                            <span>{{ item.title }}</span>
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>

            </template>
        </SidebarMenu>
    </SidebarGroup>
</template>