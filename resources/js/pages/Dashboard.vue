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
    summaryCards: Array<any>;
    delinquent: any;
    pipeline: Array<any>;
    processingTimes: Array<any>;
    selectedCategory?: string | null;
    selectedPipeline?: string | null;
    drillDownDocuments?: any | null;
    reportTitle?: string | null;
    reportDescription?: string | null;
}>();

const currentMonth = ref(props.selectedMonth);

// Refetches data from the controller when they click generate
function generateReport() {
    router.get(route('dashboard'), { month: currentMonth.value }, { preserveState: true });
}

function categoryReportUrl(row: any) {
    return `/dashboard?month=${encodeURIComponent(props.selectedMonth)}&transaction_category=${encodeURIComponent(row.label)}&pipeline_status=${encodeURIComponent(row.pipeline)}`;
}

function dashboardUrl() {
    return `/dashboard?month=${encodeURIComponent(props.selectedMonth)}`;
}

function delinquentReportUrl() {
    return `/dashboard?month=${encodeURIComponent(props.selectedMonth)}&delinquent=1`;
}

function formatDate(value: string | null) {
    if (!value) return '—';
    return new Intl.DateTimeFormat('en-PH', {
        month: 'short', day: '2-digit', year: 'numeric',
    }).format(new Date(value));
}

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
                <div>
                    <h1 class="text-2xl font-bold tracking-tight text-slate-800">Document Tracking Status</h1>
                    <p class="mt-1 text-sm text-slate-500">Segmented indicators calculated from this month's activity.</p>
                </div>
                <div class="flex items-center gap-3 rounded-xl border border-slate-200 bg-white p-1.5 shadow-sm">
                    <span class="pl-3 text-sm font-medium text-slate-500">Dashboard Report:</span>
                    
                    <!-- Dynamic Month Dropdown -->
                    <select v-model="currentMonth" class="cursor-pointer rounded-lg border-none bg-slate-50 py-1.5 pl-3 pr-8 text-sm font-semibold focus:ring-0">
                        <option v-for="month in months" :key="month" :value="month">{{ month }}</option>
                    </select>
                    
                    <button @click="generateReport" :disabled="currentMonth === selectedMonth" class="rounded-lg bg-slate-900 px-4 py-1.5 text-sm font-medium text-white transition hover:bg-slate-800 disabled:opacity-50">
                        Generate
                    </button>
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

                <!-- Delinquent / Alerts (All active departments) -->
                <component :is="delinquent.value > 0 ? Link : 'div'" :href="delinquentReportUrl()" class="flex flex-col justify-between rounded-2xl border border-slate-200 bg-white p-5 shadow-[0_2px_10px_-3px_rgba(6,81,237,0.03)] transition" :class="delinquent.value > 0 ? 'hover:border-red-200 hover:bg-red-50/30' : 'cursor-default opacity-75'" :title="delinquent.value > 0 ? 'View delinquent documents across all departments' : 'No delinquent documents across all departments'">
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
                            :is="row.value > 0 ? Link : 'div'"
                            v-for="(row, idx) in stage.rows"
                            :key="row.label"
                            :href="categoryReportUrl(row)"
                            class="flex items-end justify-between rounded-md transition"
                            :class="[idx !== stage.rows.length - 1 ? 'border-b border-slate-100 pb-2' : '', row.value > 0 ? 'hover:bg-slate-50 hover:px-2' : 'cursor-default opacity-60']"
                            :title="row.value > 0 ? `View ${stage.title} ${row.label} documents for ${selectedMonth}` : `No ${stage.title} ${row.label} documents`"
                        >
                            <span class="text-sm text-slate-500">{{ row.label }}</span>
                            <span class="text-xl font-bold text-slate-800">{{ row.value }}</span>
                        </component>
                    </div>
                </div>
            </div>

            <!-- DTS-style report drill-down, kept on the dashboard -->
            <section v-if="reportTitle && drillDownDocuments" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="flex flex-col gap-3 border-b border-slate-200 bg-slate-50 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 class="text-lg font-bold text-slate-800">{{ reportTitle }}</h2>
                        <p class="text-xs text-slate-500">{{ reportDescription }} · {{ drillDownDocuments.total }} result{{ drillDownDocuments.total === 1 ? '' : 's' }}</p>
                    </div>
                    <Link :href="dashboardUrl()" class="text-sm font-semibold text-indigo-600 transition hover:text-indigo-800">Close report</Link>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full text-left text-sm text-slate-700">
                        <thead class="border-b border-slate-200 bg-slate-100 text-xs font-bold uppercase tracking-wider text-slate-600">
                            <tr>
                                <th class="px-4 py-3">Date</th>
                                <th class="px-4 py-3">Barcode</th>
                                <th class="px-4 py-3">Document Type</th>
                                <th class="px-4 py-3">Source</th>
                                <th class="px-4 py-3">Subject Matter</th>
                                <th class="px-4 py-3">Dept.</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200">
                            <tr v-for="document in drillDownDocuments.data" :key="document.id" class="transition hover:bg-slate-50">
                                <td class="whitespace-nowrap px-4 py-3">{{ formatDate(document.created_at) }}</td>
                                <td class="whitespace-nowrap px-4 py-3 font-mono font-semibold">
                                    <Link :href="route('documents.show', document.id)" class="text-indigo-600 hover:underline">{{ document.barcode }}</Link>
                                </td>
                                <td class="px-4 py-3">{{ document.document_type?.document_type || '—' }}</td>
                                <td class="px-4 py-3">{{ document.source_name || '—' }}</td>
                                <td class="max-w-md px-4 py-3">{{ document.subject_matter || '—' }}</td>
                                <td class="px-4 py-3">{{ document.department?.code || '—' }}</td>
                            </tr>
                            <tr v-if="drillDownDocuments.data.length === 0">
                                <td colspan="6" class="px-4 py-8 text-center text-sm text-slate-500">No documents match this SLA report.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="drillDownDocuments.last_page > 1" class="flex items-center justify-between border-t border-slate-200 px-5 py-3 text-sm">
                    <Link v-if="drillDownDocuments.prev_page_url" :href="drillDownDocuments.prev_page_url" class="font-semibold text-indigo-600 hover:underline">← Previous</Link>
                    <span v-else></span>
                    <span class="text-slate-500">Page {{ drillDownDocuments.current_page }} of {{ drillDownDocuments.last_page }}</span>
                    <Link v-if="drillDownDocuments.next_page_url" :href="drillDownDocuments.next_page_url" class="font-semibold text-indigo-600 hover:underline">Next →</Link>
                    <span v-else></span>
                </div>
            </section>

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
                    </div>
                    <Link :href="route('documents.index')" class="cursor-pointer text-xs font-medium text-slate-400 hover:text-slate-600 transition">View Full Registry &rarr;</Link>
                </div>

                <div v-if="processingTimes.length > 0" class="grid grid-cols-2 gap-x-6 gap-y-8 md:grid-cols-4 lg:grid-cols-6">
                    <div v-for="item in processingTimes" :key="item.label">
                        <span class="mb-1 block truncate text-xs font-semibold uppercase tracking-wider text-slate-400" :title="item.label">{{ item.label }}</span>
                        <div class="flex items-baseline gap-1">
                            <span class="text-2xl font-bold text-slate-800">{{ item.value }}</span>
                            <span class="text-xs text-slate-400">days</span>
                        </div>
                        <div class="mt-2 h-1.5 w-full rounded-full bg-slate-100">
                            <div class="h-1.5 rounded-full transition-all duration-1000" :class="toneClass[item.tone]" :style="`width: ${item.width}%`"></div>
                        </div>
                    </div>
                </div>
                
                <div v-else class="text-center py-10">
                    <p class="text-sm font-semibold text-slate-600">No documents completed in {{ selectedMonth }}.</p>
                    <p class="text-xs text-slate-400 mt-1">Check back once files finish their routing life cycle.</p>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
