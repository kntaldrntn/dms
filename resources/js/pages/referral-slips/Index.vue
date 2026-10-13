<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { FileSignature, Sparkles, X, CheckCircle2 } from 'lucide-vue-next';
import { Notyf } from 'notyf';
import 'notyf/notyf.min.css';
import axios from 'axios';
import * as pdfjsLib from 'pdfjs-dist';

pdfjsLib.GlobalWorkerOptions.workerSrc = `//cdnjs.cloudflare.com/ajax/libs/pdf.js/${pdfjsLib.version}/pdf.worker.min.mjs`;

const props = defineProps<{
    documents: any;
    departments: Array<{ id: number; name: string; code: string }>;
    approvalMode?: 'referral' | 'ocm';
    flash?: { success?: string; error?: string };
}>();

const showModal = ref(false);
const selectedDoc = ref<any>(null);
const aiSuggestionsMap = ref<Record<number, { reason: string, priority: string }>>({});

// --- NOTIFICATIONS ---
const notyf = new Notyf({ duration: 4000, position: { x: 'right', y: 'top' } });
watch(() => props.flash, (flash) => {
    if (flash?.success) notyf.success(flash.success);
    if (flash?.error) notyf.error(flash.error);
}, { immediate: true, deep: true });

const breadcrumbs = [
    { title: props.approvalMode === 'ocm' ? 'OCM Approvals' : 'Referral Slips', href: props.approvalMode === 'ocm' ? '/ocm-approvals' : '/referral-slips' },
];

const actionOptions = [
    { value: 'for_approval', label: 'For Approval' },
    { value: 'for_signature', label: 'For Signature' },
    { value: 'for_information_reference', label: 'For Information / Reference' },
    { value: 'for_appropriate_action', label: 'For Appropriate Action' },
    { value: 'for_review_evaluation', label: 'For Review / Evaluation' },
    { value: 'lets_discuss_see_me', label: "Let's Discuss / See Me" },
    { value: 'please_attend', label: 'Please Attend' },
    { value: 'please_handle', label: 'Please Handle' },
    { value: 'for_dissemination', label: 'For Dissemination' },
    { value: 'return_documents_to_me', label: 'Return Document/s to Me' },
    { value: 'for_comment_recommendation', label: 'For Comment / Recommendation' },
    { value: 'other', label: 'Others (specify)' },
];

const form = useForm({
    targets: [] as number[],
    required_actions: [] as string[],
    other_action: '',
    concerned_personnel: '',
    remarks: '',
    attach_signature: false,
    workflow_version: 0,
    stamp_placement: null as { page: number; x: number; y: number; width: number; height: number } | null,
});

function openModal(doc: any) {
    selectedDoc.value = doc;
    form.reset();
    form.workflow_version = doc.workflow_version;
    form.clearErrors();

    let suggestions = doc.ai_routing_suggestions || [];
    if (typeof suggestions === 'string') {
        try { suggestions = JSON.parse(suggestions); } catch { suggestions = []; }
    }

    const suggestionsMap: Record<number, { reason: string, priority: string }> = {};
    const suggestedIds: number[] = [];
    
    suggestions.forEach((s: any) => {
        const id = Number(s.department_id);
        suggestionsMap[id] = { reason: s.reason, priority: s.priority };
        suggestedIds.push(id);
    });

    aiSuggestionsMap.value = suggestionsMap;
    form.targets = suggestedIds; // Auto-tick AI suggestions

    showModal.value = true;
}

async function findOcmStampPlacement() {
    if (!selectedDoc.value?.attached_file?.toLowerCase().endsWith('.pdf')) {
        throw new Error('OCM approval/signature requires the document to have an uploaded PDF.');
    }
    const response = await fetch(`/storage/${selectedDoc.value.attached_file}`);
    if (!response.ok) throw new Error('The uploaded PDF could not be read for AI placement analysis.');
    const pdf = await pdfjsLib.getDocument({ data: await response.arrayBuffer() }).promise;
    const payload = new FormData();
    const pageCount = Math.min(pdf.numPages, 5);
    for (let pageNumber = 1; pageNumber <= pageCount; pageNumber++) {
        const page = await pdf.getPage(pageNumber);
        const viewport = page.getViewport({ scale: 1.5 });
        const canvas = document.createElement('canvas');
        canvas.width = viewport.width;
        canvas.height = viewport.height;
        await page.render({ canvasContext: canvas.getContext('2d')!, viewport }).promise;
        const blob = await new Promise<Blob>((resolve, reject) => canvas.toBlob(value => value ? resolve(value) : reject(new Error('Unable to prepare a PDF page preview.')), 'image/jpeg', 0.82));
        payload.append('thumbnails[]', new File([blob], `page-${pageNumber}.jpg`, { type: 'image/jpeg' }));
        payload.append('pages[]', JSON.stringify({ page: pageNumber }));
    }
    // Laravel accepts an array of fields, so send the page metadata as indexed objects.
    payload.delete('pages[]');
    for (let pageNumber = 1; pageNumber <= pageCount; pageNumber++) payload.append(`pages[${pageNumber - 1}][page]`, String(pageNumber));
    const result = await axios.post(route('referral-slips.stamp-placement', selectedDoc.value.id), payload, { headers: { 'Content-Type': 'multipart/form-data' } });
    return result.data.placement;
}

async function submitSlip() {
    const needsOcmStamp = selectedDoc.value?.requires_signature_from === 'city_mayor' && form.attach_signature;
    try {
        form.stamp_placement = needsOcmStamp ? await findOcmStampPlacement() : null;
    } catch (error: any) {
        notyf.error(error?.response?.data?.error || error?.message || 'AI could not find a safe signature area.');
        return;
    }
    form.post(route('referral-slips.generate', selectedDoc.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            showModal.value = false;
        },
        onError: () => {
            notyf.error('Please check the form for errors.');
        }
    });
}
</script>

<template>
    <Head :title="approvalMode === 'ocm' ? 'OCM Approvals' : 'Referral Slips'" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-6 w-full mx-auto bg-slate-100 min-h-screen">
            <h1 class="text-2xl font-bold text-slate-800">{{ approvalMode === 'ocm' ? 'OCM Approval Queue' : 'Admin Referral Slips' }}</h1>
            <p class="text-sm text-slate-500 -mt-4">{{ approvalMode === 'ocm' ? 'Approve OCM documents and digitally stamp their uploaded PDF when authorized.' : 'Generate and authorize routing slips for incoming documents.' }}</p>

            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                <table class="w-full text-left text-sm text-slate-700">
                    <thead class="bg-slate-100 text-xs text-slate-600 uppercase">
                        <tr>
                            <th class="px-4 py-3 font-bold">Barcode</th>
                            <th class="px-4 py-3 font-bold">Subject Matter</th>
                            <th class="px-4 py-3 text-center font-bold">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                        <tr v-for="doc in documents.data" :key="doc.id" class="hover:bg-slate-50">
                            <td class="px-4 py-3 font-mono font-bold text-emerald-600">{{ doc.barcode }}</td>
                            <td class="px-4 py-3">{{ doc.subject_matter }}</td>
                            <td class="px-4 py-3 text-center">
                                <button @click="openModal(doc)" class="bg-indigo-50 text-indigo-700 px-4 py-1.5 rounded-lg font-bold hover:bg-indigo-100 border border-indigo-100">
                                    {{ approvalMode === 'ocm' ? 'Review & Approve' : 'Review & Generate Slip' }}
                                </button>
                            </td>
                        </tr>
                        <tr v-if="documents.data.length === 0">
                            <td colspan="3" class="py-12 text-center text-slate-500">No pending documents for Referral.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Generate Slip Modal -->
            <Transition name="fade">
                <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 backdrop-blur-sm px-4">
                    <div class="w-full max-w-3xl overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl flex flex-col">
                        <div class="px-6 py-4 border-b border-slate-200 bg-slate-50 flex justify-between items-center">
                            <h2 class="text-lg font-bold text-slate-800 flex items-center gap-2">
                                <FileSignature class="w-5 h-5 text-indigo-600"/> {{ approvalMode === 'ocm' ? 'OCM Approval' : 'Generate Slip' }}: {{ selectedDoc?.barcode }}
                            </h2>
                            <button @click="showModal = false" class="text-slate-400 hover:text-slate-600"><X class="w-5 h-5"/></button>
                        </div>

                        <div class="p-6 overflow-y-auto max-h-[65vh] bg-white">
                            <div class="mb-5 rounded-xl border border-indigo-100 bg-indigo-50 p-4">
                                <p class="text-xs font-bold uppercase tracking-wide text-indigo-800">Background Summary</p>
                                <p class="mt-1 text-sm font-semibold text-slate-800">{{ selectedDoc?.subject_matter }}</p>
                                <p class="mt-1 text-xs text-slate-600">From: {{ selectedDoc?.source_name }}</p>
                            </div>
                            <h3 class="text-sm font-bold text-slate-800 mb-3">Target Departments <span class="text-rose-500">*</span></h3>
                            
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-1">
                                <label v-for="dept in departments" :key="dept.id" 
                                    class="flex items-start gap-3 p-3 rounded-xl border transition cursor-pointer" 
                                :class="form.errors.targets ? 'border-red-300 bg-red-50' : (form.targets.includes(dept.id) ? 'bg-indigo-50 border-indigo-400 shadow-sm' : 'bg-white border-slate-300 hover:border-indigo-300 hover:bg-indigo-50/40 shadow-sm')">
                                    <input type="checkbox" v-model="form.targets" :value="dept.id" class="mt-0.5 rounded text-indigo-600 focus:ring-indigo-500" />
                                    <div class="flex flex-col">
                                        <span class="text-sm font-bold text-slate-800">{{ dept.code || dept.name }}</span>
                                        <span v-if="aiSuggestionsMap[dept.id]" class="text-[10px] text-emerald-600 font-bold"><Sparkles class="w-3 h-3 inline"/> {{ aiSuggestionsMap[dept.id].priority }} Match</span>
                                    </div>
                                </label>
                            </div>
                            <p v-if="form.errors.targets" class="text-[10px] text-red-500 mb-4 font-bold">{{ form.errors.targets }}</p>
                            <div v-else class="mb-6"></div>

                            <h3 class="text-sm font-bold text-slate-800 mb-3">Required Action <span class="text-rose-500">*</span></h3>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 mb-1">
                                <label v-for="action in actionOptions" :key="action.value" class="flex items-center gap-2.5 p-2.5 rounded-lg border cursor-pointer transition"
                                    :class="form.errors.required_actions ? 'border-red-300 bg-red-50' : (form.required_actions.includes(action.value) ? 'bg-indigo-50 border-indigo-400 shadow-sm' : 'bg-white border-slate-300 hover:border-indigo-300 hover:bg-indigo-50/40 shadow-sm')">
                                    <input v-model="form.required_actions" type="checkbox" :value="action.value" class="rounded text-indigo-600 focus:ring-indigo-500" />
                                    <span class="text-xs font-semibold text-slate-700">{{ action.label }}</span>
                                </label>
                            </div>
                            <p v-if="form.errors.required_actions" class="text-[10px] text-red-500 mb-3 font-bold">{{ form.errors.required_actions }}</p>
                            <div v-else class="mb-3"></div>

                            <div v-if="form.required_actions.includes('other')" class="mb-4">
                                <label class="mb-1 block text-sm font-bold text-slate-700">Other Required Action <span class="text-rose-500">*</span></label>
                                <input v-model="form.other_action" type="text" maxlength="255" class="w-full rounded-xl border bg-white px-3 py-2 text-sm shadow-sm outline-none transition focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500" :class="form.errors.other_action ? 'border-red-500' : 'border-slate-300'" placeholder="Specify the required action..." />
                                <p v-if="form.errors.other_action" class="text-[10px] text-red-500 mt-1 font-bold">{{ form.errors.other_action }}</p>
                            </div>

                            <div class="mb-4">
                                <label class="mb-1 block text-sm font-bold text-slate-700">Concerned Personnel</label>
                                <input v-model="form.concerned_personnel" type="text" maxlength="255" class="w-full rounded-xl border bg-white px-3 py-2 text-sm shadow-sm outline-none transition focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500" :class="form.errors.concerned_personnel ? 'border-red-500' : 'border-slate-300'" placeholder="Optional person or position responsible..." />
                                <p v-if="form.errors.concerned_personnel" class="text-[10px] text-red-500 mt-1 font-bold">{{ form.errors.concerned_personnel }}</p>
                            </div>

                            <label class="mb-1 block text-sm font-bold text-slate-700">Admin Remarks / Directives</label>
                            <textarea v-model="form.remarks" rows="2" 
                                class="w-full rounded-xl border bg-white px-3 py-2 text-sm shadow-sm outline-none transition-colors mb-1"
                                :class="form.errors.remarks ? 'border-red-500 focus:border-red-500 focus:ring-1 focus:ring-red-500' : 'border-slate-300 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500'"
                                placeholder="Optional instructions for the target departments..."></textarea>
                            <p v-if="form.errors.remarks" class="text-[10px] text-red-500 mb-4 font-bold">{{ form.errors.remarks }}</p>
                            <div v-else class="mb-4"></div>

                            <div v-if="form.required_actions.length" class="mb-4 rounded-xl border border-indigo-100 bg-indigo-50 p-3">
                                <p class="text-[10px] font-bold uppercase tracking-wide text-indigo-700">Slip Preview - Required Action</p>
                                <p class="mt-1 text-xs font-semibold text-slate-700">{{ actionOptions.filter(action => form.required_actions.includes(action.value)).map(action => action.label).join('; ') }}<span v-if="form.other_action">: {{ form.other_action }}</span></p>
                            </div>

                            <label class="flex items-center gap-3 p-3 rounded-xl border transition cursor-pointer" 
                                :class="form.errors.attach_signature ? 'border-red-300 bg-red-50' : (form.attach_signature ? 'bg-amber-50 border-amber-300 shadow-sm' : 'bg-white border-slate-300 hover:border-amber-300 shadow-sm')">
                                <input type="checkbox" v-model="form.attach_signature" class="rounded text-amber-600 focus:ring-amber-500 w-4 h-4" />
                                <div class="flex flex-col">
                                    <span class="text-sm font-bold text-slate-800 flex items-center gap-1.5">
                                        <FileSignature class="w-4 h-4 text-amber-600"/> {{ approvalMode === 'ocm' ? 'Digitally Sign and Stamp PDF' : 'Attach Authorized Signature' }}
                                    </span>
                                    <span class="text-xs text-slate-500 mt-0.5">{{ approvalMode === 'ocm' ? 'Uses AI to find blank space, then visibly stamps and cryptographically signs the uploaded PDF.' : 'Applies your digital signature to officially approve this routing slip.' }}</span>
                                </div>
                            </label>
                            <p v-if="form.errors.attach_signature" class="text-[10px] text-red-500 mt-1 font-bold">{{ form.errors.attach_signature }}</p>
                        </div>

                        <div class="px-6 py-4 border-t bg-slate-50 flex justify-end gap-3 rounded-b-2xl">
                            <button @click="showModal = false" class="px-5 py-2 bg-white border border-slate-200 rounded-xl font-medium text-slate-700 hover:bg-slate-100 transition">Cancel</button>
                            <button @click="submitSlip" :disabled="form.processing" class="px-5 py-2 bg-indigo-600 text-white rounded-xl font-bold hover:bg-indigo-700 flex items-center gap-2 transition disabled:opacity-50">
                                <CheckCircle2 class="w-4 h-4" /> Approve & Return to Records
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
