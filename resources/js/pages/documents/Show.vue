<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { Printer, ArrowLeft, RotateCcw } from 'lucide-vue-next';

const props = defineProps<{
    document: any;
    trails: any[]; 
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Documents', href: '/documents' },
    { title: props.document.barcode, href: `/documents/${props.document.id}` },
];

// --- HELPER FUNCTIONS ---

// Smart Date Formatter - Returns an object to prevent template string splitting errors
const formatDateTime = (dateString: string) => {
    if (!dateString) return { date: '-', time: '' };
    
    // 1. Replace space with 'T' (Fixes Safari/iOS "Invalid Date" bug)
    // 2. Strip 'Z' (Prevents browser from aggressively double-adding GMT+8)
    const safeString = dateString.replace(' ', 'T').replace('Z', '');
    
    const date = new Date(safeString);
    
    if (isNaN(date.getTime())) return { date: 'Invalid Date', time: '' };
    
    return {
        date: date.toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric' }),
        time: date.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' })
    };
};

// Simple string return for the top details panel
const formatDateStr = (dateString: string) => {
    const parsed = formatDateTime(dateString);
    if (parsed.date === '-') return '-';
    return `${parsed.date} ${parsed.time}`;
};

// Calculates duration dynamically (seconds, minutes, hours, or days)
const calculateDuration = (startStr: string, endStr: string) => {
    if (!startStr || !endStr) return '-';
    
    // Apply the same safety sanitation to duration calculations!
    const safeStart = startStr.replace(' ', 'T').replace('Z', '');
    const safeEnd = endStr.replace(' ', 'T').replace('Z', '');
    
    const start = new Date(safeStart).getTime();
    const end = new Date(safeEnd).getTime();
    const diffMs = end - start;
    
    if (isNaN(diffMs)) return '-';
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
        <div class="p-6 w-full mx-auto bg-slate-100 min-h-screen flex flex-col gap-6">
            
            <!-- Action Bar -->
            <div class="flex items-center justify-between">
                <Link :href="route('documents.index')" class="flex items-center gap-2 text-base font-bold text-slate-800 hover:text-[#1e4e79] transition">
                    <ArrowLeft class="w-5 h-5" /> Back to List
                </Link>
            </div>

            <!-- Top Section: Document Details (3 Columns) -->
            <div class="bg-white rounded-2xl border-2 border-slate-200 shadow-md p-6">
                <h2 class="text-xl font-extrabold text-slate-900 border-b-2 border-slate-200 pb-4 mb-5">Document Details</h2>
                
                <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                    <!-- Column 1 -->
                    <div class="flex flex-col gap-4 text-sm">
                        <div class="grid grid-cols-3 gap-2"><span class="font-bold text-slate-800">Barcode:</span> <span class="col-span-2 text-[#1e4e79] font-extrabold text-base tracking-wide">{{ document.barcode }}</span></div>
                        <div class="grid grid-cols-3 gap-2"><span class="font-bold text-slate-800">Access Code:</span> <span class="col-span-2 text-slate-900 font-medium">{{ document.access_code }}</span></div>
                        <div class="grid grid-cols-3 gap-2"><span class="font-bold text-slate-800">Date Start:</span> <span class="col-span-2 text-slate-900 font-medium">{{ formatDateStr(document.created_at) }}</span></div>
                        <div class="grid grid-cols-3 gap-2"><span class="font-bold text-slate-800">Date End:</span> <span class="col-span-2 text-slate-900 font-medium">{{ formatDateStr(document.due_date) }}</span></div>
                        <div class="grid grid-cols-3 gap-2"><span class="font-bold text-slate-800">Trans. Type:</span> <span class="col-span-2 text-slate-900 font-medium">{{ document.transaction_type?.name }}</span></div>
                        <div class="grid grid-cols-3 gap-2"><span class="font-bold text-slate-800">Doc. Type:</span> <span class="col-span-2 text-slate-900 font-medium">{{ document.document_type?.document_type }}</span></div>
                        <div class="grid grid-cols-3 gap-2"><span class="font-bold text-slate-800">Delivery:</span> <span class="col-span-2 text-slate-900 font-medium">{{ document.delivery_method?.delivery_method_name }}</span></div>
                        <div class="grid grid-cols-3 gap-2"><span class="font-bold text-slate-800">Source Type:</span> <span class="col-span-2 text-slate-900 font-medium">{{ document.source_type }}</span></div>
                    </div>

                    <!-- Column 2 -->
                    <div class="flex flex-col gap-4 text-sm">
                        <div class="flex flex-col gap-1">
                            <span class="font-bold text-slate-800">Source:</span>
                            <span class="text-slate-900 font-medium">{{ document.source_name }} <span v-if="document.gender">- {{ document.gender }}</span></span>
                            <span class="text-slate-700 font-medium text-sm">{{ document.source_location }}</span>
                        </div>
                        <div class="flex flex-col gap-1 mt-2">
                            <span class="font-bold text-slate-800">Subject Matter:</span>
                            <span class="text-slate-900 font-medium leading-relaxed">{{ document.subject_matter }}</span>
                        </div>
                    </div>

                    <!-- Column 3 -->
                    <div class="flex flex-col gap-4 text-sm">
                        <div class="flex flex-col gap-1">
                            <span class="font-bold text-slate-800">Linked Barcodes:</span>
                            <span class="text-slate-900 font-medium">{{ document.linked_documents || 'None' }}</span>
                        </div>
                        <div class="flex flex-col gap-1 mt-2">
                            <span class="font-bold text-slate-800">Attached File:</span>
                            <a v-if="document.attached_file" :href="`/storage/${document.attached_file}`" target="_blank" class="text-[#1e4e79] font-bold underline hover:text-blue-800 transition">
                                View Scanned Document
                            </a>
                            <span v-else class="text-slate-900 font-medium">None</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Bottom Section: Document Routing Trail Table -->
            <div class="bg-white rounded-2xl border-2 border-slate-200 shadow-md overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-center text-sm text-slate-800 border-collapse min-w-max">
                        <thead class="bg-[#1e4e79] text-white font-bold tracking-wide">
                            <tr>
                                <th colspan="4" class="px-4 py-3 border-r border-[#153a5b]">RECEIVED</th>
                                <th colspan="4" class="px-4 py-3 border-r border-[#153a5b]">RELEASED</th>
                                <th colspan="2" class="px-4 py-3 border-r border-[#153a5b]">DURATION</th>
                                <th rowspan="2" class="px-4 py-3 border-r border-[#153a5b] align-middle">REMARKS</th>
                                <!-- <th rowspan="2" class="px-4 py-3 align-middle w-10"></th> -->
                            </tr>
                            <tr class="bg-[#245b8c] text-blue-50">
                                <th class="px-3 py-2 border-r border-t border-[#153a5b] font-semibold">Office</th>
                                <th class="px-3 py-2 border-r border-t border-[#153a5b] font-semibold">By</th>
                                <th class="px-3 py-2 border-r border-t border-[#153a5b] font-semibold">Date/Time</th>
                                <th class="px-3 py-2 border-r border-t border-[#153a5b] font-semibold">Action</th>
                                
                                <th class="px-3 py-2 border-r border-t border-[#153a5b] font-semibold">By</th>
                                <th class="px-3 py-2 border-r border-t border-[#153a5b] font-semibold">Date/Time</th>
                                <th class="px-3 py-2 border-r border-t border-[#153a5b] font-semibold">Action</th>
                                <th class="px-3 py-2 border-r border-t border-[#153a5b] font-semibold">Released To</th>
                                
                                <th class="px-3 py-2 border-r border-t border-[#153a5b] font-semibold">Office</th>
                                <th class="px-3 py-2 border-r border-t border-[#153a5b] font-semibold">Transit</th>
                            </tr>
                        </thead>
                        
                        <tbody class="divide-y-2 divide-slate-200 text-slate-800">
                            <tr v-for="(trail, index) in trails" :key="trail.id" class="hover:bg-slate-50 transition-colors">
                                <!-- Received -->
                                <td class="px-3 py-4 border-r border-slate-200 font-bold text-slate-900">{{ trail.office?.name || 'N/A' }}</td>
                                <td class="px-3 py-4 border-r border-slate-200 font-medium">{{ trail.receiver?.name || 'System' }}</td>
                                <td class="px-3 py-4 border-r border-slate-200 whitespace-nowrap">
                                    <div class="flex flex-col">
                                        <!-- Used the cleanly mapped object keys here! -->
                                        <span class="font-bold text-slate-900">{{ formatDateTime(trail.received_at).date }}</span>
                                        <span class="text-xs font-semibold text-slate-600 mt-0.5">{{ formatDateTime(trail.received_at).time }}</span>
                                    </div>
                                </td>
                                <td class="px-3 py-4 border-r border-slate-200 font-medium">{{ trail.received_action || '-' }}</td>
                                
                                <!-- Released -->
                                <td class="px-3 py-4 border-r border-slate-200 font-medium">{{ trail.releaser?.name || '-' }}</td>
                                <td class="px-3 py-4 border-r border-slate-200 whitespace-nowrap">
                                    <div v-if="trail.released_at" class="flex flex-col">
                                        <span class="font-bold text-slate-900">{{ formatDateTime(trail.released_at).date }}</span>
                                        <span class="text-xs font-semibold text-slate-600 mt-0.5">{{ formatDateTime(trail.released_at).time }}</span>
                                    </div>
                                    <span v-else class="font-bold text-slate-400">-</span>
                                </td>
                                <td class="px-3 py-4 border-r border-slate-200 font-medium">{{ trail.released_action || '-' }}</td>
                                <td class="px-3 py-4 border-r border-slate-200 font-bold text-slate-900">{{ trail.released_to_office?.name || '-' }}</td>
                                
                                <!-- Duration -->
                                <td class="px-3 py-4 border-r border-slate-200 font-bold text-[#1e4e79]">{{ getOfficeDuration(trail) }}</td>
                                <td class="px-3 py-4 border-r border-slate-200 font-medium text-slate-600">{{ getTransitDuration(trail, index) }}</td>
                                
                                <!-- Remarks -->
                                <td class="px-3 py-4 border-r border-slate-200 text-left font-medium max-w-xs" :title="trail.remarks">
                                    {{ trail.remarks || '-' }}
                                </td>
                                
                                <!-- Action Button
                                <td class="px-3 py-4 text-center text-slate-600 hover:text-[#1e4e79] cursor-pointer transition">
                                    <RotateCcw class="w-5 h-5 mx-auto" stroke-width="2.5" />
                                </td> -->
                            </tr>
                            
                            <tr v-if="!trails || trails.length === 0">
                                <td colspan="12" class="px-4 py-12 text-center text-base font-bold text-slate-500">No routing history available for this document.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </AppLayout>
</template>