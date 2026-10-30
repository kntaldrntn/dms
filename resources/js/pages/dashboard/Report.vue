<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Printer } from 'lucide-vue-next';

defineProps<{
    title: string;
    dateRange: string;
    rows: Array<{
        id: number;
        /** Confidential and this user is Workstation staff: the server left the details out. */
        details_hidden: boolean;
        date: string;
        barcode: string;
        document_code: string | null;
        document_type: string | null;
        source_type: string | null;
        source_name: string | null;
        gender: string | null;
        contact_no: string | null;
        email: string | null;
        subject_matter: string | null;
        department: string | null;
        total_days: number;
    }>;
    generatedBy: string;
    generatedOn: string;
}>();

// Fixed column widths and wrapping (like the old DTS report) so all 11 columns fit the page, on screen and on paper.
const cell = 'border border-slate-400 px-1.5 py-1 align-middle break-words';
const columns = ['7%', '10%', '8%', '6%', '11%', '6%', '9%', '10%', '21%', '5%', '7%'];
const printReport = () => window.print();
</script>

<template>
    <Head :title="title" />

    <!-- Standalone, printable page in the layout of the old DTS report (opened in its own tab). -->
    <main class="min-h-screen bg-white px-6 py-8 text-slate-900 print:p-0">
        <div class="mx-auto max-w-[1400px]">
            <div class="mb-4 flex justify-end print:hidden">
                <button
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600"
                    @click="printReport"
                >
                    <Printer class="h-4 w-4" aria-hidden="true" /> Print
                </button>
            </div>

            <h1 class="mb-6 text-center text-xl font-medium tracking-wide">DOCUMENT MANAGEMENT SYSTEM REPORT</h1>

            <dl class="mb-5 grid grid-cols-[10rem_1fr] gap-y-1 text-sm">
                <dt>REPORT TYPE:</dt>
                <dd>{{ title }}</dd>
                <dt>DATE RANGE:</dt>
                <dd>{{ dateRange }}</dd>
                <dt>NO. OF RESULTS:</dt>
                <dd>{{ rows.length.toLocaleString() }}</dd>
            </dl>

            <table class="w-full table-fixed border-collapse text-xs print:text-[9px]">
                <colgroup>
                    <col v-for="(width, index) in columns" :key="index" :style="{ width }" />
                </colgroup>
                <thead class="text-[11px] font-semibold print:text-[9px]">
                    <tr class="text-center">
                        <th scope="col" :class="cell">DATE</th>
                        <th scope="col" :class="cell">BARCODE</th>
                        <th scope="col" :class="cell">DOCUMENT TYPE</th>
                        <th scope="col" :class="cell">SOURCE TYPE</th>
                        <th scope="col" :class="cell">SOURCE</th>
                        <th scope="col" :class="cell">GENDER</th>
                        <th scope="col" :class="cell">CONTACT NUMBER</th>
                        <th scope="col" :class="cell">EMAIL ADDRESS</th>
                        <th scope="col" :class="cell">SUBJECT MATTER</th>
                        <th scope="col" :class="cell">DEPT</th>
                        <th scope="col" :class="cell">TOTAL TIME</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in rows" :key="row.id" class="break-inside-avoid">
                        <td :class="[cell, 'text-center']">{{ row.date }}</td>
                        <td :class="[cell, 'text-center']">
                            <span v-if="row.details_hidden">{{ row.barcode }}</span>
                            <Link v-else :href="route('documents.show', row.id)" class="text-blue-600 hover:underline">{{ row.barcode }}</Link>
                        </td>
                        <td :class="[cell, 'text-center']" :title="row.document_type ?? undefined">{{ row.document_code || row.document_type }}</td>
                        <td :class="[cell, 'text-center']">{{ row.source_type }}</td>
                        <td :class="cell">{{ row.source_name }}</td>
                        <td :class="[cell, 'text-center']">{{ row.gender }}</td>
                        <td :class="cell">{{ row.contact_no }}</td>
                        <td :class="[cell, 'break-all']">{{ row.email }}</td>
                        <td :class="cell">{{ row.subject_matter }}</td>
                        <td :class="cell">{{ row.department }}</td>
                        <td :class="[cell, 'text-right']">{{ row.total_days.toFixed(2) }} days</td>
                    </tr>
                    <tr v-if="rows.length === 0">
                        <td colspan="11" :class="[cell, 'py-6 text-center text-slate-500']">No documents match this report.</td>
                    </tr>
                </tbody>
            </table>

            <footer class="mt-6 text-sm">
                <p>Generated By: {{ generatedBy }}</p>
                <p>Date: {{ generatedOn }}</p>
            </footer>
        </div>
    </main>
</template>

<style>
/* Landscape with narrow margins, as the old DTS report printed. */
@page {
    size: landscape;
    margin: 10mm;
}
</style>
