<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { Search, ScanBarcode, CheckCircle2, FastForward, X, AlertCircle, Clock, FileSignature, ArrowLeftRight } from 'lucide-vue-next';
import { ref, watch, computed } from 'vue';
import { Notyf } from 'notyf';
import 'notyf/notyf.min.css';

const props = defineProps<{
    documents: any;
    departments: Array<{ id: number; name: string }>;
    filters?: { barcode?: string };
    flash?: { success?: string; error?: string };
}>();

const searchTerm = ref(props.filters?.barcode || '');
let searchTimeout: ReturnType<typeof setTimeout>;

const performSearch = () => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        router.get(route('transactions.inbox'), { barcode: searchTerm.value }, {
            preserveState: true,
            preserveScroll: true,
        });
    }, 300);
};

watch(searchTerm, performSearch);

const clearSearch = () => {
    searchTerm.value = '';
    router.get(route('transactions.inbox'));
};

const notyf = new Notyf({ duration: 3000, position: { x: 'right', y: 'top' } });
watch(() => props.flash, (flash) => {
    if (flash?.success) notyf.success(flash.success);
    if (flash?.error) notyf.error(flash.error);
}, { immediate: true });

const breadcrumbs = [
    { title: 'My Office Inbox', href: '/transactions/inbox' },
];

// Auth & Roles
const page = usePage();
const authUser = computed(() => page.props.auth?.user as any);
const isStaff = computed(() => authUser.value?.role === 'staff');
const isRecords = computed(() => authUser.value?.department_id === 1); // Adjust ID to match your Records dept

// Processing Modal State
const showProcessModal = ref(false);
const selectedDocument = ref<any>(null);
const aiSuggestionsMap = ref<Record<number, { reason: string, priority: string }>>({});

const form = useForm({
    action: 'route',
    routed_to: '',
    remarks: '',
    attach_signature: false,
});

function openProcessModal(doc: any) {
    selectedDocument.value = doc;
    form.reset();
    form.clearErrors();
    
    // Parse AI Suggestions for the Dropdown
    let suggestions = doc.ai_routing_suggestions || [];
    if (typeof suggestions === 'string') {
        try { suggestions = JSON.parse(suggestions); } catch (e) { suggestions = []; }
    }

    const suggestionsMap: Record<number, { reason: string, priority: string }> = {};
    suggestions.forEach((s: any) => {
        suggestionsMap[Number(s.department_id)] = { reason: s.reason, priority: s.priority };
    });
    aiSuggestionsMap.value = suggestionsMap;

    // Auto-select Primary AI target if routing
    const primary = suggestions.find((s: any) => s.priority === 'Primary');
    if (primary) {
        form.routed_to = Number(primary.department_id);
    }

    showProcessModal.value = true;
}

function submitProcess() {
    form.post(route('transactions.process', selectedDocument.value.id), {
        onSuccess: () => {
            showProcessModal.value = false;
            form.reset();
        }
    });
}
</script>

<template>
    <Head title="Office Inbox" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-6 w-full mx-auto bg-slate-100 min-h-screen">
            
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold text-slate-800 tracking-tight">Office Inbox</h1>
                    <p class="text-sm text-slate-500 mt-1">Process, route, or complete documents currently assigned to your department.</p>
                </div>
                
                <div class="flex flex-col sm:flex-row items-center gap-3">
                    <div class="relative w-full sm:w-80">
                        <ScanBarcode class="absolute top-1/2 left-3 h-5 w-5 -translate-y-1/2 text-emerald-500" />
                        <input
                            v-model="searchTerm"
                            type="text"
                            placeholder="Scan or type barcode..."
                            class="block w-full rounded-xl border border-slate-300 bg-white py-2.5 pl-10 pr-10 text-sm font-mono focus:border-emerald-500 focus:ring-2 focus:ring-emerald-200 shadow-sm"
                            autofocus
                        />
                        <button v-if="searchTerm" @click="clearSearch" class="absolute top-1/2 right-3 -translate-y-1/2 text-slate-400 hover:text-slate-600">×</button>
                    </div>
                </div>
            </div>

            <!-- Table Card -->
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm text-slate-700">
                        <thead class="bg-slate-100 text-xs text-slate-600 uppercase tracking-wider border-b border-slate-200">
                            <tr>
                                <th class="px-4 py-2.5 font-bold">Barcode</th>
                                <th class="px-4 py-2.5 font-bold">Subject Matter</th>
                                <th class="px-4 py-2.5 font-bold">Source</th>
                                <th class="px-4 py-2.5 font-bold">Status</th>
                                <th class="px-4 py-2.5 text-center font-bold">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200">
                            <tr v-for="doc in documents.data" :key="doc.id" class="hover:bg-slate-50 transition-colors">
                                <td class="px-4 py-2 font-mono font-semibold text-emerald-600 whitespace-nowrap">{{ doc.barcode }}</td>
                                <td class="px-4 py-2">
                                    <p class="text-slate-800 font-medium line-clamp-2" :title="doc.subject_matter">{{ doc.subject_matter }}</p>
                                    <p class="text-xs text-slate-500 mt-0.5">{{ doc.document_type?.document_type || 'Unknown Type' }}</p>
                                </td>
                                <td class="px-4 py-2 text-slate-600 whitespace-nowrap">{{ doc.source_name }}</td>
                                
                                <td class="px-4 py-2 whitespace-nowrap">
                                    <div class="flex flex-col gap-1 items-start">
                                        <span v-if="doc.status === 'Completed'" class="inline-flex items-center px-2 py-0.5 rounded text-xs font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                            Completed
                                        </span>
                                        <span v-else class="inline-flex items-center px-2 py-0.5 rounded text-xs font-bold bg-blue-50 text-blue-700 border border-blue-200">
                                            {{ doc.status || 'Pending' }}
                                        </span>

                                        <span v-if="doc.is_delayed" class="inline-flex items-center px-2 py-0.5 rounded text-xs font-bold bg-rose-50 text-rose-700 border border-rose-200 animate-pulse" title="Missed Global SLA">
                                            <AlertCircle class="w-3 h-3 mr-1" /> Delayed
                                        </span>
                                        
                                        <span v-if="doc.is_delinquent" class="inline-flex items-center px-2 py-0.5 rounded text-xs font-bold bg-amber-50 text-amber-700 border border-amber-200" title="Exceeded Office Performance Standard">
                                            <Clock class="w-3 h-3 mr-1" /> Delinquent
                                        </span>
                                    </div>
                                </td>

                                <td class="px-4 py-2 whitespace-nowrap text-center">
                                    <button 
                                        @click="openProcessModal(doc)" 
                                        class="inline-flex items-center gap-2 rounded-lg bg-indigo-50 px-3 py-1.5 text-indigo-700 font-medium transition hover:bg-indigo-100 border border-indigo-100 shadow-sm"
                                    >
                                        Process
                                        <FastForward class="h-4 w-4" />
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="documents.data.length === 0">
                                <td colspan="5" class="py-16 text-center text-slate-500">
                                    <div class="flex flex-col items-center">
                                        <div class="w-12 h-12 bg-slate-50 rounded-full flex items-center justify-center mb-3 border border-slate-200">
                                            <Search class="h-6 w-6 text-slate-400" />
                                        </div>
                                        <h3 class="text-base font-semibold text-slate-900">Inbox is Clear</h3>
                                        <p class="mt-1 text-sm text-slate-500 max-w-sm px-4">
                                            {{ searchTerm ? 'No matching document found in your office.' : 'There are currently no pending documents routed to your department.' }}
                                        </p>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Process Transaction Modal -->
            <Transition name="fade">
                <div v-if="showProcessModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 backdrop-blur-sm px-4">
                    <div class="relative w-full max-w-2xl rounded-2xl bg-white shadow-xl border border-slate-200 flex flex-col max-h-[90vh]">
                        
                        <button @click="showProcessModal = false" class="absolute top-4 right-4 rounded-full p-1.5 text-slate-400 hover:bg-slate-100 transition z-10">
                            <X class="h-5 w-5" />
                        </button>

                        <div class="px-6 py-5 border-b border-slate-100">
                            <h2 class="text-lg font-bold text-slate-800 tracking-tight">Process Document</h2>
                        </div>
                        
                        <div class="overflow-y-auto flex-1 p-6">
                            
                            <!-- Document Details Summary -->
                            <div class="mb-6 p-4 bg-slate-50 border border-slate-200 rounded-xl">
                                <h3 class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-4">Document Details</h3>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-xs font-medium text-slate-500 mb-1">Barcode</label>
                                        <input type="text" disabled :value="selectedDocument?.barcode" class="w-full rounded-lg border border-slate-200 bg-slate-100 px-3 py-2 text-sm font-mono text-slate-600 cursor-not-allowed opacity-75" />
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-slate-500 mb-1">Source Name</label>
                                        <input type="text" disabled :value="selectedDocument?.source_name" class="w-full rounded-lg border border-slate-200 bg-slate-100 px-3 py-2 text-sm text-slate-600 cursor-not-allowed opacity-75" />
                                    </div>
                                    <div class="sm:col-span-2">
                                        <label class="block text-xs font-medium text-slate-500 mb-1">Subject Matter</label>
                                        <textarea disabled rows="2" class="w-full rounded-lg border border-slate-200 bg-slate-100 px-3 py-2 text-sm text-slate-600 cursor-not-allowed opacity-75 resize-none" :value="selectedDocument?.subject_matter"></textarea>
                                    </div>
                                </div>
                            </div>

                            <form @submit.prevent="submitProcess" id="processForm" class="space-y-6">
                                
                                <!-- Required Remarks Moved to Top -->
                                <div>
                                    <label class="mb-1.5 block text-sm font-medium text-slate-700" for="remarks">Official Remarks <span class="text-red-500">*</span></label>
                                    <textarea 
                                        v-model="form.remarks" 
                                        id="remarks" 
                                        rows="3" 
                                        class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 shadow-sm" 
                                        placeholder="Detail the action taken or instructions for the next office..."
                                        required
                                    ></textarea>
                                </div>

                                <!-- Action Selector -->
                                <div>
                                    <label class="block text-sm font-bold text-slate-800 mb-3">What would you like to do with this document?</label>
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                        
                                        <label class="relative flex cursor-pointer rounded-xl border p-4 shadow-sm focus-within:ring-2 focus-within:ring-indigo-500 transition-colors" :class="form.action === 'route' ? 'border-indigo-500 bg-indigo-50/50' : 'border-slate-200 hover:border-indigo-300'">
                                            <input type="radio" v-model="form.action" value="route" class="sr-only" />
                                            <div class="flex items-center gap-3">
                                                <div class="flex h-10 w-10 items-center justify-center rounded-lg" :class="form.action === 'route' ? 'bg-indigo-600 text-white' : 'bg-slate-100 text-slate-500'">
                                                    <FastForward class="h-5 w-5" />
                                                </div>
                                                <div>
                                                    <p class="text-sm font-semibold text-slate-900">Route</p>
                                                    <p class="text-xs text-slate-500">Send to another office</p>
                                                </div>
                                            </div>
                                        </label>

                                        <label class="relative flex cursor-pointer rounded-xl border p-4 shadow-sm focus-within:ring-2 focus-within:ring-emerald-500 transition-colors" :class="form.action === 'end' ? 'border-emerald-500 bg-emerald-50/50' : 'border-slate-200 hover:border-emerald-300'">
                                            <input type="radio" v-model="form.action" value="end" class="sr-only" />
                                            <div class="flex items-center gap-3">
                                                <div class="flex h-10 w-10 items-center justify-center rounded-lg" :class="form.action === 'end' ? 'bg-emerald-600 text-white' : 'bg-slate-100 text-slate-500'">
                                                    <CheckCircle2 class="h-5 w-5" />
                                                </div>
                                                <div>
                                                    <p class="text-sm font-semibold text-slate-900">End Transaction</p>
                                                    <p class="text-xs text-slate-500">Archive and close request</p>
                                                </div>
                                            </div>
                                        </label>
                                    </div>
                                </div>

                                <!-- Dynamic Field: Target Department (with AI highlighting) -->
                                <div v-if="form.action === 'route'">
                                    <label class="mb-1.5 block text-sm font-medium text-slate-700" for="routed_to">Next Department / Office <span class="text-red-500">*</span></label>
                                    <select v-model="form.routed_to" id="routed_to" class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 shadow-sm" :required="form.action === 'route'">
                                        <option value="" disabled>Select target office...</option>
                                        <option v-for="dept in departments" :key="dept.id" :value="dept.id">
                                            {{ dept.name }} {{ aiSuggestionsMap[dept.id]?.priority === 'Primary' ? '✨ (AI Recommended)' : '' }}
                                        </option>
                                    </select>
                                    <p v-if="aiSuggestionsMap[Number(form.routed_to)]" class="mt-1.5 text-xs font-medium text-indigo-600 flex items-start gap-1">
                                        <Sparkles class="w-3.5 h-3.5 shrink-0 relative top-0.5" /> 
                                        {{ aiSuggestionsMap[Number(form.routed_to)].reason }}
                                    </p>
                                </div>

                                <!-- Workflow Modifiers -->
                                <div class="space-y-3 pt-2">
                                    <!-- Signature Checkbox -->
                                    <label class="flex items-start gap-3 p-3 rounded-xl border transition cursor-pointer" :class="form.attach_signature ? 'bg-amber-50/50 border-amber-200' : 'bg-white border-slate-200 hover:bg-slate-50'">
                                        <div class="flex items-center h-5 mt-0.5">
                                            <input v-model="form.attach_signature" type="checkbox" class="w-4 h-4 text-amber-600 rounded border-slate-300 focus:ring-amber-500" />
                                        </div>
                                        <div class="flex flex-col">
                                            <span class="text-sm font-bold text-slate-800 flex items-center gap-1.5">
                                                <FileSignature class="w-4 h-4 text-amber-600" /> 
                                                {{ isStaff ? 'Attach Dept. Head Signature (Proxy)' : 'Attach Digital Signature' }}
                                            </span>
                                            <span class="text-xs text-slate-500 mt-0.5">
                                                {{ isStaff ? "Applies your Department Head's authorized visual signature stamp." : "Applies your uploaded visual signature stamp." }}
                                            </span>
                                        </div>
                                    </label>
                                </div>

                            </form>
                        </div>
                        
                        <div class="px-6 py-4 border-t border-slate-100 flex justify-end gap-3 bg-slate-50 rounded-b-2xl">
                            <button type="button" @click="showProcessModal = false" class="rounded-xl bg-white border border-slate-200 px-5 py-2.5 text-sm font-medium text-slate-700 transition hover:bg-slate-100">
                                Cancel
                            </button>
                            <button 
                                type="submit" 
                                form="processForm"
                                class="rounded-xl px-5 py-2.5 text-sm font-medium text-white shadow-sm transition disabled:opacity-50"
                                :class="form.action === 'end' ? 'bg-emerald-600 hover:bg-emerald-700' : 'bg-indigo-600 hover:bg-indigo-700'"
                                :disabled="form.processing"
                            >
                                {{ form.processing ? 'Saving...' : form.action === 'end' ? 'Confirm Archival' : 'Confirm Route' }}
                            </button>
                        </div>

                    </div>
                </div>
            </Transition>

        </div>
    </AppLayout>
</template>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity 0.2s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>