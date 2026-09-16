<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import DeleteConfirmationModal from '@/components/DeleteConfirmationModal.vue';
import RoutingModal from '@/components/RoutingModal.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Search, Pencil, Trash2, FastForward, AlertCircle, Clock, Loader2, Sparkles, Link as LinkIcon, FileUp, X } from 'lucide-vue-next';
import { ref, watch } from 'vue';
import { Notyf } from 'notyf';
import 'notyf/notyf.min.css';
import axios from 'axios';
import * as pdfjsLib from 'pdfjs-dist';
import Tesseract from 'tesseract.js';

pdfjsLib.GlobalWorkerOptions.workerSrc = `//cdnjs.cloudflare.com/ajax/libs/pdf.js/${pdfjsLib.version}/pdf.worker.min.mjs`;

const props = defineProps<{
    documents: any;
    departments: Array<{ id: number; name: string }>;
    transactionTypes: Array<{ id: number; name: string }>;
    documentTypes: Array<{ id: number; document_type: string }>;
    classifications: Array<{ id: number; name: string }>;
    deliveryMethods: Array<{ id: number; delivery_method_name: string }>;
    generatedAccessCode: string;
    filters?: { search?: string };
    flash?: { success?: string; error?: string };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'System Documents', href: '/documents' },
];

const searchTerm = ref(props.filters?.search || '');
let searchTimeout: ReturnType<typeof setTimeout>;

const performSearch = () => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        router.get(route('documents.index'), { search: searchTerm.value }, { preserveState: true, preserveScroll: true });
    }, 300);
};

watch(searchTerm, performSearch);

const clearSearch = () => {
    searchTerm.value = '';
    router.get(route('documents.index'));
};

const goToPage = (url: string) => {
    if (!url) return;
    router.get(url, { search: searchTerm.value }, { preserveState: true, preserveScroll: true });
};

const notyf = new Notyf({ duration: 3000, position: { x: 'right', y: 'top' } });
watch(() => props.flash, (flash) => {
    if (flash?.success) notyf.success(flash.success);
    if (flash?.error) notyf.error(flash.error);
}, { immediate: true });

const showDeleteModal = ref(false);
const itemToDeleteUrl = ref('');
const itemToDeleteName = ref('');
function openDeleteModal(doc: any) {
    itemToDeleteUrl.value = route('documents.destroy', doc.id);
    itemToDeleteName.value = `Document ${doc.barcode}`;
    showDeleteModal.value = true;
}

const showRoutingModal = ref(false);
const documentToRoute = ref<any>(null);
function openRoutingModal(doc: any) {
    documentToRoute.value = doc;
    showRoutingModal.value = true;
}

// --- FORM STATE ---
const form = useForm({
    barcode: '',
    transaction_type_id: '',
    document_type_id: '',
    source_type: 'External', 
    source_location: 'N/A',  
    classification_id: '',
    delivery_method_id: '',
    source_name: '',
    gender: '',
    contact_no: '',
    email: '',
    subject_matter: '',
    
    linked_documents: '', // String field for barcodes
    attached_file: null as File | null, // The physical file

    department_id: '', 
    access_code: props.generatedAccessCode,
});

// --- SMART BARCODE SEARCH ---
const showBarcodeDropdown = ref(false);
const barcodeResults = ref<Array<{ barcode: string, subject_matter: string }>>([]);

const handleLinkedDocumentInput = async (e: any) => {
    const val = e.target.value;
    const words = val.trim().split(' ');
    const lastWord = words[words.length - 1]; 

    if (lastWord.length >= 3) {
        try {
            const res = await axios.get(route('documents.searchBarcodes'), { params: { q: lastWord } });
            barcodeResults.value = res.data;
            showBarcodeDropdown.value = barcodeResults.value.length > 0;
        } catch (err) {
            showBarcodeDropdown.value = false;
        }
    } else {
        showBarcodeDropdown.value = false;
    }
};

const selectBarcode = (barcode: string) => {
    const words = form.linked_documents.trim().split(' ');
    words.pop(); 
    words.push(barcode); 
    form.linked_documents = words.join(' ') + ' '; 
    showBarcodeDropdown.value = false;
    document.getElementById('linked_documents_input')?.focus();
};

// --- AI LOGIC ---
const isAnalyzing = ref(false);
const analysisError = ref('');
const routingSuggestions = ref<Array<{ department_id: number, reason: string }>>([]);
const aiMetrics = ref<{ tokens: number, confidence: number } | null>(null);
const executiveSuggestion = ref<{ route: string, reason: string } | null>(null);

watch(() => form.source_type, (newType) => {
    if (newType === 'External') form.source_location = 'N/A';
    else if (newType === 'Internal') form.source_location = ''; 
});

const handleFileUpload = async (event: Event) => {
    const target = event.target as HTMLInputElement;
    const file = target.files?.[0];
    if (!file) return;

    form.attached_file = file; 
    isAnalyzing.value = true;
    routingSuggestions.value = []; 
    analysisError.value = '';
    aiMetrics.value = null;

    const payload = new FormData();
    payload.append('file', file);

    try {
        let extractedText = "";
        let aiThumbnailBlob = null;

        if (file.type === 'application/pdf') {
            const arrayBuffer = await file.arrayBuffer();
            const pdf = await pdfjsLib.getDocument({ data: arrayBuffer }).promise;
            const page = await pdf.getPage(1);
            const viewport = page.getViewport({ scale: 2.0 }); 
            const tempCanvas = document.createElement('canvas');
            tempCanvas.width = viewport.width;
            tempCanvas.height = viewport.height;
            await page.render({ canvasContext: tempCanvas.getContext('2d'), viewport }).promise;
            
            const worker = await Tesseract.createWorker('eng');
            const ret = await worker.recognize(tempCanvas);
            extractedText = ret.data.text;
            await worker.terminate();

            const visionCanvas = document.createElement('canvas');
            const scaleFactor = 800 / viewport.width;
            visionCanvas.width = 800;
            visionCanvas.height = viewport.height * scaleFactor;
            visionCanvas.getContext('2d')?.drawImage(tempCanvas, 0, 0, visionCanvas.width, visionCanvas.height);
            aiThumbnailBlob = await new Promise<Blob>((resolve) => visionCanvas.toBlob(resolve as BlobCallback, 'image/jpeg', 0.6));
        } else {
            const worker = await Tesseract.createWorker('eng');
            const ret = await worker.recognize(file);
            extractedText = ret.data.text;
            await worker.terminate();
            aiThumbnailBlob = file; 
        }

        const compressedFile = new File([aiThumbnailBlob], 'thumb.jpg', { type: 'image/jpeg' });
        payload.append('ai_thumbnail', compressedFile);
        payload.append('extracted_text', extractedText);

        const response = await axios.post('/documents/analyze', payload, { headers: { 'Content-Type': 'multipart/form-data' } });
        const data = response.data;
        
        if (data.barcode) form.barcode = data.barcode;
        if (data.source_type) form.source_type = data.source_type;
        if (data.source_location) {
            setTimeout(() => { form.source_location = data.source_location; }, 50);
        }
        if (data.delivery_method_id) form.delivery_method_id = data.delivery_method_id;
        if (data.source_name) form.source_name = data.source_name;
        if (data.gender) form.gender = data.gender;
        if (data.contact_no) form.contact_no = data.contact_no;
        if (data.email) form.email = data.email;
        if (data.subject_matter) form.subject_matter = data.subject_matter;
        if (data.document_type_id) form.document_type_id = data.document_type_id;
        if (data.transaction_type_id) form.transaction_type_id = data.transaction_type_id;
        if (data.classification_id) form.classification_id = data.classification_id;
        
        // 1. Handle Executive Approval (Mayor=31 / Admin=3)
        if (data.department_id === 3 || data.department_id === 31) {
            form.department_id = data.department_id;
            executiveSuggestion.value = {
                route: data.department_id === 31 ? 'Office of the City Mayor' : 'Office of the City Administrator',
                reason: data.approval_reason || 'Identified as requiring executive approval.'
            };
        } else {
            // Nullable for other departments
            form.department_id = ''; 
            executiveSuggestion.value = null;
        }
        
        // 2. Silently queue the executing departments
        if (data.routing_suggestions && Array.isArray(data.routing_suggestions)) {
            routingSuggestions.value = data.routing_suggestions;
            if (typeof (form as any).ai_routing_suggestions !== 'undefined') {
                (form as any).ai_routing_suggestions = data.routing_suggestions; 
            }
        }
        
        if (data.meta) {
            aiMetrics.value = {
                tokens: data.meta.total_tokens,
                confidence: data.confidence_score || 0
            };
        }

        notyf.success('AI successfully extracted data.');
    } catch (err: any) {
        analysisError.value = err.response?.data?.error || 'Document uploaded, but automatic analysis failed. You can fill the fields manually.';
        notyf.error('AI Analysis failed. Please input manually.');
    } finally {
        isAnalyzing.value = false;
    }
};

function submitDocument() {
    form.post(route('documents.store'), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            form.access_code = Math.random().toString(36).substring(2, 8).toUpperCase();
            routingSuggestions.value = [];
            analysisError.value = '';
        },
    });
}
</script>

<template>
    <Head title="System Documents" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-6 w-full mx-auto bg-slate-100 min-h-screen">
            
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold text-slate-800 tracking-tight">System Documents</h1>
                    <p class="text-sm text-slate-500 mt-1">Register new incoming documents and track routing status.</p>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
                
                <!-- LEFT PANEL: NEW DOCUMENT FORM -->
                <div class="lg:col-span-5 bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                    <div class="p-4 border-b border-slate-200 bg-slate-50 flex justify-between items-center">
                        <h2 class="text-base font-bold text-slate-800 tracking-tight">New Document</h2>
                        
                        <div class="relative flex items-center">
                            <input type="file" id="ai-upload" accept=".pdf,.jpg,.png" class="hidden" :disabled="isAnalyzing" @change="handleFileUpload" />
                            <label for="ai-upload" class="flex items-center gap-1.5 px-3 py-1.5 bg-emerald-50 text-emerald-700 border border-emerald-200 rounded-lg text-xs font-semibold cursor-pointer hover:bg-emerald-100 transition" :class="{'opacity-50 pointer-events-none': isAnalyzing}">
                                <Loader2 v-if="isAnalyzing" class="w-3.5 h-3.5 animate-spin" />
                                <Sparkles v-else class="w-3.5 h-3.5" />
                                {{ isAnalyzing ? 'Scanning...' : 'AI Autofill' }}
                            </label>
                        </div>
                    </div>

                    <form @submit.prevent="submitDocument" class="p-5 flex flex-col gap-4">
                        
                        <!-- Row 1 -->
                        <div class="grid grid-cols-3 gap-3">
                            <div>
                                <label class="block text-xs font-medium text-slate-700 mb-1">Barcode</label>
                                <input v-model="form.barcode" type="text" maxlength="10" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 shadow-sm" required />
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-slate-700 mb-1">Transaction Type</label>
                                <select v-model="form.transaction_type_id" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 shadow-sm" required>
                                    <option value="" disabled>Select</option>
                                    <option v-for="type in transactionTypes" :key="type.id" :value="type.id">{{ type.name }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-slate-700 mb-1">Document Type</label>
                                <select v-model="form.document_type_id" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 shadow-sm" required>
                                    <option value="" disabled>Select</option>
                                    <option v-for="type in documentTypes" :key="type.id" :value="type.id">{{ type.document_type }}</option>
                                </select>
                            </div>
                        </div>

                        <!-- Row 2 -->
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-medium text-slate-700 mb-1">Source Type</label>
                                <select v-model="form.source_type" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 shadow-sm">
                                    <option value="External">External</option>
                                    <option value="Internal">Internal</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-slate-700 mb-1">Source Location</label>
                                <input v-if="form.source_type === 'External'" v-model="form.source_location" type="text" disabled class="w-full rounded-xl border border-slate-200 px-3 py-2 bg-slate-100 text-sm text-slate-500 cursor-not-allowed shadow-sm" />
                                <select v-else v-model="form.source_location" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 shadow-sm" required>
                                    <option value="" disabled>Select Department</option>
                                    <option v-for="dept in departments" :key="dept.id" :value="dept.id">{{ dept.name }}</option>
                                </select>
                            </div>
                        </div>

                        <!-- Row 3 -->
                        <div>
                            <label class="block text-xs font-medium text-slate-700 mb-1">Document Classification</label>
                            <select v-model="form.classification_id" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 shadow-sm">
                                <option value="" disabled>Select Classification</option>
                                <option v-for="classif in classifications" :key="classif.id" :value="classif.id">{{ classif.name }}</option>
                            </select>
                        </div>

                        <!-- Row 4 -->
                        <div class="grid grid-cols-3 gap-3">
                            <div>
                                <label class="block text-xs font-medium text-slate-700 mb-1">Delivery Method</label>
                                <select v-model="form.delivery_method_id" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 shadow-sm">
                                    <option value="" disabled>Select</option>
                                    <option v-for="method in deliveryMethods" :key="method.id" :value="method.id">{{ method.delivery_method_name }}</option>
                                </select>
                            </div>
                            <div class="col-span-2">
                                <label class="block text-xs font-medium text-slate-700 mb-1">Source Name</label>
                                <input v-model="form.source_name" type="text" placeholder="Name/Company/Agency" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 shadow-sm" required />
                            </div>
                        </div>

                        <!-- Row 5 -->
                        <div class="grid grid-cols-3 gap-3">
                            <div>
                                <label class="block text-xs font-medium text-slate-700 mb-1">Gender</label>
                                <select v-model="form.gender" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 shadow-sm">
                                    <option value="" disabled>Select</option>
                                    <option value="Male">Male</option>
                                    <option value="Female">Female</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-slate-700 mb-1">Contact No.</label>
                                <input v-model="form.contact_no" type="text" placeholder="Optional" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 shadow-sm" />
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-slate-700 mb-1">Email</label>
                                <input v-model="form.email" type="email" placeholder="Optional" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 shadow-sm" />
                            </div>
                        </div>

                        <!-- Row 6 -->
                        <div>
                            <label class="block text-xs font-medium text-slate-700 mb-1">Subject Matter</label>
                            <textarea v-model="form.subject_matter" rows="2" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 shadow-sm" required></textarea>
                        </div>

                        <!-- Row 7: Smart Barcode Search -->
                        <div class="relative">
                            <label class="block text-xs font-medium text-slate-700 mb-1">Linked Barcode/s (Optional)</label>
                            <div class="relative">
                                <LinkIcon class="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-slate-400" />
                                <input 
                                    id="linked_documents_input"
                                    v-model="form.linked_documents" 
                                    @input="handleLinkedDocumentInput"
                                    type="text" 
                                    autocomplete="off"
                                    placeholder="Type barcode to search, separate multiple with space" 
                                    class="w-full rounded-xl border border-slate-300 py-2 pl-9 pr-3 text-sm focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 shadow-sm" 
                                />
                            </div>
                            <div v-if="showBarcodeDropdown" class="absolute z-10 mt-1 w-full bg-white rounded-xl shadow-lg border border-slate-200 overflow-hidden">
                                <ul class="max-h-48 overflow-y-auto py-1">
                                    <li v-for="res in barcodeResults" :key="res.barcode" @click="selectBarcode(res.barcode)" class="px-4 py-2 hover:bg-slate-50 cursor-pointer border-b border-slate-100 last:border-0 transition-colors">
                                        <div class="font-mono text-xs font-bold text-emerald-600">{{ res.barcode }}</div>
                                        <div class="text-[10px] text-slate-500 truncate">{{ res.subject_matter }}</div>
                                    </li>
                                </ul>
                            </div>
                        </div>

                        <!-- Row 8: Attachment Status / Manual Upload -->
                        <div>
                            <label class="block text-xs font-medium text-slate-700 mb-1">Attached Document (Optional)</label>
                            
                            <!-- State 1: File is attached (via AI or Manual) -->
                            <div v-if="form.attached_file" class="flex items-center justify-between p-2 bg-emerald-50 border border-emerald-200 rounded-xl shadow-sm">
                                <div class="flex items-center gap-2 overflow-hidden pl-1">
                                    <FileUp class="w-4 h-4 text-emerald-600 shrink-0" />
                                    <span class="text-xs font-medium text-emerald-800 truncate">
                                        {{ form.attached_file.name }}
                                    </span>
                                </div>
                                <button type="button" @click="form.attached_file = null" class="text-emerald-600 hover:text-emerald-800 p-1 bg-emerald-100/50 hover:bg-emerald-200 rounded-lg transition" title="Remove Attachment">
                                    <X class="w-3.5 h-3.5" />
                                </button>
                            </div>

                            <!-- State 2: No file attached (Manual Entry Mode) -->
                            <div v-else class="relative flex items-center">
                                <FileUp class="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-slate-400" />
                                <input type="file" accept=".pdf,.jpg,.jpeg,.png" @change="(e: any) => form.attached_file = e.target.files[0]" class="w-full rounded-xl border border-slate-300 py-1.5 pl-9 pr-3 text-sm bg-white shadow-sm file:mr-4 file:py-1 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-slate-100 file:text-slate-700 hover:file:bg-slate-200" />
                            </div>
                        </div>

                        <hr class="border-slate-100 my-1" />

                        <!-- Footer -->
                        <div class="flex items-center justify-between">
                            <div class="text-xs text-slate-500">
                                Code: <span class="font-bold text-slate-800 ml-1">{{ form.access_code }}</span>
                            </div>
                            <div class="flex items-center gap-3">
                                <select v-model="form.department_id" class="rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 shadow-sm">
                                    <option value="">Direct To (Optional)</option>
                                    <option :value="3">City Administrator</option>
                                    <option :value="31">City Mayor</option>
                                </select>
                                <button type="submit" :disabled="form.processing || isAnalyzing" class="bg-emerald-600 text-white text-sm px-5 py-2 rounded-xl font-medium shadow-sm hover:bg-emerald-700 transition disabled:opacity-50">
                                    Save Document
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- RIGHT PANEL: DATATABLE -->
                <div class="lg:col-span-7 bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden flex flex-col">
                    <div class="p-4 border-b border-slate-200 bg-slate-50 flex items-center justify-between gap-4">
                        <div class="relative w-full max-w-sm">
                            <Search class="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-slate-400" />
                            <input v-model="searchTerm" type="text" placeholder="Search barcode or subject..." class="block w-full rounded-xl border border-slate-300 bg-white py-2 pl-9 pr-10 text-sm placeholder-slate-400 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 shadow-sm" />
                            <button v-if="searchTerm" @click="clearSearch" class="absolute top-1/2 right-3 -translate-y-1/2 text-slate-400 hover:text-slate-600">×</button>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm text-slate-700">
                            <thead class="bg-slate-100 text-xs text-slate-600 uppercase tracking-wider border-b border-slate-200">
                                <tr>
                                    <th class="px-4 py-2.5 font-bold">Barcode</th>
                                    <th class="px-4 py-2.5 font-bold">Subject Matter</th>
                                    <th class="px-4 py-2.5 font-bold">Source Name</th>
                                    <th class="px-4 py-2.5 font-bold">Status</th>
                                    <th class="px-4 py-2.5 text-center font-bold">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200">
                                <tr v-for="doc in documents.data" :key="doc.id" class="transition-colors hover:bg-slate-50">
                                    <td class="px-4 py-3 font-mono font-semibold text-emerald-600 whitespace-nowrap">
                                        <Link :href="route('documents.show', doc.id)" class="hover:underline">{{ doc.barcode }}</Link>
                                    </td>
                                    <td class="px-4 py-3">
                                        <p class="font-medium text-slate-800 line-clamp-2" :title="doc.subject_matter">{{ doc.subject_matter }}</p>
                                        <p class="text-[11px] text-slate-500 mt-0.5">{{ doc.document_type?.document_type }}</p>
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap text-slate-600">{{ doc.source_name }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap">
                                        <div class="flex flex-col gap-1 items-start">
                                            <span v-if="doc.status === 'Completed'" class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                                Completed
                                            </span>
                                            <span v-else class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-bold bg-blue-50 text-blue-700 border border-blue-200">
                                                {{ doc.status || 'Pending' }}
                                            </span>
                                            <span v-if="doc.is_delayed" class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-bold bg-rose-50 text-rose-700 border border-rose-200 animate-pulse">
                                                <AlertCircle class="w-3 h-3 mr-1" /> Delayed
                                            </span>
                                            <span v-if="doc.is_delinquent" class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-bold bg-amber-50 text-amber-700 border border-amber-200">
                                                <Clock class="w-3 h-3 mr-1" /> Delinquent
                                            </span>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap">
                                        <div class="flex justify-center gap-1.5">
                                            <button @click="openRoutingModal(doc)" class="rounded-md bg-indigo-50 p-1.5 text-indigo-600 transition hover:bg-indigo-100 border border-indigo-100" title="Route">
                                                <FastForward class="h-4 w-4" />
                                            </button>
                                            <Link :href="route('documents.edit', doc.id)" class="rounded-md bg-slate-50 p-1.5 text-slate-600 transition hover:bg-slate-100 border border-slate-200" title="Edit">
                                                <Pencil class="h-4 w-4" />
                                            </Link>
                                            <button @click="openDeleteModal(doc)" class="rounded-md bg-red-50 p-1.5 text-red-600 transition hover:bg-red-100 border border-red-100" title="Delete">
                                                <Trash2 class="h-4 w-4" />
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <tr v-if="documents.data.length === 0">
                                    <td colspan="5" class="py-12 text-center text-slate-500">No documents found.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div v-if="documents.last_page > 1" class="flex items-center justify-between border-t border-slate-200 bg-slate-50 px-4 py-3 mt-auto">
                        <p class="text-sm text-slate-500">
                            Showing <span class="font-medium text-slate-700">{{ documents.from }}</span>
                            to <span class="font-medium text-slate-700">{{ documents.to }}</span> of
                            <span class="font-medium text-slate-700">{{ documents.total }}</span> results
                        </p>
                        <div class="flex gap-1">
                            <button v-for="(link, index) in documents.links.slice(1, -1)" :key="index" @click="goToPage(String(link.url))" :disabled="!link.url"
                                class="rounded-lg px-3 py-1 text-sm font-medium transition border"
                                :class="link.active ? 'bg-emerald-600 text-white border-emerald-600 shadow-sm' : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-50'">
                                {{ link.label }}
                            </button>
                        </div>
                    </div>
                </div>

            </div>

            <DeleteConfirmationModal v-model:show="showDeleteModal" :deleteUrl="itemToDeleteUrl" :item-name="itemToDeleteName" title="Deactivate Document" />
            <RoutingModal :show="showRoutingModal" :document="documentToRoute" :departments="departments" @close="showRoutingModal = false" />

        </div>
    </AppLayout>
</template>