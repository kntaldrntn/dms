<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Save, FileText, Clock, Building2, User, UploadCloud, Loader2, Sparkles, Info, ArrowLeft, Paperclip } from 'lucide-vue-next';
import { watch, ref } from 'vue';
import axios from 'axios';
import * as pdfjsLib from 'pdfjs-dist';
import Tesseract from 'tesseract.js';
import { BrowserMultiFormatReader } from '@zxing/browser';

pdfjsLib.GlobalWorkerOptions.workerSrc = `//cdnjs.cloudflare.com/ajax/libs/pdf.js/${pdfjsLib.version}/pdf.worker.min.mjs`;

const props = defineProps<{
    transactionTypes: Array<{ id: number; name: string }>;
    documentTypes: Array<{ id: number; document_type: string }>;
    classifications: Array<{ id: number; name: string }>;
    deliveryMethods: Array<{ id: number; name: string }>;
    departments: Array<{ id: number; name: string }>;
    generatedAccessCode: string;
    recentDocuments: Array<any>;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Documents', href: '/documents' },
    { title: 'New Document', href: '/documents/create' },
];

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
    linked_documents: null as File | null,
    department_id: '', 
    ai_routing_suggestions: props.document?.ai_routing_suggestions || [],
    access_code: props.generatedAccessCode,
});

const isAnalyzing = ref(false);
const analysisError = ref('');
const routingSuggestions = ref<Array<{ department_id: number, reason: string }>>([]);
const aiMetrics = ref<{ tokens: number, confidence: number } | null>(null);

const getDepartmentName = (id: number) => {
    const dept = props.departments.find(d => d.id === id);
    return dept ? dept.name : 'Unknown Office';
};

watch(() => form.source_type, (newType) => {
    if (newType === 'External') {
        form.source_location = 'N/A';
    } else if (newType === 'Internal') {
        form.source_location = ''; 
    }
});

const handleFileUpload = async (event: Event) => {
    const target = event.target as HTMLInputElement;
    const file = target.files?.[0];
    
    const allowedTypes = ['application/pdf', 'image/jpeg', 'image/jpg', 'image/png'];
    if (!file || !allowedTypes.includes(file.type)) return;

    form.linked_documents = file; 
    
    isAnalyzing.value = true;
    routingSuggestions.value = []; 
    analysisError.value = '';
    aiMetrics.value = null;

    const payload = new FormData();
    payload.append('file', file); // Send original to storage

    try {
        let extractedText = "";
        let aiThumbnailBlob = null;

        if (file.type === 'application/pdf') {
            const arrayBuffer = await file.arrayBuffer();
            const pdf = await pdfjsLib.getDocument({ data: arrayBuffer }).promise;
            
            const numPages = pdf.numPages;
            let totalHeight = 0;
            let maxWidth = 0;
            const tempCanvases = [];

            for (let i = 1; i <= numPages; i++) {
                const page = await pdf.getPage(i);
                // 1. High-Res Scale for Tesseract to read perfectly
                const viewport = page.getViewport({ scale: 2.0 }); 
                
                const tempCanvas = document.createElement('canvas');
                tempCanvas.width = viewport.width;
                tempCanvas.height = viewport.height;
                
                await page.render({ canvasContext: tempCanvas.getContext('2d'), viewport }).promise;
                
                tempCanvases.push(tempCanvas);
                totalHeight += viewport.height;
                maxWidth = Math.max(maxWidth, viewport.width);
            }

            // Create the Master High-Res Canvas
            const masterCanvas = document.createElement('canvas');
            const masterContext = masterCanvas.getContext('2d');
            masterCanvas.width = maxWidth;
            masterCanvas.height = totalHeight;

            let currentY = 0;
            for (const c of tempCanvases) {
                masterContext?.drawImage(c, 0, currentY);
                currentY += c.height;
            }

            // 2. Run Tesseract on the High-Res Canvas
            const worker = await Tesseract.createWorker('eng');
            const ret = await worker.recognize(masterCanvas);
            extractedText = ret.data.text;
            await worker.terminate();

            

            // 3. Shrink the canvas down to a tiny 800px thumbnail to save OpenAI Tokens!
            const visionCanvas = document.createElement('canvas');
            const visionCtx = visionCanvas.getContext('2d');
            const scaleFactor = 800 / maxWidth;
            visionCanvas.width = 800;
            visionCanvas.height = totalHeight * scaleFactor;
            
            visionCtx?.drawImage(masterCanvas, 0, 0, visionCanvas.width, visionCanvas.height);
            
            aiThumbnailBlob = await new Promise<Blob>((resolve) => visionCanvas.toBlob(resolve as BlobCallback, 'image/jpeg', 0.6));

        } else {
            // Process standard images
            const worker = await Tesseract.createWorker('eng');
            const ret = await worker.recognize(file);
            extractedText = ret.data.text;
            await worker.terminate();

            const imageBitmap = await createImageBitmap(file);
            const canvas = document.createElement('canvas');
            const ctx = canvas.getContext('2d');
            
            const scaleFactor = 800 / imageBitmap.width;
            canvas.width = 800;
            canvas.height = imageBitmap.height * scaleFactor;
            ctx?.drawImage(imageBitmap, 0, 0, canvas.width, canvas.height);
            
            aiThumbnailBlob = await new Promise<Blob>((resolve) => canvas.toBlob(resolve as BlobCallback, 'image/jpeg', 0.6));
        }

        // Send the tiny thumbnail and the high-quality text to Laravel
        const compressedFile = new File([aiThumbnailBlob], file.name.replace('.pdf', '.jpg'), { type: 'image/jpeg' });
        payload.append('ai_thumbnail', compressedFile);
        payload.append('extracted_text', extractedText);

        const response = await axios.post('/documents/analyze', payload, {
            headers: { 'Content-Type': 'multipart/form-data' }
        });

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
        // if (data.department_id) form.department_id = data.department_id;
        
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
    } catch (err: any) {
        analysisError.value = err.response?.data?.error || 'Document uploaded, but automatic analysis failed. You can fill the fields manually.';
    } finally {
        isAnalyzing.value = false;
    }
};

function submitDocument() {
    form.post(route('documents.store'), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            routingSuggestions.value = [];
            analysisError.value = '';
        },
    });
}
</script>

<template>
    <Head title="New Document Intake" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="p-6 w-full mx-auto bg-slate-50/50 min-h-screen">
            
            <div class="mb-6">
                <h1 class="text-2xl font-bold text-slate-800 tracking-tight">New Document Registration</h1>
                <p class="text-sm text-slate-500 mt-1">Register and route incoming physical or digital documents.</p>
            </div>

            <div class="grid grid-cols-1 xl:grid-cols-12 gap-6">
                
                <div class="xl:col-span-8">
                    <form @submit.prevent="submitDocument" class="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden">
                        
                        <!-- AI PDF Intake Zone -->
                        <div class="p-6 border-b border-slate-100 bg-emerald-50/40">
                            <div class="flex items-center justify-between mb-3">
                                <h2 class="text-sm font-semibold text-emerald-900 flex items-center gap-2">
                                    <Sparkles class="w-4 h-4 text-emerald-600" />
                                    AI Document Analysis (GPT-4o-mini)
                                </h2>
                                <span v-if="form.linked_documents" class="text-xs text-emerald-700 font-mono">
                                    {{ form.linked_documents.name }}
                                </span>
                            </div>

                            <label class="flex flex-col items-center justify-center w-full h-28 border-2 border-dashed border-emerald-300 rounded-xl cursor-pointer bg-white hover:bg-emerald-50/50 transition">
                                <div class="flex flex-col items-center justify-center py-4">
                                    <Loader2 v-if="isAnalyzing" class="w-6 h-6 text-emerald-600 animate-spin mb-1" />
                                    <UploadCloud v-else class="w-6 h-6 text-emerald-600 mb-1" />
                                    <p class="text-xs text-slate-600">
                                        <span v-if="isAnalyzing" class="font-medium text-emerald-700">Scanning and extracting text (OCR)...</span>
                                        <span v-else class="font-medium text-slate-700">Click or drag PDF/Image here to auto-fill form</span>
                                    </p>
                                    <p class="text-[11px] text-slate-400 mt-0.5">PDF, JPG, or PNG up to 10MB</p>
                                </div>
                                <input type="file" accept=".pdf,.jpg,.jpeg,.png" class="hidden" :disabled="isAnalyzing" @change="handleFileUpload" />
                            </label>

                            <!-- Error Fallback Banner -->
                            <div v-if="analysisError" class="mt-3 p-3 bg-amber-50 border border-amber-200 rounded-xl text-xs text-amber-800">
                                {{ analysisError }}
                            </div>
                            <div v-if="aiMetrics" class="mt-4 flex items-center justify-between px-2 text-[11px] font-medium uppercase tracking-wide">
                                <div class="text-slate-500">
                                    Tokens Used: <span class="text-slate-700 font-bold">{{ aiMetrics.tokens.toLocaleString() }}</span>
                                </div>
                                <div :class="aiMetrics.confidence > 80 ? 'text-emerald-600' : 'text-amber-600'">
                                    Extraction Confidence: <span class="font-bold">{{ aiMetrics.confidence }}%</span>
                                </div>
                            </div>
                        </div>

                        <!-- Core Details -->
                        <div class="p-6 border-b border-slate-100">
                            <h2 class="text-base font-semibold text-slate-800 flex items-center gap-2 mb-5">
                                <FileText class="w-4 h-4 text-emerald-600" /> Core Details
                            </h2>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Barcode (Max 10)</label>
                                    <input v-model="form.barcode" type="text" maxlength="10" class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 shadow-sm" required />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Transaction Type</label>
                                    <select v-model="form.transaction_type_id" class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 shadow-sm" required>
                                        <option value="" disabled>Select One</option>
                                        <option v-for="type in transactionTypes" :key="type.id" :value="type.id">{{ type.name }}</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Document Type</label>
                                    <select v-model="form.document_type_id" class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 shadow-sm" required>
                                        <option value="" disabled>Select One</option>
                                        <option v-for="type in documentTypes" :key="type.id" :value="type.id">{{ type.document_type }}</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Source Information -->
                        <div class="p-6 border-b border-slate-100 bg-slate-50/30">
                            <h2 class="text-base font-semibold text-slate-800 flex items-center gap-2 mb-5">
                                <Building2 class="w-4 h-4 text-emerald-600" /> Source Information
                            </h2>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-5 mb-5">
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Source Type</label>
                                    <select v-model="form.source_type" class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 shadow-sm">
                                        <option value="External">External</option>
                                        <option value="Internal">Internal</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Source Location</label>
                                    <input v-if="form.source_type === 'External'" v-model="form.source_location" type="text" disabled class="w-full rounded-xl border border-slate-200 px-4 py-2.5 bg-slate-100 text-slate-500 text-sm shadow-sm cursor-not-allowed" />
                                    <select v-else v-model="form.source_location" class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 shadow-sm" required>
                                        <option value="" disabled>Select Department</option>
                                        <option v-for="dept in departments" :key="dept.id" :value="dept.id">{{ dept.name }}</option>
                                    </select>
                                </div>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Source Name (Agency/Company/Person)</label>
                                    <input v-model="form.source_name" type="text" class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 shadow-sm" required />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Delivery Method</label>
                                    <select v-model="form.delivery_method_id" class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 shadow-sm">
                                        <option value="" disabled>Select One</option>
                                        <option v-for="method in deliveryMethods" :key="method.id" :value="method.id">{{ method.delivery_method_name }}</option>
                                    </select>
                                </div>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-5 mt-5">
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Gender</label>
                                    <select v-model="form.gender" class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 shadow-sm">
                                        <option value="" disabled>Select Gender</option>
                                        <option value="Male">Male</option>
                                        <option value="Female">Female</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Contact No.</label>
                                    <input v-model="form.contact_no" type="text" placeholder="Contact No." class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 shadow-sm" />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Email Address</label>
                                    <input v-model="form.email" type="email" placeholder="Email Address" class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 shadow-sm" />
                                </div>
                            </div>
                        </div>

                        <!-- Routing & Content -->
                        <div class="p-6 border-b border-slate-100">
                            <h2 class="text-base font-semibold text-slate-800 flex items-center gap-2 mb-5">
                                <User class="w-4 h-4 text-emerald-600" /> Subject & Routing
                            </h2>
                            <div class="mb-5">
                                <label class="block text-sm font-medium text-slate-700 mb-1.5">Subject Matter</label>
                                <textarea v-model="form.subject_matter" rows="3" class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 shadow-sm" required></textarea>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-5 mb-5">
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Document Classification</label>
                                    <select v-model="form.classification_id" class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 shadow-sm">
                                        <option value="" disabled>Select One</option>
                                        <option v-for="classif in classifications" :key="classif.id" :value="classif.id">{{ classif.name }}</option>
                                    </select>
                                </div>
                                
                                <!-- Routing Destinations & Smart Cards -->
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Direct To (Routing)</label>
                                    <select v-model="form.department_id" class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 shadow-sm" required>
                                        <option value="" disabled>Select Destination Office</option>
                                        <option v-for="dept in departments" :key="dept.id" :value="dept.id">{{ dept.name }}</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Footer Actions -->
                        <div class="flex items-center justify-between p-6 border-t border-slate-100 bg-white">
                            <div class="text-sm">
                                <span class="text-slate-500">Access Code: </span>
                                <span class="font-bold text-slate-800">{{ form.access_code }}</span>
                            </div>
                            <button type="submit" :disabled="form.processing || isAnalyzing" class="flex items-center gap-2 bg-emerald-600 text-white px-6 py-2.5 rounded-xl font-medium shadow-sm hover:bg-emerald-700 transition disabled:opacity-50">
                                <Save class="w-4 h-4" />
                                {{ form.processing ? 'Saving...' : 'Save Document' }}
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Recent Intakes -->
                <div class="xl:col-span-4">
                    <div class="bg-white rounded-2xl border border-slate-100 shadow-sm p-6 h-full">
                        <h3 class="text-sm font-bold text-slate-800 uppercase tracking-wider flex items-center gap-2 mb-6">
                            <Clock class="w-4 h-4 text-slate-400" /> Recent Intakes
                        </h3>
                        
                        <div v-if="recentDocuments && recentDocuments.length > 0" class="flex flex-col gap-3">
                            <div v-for="doc in recentDocuments" :key="doc.id" class="p-4 border border-slate-100 rounded-xl hover:bg-slate-50 transition-colors group">
                                <div class="flex justify-between items-start mb-1.5">
                                    <Link :href="route('documents.show', doc.id)" class="text-sm font-bold font-mono text-emerald-600 group-hover:underline">
                                        {{ doc.barcode }}
                                    </Link>
                                    <span class="text-[10px] font-medium text-slate-400 bg-slate-100 px-2 py-0.5 rounded-full">
                                        {{ new Date(doc.created_at).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'}) }}
                                    </span>
                                </div>
                                <p class="text-sm text-slate-700 font-medium truncate" :title="doc.subject_matter">{{ doc.subject_matter }}</p>
                                <p class="text-xs text-slate-500 mt-1 flex items-center gap-1">
                                    <FileText class="w-3 h-3" /> {{ doc.document_type?.document_type || 'Unknown' }}
                                </p>
                            </div>
                        </div>

                        <div v-else class="flex flex-col items-center justify-center py-16 text-center">
                            <div class="w-14 h-14 bg-slate-50 rounded-full flex items-center justify-center mb-4">
                                <FileText class="w-6 h-6 text-slate-400" />
                            </div>
                            <p class="text-sm text-slate-600 font-medium">No documents processed yet.</p>
                            <p class="text-xs text-slate-400 mt-1.5 max-w-[200px] mx-auto">Saved documents will appear here automatically.</p>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </AppLayout>
</template>