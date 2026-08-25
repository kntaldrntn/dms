<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: '/dashboard',
    },
];

defineProps<{
    name?: string;
}>();

// TODO: replace with real prop/controller data
const selectedMonth = ref('August');

const summaryCards = [
    { label: 'Monthly Documents', value: '1,517', badge: 'Total', badgeColor: 'emerald' },
    { label: 'External Clients', value: '646', suffix: 'Served' },
    { label: 'Internal Clients', value: '871', suffix: 'Served' },
];

const delinquent = {
    value: 46,
    label: 'Transactions',
    todo: 1,
    dueTomorrow: 0,
};

// Status pipeline broken down by complexity, matching the legacy system's On-Time / On-Going / Delayed cards
const pipeline = [
    {
        title: 'On-Time',
        subtitle: 'Documents',
        status: 'Healthy',
        statusColor: 'emerald',
        rows: [
            { label: 'Simple', value: 383 },
            { label: 'Complex', value: 43 },
            { label: 'Highly Technical', value: 0 },
        ],
    },
    {
        title: 'On-Going',
        subtitle: 'Documents',
        status: 'In Progress',
        statusColor: 'blue',
        rows: [
            { label: 'Simple', value: 637 },
            { label: 'Complex', value: 173 },
            { label: 'Highly Technical', value: 1 },
        ],
    },
    {
        title: 'Delayed',
        subtitle: 'Documents',
        status: 'Attention',
        statusColor: 'orange',
        rows: [
            { label: 'Simple', value: 278 },
            { label: 'Complex', value: 2 },
            { label: 'Highly Technical', value: 0 },
        ],
    },
];

// Static class strings (not interpolated) so Tailwind's JIT scanner can detect them
const toneClass = {
    'emerald-600': 'bg-emerald-600',
    'emerald-500': 'bg-emerald-500',
    'emerald-400': 'bg-emerald-400',
    'emerald-300': 'bg-emerald-300',
    'emerald-200': 'bg-emerald-200',
    'slate-200': 'bg-slate-200',
} as const;

// Average processing time per real document type
const processingTimes = [
    { label: 'AIR/RIS', value: 8.71, width: 100, tone: 'emerald-600' },
    { label: 'Purchase Order', value: 5.03, width: 58, tone: 'emerald-500' },
    { label: 'IPW', value: 5.3, width: 61, tone: 'emerald-500' },
    { label: 'Payroll', value: 4.71, width: 54, tone: 'emerald-400' },
    { label: 'Voucher', value: 4.66, width: 54, tone: 'emerald-400' },
    { label: 'Obligation Request', value: 4.24, width: 49, tone: 'emerald-400' },
    { label: 'Project Proposal', value: 4.07, width: 47, tone: 'emerald-300' },
    { label: 'Purchase Request', value: 3.75, width: 43, tone: 'emerald-300' },
    { label: 'BAC Resolution', value: 2.61, width: 30, tone: 'emerald-300' },
    { label: 'Permits', value: 2.16, width: 25, tone: 'emerald-200' },
    { label: 'Communication', value: 1.71, width: 20, tone: 'emerald-200' },
    { label: 'Abstract of Canvass', value: 1.42, width: 16, tone: 'emerald-200' },
] as const;
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex w-full min-h-screen flex-col gap-6 bg-slate-50/50 p-6">
            <!-- Header & Filters -->
            <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
                <div>
                    <h1 class="text-2xl font-bold tracking-tight text-slate-800">Document Tracking Pulse</h1>
                    <p class="mt-1 text-sm text-slate-500">Segmented indicators calculated from this month's activity.</p>
                </div>
                <div class="flex items-center gap-3 rounded-xl border border-slate-200 bg-white p-1.5 shadow-sm">
                    <span class="pl-3 text-sm font-medium text-slate-500">Dashboard Report:</span>
                    <select v-model="selectedMonth" class="cursor-pointer rounded-lg border-none bg-slate-50 py-1.5 pl-3 pr-8 text-sm font-semibold focus:ring-0">
                        <option>August</option>
                        <option>September</option>
                    </select>
                    <button class="rounded-lg bg-slate-900 px-4 py-1.5 text-sm font-medium text-white transition hover:bg-slate-800">Generate</button>
                </div>
            </div>

            <!-- Top Summary Cards -->
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-4">
                <div
                    v-for="card in summaryCards"
                    :key="card.label"
                    class="flex flex-col justify-between rounded-2xl border border-slate-200 bg-white p-5 shadow-[0_2px_10px_-3px_rgba(6,81,237,0.03)]"
                >
                    <div class="flex items-start justify-between">
                        <span class="text-sm font-semibold text-slate-500">{{ card.label }}</span>
                        <span v-if="card.badge" class="rounded-md bg-emerald-50 px-2 py-1 text-xs font-bold text-emerald-600">{{ card.badge }}</span>
                    </div>
                    <div class="mt-4">
                        <span class="text-3xl font-bold text-slate-800">{{ card.value }}</span>
                        <span v-if="card.suffix" class="ml-2 text-xs text-slate-400">{{ card.suffix }}</span>
                    </div>
                </div>

                <!-- Delinquent / Alerts -->
                <div class="flex flex-col justify-between rounded-2xl border border-slate-200 bg-white p-5 shadow-[0_2px_10px_-3px_rgba(6,81,237,0.03)]">
                    <div class="flex items-start justify-between">
                        <span class="text-sm font-semibold text-slate-500">Delinquent</span>
                        <span class="rounded-md bg-red-50 px-2 py-1 text-xs font-bold text-red-600">Action Req</span>
                    </div>
                    <div class="mt-4 flex items-end justify-between">
                        <div>
                            <span class="text-3xl font-bold text-red-600">{{ delinquent.value }}</span>
                            <span class="ml-2 text-xs text-slate-400">{{ delinquent.label }}</span>
                        </div>
                        <div class="text-right">
                            <span class="block text-xs font-medium text-slate-500">To-Do: <strong class="text-slate-800">{{ delinquent.todo }}</strong></span>
                            <span class="block text-xs font-medium text-slate-500">Due Tmrw: <strong class="text-slate-800">{{ delinquent.dueTomorrow }}</strong></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Middle Status Pipeline (On-Time, On-Going, Delayed) -->
            <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <div
                    v-for="stage in pipeline"
                    :key="stage.title"
                    class="rounded-2xl border border-slate-200 bg-white p-6 shadow-[0_2px_10px_-3px_rgba(6,81,237,0.03)]"
                >
                    <div class="mb-6 flex items-center justify-between">
                        <div>
                            <h3 class="text-lg font-bold text-slate-800">{{ stage.title }}</h3>
                            <p class="text-xs font-medium text-slate-400">{{ stage.subtitle }}</p>
                        </div>
                        <span
                            class="flex items-center gap-1.5 rounded-full px-2 py-1 text-xs font-semibold"
                            :class="{
                                'bg-emerald-50 text-emerald-600': stage.statusColor === 'emerald',
                                'bg-blue-50 text-blue-600': stage.statusColor === 'blue',
                                'bg-orange-50 text-orange-600': stage.statusColor === 'orange',
                            }"
                        >
                            <span
                                class="h-1.5 w-1.5 rounded-full"
                                :class="{
                                    'bg-emerald-500': stage.statusColor === 'emerald',
                                    'bg-blue-500': stage.statusColor === 'blue',
                                    'bg-orange-500': stage.statusColor === 'orange',
                                }"
                            ></span>
                            {{ stage.status }}
                        </span>
                    </div>
                    <div class="space-y-4">
                        <div
                            v-for="(row, idx) in stage.rows"
                            :key="row.label"
                            class="flex items-end justify-between"
                            :class="idx !== stage.rows.length - 1 ? 'border-b border-slate-100 pb-2' : ''"
                        >
                            <span class="text-sm text-slate-500">{{ row.label }}</span>
                            <span class="text-xl font-bold text-slate-800">{{ row.value }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Bottom Section (Average Processing Times) -->
            <div class="w-full rounded-2xl border border-slate-200 bg-white p-6 shadow-[0_2px_10px_-3px_rgba(6,81,237,0.03)]">
                <div class="mb-6 flex items-center justify-between border-b border-slate-100 pb-4">
                    <div class="flex items-center gap-3">
                        <div class="flex h-8 w-8 items-center justify-center rounded-full bg-emerald-50">
                            <svg class="h-4 w-4 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                        <h3 class="text-lg font-bold text-slate-800">Average Processing Times</h3>
                    </div>
                    <span class="cursor-pointer text-xs font-medium text-slate-400 hover:text-slate-600">View full report &rarr;</span>
                </div>

                <div class="grid grid-cols-2 gap-x-6 gap-y-8 md:grid-cols-4 lg:grid-cols-6">
                    <div v-for="item in processingTimes" :key="item.label">
                        <span class="mb-1 block truncate text-xs font-semibold uppercase tracking-wider text-slate-400">{{ item.label }}</span>
                        <div class="flex items-baseline gap-1">
                            <span class="text-2xl font-bold text-slate-800">{{ item.value.toFixed(2) }}</span>
                            <span class="text-xs text-slate-400">days</span>
                        </div>
                        <div class="mt-2 h-1.5 w-full rounded-full bg-slate-100">
                            <div class="h-1.5 rounded-full" :class="toneClass[item.tone]" :style="`width: ${item.width}%`"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>