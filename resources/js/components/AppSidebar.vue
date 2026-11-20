<script setup lang="ts">
import NavMain from '@/components/NavMain.vue';
import { Sidebar, SidebarContent, SidebarFooter } from '@/components/ui/sidebar';
import { useSidebarCounts } from '@/composables/useSidebarCounts';
import { type NavItem, type NavSection } from '@/types';
import { usePage } from '@inertiajs/vue3';
import {
    AlarmClock,
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

const link = (title: string, name: string, icon: NavItem['icon'], badge?: NavItem['badge'], activePattern = `${name.split('.')[0]}.*`): NavItem => ({
    title,
    href: route(name),
    icon,
    isActive: route().current(activePattern),
    badge,
});

// Last known counts, kept across page visits (the deferred prop arrives just after each page renders).
// A badge-capable link gets null when its count is 0, so it keeps its badge space and the text never shifts.
const counts = useSidebarCounts();
const plural = (count: number, word: string) => `${count} ${word}${count === 1 ? '' : 's'}`;
const waitingBadge = (count: number | null | undefined): NavItem['badge'] =>
    count ? { count, tone: 'default', label: `${plural(count, 'document')} waiting` } : null;

const transactionsBadge = computed<NavItem['badge']>(() => {
    const inbox = counts.value?.transactions;
    if (!inbox?.open) return null;
    const label = plural(inbox.open, 'open document') + (inbox.delinquent ? `, ${inbox.delinquent} delinquent` : '');
    return { count: inbox.open, tone: inbox.delinquent ? 'alert' : 'default', label };
});

const monitorBadge = computed<NavItem['badge']>(() => {
    const untagged = counts.value?.untaggedDelinquent;
    return untagged ? { count: untagged, tone: 'alert', label: `${untagged} delinquent, not tagged yet` } : null;
});

const roleLabels: Record<string, string> = {
    staff: 'Department Staff',
    department_head: 'Department Head',
    city_administrator: 'City Administrator',
    city_mayor: 'City Mayor',
    records_manager: 'Records Manager',
    system_administrator: 'System Administrator',
};
const office = computed(() => page.props.auth.office as { code: string; name: string } | null);
const roleLabel = computed(() => roleLabels[role.value] ?? '');

const sections = computed<NavSection[]>(() => {
    const isSystemAdministrator = role.value === 'system_administrator';

    const approvals = [
        // Approval pages depend on the office (every ADM/OCM user records approvals), not on the role alone.
        ...(page.props.auth.canViewAdmApprovals
            ? [link('ADM Approvals', 'adm-approvals.index', Stamp, waitingBadge(counts.value?.admApprovals))]
            : []),
        ...(page.props.auth.canViewOcmApprovals
            ? [link('OCM Approvals', 'ocm-approvals.index', BadgeCheck, waitingBadge(counts.value?.ocmApprovals))]
            : []),
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
                link('Dashboard', 'dashboard', LayoutGrid, undefined, 'dashboard*'),
                link('New Document', 'documents.index', FilePlus),
                link('Transactions', 'transactions.inbox', Inbox, transactionsBadge.value),
            ],
        },
        { label: 'Approvals', items: approvals },
        {
            label: 'Monitoring',
            items: page.props.auth.canMonitorDelinquency ? [link('Delinquency Monitor', 'delinquency.index', AlarmClock, monitorBadge.value)] : [],
        },
        { label: 'Administration', items: administration },
    ].filter((section) => section.items.length > 0);
});
</script>

<template>
    <Sidebar collapsible="icon" class="border-r border-slate-200 bg-white">
        <!-- Collapsed (48px): no outer padding, so the group padding leaves exactly the 32px icon button, centred. -->
        <SidebarContent class="scrollbar-none gap-2 px-2 pb-4 pt-20 group-data-[collapsible=icon]:px-0">
            <NavMain :sections="sections" />
        </SidebarContent>
        <!-- Which office the user is working as (the Transactions count is that office's inbox). -->
        <SidebarFooter v-if="office" class="border-t border-slate-200 p-2">
            <div
                class="flex items-center gap-3 rounded-lg bg-slate-50 p-2 group-data-[collapsible=icon]:justify-center group-data-[collapsible=icon]:bg-transparent group-data-[collapsible=icon]:p-0"
                :title="`${office.name} · ${roleLabel}`"
            >
                <span
                    class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-[#265f92] text-[10px] font-bold tracking-wide text-white"
                    aria-hidden="true"
                    >{{ office.code.slice(0, 4) }}</span
                >
                <div class="min-w-0 group-data-[collapsible=icon]:sr-only">
                    <p class="sr-only">Signed in for</p>
                    <p class="truncate text-sm font-semibold text-slate-800">{{ office.name }}</p>
                    <p class="truncate text-xs text-slate-500">{{ office.code }} · {{ roleLabel }}</p>
                </div>
            </div>
        </SidebarFooter>
    </Sidebar>
</template>
