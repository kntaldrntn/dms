<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, FileSignature, ArrowLeftRight } from 'lucide-vue-next';

const props = defineProps<{
    document: any;
    trails: any[]; 
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Documents', href: '/documents' },
    { title: props.document.barcode, href: `/documents/${props.document.id}` },
];

// --- HELPER FUNCTIONS ---

const formatDateTime = (dateString: string) => {
    if (!dateString) return { date: '-', time: '' };
    const safeString = dateString.replace(' ', 'T').replace('Z', '');
    const date = new Date(safeString);
    if (isNaN(date.getTime())) return { date: 'Invalid Date', time: '' };
    
    return {
        date: date.toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric' }),
        time: date.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' })
    };
};

const formatDateStr = (dateString: string) => {
    const parsed = formatDateTime(dateString);
    if (parsed.date === '-') return '-';
    return `${parsed.date} ${parsed.time}`;
};

const calculateDuration = (startStr: string, endStr: string) => {
    if (!startStr || !endStr) return '-';
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

const getOfficeDuration = (trail: any) => {
    return calculateDuration(trail.received_at, trail.released_at);
};

const getTransitDuration = (trail: any, index: number) => {
    const nextTrail = props.trails[index + 1];
    if (!trail.released_at || !nextTrail || !nextTrail.received_at) return '-';
    return calculateDuration(trail.released_at, nextTrail.received_at);
};

const formatRequiredAction = (action: string) => action.replaceAll('_', ' ').replace(/\b\w/g, letter => letter.toUpperCase());
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

            <!-- Top Section: Document Details -->
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
                                        <span class="font-bold text-slate-900">{{ formatDateTime(trail.received_at).date }}</span>
                                        <span class="text-xs font-semibold text-slate-600 mt-0.5">{{ formatDateTime(trail.received_at).time }}</span>
                                    </div>
                                </td>
                                <td class="px-3 py-4 border-r border-slate-200 font-medium">{{ trail.received_action || '-' }}</td>
                                
                                <!-- Released -->
                                <td class="px-3 py-4 border-r border-slate-200 font-medium">
                                    <div class="flex flex-col items-center">
                                        <span>{{ trail.releaser?.name || '-' }}</span>
                                    </div>
                                </td>
                                <td class="px-3 py-4 border-r border-slate-200 whitespace-nowrap">
                                    <div v-if="trail.released_at" class="flex flex-col">
                                        <span class="font-bold text-slate-900">{{ formatDateTime(trail.released_at).date }}</span>
                                        <span class="text-xs font-semibold text-slate-600 mt-0.5">{{ formatDateTime(trail.released_at).time }}</span>
                                    </div>
                                    <span v-else class="font-bold text-slate-400">-</span>
                                </td>
                                
                                <!-- NEW: Action Cell with Badges -->
                                <td class="px-3 py-4 border-r border-slate-200 font-medium text-left">
                                    <div class="flex flex-col items-start gap-1.5">
                                        <span>{{ trail.released_action || '-' }}</span>
                                        
                                        <!-- Digital Signature Badge -->
                                        <div v-if="trail.is_digitally_signed" class="inline-flex items-center gap-1.5 text-[10px] uppercase tracking-wider font-bold bg-amber-100 text-amber-700 px-2 py-1 rounded shadow-sm border border-amber-200" :title="`Signed by ${trail.signer?.name}`">
                                            <FileSignature class="w-3 h-3" /> Signed
                                        </div>
                                        
                                        <!-- Return Route Badge -->
                                        <div v-if="trail.trail_type === 'return'" class="inline-flex items-center gap-1.5 text-[10px] uppercase tracking-wider font-bold bg-rose-100 text-rose-700 px-2 py-1 rounded shadow-sm border border-rose-200">
                                            <ArrowLeftRight class="w-3 h-3" /> Returned
                                        </div>
                                    </div>
                                </td>
                                
                                <td class="px-3 py-4 border-r border-slate-200 font-bold text-slate-900">{{ trail.released_to_office?.name || '-' }}</td>
                                
                                <!-- Duration -->
                                <td class="px-3 py-4 border-r border-slate-200 font-bold text-[#1e4e79]">{{ getOfficeDuration(trail) }}</td>
                                <td class="px-3 py-4 border-r border-slate-200 font-medium text-slate-600">{{ getTransitDuration(trail, index) }}</td>
                                
                                <!-- Remarks -->
                                <td class="px-3 py-4 border-r border-slate-200 text-left font-medium max-w-xs" :title="trail.remarks">
                                    <div>{{ trail.remarks || '-' }}</div>
                                    <div v-if="trail.required_actions?.length" class="mt-1 text-[10px] text-indigo-700">
                                        <span class="font-bold">Required:</span> {{ trail.required_actions.map(formatRequiredAction).join(', ') }}
                                        <span v-if="trail.other_action"> - {{ trail.other_action }}</span>
                                    </div>
                                    <div v-if="trail.concerned_personnel" class="mt-1 text-[10px] text-slate-600">
                                        <span class="font-bold">Personnel:</span> {{ trail.concerned_personnel }}
                                    </div>
                                </td>
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
