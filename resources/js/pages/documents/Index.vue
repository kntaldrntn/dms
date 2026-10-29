<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import RoutingModal from '@/components/RoutingModal.vue';
import OfficePicker from '@/components/OfficePicker.vue';
import InputError from '@/components/InputError.vue';
import { useIntakeRulebook, type IntakeDocumentType } from '@/composables/useIntakeRulebook';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Search, Pencil, FastForward, AlertCircle, Clock, Loader2, Sparkles, FileUp, X } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { Notyf } from 'notyf';
import 'notyf/notyf.min.css';
import axios from 'axios';
import { scanIntakeFile } from '@/lib/intakeScan';
import { barcodeBeingTyped, withChosenBarcode } from '@/lib/linkedDocuments';

const props = defineProps<{
    documents: any;
    departments: Array<{ id: number; name: string; code: string }>;
    transactionTypes: Array<{ id: number; name: string }>;
    documentTypes: IntakeDocumentType[];
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

// Documents waiting on an executive step (their Primary entry's stage) cannot be routed by Records yet.
const pendingApprovalLabels: Record<string, string> = {
    adm_approval: 'Pending City Administrator Approval',
    ocm_approval: 'Pending City Mayor Approval',
};

// A document dispatched to several offices is held by all of them (`holders`); department_id is only the primary one.
type Holder = { id: number; code: string; name: string };
const holdersOf = (doc: any): Holder[] => (doc.holders?.length ? doc.holders : doc.department ? [doc.department] : []);
const holderCodes = (doc: any) => holdersOf(doc).map((office) => office.code || office.name).join(', ') || 'Exec.';
const holderNames = (doc: any) => holdersOf(doc).map((office) => office.name).join(', ');
// Only the Primary office acts on a document; offices with a view-only copy cannot route it.
const holdsCopy = (doc: any, officeId: number) =>
    holdersOf(doc).some((office: any) => office.id === officeId && (office.pivot?.copy_role ?? 'primary') === 'primary');

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
    is_confidential: false,
    route_mode: 'paper' as 'paperless' | 'paper',
    delivery_method_id: '',
    source_name: '',
    gender: '',
    contact_no: '',
    email: '',
    subject_matter: '',
    
    linked_documents: '', 
    attached_file: null as File | null, 

    department_id: cityAdministratorId as number | null,
    // Used when the document goes straight to departments instead of ADM/OCM.
    routed_to: [] as number[],
    primary_office_id: null as number | null,
    access_code: props.generatedAccessCode,
    ai_routing_suggestions: [] as any[],
});

const { selectedType, allowedClassifications, paperlessAllowed, paperlessEnabled } = useIntakeRulebook(form, () => props.documentTypes, () => props.classifications);

// Confidential documents get no AI assist and no upload (FGD), so drop anything the AI or a scan already added.
watch(() => form.is_confidential, (isConfidential) => {
    if (!isConfidential) return;
    form.attached_file = null;
    analyzedFile.value = null;
    form.ai_routing_suggestions = [];
    form.routed_to = form.routed_to.slice(0, 1);
    executiveSuggestion.value = null;
    analysisError.value = '';
});

// --- SMART BARCODE SEARCH ---
const showBarcodeDropdown = ref(false);
const barcodeResults = ref<Array<{ barcode: string, subject_matter: string }>>([]);

const handleLinkedDocumentInput = async (e: any) => {
    const lastWord = barcodeBeingTyped(e.target.value);

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
    form.linked_documents = withChosenBarcode(form.linked_documents, barcode);
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

/** Tell Records why autofill failed instead of one generic message. */
function analysisFailureReason(err: any): string {
    const status = err?.response?.status;
    const data = err?.response?.data;
    if (status === 422) return String(Object.values(data?.errors ?? {}).flat()[0] ?? data?.message ?? 'The file could not be analyzed.');
    if (status === 419) return 'Your session expired. Refresh the page and try again.';
    if (status === 403) return 'Your account is not allowed to use AI autofill.';
    if (data?.error) return data.error;
    if (err?.message && !err?.response) return `The file could not be read in the browser: ${err.message}`;

    return 'Document uploaded, but automatic analysis failed. You can fill the fields manually.';
}

// --- STEP 1: one upload area attaches the scan and (optionally) autofills the form from it ---
const autofillWithAi = ref(true);
const isDragging = ref(false);
const analyzedFile = ref<File | null>(null);
const ACCEPTED_FILE = /\.(pdf|jpe?g|png)$/i;

function chooseFile(file?: File | null) {
    if (!file || form.is_confidential) return;
    if (!ACCEPTED_FILE.test(file.name)) {
        notyf.error('Upload a PDF, JPG or PNG file.');
        return;
    }
    form.attached_file = file;
    form.clearErrors('attached_file');
    analyzedFile.value = null;
    if (autofillWithAi.value) analyzeFile(file);
}

function onFileDrop(event: DragEvent) {
    isDragging.value = false;
    chooseFile(event.dataTransfer?.files?.[0]);
}

function removeFile() {
    form.attached_file = null;
    analyzedFile.value = null;
    analysisError.value = '';
}

const analyzeFile = async (file: File) => {
    isAnalyzing.value = true;
    executiveSuggestion.value = null;
    analysisError.value = '';
    aiMetrics.value = null;
    form.clearErrors(); 

    try {
        const scan = await scanIntakeFile(file);
        if (!scan.text.trim()) {
            throw new Error('No readable text was found in the file. Upload a clearer scan or fill the fields manually.');
        }

        const response = await axios.post(route('documents.analyze'), { extracted_text: scan.text });
        const data = response.data;
        // A redirect (e.g. outside working hours) is followed by the browser and returns an HTML page, not JSON.
        if (!data || typeof data !== 'object') {
            throw new Error('The server did not return analysis results. Refresh the page and check that it is within working hours.');
        }

        // A barcode already typed or scanned by Records wins; a decoded sticker beats the AI's OCR reading.
        if (!form.barcode.trim()) form.barcode = scan.barcode || data.barcode || '';
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
        // Kept for the next office's routing screen, and used below when it goes straight to departments.
        if (data.routing_suggestions && Array.isArray(data.routing_suggestions)) {
            form.ai_routing_suggestions = data.routing_suggestions;
        }

        // Resolve executive destinations from their configured department codes,
        // never from seed-only numeric IDs. No executive target: straight to the suggested departments.
        if (Number(data.department_id) === cityMayorId) {
            directTo.value = 'OCM';
            executiveSuggestion.value = { route: 'City Mayor', reason: data.approval_reason || 'Requires explicit Mayoral approval.' };
        } else if (Number(data.department_id) === cityAdministratorId) {
            directTo.value = 'ADM';
            executiveSuggestion.value = { route: 'City Administrator', reason: data.approval_reason || 'Requires City Administrator approval.' };
        } else {
            const suggested = form.ai_routing_suggestions.filter((suggestion: any) =>
                intakeOffices.value.some((office) => office.id === Number(suggestion.department_id)),
            );
            if (suggested.length) {
                // The Primary comes from the offices that survived the filter, so it is always one of the routed offices.
                const primaryId = Number((suggested.find((suggestion: any) => suggestion.priority === 'Primary') ?? suggested[0]).department_id);
                directTo.value = 'departments';
                form.routed_to = form.is_confidential ? [primaryId] : suggested.map((suggestion: any) => Number(suggestion.department_id));
                form.primary_office_id = primaryId;
                executiveSuggestion.value = { route: 'the departments', reason: 'No executive approval needed.' };
            }
        }

        if (data.meta) {
            aiMetrics.value = {
                total_tokens: data.meta.total_tokens,
                prompt_tokens: data.meta.prompt_tokens,
                completion_tokens: data.meta.completion_tokens,
                confidence: data.confidence_score || 0
            };
        }

        analyzedFile.value = file;
        notyf.success('AI successfully extracted data.');
    } catch (err: any) {
        analysisError.value = analysisFailureReason(err);
        notyf.error('AI Analysis failed. Please input manually.');
    } finally {
        isAnalyzing.value = false;
    }
};

// --- DIRECT TO: City Administrator, City Mayor, or straight to departments ---
const directTo = ref<'ADM' | 'OCM' | 'departments'>('ADM');
watch(directTo, (target) => {
    form.department_id = target === 'ADM' ? cityAdministratorId : target === 'OCM' ? cityMayorId : null;
    if (target === 'departments' && !form.routed_to.length) showOfficePicker.value = true;
});
// ADM and OCM are chosen in Direct to (they open an approval stage); Records does not route to itself.
const intakeOffices = computed(() => props.departments.filter((office) => !['REC', 'ADM', 'OCM'].includes(office.code)));
const showOfficePicker = ref(false);
const pickerSuggestions = computed(() =>
    Object.fromEntries(
        form.ai_routing_suggestions.map((suggestion: any) => [Number(suggestion.department_id), { reason: suggestion.reason, priority: suggestion.priority }]),
    ),
);
const officeCode = (id: number) => props.departments.find((office) => office.id === id)?.code ?? id;

// Plain, compact fields in the style of the previous DTS intake form.
const labelClass = 'mb-1 block truncate text-sm text-slate-700';
const fieldClass = (error?: string) => [
    'w-full rounded-md border bg-white px-2.5 py-1.5 text-sm text-slate-800 placeholder-slate-400 focus:outline-none focus:ring-1',
    error ? 'border-red-500 focus:border-red-500 focus:ring-red-500' : 'border-slate-300 focus:border-emerald-600 focus:ring-emerald-600',
];
const iconButtonClass = 'inline-flex h-8 w-8 items-center justify-center rounded-md border border-slate-200 text-slate-600 transition hover:bg-slate-100';
// Numbered step headings so Records reads the form top to bottom: confidential → file → details → sender → subject → send.
const stepClass = 'mb-1.5 flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-slate-500';
const stepNumberClass = 'flex h-5 w-5 items-center justify-center rounded-full bg-slate-800 text-[11px] font-semibold text-white';
const routeNeedsFile = computed(() => form.route_mode === 'paperless' && !form.attached_file);

function submitDocument() {
    form.transform((data) =>
        directTo.value === 'departments' ? { ...data, department_id: null } : { ...data, routed_to: [], primary_office_id: null },
    ).post(route('documents.store'), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            directTo.value = 'ADM';
            form.access_code = Math.random().toString(36).substring(2, 8).toUpperCase();
            executiveSuggestion.value = null;
            aiMetrics.value = null;
            analysisError.value = '';
            analyzedFile.value = null;
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
        <div class="mx-auto flex min-h-screen w-full flex-col gap-4 bg-slate-50 p-4">
            <div class="grid grid-cols-1 items-start gap-4 lg:grid-cols-12">
                <!-- LEFT PANEL: NEW DOCUMENT FORM (dense grid so the whole form fits one screen) -->
                <section class="rounded-lg border border-slate-200 bg-white lg:col-span-6" aria-labelledby="new-document-title">
                    <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
                        <h2 id="new-document-title" class="text-base font-semibold text-slate-800">New Document</h2>
                        <p class="text-xs text-slate-500">
                            Access Code: <span class="font-mono font-semibold text-slate-800">{{ form.access_code }}</span>
                        </p>
                    </div>

                    <form class="flex flex-col gap-3 p-4" @submit.prevent="submitDocument">
                        <!-- STEPS 1–2: confidentiality decides whether the scan may be uploaded and read by AI -->
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <h3 :class="stepClass"><span :class="stepNumberClass">1</span> Is it confidential?</h3>
                                <label
                                    for="intake_confidential"
                                    class="flex h-[52px] cursor-pointer items-center gap-2 rounded-md border px-2.5 transition"
                                    :class="form.is_confidential ? 'border-amber-400 bg-amber-50' : 'border-slate-200 hover:bg-slate-50'"
                                    title="Confidential documents are not uploaded or read by AI, go by paper, and you type the details yourself."
                                >
                                    <input
                                        id="intake_confidential"
                                        v-model="form.is_confidential"
                                        type="checkbox"
                                        aria-describedby="intake_confidential_hint"
                                        class="h-4 w-4 shrink-0 rounded border-slate-300 text-amber-600 focus:ring-amber-600"
                                    />
                                    <span class="min-w-0 leading-tight">
                                        <span class="block text-sm font-medium text-slate-800">Confidential document</span>
                                        <span id="intake_confidential_hint" class="block truncate text-xs text-slate-500">
                                            {{ selectedType?.may_be_confidential ? 'This type may be confidential · ' : '' }}No upload, no AI
                                        </span>
                                    </span>
                                </label>
                                <InputError class="mt-1 text-xs" :message="form.errors.is_confidential" />
                            </div>

                            <div>
                                <div class="flex items-center justify-between gap-2">
                                    <h3 :class="stepClass"><span :class="stepNumberClass">2</span> Attach the scan</h3>
                                    <label v-if="!form.is_confidential" class="mb-1.5 flex items-center gap-1.5 text-xs text-slate-600">
                                        <input
                                            v-model="autofillWithAi"
                                            type="checkbox"
                                            class="h-3.5 w-3.5 rounded border-slate-300 text-emerald-600 focus:ring-emerald-600"
                                        />
                                        AI autofill
                                    </label>
                                </div>
                                <p
                                    v-if="form.is_confidential"
                                    class="flex h-[52px] items-center rounded-md border border-dashed border-slate-300 px-2.5 text-xs text-slate-500"
                                >
                                    Skipped for confidential documents.
                                </p>
                                <div v-else-if="form.attached_file" class="flex h-[52px] flex-col justify-center rounded-md border border-slate-300 px-2.5">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="flex min-w-0 items-center gap-1.5 text-sm text-slate-700">
                                            <FileUp class="h-3.5 w-3.5 shrink-0 text-slate-400" aria-hidden="true" />
                                            <span class="truncate">{{ form.attached_file.name }}</span>
                                        </span>
                                        <button
                                            type="button"
                                            class="rounded p-0.5 text-slate-500 hover:bg-slate-100 hover:text-slate-700 disabled:opacity-50"
                                            aria-label="Remove attachment"
                                            :disabled="isAnalyzing"
                                            @click="removeFile"
                                        >
                                            <X class="h-3.5 w-3.5" aria-hidden="true" />
                                        </button>
                                    </div>
                                    <p class="flex items-center gap-1 text-xs leading-tight" role="status" aria-live="polite">
                                        <template v-if="isAnalyzing">
                                            <Loader2 class="h-3 w-3 animate-spin text-emerald-600" aria-hidden="true" />
                                            <span class="text-slate-600">Reading and filling the form…</span>
                                        </template>
                                        <template v-else-if="analyzedFile === form.attached_file">
                                            <Sparkles class="h-3 w-3 text-emerald-600" aria-hidden="true" />
                                            <span class="text-emerald-700">AI filled the form. Check every field.</span>
                                        </template>
                                        <button
                                            v-else
                                            type="button"
                                            class="font-medium text-emerald-700 hover:underline"
                                            @click="form.attached_file && analyzeFile(form.attached_file)"
                                        >
                                            Fill the form with AI
                                        </button>
                                    </p>
                                </div>
                                <label
                                    v-else
                                    for="intake_attachment"
                                    class="flex h-[52px] cursor-pointer items-center justify-center gap-2 rounded-md border-2 border-dashed px-2.5 text-center transition focus-within:ring-2 focus-within:ring-emerald-600"
                                    :class="[
                                        isDragging ? 'border-emerald-500 bg-emerald-50' : 'border-slate-300 hover:bg-slate-50',
                                        form.errors.attached_file ? 'border-red-500' : '',
                                    ]"
                                    @dragover.prevent="isDragging = true"
                                    @dragleave.prevent="isDragging = false"
                                    @drop.prevent="onFileDrop"
                                >
                                    <FileUp class="h-4 w-4 shrink-0 text-slate-400" aria-hidden="true" />
                                    <span class="leading-tight">
                                        <span class="block text-sm font-medium text-slate-700">Choose or drop the scan</span>
                                        <span class="block text-xs" :class="routeNeedsFile ? 'text-amber-700' : 'text-slate-500'">
                                            PDF/JPG/PNG · {{ form.route_mode === 'paperless' ? 'required (paperless)' : 'optional' }}
                                        </span>
                                    </span>
                                    <input
                                        id="intake_attachment"
                                        type="file"
                                        accept=".pdf,.jpg,.jpeg,.png"
                                        class="sr-only"
                                        @change="(e: any) => chooseFile(e.target.files?.[0])"
                                    />
                                </label>
                                <InputError class="mt-1 text-xs" :message="form.errors.attached_file" />
                            </div>
                        </div>
                        <p v-if="analysisError" role="alert" class="flex items-start gap-2 rounded-md border border-rose-200 bg-rose-50 px-2.5 py-1.5 text-xs text-rose-700">
                            <AlertCircle class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            {{ analysisError }}
                        </p>

                        <!-- STEP 3: document details -->
                        <h3 :class="stepClass"><span :class="stepNumberClass">3</span> Document details</h3>
                        <div class="grid grid-cols-4 gap-3">
                            <div>
                                <label for="intake_barcode" :class="labelClass">Barcode</label>
                                <input id="intake_barcode" v-model="form.barcode" type="text" maxlength="10" placeholder="Max 10" :class="fieldClass(form.errors.barcode)" />
                                <InputError class="mt-1 text-xs" :message="form.errors.barcode" />
                            </div>
                            <div>
                                <label for="intake_document_type" :class="labelClass">Document Type</label>
                                <select id="intake_document_type" v-model="form.document_type_id" :class="fieldClass(form.errors.document_type_id)">
                                    <option value="" disabled>Select one</option>
                                    <option v-for="type in documentTypes" :key="type.id" :value="type.id">{{ type.document_type }}</option>
                                </select>
                                <InputError class="mt-1 text-xs" :message="form.errors.document_type_id" />
                            </div>
                            <div class="col-span-2">
                                <label for="intake_classification" :class="labelClass">Classification</label>
                                <select id="intake_classification" v-model="form.classification_id" :class="fieldClass(form.errors.classification_id)">
                                    <option value="" disabled>Select one</option>
                                    <option v-for="classif in allowedClassifications" :key="classif.id" :value="classif.id">{{ classif.name }}</option>
                                </select>
                                <InputError class="mt-1 text-xs" :message="form.errors.classification_id" />
                            </div>
                            <div>
                                <label for="intake_transaction_type" :class="labelClass">Transaction Type</label>
                                <select id="intake_transaction_type" v-model="form.transaction_type_id" :class="fieldClass(form.errors.transaction_type_id)">
                                    <option value="" disabled>Select one</option>
                                    <option v-for="type in transactionTypes" :key="type.id" :value="type.id">{{ type.name }}</option>
                                </select>
                                <InputError class="mt-1 text-xs" :message="form.errors.transaction_type_id" />
                            </div>
                            <div v-if="paperlessEnabled">
                                <label for="intake_route_mode" :class="labelClass">Route</label>
                                <select
                                    id="intake_route_mode"
                                    v-model="form.route_mode"
                                    :title="paperlessAllowed ? 'Paperless needs the scan from step 2.' : 'Paperless needs a digitally signed, non-confidential type.'"
                                    :class="fieldClass(form.errors.route_mode)"
                                >
                                    <option value="paperless" :disabled="!paperlessAllowed">Paperless (PNPKI)</option>
                                    <option value="paper">Paper (wet signature)</option>
                                </select>
                                <InputError class="mt-1 text-xs" :message="form.errors.route_mode" />
                            </div>
                            <div>
                                <label for="intake_delivery_method" :class="labelClass">Delivery Method</label>
                                <select id="intake_delivery_method" v-model="form.delivery_method_id" :class="fieldClass(form.errors.delivery_method_id)">
                                    <option value="" disabled>Select one</option>
                                    <option v-for="method in deliveryMethods" :key="method.id" :value="method.id">{{ method.delivery_method_name }}</option>
                                </select>
                                <InputError class="mt-1 text-xs" :message="form.errors.delivery_method_id" />
                            </div>
                            <div class="relative">
                                <label for="linked_documents_input" :class="labelClass">Linked Doc/s <span class="text-slate-400">(optional)</span></label>
                                <input
                                    id="linked_documents_input"
                                    v-model="form.linked_documents"
                                    type="text"
                                    autocomplete="off"
                                    placeholder="e.g. C003192314, C003196677"
                                    :class="fieldClass(form.errors.linked_documents)"
                                    @input="handleLinkedDocumentInput"
                                />
                                <InputError class="mt-1 text-xs" :message="form.errors.linked_documents" />
                                <ul v-if="showBarcodeDropdown" class="absolute right-0 z-10 mt-1 max-h-48 w-64 overflow-y-auto rounded-md border border-slate-200 bg-white py-1 shadow-md">
                                    <li v-for="res in barcodeResults" :key="res.barcode">
                                        <button type="button" class="w-full px-3 py-1.5 text-left hover:bg-slate-50 focus:bg-slate-50 focus:outline-none" @click="selectBarcode(res.barcode)">
                                            <span class="block font-mono text-xs font-semibold text-emerald-700">{{ res.barcode }}</span>
                                            <span class="block truncate text-xs text-slate-500">{{ res.subject_matter }}</span>
                                        </button>
                                    </li>
                                </ul>
                            </div>
                        </div>
                        <p v-if="paperlessEnabled && (form.route_mode === 'paperless' || !paperlessAllowed)" class="-mt-1 text-xs" :class="routeNeedsFile ? 'text-amber-700' : 'text-slate-500'">
                            <template v-if="!paperlessAllowed">Paperless needs a digitally signed, non-confidential type.</template>
                            <template v-else-if="routeNeedsFile">Paperless: attach the scan in step 2 before saving.</template>
                            <template v-else>Paperless: the attached scan will be signed with PNPKI.</template>
                        </p>

                        <!-- STEP 4: who sent it -->
                        <h3 :class="stepClass"><span :class="stepNumberClass">4</span> Sender</h3>
                        <div class="grid grid-cols-4 gap-3">
                            <div>
                                <label for="intake_source_type" :class="labelClass">Source Type</label>
                                <select id="intake_source_type" v-model="form.source_type" :class="fieldClass(form.errors.source_type)">
                                    <option value="External">External</option>
                                    <option value="Internal">Internal</option>
                                </select>
                                <InputError class="mt-1 text-xs" :message="form.errors.source_type" />
                            </div>
                            <div class="col-span-2">
                                <label for="intake_source_location" :class="labelClass">Source Location</label>
                                <input
                                    v-if="form.source_type === 'External'"
                                    id="intake_source_location"
                                    v-model="form.source_location"
                                    type="text"
                                    disabled
                                    class="w-full cursor-not-allowed rounded-md border border-slate-200 bg-slate-100 px-2.5 py-1.5 text-sm text-slate-500"
                                />
                                <select v-else id="intake_source_location" v-model="form.source_location" :class="fieldClass(form.errors.source_location)">
                                    <option value="" disabled>Select office</option>
                                    <option v-for="dept in departments" :key="dept.id" :value="dept.id">{{ dept.name }}</option>
                                </select>
                                <InputError v-if="form.source_type === 'Internal'" class="mt-1 text-xs" :message="form.errors.source_location" />
                            </div>
                            <div>
                                <label for="intake_gender" :class="labelClass">Gender</label>
                                <select id="intake_gender" v-model="form.gender" :class="fieldClass(form.errors.gender)">
                                    <option value="" disabled>Select one</option>
                                    <option value="Male">Male</option>
                                    <option value="Female">Female</option>
                                </select>
                                <InputError class="mt-1 text-xs" :message="form.errors.gender" />
                            </div>
                            <div class="col-span-2">
                                <label for="intake_source_name" :class="labelClass">Source Name</label>
                                <input
                                    id="intake_source_name"
                                    v-model="form.source_name"
                                    type="text"
                                    placeholder="Name/Company/Agency/Office"
                                    :class="fieldClass(form.errors.source_name)"
                                />
                                <InputError class="mt-1 text-xs" :message="form.errors.source_name" />
                            </div>
                            <div>
                                <label for="intake_contact_no" :class="labelClass">Contact No.</label>
                                <input id="intake_contact_no" v-model="form.contact_no" type="text" placeholder="Optional" :class="fieldClass(form.errors.contact_no)" />
                                <InputError class="mt-1 text-xs" :message="form.errors.contact_no" />
                            </div>
                            <div>
                                <label for="intake_email" :class="labelClass">Email Address</label>
                                <input id="intake_email" v-model="form.email" type="email" placeholder="Optional" :class="fieldClass(form.errors.email)" />
                                <InputError class="mt-1 text-xs" :message="form.errors.email" />
                            </div>
                        </div>

                        <!-- STEP 5: what it is about -->
                        <h3 :class="stepClass"><span :class="stepNumberClass">5</span> Subject</h3>
                        <div>
                            <label for="intake_subject_matter" :class="labelClass">Subject Matter</label>
                            <textarea
                                id="intake_subject_matter"
                                v-model="form.subject_matter"
                                rows="2"
                                :class="fieldClass(form.errors.subject_matter)"
                            ></textarea>
                            <InputError class="mt-1 text-xs" :message="form.errors.subject_matter" />
                        </div>

                        <!-- STEP 6: where it goes, then save -->
                        <h3 :class="stepClass"><span :class="stepNumberClass">6</span> Send to</h3>
                        <div class="flex flex-wrap items-center gap-2">
                            <label for="intake_direct_to" class="sr-only">Direct to</label>
                            <select
                                id="intake_direct_to"
                                v-model="directTo"
                                aria-describedby="intake_direct_to_hint"
                                class="!w-auto"
                                :class="fieldClass(form.errors.department_id)"
                            >
                                <option value="ADM">City Administrator</option>
                                <option value="OCM">City Mayor</option>
                                <option value="departments">Departments</option>
                            </select>
                            <template v-if="directTo === 'departments'">
                                <span v-if="form.routed_to.length" class="text-sm text-slate-700">
                                    <template v-for="(id, index) in form.routed_to" :key="id">
                                        <span :class="id === form.primary_office_id ? 'font-semibold' : ''">{{ officeCode(id) }}</span
                                        ><span v-if="id === form.primary_office_id" class="text-xs text-emerald-700"> (Primary)</span
                                        >{{ index < form.routed_to.length - 1 ? ', ' : '' }}
                                    </template>
                                </span>
                                <span v-else class="text-sm text-slate-500">No departments chosen</span>
                                <button
                                    type="button"
                                    class="rounded-md border border-slate-300 px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50"
                                    @click="showOfficePicker = true"
                                >
                                    {{ form.routed_to.length ? 'Change' : 'Choose offices' }}
                                </button>
                            </template>
                            <button
                                type="submit"
                                :disabled="form.processing || isAnalyzing"
                                class="ml-auto rounded-md bg-emerald-600 px-4 py-1.5 text-sm font-medium text-white transition hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-600 focus:ring-offset-1 disabled:opacity-50"
                            >
                                Save Document
                            </button>
                        </div>
                        <p v-if="executiveSuggestion" id="intake_direct_to_hint" class="-mt-1 text-xs text-slate-500">
                            <Sparkles class="inline h-3 w-3 text-emerald-600" aria-hidden="true" />
                            AI suggests {{ executiveSuggestion.route }}: {{ executiveSuggestion.reason }}
                        </p>
                        <InputError
                            class="text-xs"
                            :message="form.errors.department_id || form.errors.routed_to || form.errors['routed_to.0'] || form.errors.primary_office_id"
                        />
                    </form>
                </section>

                <!-- RIGHT PANEL: DATATABLE -->
                <section class="flex flex-col rounded-lg border border-slate-200 bg-white lg:col-span-6" aria-label="Registered documents">
                    <div class="border-b border-slate-200 px-4 py-3">
                        <div class="relative w-full max-w-sm">
                            <Search class="absolute top-1/2 left-2.5 h-4 w-4 -translate-y-1/2 text-slate-400" aria-hidden="true" />
                            <input
                                v-model="searchTerm"
                                type="text"
                                placeholder="Search barcode or subject..."
                                aria-label="Search documents"
                                class="block w-full rounded-md border border-slate-300 bg-white py-1.5 pr-8 pl-8 text-sm placeholder-slate-400 focus:border-emerald-600 focus:ring-1 focus:ring-emerald-600 focus:outline-none"
                            />
                            <button
                                v-if="searchTerm"
                                type="button"
                                aria-label="Clear search"
                                class="absolute top-1/2 right-2.5 -translate-y-1/2 text-slate-400 hover:text-slate-600"
                                @click="clearSearch"
                            >
                                ×
                            </button>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm text-slate-700">
                            <thead class="border-b border-slate-200 text-xs text-slate-500 uppercase">
                                <tr>
                                    <th class="px-4 py-2 font-medium">Barcode</th>
                                    <th class="px-4 py-2 font-medium">Subject Matter</th>
                                    <th class="px-4 py-2 font-medium">Source Name</th>
                                    <th class="px-4 py-2 font-medium">Status</th>
                                    <th class="px-4 py-2 text-center font-medium">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <tr v-for="doc in documents.data" :key="doc.id" class="hover:bg-slate-50">
                                    <td class="px-4 py-2.5 font-mono whitespace-nowrap">
                                        <Link :href="route('documents.show', doc.id)" class="text-emerald-700 hover:underline">{{ doc.barcode }}</Link>
                                    </td>
                                    <td class="px-4 py-2.5">
                                        <p class="line-clamp-2 text-slate-800" :title="doc.subject_matter">{{ doc.subject_matter }}</p>
                                        <p class="mt-0.5 text-xs text-slate-500">
                                            {{ doc.document_type?.document_type }}
                                            · {{ doc.route_mode === 'paperless' ? 'Paperless' : 'Paper' }}
                                            <span v-if="doc.is_confidential" class="font-medium text-amber-700">· Confidential</span>
                                        </p>
                                    </td>
                                    <td class="px-4 py-2.5 whitespace-nowrap text-slate-600">{{ doc.source_name }}</td>
                                    <td class="px-4 py-2.5 whitespace-nowrap">
                                        <p :class="doc.status === 'Completed' ? 'text-emerald-700' : 'text-slate-700'">{{ doc.status || 'Pending' }}</p>
                                        <!-- Where it is now; Records can route it only when no approval is pending and Records is Primary -->
                                        <p
                                            v-if="pendingApprovalLabels[doc.stage] || !holdsCopy(doc, $page.props.auth.user.department_id)"
                                            class="text-xs text-slate-500"
                                            :title="holderNames(doc)"
                                        >
                                            {{ pendingApprovalLabels[doc.stage] || `With ${holderCodes(doc)}` }}
                                        </p>
                                        <p v-if="doc.is_delayed" class="flex items-center gap-1 text-xs font-medium text-rose-700">
                                            <AlertCircle class="h-3 w-3" aria-hidden="true" /> Delayed
                                        </p>
                                        <p v-if="doc.is_delinquent" class="flex items-center gap-1 text-xs font-medium text-amber-700">
                                            <Clock class="h-3 w-3" aria-hidden="true" /> Delinquent
                                        </p>
                                    </td>
                                    <td class="px-4 py-2.5 whitespace-nowrap">
                                        <!-- Same-size buttons, centered: Route (only when Records may route it), then Edit -->
                                        <div class="flex items-center justify-center gap-1.5">
                                            <button
                                                v-if="!pendingApprovalLabels[doc.stage] && holdsCopy(doc, $page.props.auth.user.department_id)"
                                                type="button"
                                                :class="iconButtonClass"
                                                title="Route"
                                                aria-label="Route document"
                                                @click="openRoutingModal(doc)"
                                            >
                                                <FastForward class="h-4 w-4" aria-hidden="true" />
                                            </button>

                                            <Link :href="route('documents.edit', doc.id)" :class="iconButtonClass" title="Edit" aria-label="Edit document">
                                                <Pencil class="h-4 w-4" aria-hidden="true" />
                                            </Link>
                                        </div>
                                    </td>
                                </tr>
                                <tr v-if="documents.data.length === 0">
                                    <td colspan="5" class="py-10 text-center text-slate-500">No documents found.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div v-if="documents.last_page > 1" class="mt-auto flex items-center justify-between border-t border-slate-200 px-4 py-2.5">
                        <p class="text-sm text-slate-500">Showing {{ documents.from }} to {{ documents.to }} of {{ documents.total }}</p>
                        <div class="flex gap-1">
                            <button
                                v-for="(link, index) in documents.links.slice(1, -1)"
                                :key="index"
                                type="button"
                                :disabled="!link.url"
                                :aria-current="link.active ? 'page' : undefined"
                                class="rounded-md border px-2.5 py-0.5 text-sm transition"
                                :class="link.active ? 'border-emerald-600 bg-emerald-600 text-white' : 'border-slate-200 text-slate-600 hover:bg-slate-50'"
                                @click="goToPage(String(link.url))"
                            >
                                {{ link.label }}
                            </button>
                        </div>
                    </div>
                </section>
            </div>

            <!-- Intake straight to departments: Primary office + view-only copies -->
            <div v-if="showOfficePicker" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 px-4" @keydown.esc="showOfficePicker = false">
                <div class="flex max-h-[90vh] w-full max-w-3xl flex-col rounded-lg border border-slate-200 bg-white" role="dialog" aria-modal="true" aria-labelledby="office-picker-title">
                    <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
                        <h2 id="office-picker-title" class="text-base font-semibold text-slate-800">Send to departments</h2>
                        <button type="button" class="rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Close" @click="showOfficePicker = false">
                            <X class="h-5 w-5" aria-hidden="true" />
                        </button>
                    </div>
                    <div class="flex min-h-0 flex-1 flex-col p-4">
                        <OfficePicker
                            v-model="form.routed_to"
                            v-model:primary="form.primary_office_id"
                            :offices="intakeOffices"
                            :suggestions="pickerSuggestions"
                            :single="form.is_confidential"
                        />
                    </div>
                    <div class="flex justify-end border-t border-slate-200 px-4 py-3">
                        <button type="button" class="rounded-md bg-emerald-600 px-4 py-1.5 text-sm font-medium text-white hover:bg-emerald-700" @click="showOfficePicker = false">
                            Done
                        </button>
                    </div>
                </div>
            </div>

            <RoutingModal :show="showRoutingModal" :document="documentToRoute" :departments="departments" @close="showRoutingModal = false" />
        </div>
    </AppLayout>
</template>
