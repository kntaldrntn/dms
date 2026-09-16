<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Save, ArrowLeft, Loader2, Link as LinkIcon, FileUp, Download } from 'lucide-vue-next';
import { ref, watch } from 'vue';
import { Notyf } from 'notyf';
import 'notyf/notyf.min.css';
import axios from 'axios';

const props = defineProps<{
    document: any;
    transactionTypes: Array<{ id: number; name: string }>;
    documentTypes: Array<{ id: number; document_type: string }>;
    classifications: Array<{ id: number; name: string }>;
    deliveryMethods: Array<{ id: number; delivery_method_name: string }>;
    departments: Array<{ id: number; name: string }>;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'System Documents', href: '/documents' },
    { title: `Edit: ${props.document.barcode}`, href: `/documents/${props.document.id}/edit` },
];

const notyf = new Notyf({ duration: 3000, position: { x: 'right', y: 'top' } });

// --- FORM STATE (Pre-filled for editing) ---
const form = useForm({
    _method: 'put',
    barcode: props.document.barcode,
    transaction_type_id: props.document.transaction_type_id,
    document_type_id: props.document.document_type_id,
    source_type: props.document.source_type,
    source_location: props.document.source_location || 'N/A',  
    classification_id: props.document.classification_id || '',
    delivery_method_id: props.document.delivery_method_id || '',
    source_name: props.document.source_name,
    gender: props.document.gender || '',
    contact_no: props.document.contact_no || '',
    email: props.document.email || '',
    subject_matter: props.document.subject_matter,
    
    // Existing data bindings
    linked_documents: props.document.linked_documents || '', 
    attached_file: null as File | null, 

    department_id: props.document.department_id,
    access_code: props.document.access_code,
});

watch(() => form.source_type, (newType) => {
    if (newType === 'External') form.source_location = 'N/A';
    else if (newType === 'Internal' && props.document.source_type !== 'Internal') form.source_location = ''; 
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

function updateDocument() {
    form.post(route('documents.update', props.document.id), {
        preserveScroll: true,
        onSuccess: () => {
            notyf.success('Document updated successfully.');
        }
    });
}
</script>

<template>
    <Head :title="`Edit Document - ${document.barcode}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-6 w-full mx-auto bg-slate-100 min-h-screen">
            
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-slate-800 tracking-tight">Edit Document Registration</h1>
                    <p class="text-sm text-slate-500 mt-1">Update the details or routing information for {{ document.barcode }}.</p>
                </div>
                <Link :href="route('documents.index')" class="flex items-center gap-2 text-sm font-medium text-slate-600 hover:text-emerald-600 transition">
                    <ArrowLeft class="w-4 h-4" /> Back to Dashboard
                </Link>
            </div>

            <!-- Centered Edit Form -->
            <div class="max-w-4xl mx-auto w-full bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                <form @submit.prevent="updateDocument" class="p-6 flex flex-col gap-5">
                    
                    <!-- Row 1 -->
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1.5">Barcode</label>
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

                    <!-- Row 2 -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1.5">Source Type</label>
                            <select v-model="form.source_type" class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 shadow-sm">
                                <option value="External">External</option>
                                <option value="Internal">Internal</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1.5">Source Location</label>
                            <input v-if="form.source_type === 'External'" v-model="form.source_location" type="text" disabled class="w-full rounded-xl border border-slate-200 px-4 py-2.5 bg-slate-100 text-sm text-slate-500 cursor-not-allowed shadow-sm" />
                            <select v-else v-model="form.source_location" class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 shadow-sm" required>
                                <option value="" disabled>Select Department</option>
                                <option v-for="dept in departments" :key="dept.id" :value="dept.id">{{ dept.name }}</option>
                            </select>
                        </div>
                    </div>

                    <!-- Row 3 & 4 (Combined into a cleaner layout) -->
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1.5">Classification</label>
                            <select v-model="form.classification_id" class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 shadow-sm">
                                <option value="" disabled>Select One</option>
                                <option v-for="classif in classifications" :key="classif.id" :value="classif.id">{{ classif.name }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1.5">Delivery Method</label>
                            <select v-model="form.delivery_method_id" class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 shadow-sm">
                                <option value="" disabled>Select One</option>
                                <option v-for="method in deliveryMethods" :key="method.id" :value="method.id">{{ method.delivery_method_name }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1.5">Gender</label>
                            <select v-model="form.gender" class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 shadow-sm">
                                <option value="" disabled>Select Gender</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                            </select>
                        </div>
                    </div>

                    <!-- Source Identity -->
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div class="md:col-span-1">
                            <label class="block text-sm font-medium text-slate-700 mb-1.5">Source Name</label>
                            <input v-model="form.source_name" type="text" class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 shadow-sm" required />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1.5">Contact No.</label>
                            <input v-model="form.contact_no" type="text" class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 shadow-sm" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1.5">Email</label>
                            <input v-model="form.email" type="email" class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 shadow-sm" />
                        </div>
                    </div>

                    <!-- Subject Matter -->
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1.5">Subject Matter</label>
                        <textarea v-model="form.subject_matter" rows="3" class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 shadow-sm" required></textarea>
                    </div>

                    <hr class="border-slate-100" />

                    <!-- Attachment Section -->
                    <div class="bg-slate-50/50 p-4 rounded-xl border border-slate-100 flex flex-col gap-4">
                        
                        <!-- Show Existing File if Present -->
                        <div v-if="document.attached_file" class="flex items-center justify-between p-3 bg-white border border-slate-200 rounded-lg shadow-sm">
                            <div class="flex items-center gap-2">
                                <FileUp class="w-5 h-5 text-emerald-600" />
                                <div>
                                    <p class="text-sm font-semibold text-slate-800">Scanned Document Exists</p>
                                    <p class="text-xs text-slate-500">You can upload a new one to replace it.</p>
                                </div>
                            </div>
                            <a :href="`/storage/${document.attached_file}`" target="_blank" class="flex items-center gap-1.5 text-xs font-medium text-emerald-700 bg-emerald-50 hover:bg-emerald-100 px-3 py-1.5 rounded-lg border border-emerald-200 transition">
                                <Download class="w-3.5 h-3.5" /> View File
                            </a>
                        </div>

                        <!-- Manual Upload replacement -->
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1.5">
                                {{ document.attached_file ? 'Replace File (Optional)' : 'Upload Scanned File (Optional)' }}
                            </label>
                            <input type="file" accept=".pdf,.jpg,.jpeg,.png" @change="(e: any) => form.attached_file = e.target.files[0]" class="block w-full text-sm text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-sm file:font-semibold file:bg-slate-100 file:text-slate-700 hover:file:bg-slate-200 border border-slate-300 rounded-xl shadow-sm bg-white" />
                        </div>
                    </div>

                    <!-- Smart Barcode Search -->
                    <div class="relative">
                        <label class="block text-sm font-medium text-slate-700 mb-1.5">Linked Barcode/s (Optional)</label>
                        <div class="relative">
                            <LinkIcon class="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-slate-400" />
                            <input 
                                id="linked_documents_input"
                                v-model="form.linked_documents" 
                                @input="handleLinkedDocumentInput"
                                type="text" 
                                autocomplete="off"
                                placeholder="Type barcode to search, separate multiple with space" 
                                class="w-full rounded-xl border border-slate-300 py-2.5 pl-9 pr-3 text-sm focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 shadow-sm" 
                            />
                        </div>
                        <div v-if="showBarcodeDropdown" class="absolute z-10 mt-1 w-full bg-white rounded-xl shadow-lg border border-slate-200 overflow-hidden">
                            <ul class="max-h-48 overflow-y-auto py-1">
                                <li v-for="res in barcodeResults" :key="res.barcode" @click="selectBarcode(res.barcode)" class="px-4 py-2 hover:bg-slate-50 cursor-pointer border-b border-slate-100 last:border-0 transition-colors">
                                    <div class="font-mono text-xs font-bold text-emerald-600">{{ res.barcode }}</div>
                                    <div class="text-xs text-slate-500 truncate">{{ res.subject_matter }}</div>
                                </li>
                            </ul>
                        </div>
                    </div>

                    <hr class="border-slate-100 mt-2" />

                    <!-- Footer Actions -->
                    <div class="flex items-center justify-between pt-2">
                        <div class="text-sm text-slate-500">
                            Access Code: <span class="font-bold text-slate-800 ml-1">{{ form.access_code }}</span>
                        </div>
                        <button type="submit" :disabled="form.processing" class="flex items-center gap-2 bg-emerald-600 text-white px-6 py-2.5 rounded-xl font-medium shadow-sm hover:bg-emerald-700 transition disabled:opacity-50">
                            <Save class="w-4 h-4" />
                            {{ form.processing ? 'Updating...' : 'Update Document' }}
                        </button>
                    </div>

                </form>
            </div>
        </div>
    </AppLayout>
</template>