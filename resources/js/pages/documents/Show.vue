<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { Printer, ArrowLeft, RotateCcw } from 'lucide-vue-next';

const props = defineProps<{
    document: any;
    trails: any[]; // The routing history array from the controller
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Documents', href: '/documents' },
    { title: props.document.barcode, href: `/documents/${props.document.id}` },
];

// --- HELPER FUNCTIONS ---

// Formats date to: "Sep. 02, 2026 10:58 AM"
const formatDate = (dateString: string) => {
    if (!dateString) return '-';
    
    // Strip the 'Z' (UTC marker) to prevent JS from double-adding GMT+8
    const rawLocalString = dateString.replace('Z', '');
    
    const date = new Date(rawLocalString);
    
    return date.toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric' }) + 
           ' ' + 
           date.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
};

// Calculates duration dynamically (seconds, minutes, hours, or days)
const calculateDuration = (startStr: string, endStr: string) => {
    if (!startStr || !endStr) return '-';
    
    const start = new Date(startStr).getTime();
    const end = new Date(endStr).getTime();
    const diffMs = end - start;
    
    if (diffMs <= 0) return '0.00 sec.';
    
    const diffSec = diffMs / 1000;
    if (diffSec < 60) return `${diffSec.toFixed(2)} sec.`;
    
    const diffMin = diffSec / 60;
    if (diffMin < 60) return `${diffMin.toFixed(2)} min.`;
    
    const diffHrs = diffMin / 60;
    if (diffHrs < 24) return `${diffHrs.toFixed(2)} hrs.`;
    
    const diffDays = diffHrs / 24;
    return `${diffDays.toFixed(2)} days`;
};

// Office Duration = Time between this office receiving it and releasing it
const getOfficeDuration = (trail: any) => {
    return calculateDuration(trail.received_at, trail.released_at);
};

// Transit Duration = Time between this office releasing it, and the NEXT office receiving it
const getTransitDuration = (trail: any, index: number) => {
    const nextTrail = props.trails[index + 1];
    if (!trail.released_at || !nextTrail || !nextTrail.received_at) return '-';
    return calculateDuration(trail.released_at, nextTrail.received_at);
};
</script>

<template>
    <Head :title="`Document - ${document.barcode}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="p-6 w-full mx-auto bg-slate-50/50 min-h-screen flex flex-col gap-6">
            
            <!-- Action Bar -->
            <div class="flex items-center justify-between">
                <Link :href="route('documents.index')" class="flex items-center gap-2 text-sm font-medium text-slate-600 hover:text-emerald-600 transition">
                    <ArrowLeft class="w-4 h-4" /> Back to List
                </Link>
                <button class="flex items-center gap-2 bg-white border border-slate-200 text-slate-700 px-4 py-2 rounded-xl text-sm font-medium shadow-sm hover:bg-slate-50 transition">
                    <Printer class="w-4 h-4" /> Print Routing Slip
                </button>
            </div>

            <!-- Top Section: Document Details (3 Columns) -->
            <div class="bg-white rounded-2xl border border-slate-100 shadow-sm p-6">
                <h2 class="text-lg font-bold text-slate-800 border-b border-slate-100 pb-4 mb-4">Document Details</h2>
                
                <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                    <!-- Column 1 -->
                    <div class="flex flex-col gap-3 text-sm">
                        <div class="grid grid-cols-3 gap-2"><span class="font-semibold text-slate-700">Barcode:</span> <span class="col-span-2 text-emerald-600 font-bold">{{ document.barcode }}</span></div>
                        <div class="grid grid-cols-3 gap-2"><span class="font-semibold text-slate-700">Access Code:</span> <span class="col-span-2 text-slate-600">{{ document.access_code }}</span></div>
                        <div class="grid grid-cols-3 gap-2"><span class="font-semibold text-slate-700">Date Start:</span> <span class="col-span-2 text-slate-600">{{ formatDate(document.created_at) }}</span></div>
                        <div class="grid grid-cols-3 gap-2"><span class="font-semibold text-slate-700">Date End:</span> <span class="col-span-2 text-slate-600">{{ formatDate(document.due_date) }}</span></div>
                        <div class="grid grid-cols-3 gap-2"><span class="font-semibold text-slate-700">Transaction Type:</span> <span class="col-span-2 text-slate-600">{{ document.transaction_type?.name }}</span></div>
                        <div class="grid grid-cols-3 gap-2"><span class="font-semibold text-slate-700">Document Type:</span> <span class="col-span-2 text-slate-600">{{ document.document_type?.document_type }}</span></div>
                        <div class="grid grid-cols-3 gap-2"><span class="font-semibold text-slate-700">Delivery Method:</span> <span class="col-span-2 text-slate-600">{{ document.delivery_method?.delivery_method_name }}</span></div>
                        <div class="grid grid-cols-3 gap-2"><span class="font-semibold text-slate-700">Source Type:</span> <span class="col-span-2 text-slate-600">{{ document.source_type }}</span></div>
                    </div>

                    <!-- Column 2 -->
                    <div class="flex flex-col gap-3 text-sm">
                        <div class="flex flex-col gap-1">
                            <span class="font-semibold text-slate-700">Source:</span>
                            <span class="text-slate-600">{{ document.source_name }} <span v-if="document.gender">- {{ document.gender }}</span></span>
                            <span class="text-slate-500 text-xs">{{ document.source_location }}</span>
                        </div>
                        <div class="flex flex-col gap-1 mt-2">
                            <span class="font-semibold text-slate-700">Subject Matter:</span>
                            <span class="text-slate-600 leading-relaxed">{{ document.subject_matter }}</span>
                        </div>
                    </div>

                    <!-- Column 3 -->
                    <div class="flex flex-col gap-3 text-sm">
                        <div class="flex flex-col gap-1">
                            <span class="font-semibold text-slate-700">Document/s Linked:</span>
                            <span class="text-slate-600">{{ document.linked_documents || 'N/A' }}</span>
                        </div>
                        <div class="flex flex-col gap-1 mt-2">
                            <span class="font-semibold text-slate-700">Attachments:</span>
                            <span class="text-slate-600">N/A</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Bottom Section: Document Routing Trail Table -->
            <div class="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-center text-xs text-slate-700 border-collapse min-w-max">
                        <!-- Complex Header Grouping -->
                        <thead class="bg-[#1e4e79] text-white font-medium tracking-wide">
                            <tr>
                                <th colspan="4" class="px-4 py-3 border-r border-[#153a5b]">RECEIVED</th>
                                <th colspan="4" class="px-4 py-3 border-r border-[#153a5b]">RELEASED</th>
                                <th colspan="2" class="px-4 py-3 border-r border-[#153a5b]">DURATION</th>
                                <th rowspan="2" class="px-4 py-3 border-r border-[#153a5b] align-middle">REMARKS</th>
                                <th rowspan="2" class="px-4 py-3 align-middle w-10"></th>
                            </tr>
                            <tr class="bg-[#245b8c] text-blue-50">
                                <th class="px-3 py-2 border-r border-t border-[#153a5b] font-medium">Office</th>
                                <th class="px-3 py-2 border-r border-t border-[#153a5b] font-medium">By</th>
                                <th class="px-3 py-2 border-r border-t border-[#153a5b] font-medium">Date/Time</th>
                                <th class="px-3 py-2 border-r border-t border-[#153a5b] font-medium">Action</th>
                                
                                <th class="px-3 py-2 border-r border-t border-[#153a5b] font-medium">By</th>
                                <th class="px-3 py-2 border-r border-t border-[#153a5b] font-medium">Date/Time</th>
                                <th class="px-3 py-2 border-r border-t border-[#153a5b] font-medium">Action</th>
                                <th class="px-3 py-2 border-r border-t border-[#153a5b] font-medium">Released To</th>
                                
                                <th class="px-3 py-2 border-r border-t border-[#153a5b] font-medium">Office</th>
                                <th class="px-3 py-2 border-r border-t border-[#153a5b] font-medium">Transit</th>
                            </tr>
                        </thead>
                        
                        <tbody class="divide-y divide-slate-200 text-slate-600">
                            <!-- Render the dynamic trails from the database -->
                            <tr v-for="(trail, index) in trails" :key="trail.id" class="hover:bg-slate-50 transition-colors">
                                <!-- Received -->
                                <td class="px-3 py-3 border-r border-slate-200 font-semibold">{{ trail.office?.name || 'N/A' }}</td>
                                <td class="px-3 py-3 border-r border-slate-200">{{ trail.receiver?.name || 'System' }}</td>
                                <td class="px-3 py-3 border-r border-slate-200 whitespace-nowrap">
                                    <div class="flex flex-col">
                                        <span>{{ formatDate(trail.received_at).split(' ')[0] + ' ' + formatDate(trail.received_at).split(' ')[1] + ' ' + formatDate(trail.received_at).split(' ')[2] }}</span>
                                        <span class="text-[10px] text-slate-400">{{ formatDate(trail.received_at).split(' ')[3] + ' ' + formatDate(trail.received_at).split(' ')[4] }}</span>
                                    </div>
                                </td>
                                <td class="px-3 py-3 border-r border-slate-200">{{ trail.received_action || '-' }}</td>
                                
                                <!-- Released -->
                                <td class="px-3 py-3 border-r border-slate-200">{{ trail.releaser?.name || '-' }}</td>
                                <td class="px-3 py-3 border-r border-slate-200 whitespace-nowrap">
                                    <div v-if="trail.released_at" class="flex flex-col">
                                        <span>{{ formatDate(trail.released_at).split(' ')[0] + ' ' + formatDate(trail.released_at).split(' ')[1] + ' ' + formatDate(trail.released_at).split(' ')[2] }}</span>
                                        <span class="text-[10px] text-slate-400">{{ formatDate(trail.released_at).split(' ')[3] + ' ' + formatDate(trail.released_at).split(' ')[4] }}</span>
                                    </div>
                                    <span v-else>-</span>
                                </td>
                                <td class="px-3 py-3 border-r border-slate-200">{{ trail.released_action || '-' }}</td>
                                <td class="px-3 py-3 border-r border-slate-200 font-semibold">{{ trail.released_to_office?.name || '-' }}</td>
                                
                                <!-- Duration -->
                                <td class="px-3 py-3 border-r border-slate-200">{{ getOfficeDuration(trail) }}</td>
                                <td class="px-3 py-3 border-r border-slate-200">{{ getTransitDuration(trail, index) }}</td>
                                
                                <!-- Remarks -->
                                <td class="px-3 py-3 border-r border-slate-200 text-left max-w-xs truncate" :title="trail.remarks">
                                    {{ trail.remarks || '-' }}
                                </td>
                                
                                <!-- Action Button (e.g., Update/Edit Trail) -->
                                <td class="px-3 py-3 text-center text-slate-400 hover:text-[#1e4e79] cursor-pointer transition">
                                    <RotateCcw class="w-4 h-4 mx-auto" />
                                </td>
                            </tr>
                            
                            <tr v-if="!trails || trails.length === 0">
                                <td colspan="12" class="px-4 py-12 text-center text-slate-500">No routing history available for this document.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </AppLayout>
</template>