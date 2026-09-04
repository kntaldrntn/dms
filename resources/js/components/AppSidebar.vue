<script setup lang="ts">
import { computed } from 'vue';
import { usePage, Link } from '@inertiajs/vue3';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem
} from '@/components/ui/sidebar';
import { type NavItem } from '@/types';
import {
    Archive,
    ArrowLeftRight,
    FilePlus,
    LayoutGrid,
    ClipboardList,
    Settings,
    Users,
    Building2,
    Clock,
} from 'lucide-vue-next';
import AppLogo from './AppLogo.vue';

const page = usePage();
const user = computed(() => page.props.auth.user);

const userRole = computed(() => {
    return user.value?.role || 'guest';
});

const mainNavItems = computed<NavItem[]>(() => {
    const menuConfig = [
        {
            title: 'Dashboard',
            href: route('dashboard'),
            icon: LayoutGrid,
            roles: ['system_administrator', 'records_manager', 'user'],
        },
        {
            title: 'Document',
            href: route('documents.index'),
            icon: FilePlus,
            roles: ['system_administrator', 'records_manager', 'user'],
        },
        // {
        //     title: 'Transaction',
        //     href: '/transactions',
        //     icon: ArrowLeftRight,
        //     roles: ['system_administrator', 'records_manager', 'user'],
        // },
        // {
        //     title: 'Archive',
        //     href: '/archive',
        //     icon: Archive,
        //     roles: ['system_administrator', 'records_manager', 'user'],
        // },
        // {
        //     title: 'Previous Archive',
        //     href: '/archive/previous',
        //     icon: Archive,
        //     roles: ['system_administrator', 'records_manager', 'user'],
        // },
        // {
        //     title: 'Report',
        //     href: '/reports',
        //     icon: ClipboardList,
        //     roles: ['system_administrator', 'records_manager'],
        // },
        // Collapsible Settings Dropdown
        {
            title: 'Settings',
            icon: Settings,
            roles: ['system_administrator'],
            isActive: route().current('departments.*') || 
                      route().current('users.*') ||
                      route().current('document-classifications.*') ||
                      route().current('document-types.*') ||
                      route().current('performance-standards.*') ||
                      route().current('transaction-types.*') ||
                      route().current('delivery-methods.*'),
            items: [
                {
                    title: 'Departments',
                    href: route('departments.index'),
                    icon: Building2,
                },
                {
                    title: 'Delivery Methods',
                    href: route('delivery-methods.index'),
                    icon: ArrowLeftRight,
                },
                {
                    title: 'Document Classifications',
                    href : route('document-classifications.index'),
                    icon : ClipboardList,
                },
                {
                    title: 'Document Types',
                    href: route('document-types.index'),
                    icon: ClipboardList,
                },
                {
                    title: 'Performance Standards',
                    href: route('performance-standards.index'),
                    icon: Clock,
                },
                {
                    title: 'Transaction Types',
                    href: route('transaction-types.index'),
                    icon: ArrowLeftRight,
                },
                {
                    title: 'User Management',
                    href: route('users.index'),
                    icon: Users,
                },
            ],
        },
    ];

    return menuConfig.filter(item => item.roles.includes(userRole.value));
});
</script>

<template>
    <Sidebar collapsible="icon" variant="inset" class="border-slate-100 bg-white">
        <SidebarHeader class="border-b border-slate-100">
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child class="hover:bg-slate-50 data-[active=true]:bg-emerald-50 data-[active=true]:text-emerald-600">
                        <Link :href="route('dashboard')">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent class="px-1.5 pt-4">
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter class="border-t border-slate-100">
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
