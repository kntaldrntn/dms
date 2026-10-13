<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import DeleteConfirmationModal from '@/components/DeleteConfirmationModal.vue';
import RoutingModal from '@/components/RoutingModal.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Search, Pencil, Trash2, FastForward, AlertCircle, Clock, Loader2, Sparkles, Link as LinkIcon, FileUp, X, FileText } from 'lucide-vue-next';
import { ref, watch } from 'vue';
import { Notyf } from 'notyf';
import 'notyf/notyf.min.css';
import axios from 'axios';
import * as pdfjsLib from 'pdfjs-dist';
import Tesseract from 'tesseract.js';

pdfjsLib.GlobalWorkerOptions.workerSrc = `//cdnjs.cloudflare.com/ajax/libs/pdf.js/${pdfjsLib.version}/pdf.worker.min.mjs`;

const props = defineProps<{
    documents: any;
    departments: Array<{ id: number; name: string; code: string }>;
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

const departmentId = (code: string) => Number(props.departments.find(department => department.code === code)?.id || 0);
const cityAdministratorId = departmentId('ADM');
const cityMayorId = departmentId('OCM');

// Documents waiting on an executive step cannot be routed by Records yet.
const pendingApprovalLabels: Record<string, string> = {
    city_administrator: 'Pending City Administrator Slip',
    department_head: 'Pending Dept. Head Signature',
    city_mayor: 'Pending City Mayor Review',
};

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
const itemToDeleteVersion = ref(0);
function openDeleteModal(doc: any) {
    itemToDeleteUrl.value = route('documents.destroy', doc.id);
    itemToDeleteName.value = `Document ${doc.barcode}`;
    itemToDeleteVersion.value = doc.workflow_version;
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
    
    linked_documents: '', 
    attached_file: null as File | null, 

    department_id: cityAdministratorId,
    access_code: props.generatedAccessCode,
    ai_routing_suggestions: [] as any[],
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
        } catch {
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
const executiveSuggestion = ref<{ route: string, reason: string } | null>(null);

const aiMetrics = ref<{ 
    total_tokens: number, 
    prompt_tokens: number, 
    completion_tokens: number, 
    confidence: number 
} | null>(null);

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
    executiveSuggestion.value = null;
    analysisError.value = '';
    aiMetrics.value = null;
    form.clearErrors(); 

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
        
        // Resolve executive destinations from their configured department codes,
        // never from seed-only numeric IDs.
        if (Number(data.department_id) === cityMayorId) {
            form.department_id = cityMayorId;
            executiveSuggestion.value = { route: 'City Mayor', reason: data.approval_reason || 'Requires explicit Mayoral approval.' };
        } else {
            form.department_id = cityAdministratorId;
            executiveSuggestion.value = { route: 'City Administrator', reason: 'Requires standard referral slip processing.' };
        }
        
        // Silently capture all recommendations for the Admin's Referral Slip module later
        if (data.routing_suggestions && Array.isArray(data.routing_suggestions)) {
            form.ai_routing_suggestions = data.routing_suggestions; 
        }

        if (data.meta) {
            aiMetrics.value = {
                total_tokens: data.meta.total_tokens,
                prompt_tokens: data.meta.prompt_tokens,
                completion_tokens: data.meta.completion_tokens,
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
            executiveSuggestion.value = null;
            aiMetrics.value = null;
            analysisError.value = '';
        },
        onError: () => {
            notyf.error('Please check the highlighted fields.');
        }
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
                        
                        <div class="grid grid-cols-3 gap-3">
                            <div>
                                <label class="block text-xs font-medium text-slate-700 mb-1">Barcode</label>
                                <input v-model="form.barcode" type="text" maxlength="10" 
                                    class="w-full rounded-xl border px-3 py-2 text-sm shadow-sm transition-colors"
                                    :class="form.errors.barcode ? 'border-red-500 focus:border-red-500 focus:ring-1 focus:ring-red-500' : 'border-slate-300 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500'" 
                                />
                                <p v-if="form.errors.barcode" class="text-[10px] text-red-500 mt-1 font-bold">{{ form.errors.barcode }}</p>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-slate-700 mb-1">Transaction Type</label>
                                <select v-model="form.transaction_type_id" 
                                    class="w-full rounded-xl border px-3 py-2 text-sm shadow-sm transition-colors"
                                    :class="form.errors.transaction_type_id ? 'border-red-500 focus:border-red-500 focus:ring-1 focus:ring-red-500' : 'border-slate-300 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500'">
                                    <option value="" disabled>Select</option>
                                    <option v-for="type in transactionTypes" :key="type.id" :value="type.id">{{ type.name }}</option>
                                </select>
                                <p v-if="form.errors.transaction_type_id" class="text-[10px] text-red-500 mt-1 font-bold">Required</p>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-slate-700 mb-1">Document Type</label>
                                <select v-model="form.document_type_id" 
                                    class="w-full rounded-xl border px-3 py-2 text-sm shadow-sm transition-colors"
                                    :class="form.errors.document_type_id ? 'border-red-500 focus:border-red-500 focus:ring-1 focus:ring-red-500' : 'border-slate-300 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500'">
                                    <option value="" disabled>Select</option>
                                    <option v-for="type in documentTypes" :key="type.id" :value="type.id">{{ type.document_type }}</option>
                                </select>
                                <p v-if="form.errors.document_type_id" class="text-[10px] text-red-500 mt-1 font-bold">Required</p>
                            </div>
                        </div>

                        <div class="grid grid-cols-3 gap-3">
                            <div>
                                <label class="block text-xs font-medium text-slate-700 mb-1">Source Type</label>
                                <select v-model="form.source_type" 
                                    class="w-full rounded-xl border px-3 py-2 text-sm shadow-sm transition-colors"
                                    :class="form.errors.source_type ? 'border-red-500 focus:border-red-500 focus:ring-1 focus:ring-red-500' : 'border-slate-300 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500'">
                                    <option value="External">External</option>
                                    <option value="Internal">Internal</option>
                                </select>
                                <p v-if="form.errors.source_type" class="text-[10px] text-red-500 mt-1 font-bold">{{ form.errors.source_type }}</p>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-slate-700 mb-1">Source Location</label>
                                <input v-if="form.source_type === 'External'" v-model="form.source_location" type="text" disabled class="w-full rounded-xl border border-slate-200 px-3 py-2 bg-slate-100 text-sm text-slate-500 cursor-not-allowed shadow-sm" />
                                <select v-else v-model="form.source_location" 
                                    class="w-full rounded-xl border px-3 py-2 text-sm shadow-sm transition-colors"
                                    :class="form.errors.source_location ? 'border-red-500 focus:border-red-500 focus:ring-1 focus:ring-red-500' : 'border-slate-300 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500'">
                                    <option value="" disabled>Select Department</option>
                                    <option v-for="dept in departments" :key="dept.id" :value="dept.id">{{ dept.name }}</option>
                                </select>
                                <p v-if="form.errors.source_location && form.source_type === 'Internal'" class="text-[10px] text-red-500 mt-1 font-bold">Required</p>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-slate-700 mb-1">Classification</label>
                                <select v-model="form.classification_id"
                                    class="w-full rounded-xl border px-3 py-2 text-sm shadow-sm transition-colors"
                                    :class="form.errors.classification_id ? 'border-red-500 focus:border-red-500 focus:ring-1 focus:ring-red-500' : 'border-slate-300 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500'">
                                    <option value="" disabled>Select</option>
                                    <option v-for="classif in classifications" :key="classif.id" :value="classif.id">{{ classif.name }}</option>
                                </select>
                                <p v-if="form.errors.classification_id" class="text-[10px] text-red-500 mt-1 font-bold">{{ form.errors.classification_id }}</p>
                            </div>
                        </div>

                        <div class="grid grid-cols-3 gap-3">
                            <div>
                                <label class="block text-xs font-medium text-slate-700 mb-1">Delivery Method</label>
                                <select v-model="form.delivery_method_id" 
                                    class="w-full rounded-xl border px-3 py-2 text-sm shadow-sm transition-colors"
                                    :class="form.errors.delivery_method_id ? 'border-red-500 focus:border-red-500 focus:ring-1 focus:ring-red-500' : 'border-slate-300 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500'">
                                    <option value="" disabled>Select</option>
                                    <option v-for="method in deliveryMethods" :key="method.id" :value="method.id">{{ method.delivery_method_name }}</option>
                                </select>
                                <p v-if="form.errors.delivery_method_id" class="text-[10px] text-red-500 mt-1 font-bold">{{ form.errors.delivery_method_id }}</p>
                            </div>
                            <div class="col-span-2">
                                <label class="block text-xs font-medium text-slate-700 mb-1">Source Name</label>
                                <input v-model="form.source_name" type="text" placeholder="Name/Company/Agency" 
                                    class="w-full rounded-xl border px-3 py-2 text-sm shadow-sm transition-colors"
                                    :class="form.errors.source_name ? 'border-red-500 focus:border-red-500 focus:ring-1 focus:ring-red-500' : 'border-slate-300 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500'" 
                                />
                                <p v-if="form.errors.source_name" class="text-[10px] text-red-500 mt-1 font-bold">Required</p>
                            </div>
                        </div>

                        <div class="grid grid-cols-3 gap-3">
                            <div>
                                <label class="block text-xs font-medium text-slate-700 mb-1">Gender</label>
                                <select v-model="form.gender" 
                                    class="w-full rounded-xl border px-3 py-2 text-sm shadow-sm transition-colors"
                                    :class="form.errors.gender ? 'border-red-500 focus:border-red-500 focus:ring-1 focus:ring-red-500' : 'border-slate-300 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500'">
                                    <option value="" disabled>Select</option>
                                    <option value="Male">Male</option>
                                    <option value="Female">Female</option>
                                </select>
                                <p v-if="form.errors.gender" class="text-[10px] text-red-500 mt-1 font-bold">{{ form.errors.gender }}</p>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-slate-700 mb-1">Contact No.</label>
                                <input v-model="form.contact_no" type="text" placeholder="Optional" 
                                    class="w-full rounded-xl border px-3 py-2 text-sm shadow-sm transition-colors"
                                    :class="form.errors.contact_no ? 'border-red-500 focus:border-red-500 focus:ring-1 focus:ring-red-500' : 'border-slate-300 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500'" 
                                />
                                <p v-if="form.errors.contact_no" class="text-[10px] text-red-500 mt-1 font-bold">{{ form.errors.contact_no }}</p>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-slate-700 mb-1">Email</label>
                                <input v-model="form.email" type="email" placeholder="Optional" 
                                    class="w-full rounded-xl border px-3 py-2 text-sm shadow-sm transition-colors"
                                    :class="form.errors.email ? 'border-red-500 focus:border-red-500 focus:ring-1 focus:ring-red-500' : 'border-slate-300 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500'" 
                                />
                                <p v-if="form.errors.email" class="text-[10px] text-red-500 mt-1 font-bold">{{ form.errors.email }}</p>
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-slate-700 mb-1">Subject Matter</label>
                            <textarea v-model="form.subject_matter" rows="2" 
                                class="w-full rounded-xl border px-3 py-2 text-sm shadow-sm transition-colors"
                                :class="form.errors.subject_matter ? 'border-red-500 focus:border-red-500 focus:ring-1 focus:ring-red-500' : 'border-slate-300 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500'"></textarea>
                            <p v-if="form.errors.subject_matter" class="text-[10px] text-red-500 mt-1 font-bold">Required</p>
                        </div>

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
                                    class="w-full rounded-xl border border-slate-300 py-2 pl-9 pr-3 text-sm focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 shadow-sm transition-colors"
                                    :class="{'border-red-500 focus:border-red-500 focus:ring-red-500': form.errors.linked_documents}" 
                                />
                            </div>
                            <p v-if="form.errors.linked_documents" class="text-[10px] text-red-500 mt-1 font-bold">{{ form.errors.linked_documents }}</p>
                            
                            <div v-if="showBarcodeDropdown" class="absolute z-10 mt-1 w-full bg-white rounded-xl shadow-lg border border-slate-200 overflow-hidden">
                                <ul class="max-h-48 overflow-y-auto py-1">
                                    <li v-for="res in barcodeResults" :key="res.barcode" @click="selectBarcode(res.barcode)" class="px-4 py-2 hover:bg-slate-50 cursor-pointer border-b border-slate-100 last:border-0 transition-colors">
                                        <div class="font-mono text-xs font-bold text-emerald-600">{{ res.barcode }}</div>
                                        <div class="text-[10px] text-slate-500 truncate">{{ res.subject_matter }}</div>
                                    </li>
                                </ul>
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-slate-700 mb-1">Attached Document (Optional)</label>
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
                            <div v-else class="relative flex items-center">
                                <FileUp class="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-slate-400" />
                                <input type="file" accept=".pdf,.jpg,.jpeg,.png" @change="(e: any) => form.attached_file = e.target.files[0]" 
                                    class="w-full rounded-xl border py-1.5 pl-9 pr-3 text-sm bg-white shadow-sm file:mr-4 file:py-1 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-slate-100 file:text-slate-700 hover:file:bg-slate-200 transition-colors"
                                    :class="form.errors.attached_file ? 'border-red-500 focus:border-red-500 focus:ring-1 focus:ring-red-500' : 'border-slate-300'"
                                />
                            </div>
                            <p v-if="form.errors.attached_file" class="text-[10px] text-red-500 mt-1 font-bold">{{ form.errors.attached_file }}</p>
                        </div>

                        <!-- AI Routing Suggestions UI (Records View) -->
                        <div v-if="executiveSuggestion" class="bg-indigo-50 border border-indigo-100 rounded-xl p-3 mt-1 flex flex-col gap-2">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-indigo-700 flex items-center gap-1.5">
                                <Sparkles class="w-3 h-3" /> AI Routing Analysis
                            </span>
                            
                            <div class="bg-white/60 rounded-lg p-2 border border-indigo-50">
                                <p class="text-[11px] text-indigo-900 font-bold">Executive Routing: {{ executiveSuggestion.route }}</p>
                                <p class="text-[10px] text-indigo-700 mt-0.5 leading-tight">{{ executiveSuggestion.reason }}</p>
                            </div>
                        </div>

                        <!-- <div v-if="aiMetrics" class="bg-slate-800 text-slate-200 border border-slate-700 rounded-xl p-3 flex flex-wrap items-center gap-4 text-[11px] font-mono shadow-inner mb-4 mt-2 mx-1">
                            <div class="text-emerald-400 font-bold">🛠️ DEBUG METRICS:</div>
                            <div>Accuracy: <span :class="aiMetrics.confidence > 80 ? 'text-emerald-400' : 'text-amber-400'" class="font-bold">{{ aiMetrics.confidence }}%</span></div>
                            <div>Total: <span class="text-white">{{ aiMetrics.total_tokens }}</span></div>
                            <div>Prompt: <span class="text-white">{{ aiMetrics.prompt_tokens }}</span></div>
                            <div>Completion: <span class="text-white">{{ aiMetrics.completion_tokens }}</span></div>
                        </div> -->

                        <hr class="border-slate-100 mt-1" />

                        <!-- Footer -->
                        <div class="flex items-center justify-between">
                            <div class="text-xs text-slate-500">
                                Code: <span class="font-bold text-slate-800 ml-1">{{ form.access_code }}</span>
                            </div>
                            <div class="flex items-center gap-3">
                                <select v-model="form.department_id" 
                                    class="rounded-xl border px-3 py-2 text-sm shadow-sm transition-colors font-semibold bg-indigo-50 text-indigo-800"
                                    :class="form.errors.department_id ? 'border-red-500 focus:border-red-500 focus:ring-1 focus:ring-red-500' : 'border-indigo-200 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500'">
                                    <option :value="cityAdministratorId">Route to City Administrator</option>
                                    <option :value="cityMayorId">Route to City Mayor</option>
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
                            <input v-model="searchTerm" type="text" placeholder="Search barcode or subject..." aria-label="Search documents" class="block w-full rounded-xl border border-slate-300 bg-white py-2 pl-9 pr-10 text-sm placeholder-slate-400 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 shadow-sm" />
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
                                        <div class="flex justify-center items-center gap-2">

                                            <!-- REFERRAL SLIP: authorized approvers and Records can reopen an approved slip -->
                                            <a v-if="doc.approved_routing_slip && doc.requires_signature_from !== 'department_head' && ['records_manager', 'city_administrator', 'city_mayor', 'system_administrator'].includes($page.props.auth.user.role)" :href="route('referral-slips.preview', doc.id)" target="_blank" rel="noopener" class="rounded-md border border-violet-200 bg-violet-50 p-1.5 text-violet-700 shadow-sm transition hover:bg-violet-100" title="Open Referral Action Slip PDF preview">
                                                <FileText class="h-4 w-4" />
                                            </a>

                                            <!-- ROUTING/STATUS: Hide FastForward while City Administrator approval is pending -->
                                            <span v-if="pendingApprovalLabels[doc.requires_signature_from] || doc.department_id !== $page.props.auth.user.department_id" class="text-[10px] font-bold uppercase tracking-wider text-slate-500 py-1.5 px-2 bg-slate-100 rounded-md border border-slate-200">
                                                {{ pendingApprovalLabels[doc.requires_signature_from] || `With ${doc.department?.code || 'Exec.'}` }}
                                            </span>

                                            <!-- ROUTING BUTTON: Show only after required approval -->
                                            <button v-else @click="openRoutingModal(doc)" 
                                                class="rounded-md p-1.5 transition border shadow-sm"
                                                :class="doc.approved_routing_slip ? 'bg-emerald-50 text-emerald-600 hover:bg-emerald-100 border-emerald-200' : 'bg-indigo-50 text-indigo-600 hover:bg-indigo-100 border-indigo-100'" 
                                                :title="doc.approved_routing_slip ? 'Execute Referral Slip' : 'Route'">
                                                <FastForward class="h-4 w-4" />
                                            </button>

                                            <!-- EDIT & DELETE ALWAYS VISIBLE -->
                                            <Link :href="route('documents.edit', doc.id)" class="rounded-md bg-slate-50 p-1.5 text-slate-600 transition hover:bg-slate-100 border border-slate-200" title="Edit">
                                                <Pencil class="h-4 w-4" />
                                            </Link>
                                            <button @click="openDeleteModal(doc)" class="rounded-md bg-rose-50 p-1.5 text-rose-600 transition hover:bg-rose-100 border border-rose-100" title="Delete">
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

            <DeleteConfirmationModal v-model:show="showDeleteModal" :deleteUrl="itemToDeleteUrl" :delete-data="{ workflow_version: itemToDeleteVersion }" :item-name="itemToDeleteName" title="Deactivate Document" />
            <RoutingModal :show="showRoutingModal" :document="documentToRoute" :departments="departments" @close="showRoutingModal = false" />

        </div>
    </AppLayout>
</template>
