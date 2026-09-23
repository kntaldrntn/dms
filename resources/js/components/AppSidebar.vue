<script setup lang="ts">
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import NavMain from '@/components/NavMain.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem
} from '@/components/ui/sidebar';
import { type NavItem } from '@/types';
import {
    ArrowLeftRight,
    FilePlus,
    LayoutGrid,
    ClipboardList,
    Settings,
    Users,
    Building2,
    Clock,
    Inbox,
    FileSignature, // <-- ADDED FOR REFERRAL SLIPS
} from 'lucide-vue-next';

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
            roles: ['system_administrator', 'records_manager', 'user', 'staff', 'department_head', 'city_administrator', 'city_mayor'],
        },
        {
            title: 'Document',
            href: route('documents.index'),
            icon: FilePlus,
            roles: ['system_administrator', 'records_manager', 'user'],
        },
        {
            title: 'Transactions',
            href: route('transactions.inbox'),
            icon: Inbox,
            roles: ['system_administrator', 'records_manager', 'user', 'staff', 'department_head', 'city_administrator', 'city_mayor'],
        },
        // NEW: Dedicated Referral Slip Module for the City Admin Hub
        {
            title: 'Referral Slips',
            href: '/referral-slips', // We will register this route next
            icon: FileSignature,
            roles: ['system_administrator', 'city_administrator'],
        },
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
    <Sidebar collapsible="icon" class="border-r border-slate-200 bg-white">
        <!-- SidebarHeader with Logo has been removed. -->
        <SidebarContent class="px-1.5 pt-16">
            <NavMain :items="mainNavItems" />
        </SidebarContent>
    </Sidebar>
</template>