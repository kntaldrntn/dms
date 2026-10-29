<script setup lang="ts">
import NavMain from '@/components/NavMain.vue';
import { Sidebar, SidebarContent } from '@/components/ui/sidebar';
import { type NavItem, type NavSection } from '@/types';
import { usePage } from '@inertiajs/vue3';
import {
    BadgeCheck,
    Building2,
    Contact,
    FilePlus,
    FileText,
    Gauge,
    Inbox,
    LayoutGrid,
    ListChecks,
    Mail,
    Stamp,
    Tags,
    Truck,
    UserCog,
} from 'lucide-vue-next';
import { computed } from 'vue';

const page = usePage();
const role = computed(() => page.props.auth.user?.role ?? 'guest');

const link = (title: string, name: string, icon: NavItem['icon'], activePattern = `${name.split('.')[0]}.*`): NavItem => ({
    title,
    href: route(name),
    icon,
    isActive: route().current(activePattern),
});

const sections = computed<NavSection[]>(() => {
    const isSystemAdministrator = role.value === 'system_administrator';

    const approvals = [
        // Approval pages depend on the office (every ADM/OCM user records approvals), not on the role alone.
        ...(page.props.auth.canViewAdmApprovals ? [link('ADM Approvals', 'adm-approvals.index', Stamp)] : []),
        ...(page.props.auth.canViewOcmApprovals ? [link('OCM Approvals', 'ocm-approvals.index', BadgeCheck)] : []),
    ];

    // Registries are open to Records; user management stays with the System Administrator.
    const administration =
        isSystemAdministrator || role.value === 'records_manager'
            ? [
                  link('Departments', 'departments.index', Building2),
                  link('People Directory', 'people.index', Contact),
                  link('Document Types', 'document-types.index', FileText),
                  link('Classifications', 'document-classifications.index', Tags),
                  link('Transaction Types', 'transaction-types.index', ListChecks),
                  link('Delivery Methods', 'delivery-methods.index', Truck),
                  link('Performance Standards', 'performance-standards.index', Gauge),
                  ...(isSystemAdministrator
                      ? [link('User Management', 'users.index', UserCog), link('Email Settings', 'mail-settings.edit', Mail)]
                      : []),
              ]
            : [];

    return [
        {
            label: 'Documents',
            items: [
                link('Dashboard', 'dashboard', LayoutGrid, 'dashboard*'),
                link('New Document', 'documents.index', FilePlus),
                link('Transactions', 'transactions.inbox', Inbox),
            ],
        },
        { label: 'Approvals', items: approvals },
        { label: 'Administration', items: administration },
    ].filter((section) => section.items.length > 0);
});
</script>

<template>
    <Sidebar collapsible="icon" class="border-r border-slate-200 bg-white">
        <SidebarContent class="gap-2 px-2 pb-4 pt-20">
            <NavMain :sections="sections" />
        </SidebarContent>
    </Sidebar>
</template>
