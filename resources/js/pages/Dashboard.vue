<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: '/dashboard',
    },
];

// Receive the live data from our new DashboardController
const props = defineProps<{
    selectedMonth: string;
    months: string[];
    selectedYear: number;
    years: number[];
    transactionCategories: string[];
    processingCategory: string;
    /** The Workstation user's own office; null when the dashboard covers every office. */
    scope: { code: string; name: string } | null;
    summaryCards: Array<any>;
    delinquent: any;
    pipeline: Array<any>;
    processingTimes: Array<any>;
}>();

const currentMonth = ref(props.selectedMonth);
const currentYear = ref(props.selectedYear);

// Refetches data from the controller when they click generate
function generateReport() {
    router.get(route('dashboard'), { month: currentMonth.value, year: currentYear.value, processing_category: props.processingCategory }, { preserveState: true });
}

// Card reports open in their own tab (they can run to thousands of rows), for the generated month and year.
function reportUrl(filters: Record<string, string>) {
    return route('dashboard.report', { month: props.selectedMonth, year: props.selectedYear, ...filters });
}

function categoryReportUrl(row: any) {
    return reportUrl({ transaction_category: row.label, pipeline_status: row.pipeline });
}

function changeProcessingCategory(event: Event) {
    router.get(
        route('dashboard', { month: props.selectedMonth, year: props.selectedYear, processing_category: (event.target as HTMLSelectElement).value }),
        {},
        { preserveScroll: true },
    );
}

const scopeLabel = props.delinquent.scope === 'all' ? 'All departments' : 'Your office';
const scopeText = props.delinquent.scope === 'all' ? 'across all departments' : 'held by your office';
const alertCards = [
    { report: 'delinquent', title: 'Delinquent', badge: 'Action Req', value: props.delinquent.value, suffix: props.delinquent.label, empty: 'No delinquent documents', tone: 'red' },
    { report: 'due_tomorrow', title: 'For Due', badge: 'Tomorrow', value: props.delinquent.dueTomorrow, suffix: 'Documents', empty: 'Nothing is due tomorrow', tone: 'orange' },
    { report: 'todo', title: 'To-Do List', badge: 'Open', value: props.delinquent.todo, suffix: 'Documents', empty: 'Nothing to do', tone: 'emerald' },
];
const alertTone: Record<string, { value: string; badge: string; hover: string }> = {
    red: { value: 'text-red-600', badge: 'bg-red-50 text-red-600', hover: 'hover:border-red-200 hover:bg-red-50/30' },
    orange: { value: 'text-orange-600', badge: 'bg-orange-50 text-orange-600', hover: 'hover:border-orange-200 hover:bg-orange-50/30' },
    emerald: { value: 'text-slate-800', badge: 'bg-emerald-50 text-emerald-600', hover: 'hover:border-emerald-200 hover:bg-emerald-50/30' },
};

// Static class strings (not interpolated) so Tailwind's JIT scanner can detect them
const toneClass: Record<string, string> = {
    'emerald-600': 'bg-emerald-600',
    'emerald-500': 'bg-emerald-500',
    'emerald-400': 'bg-emerald-400',
    'emerald-300': 'bg-emerald-300',
    'emerald-200': 'bg-emerald-200',
    'slate-200': 'bg-slate-200',
};
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex w-full min-h-screen flex-col gap-6 bg-slate-50/50 p-6">
            
            <!-- Header & Filters -->
            <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
                <div class="flex items-center gap-3 rounded-xl border border-slate-200 bg-white p-1.5 shadow-sm">
                    <span class="pl-3 text-sm font-medium text-slate-500">Dashboard Report:</span>
                    
                    <!-- Dynamic Month Dropdown -->
                    <select v-model="currentMonth" aria-label="Report month" class="cursor-pointer rounded-lg border-none bg-slate-50 py-1.5 pl-3 pr-8 text-sm font-semibold focus:ring-0">
                        <option v-for="month in months" :key="month" :value="month">{{ month }}</option>
                    </select>
                    <select v-model="currentYear" aria-label="Report year" class="cursor-pointer rounded-lg border-none bg-slate-50 py-1.5 pl-3 pr-8 text-sm font-semibold focus:ring-0">
                        <option v-for="year in years" :key="year" :value="year">{{ year }}</option>
                    </select>

                    <button @click="generateReport" :disabled="currentMonth === selectedMonth && currentYear === selectedYear" class="rounded-lg bg-slate-900 px-4 py-1.5 text-sm font-medium text-white transition hover:bg-slate-800 disabled:opacity-50">
                        Generate
                    </button>
                </div>
                <p class="text-sm text-slate-500">
                    <template v-if="scope">
                        Showing <span class="font-semibold text-slate-700">{{ scope.name }}</span> only
                    </template>
                    <template v-else>Showing all offices</template>
                </p>
            </div>

            <!-- Top Summary Cards -->
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-6">
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

                <!-- Alert cards: all departments for oversight roles, otherwise the user's office -->
                <component
                    :is="card.value > 0 ? 'a' : 'div'"
                    v-for="card in alertCards"
                    :key="card.report"
                    :href="card.value > 0 ? reportUrl({ report: card.report }) : undefined"
                    :target="card.value > 0 ? '_blank' : undefined"
                    :rel="card.value > 0 ? 'noopener' : undefined"
                    class="flex flex-col justify-between rounded-2xl border border-slate-200 bg-white p-5 shadow-[0_2px_10px_-3px_rgba(6,81,237,0.03)] transition"
                    :class="card.value > 0 ? alertTone[card.tone].hover : 'cursor-default opacity-75'"
                    :title="card.value > 0 ? `Open the ${card.title} report ${scopeText} in a new tab` : `${card.empty} ${scopeText}`"
                >
                    <div class="flex items-start justify-between">
                        <span class="text-sm font-semibold text-slate-500">
                            {{ card.title }}
                            <span class="block text-[11px] font-medium text-slate-400">{{ scopeLabel }}</span>
                        </span>
                        <span class="rounded-md px-2 py-1 text-xs font-bold" :class="alertTone[card.tone].badge">{{ card.badge }}</span>
                    </div>
                    <div class="mt-4">
                        <span class="text-3xl font-bold" :class="alertTone[card.tone].value">{{ card.value }}</span>
                        <span class="ml-2 text-xs text-slate-400">{{ card.suffix }}</span>
                    </div>
                </component>
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
                        <component
                            :is="row.value > 0 ? 'a' : 'div'"
                            v-for="(row, idx) in stage.rows"
                            :key="row.label"
                            :href="row.value > 0 ? categoryReportUrl(row) : undefined"
                            :target="row.value > 0 ? '_blank' : undefined"
                            :rel="row.value > 0 ? 'noopener' : undefined"
                            class="flex items-end justify-between rounded-md transition"
                            :class="[idx !== stage.rows.length - 1 ? 'border-b border-slate-100 pb-2' : '', row.value > 0 ? 'hover:bg-slate-50 hover:px-2' : 'cursor-default opacity-60']"
                            :title="row.value > 0 ? `Open the ${stage.title} ${row.label} report for ${selectedMonth} ${selectedYear} in a new tab` : `No ${stage.title} ${row.label} documents`"
                        >
                            <span class="text-sm text-slate-500">{{ row.label }}</span>
                            <span class="text-xl font-bold text-slate-800">{{ row.value }}</span>
                        </component>
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
                        <h3 class="text-lg font-bold text-slate-800">Average Processing Times (Completed)</h3>
                        <select
                            :value="processingCategory"
                            aria-label="Transaction category for processing times"
                            class="cursor-pointer rounded-lg border border-slate-200 bg-slate-50 py-1 pl-3 pr-8 text-sm font-semibold focus:ring-0"
                            @change="changeProcessingCategory"
                        >
                            <option v-for="category in transactionCategories" :key="category" :value="category">{{ category }}</option>
                        </select>
                    </div>
                    <Link :href="route('documents.index')" class="cursor-pointer text-xs font-medium text-slate-400 hover:text-slate-600 transition">View Full Registry &rarr;</Link>
                </div>

                <div v-if="processingTimes.length > 0" class="grid grid-cols-2 gap-x-6 gap-y-8 md:grid-cols-4 lg:grid-cols-6">
                    <div v-for="item in processingTimes" :key="item.label">
                        <span class="mb-1 block truncate text-xs font-semibold uppercase tracking-wider text-slate-400" :title="item.label">{{ item.label }}</span>
                        <div class="flex items-baseline gap-1">
                            <span class="text-2xl font-bold text-slate-800">{{ Number(item.value).toFixed(2) }}</span>
                            <span class="text-xs text-slate-400">days</span>
                        </div>
                        <div class="mt-2 h-1.5 w-full rounded-full bg-slate-100">
                            <div class="h-1.5 rounded-full transition-all duration-1000" :class="toneClass[item.tone]" :style="`width: ${item.width}%`"></div>
                        </div>
                    </div>
                </div>
                
                <div v-else class="text-center py-10">
                    <p class="text-sm font-semibold text-slate-600">No {{ processingCategory }} document types are registered.</p>
                    <p class="text-xs text-slate-400 mt-1">Add document types in the Document Type registry.</p>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
