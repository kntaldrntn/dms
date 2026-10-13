<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { CheckCircle2, FileSignature, FileText, PenLine, Send, Sparkles, X } from 'lucide-vue-next';
import { Notyf } from 'notyf';
import 'notyf/notyf.min.css';

const props = defineProps<{
    documents: any;
    departments: Array<{ id: number; name: string; code: string }>;
    flash?: { success?: string; error?: string };
}>();

const notyf = new Notyf({ duration: 4000, position: { x: 'right', y: 'top' } });
watch(() => props.flash, (flash) => {
    if (flash?.success) notyf.success(flash.success);
    if (flash?.error) notyf.error(flash.error);
}, { immediate: true, deep: true });

const breadcrumbs = [{ title: 'Referral Slips', href: '/referral-slips' }];

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

const selectedDoc = ref<any>(null);
const aiSuggestionsMap = ref<Record<number, { reason: string; priority: string }>>({});

// Stage 1 (City Administrator) generates the slip; stage 2 (ADM Department Head) signs it.
const isSigningStage = computed(() => selectedDoc.value?.requires_signature_from === 'department_head');

const generateForm = useForm({
    targets: [] as number[],
    required_actions: [] as string[],
    other_action: '',
    concerned_personnel: '',
    remarks: '',
    workflow_version: 0,
});

const signForm = useForm({
    signature_mode: '' as '' | 'electronic' | 'physical',
    workflow_version: 0,
});

const slipTargets = computed(() => {
    const ids: number[] = (selectedDoc.value?.approved_routing_slip || []).map(Number);
    return props.departments.filter((department) => ids.includes(department.id));
});

function openModal(doc: any) {
    selectedDoc.value = doc;

    if (doc.requires_signature_from === 'department_head') {
        signForm.reset();
        signForm.clearErrors();
        signForm.workflow_version = doc.workflow_version;
        return;
    }

    generateForm.reset();
    generateForm.clearErrors();
    generateForm.workflow_version = doc.workflow_version;

    let suggestions = doc.ai_routing_suggestions || [];
    if (typeof suggestions === 'string') {
        try { suggestions = JSON.parse(suggestions); } catch { suggestions = []; }
    }
    // Only offices the slip can target count: the AI sometimes suggests ADM itself, which is not
    // listed and would otherwise be pre-ticked invisibly and fail validation on submit.
    const selectableIds = props.departments.map((department) => department.id);
    const suggestionsMap: Record<number, { reason: string; priority: string }> = {};
    (Array.isArray(suggestions) ? suggestions : []).forEach((suggestion: any) => {
        const departmentId = Number(suggestion?.department_id);
        if (selectableIds.includes(departmentId)) {
            suggestionsMap[departmentId] = { reason: suggestion.reason || '', priority: suggestion.priority || 'Suggested' };
        }
    });
    aiSuggestionsMap.value = suggestionsMap;
    generateForm.targets = Object.keys(suggestionsMap).map(Number); // Pre-tick AI suggestions
}

const hasAiSuggestions = computed(() => Object.keys(aiSuggestionsMap.value).length > 0);

function closeModal() {
    selectedDoc.value = null;
}

function submitGenerate() {
    generateForm.post(route('referral-slips.generate', selectedDoc.value.id), {
        preserveScroll: true,
        onSuccess: closeModal,
        onError: () => notyf.error('Please check the form for errors.'),
    });
}

function submitSignature() {
    signForm.post(route('referral-slips.sign', selectedDoc.value.id), {
        preserveScroll: true,
        onSuccess: closeModal,
        onError: () => notyf.error('Please check the form for errors.'),
    });
}
</script>

<template>
    <Head title="Referral Slips" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex min-h-screen w-full flex-col gap-6 bg-slate-100 p-6">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-slate-800">Referral Slips</h1>
                <p class="mt-1 text-sm text-slate-500">
                    The City Administrator generates the slip, then the Department Head chooses E-Signature or Physical Signature before it returns to Records.
                </p>
            </div>

            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <table class="w-full text-left text-sm text-slate-700">
                    <thead class="border-b border-slate-200 bg-slate-100 text-xs uppercase tracking-wider text-slate-600">
                        <tr>
                            <th scope="col" class="px-4 py-2.5 font-bold">Barcode</th>
                            <th scope="col" class="px-4 py-2.5 font-bold">Subject Matter</th>
                            <th scope="col" class="px-4 py-2.5 font-bold">Stage</th>
                            <th scope="col" class="px-4 py-2.5 text-center font-bold">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                        <tr v-for="doc in documents.data" :key="doc.id" class="transition-colors hover:bg-slate-50">
                            <td class="whitespace-nowrap px-4 py-3 font-mono font-semibold text-emerald-600">{{ doc.barcode }}</td>
                            <td class="px-4 py-3">
                                <p class="line-clamp-2 font-medium text-slate-800" :title="doc.subject_matter">{{ doc.subject_matter }}</p>
                                <p class="mt-0.5 text-[11px] text-slate-500">From: {{ doc.source_name }}</p>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3">
                                <span
                                    v-if="doc.requires_signature_from === 'department_head'"
                                    class="inline-flex items-center rounded border border-amber-200 bg-amber-50 px-2 py-0.5 text-[11px] font-bold text-amber-700"
                                >
                                    Awaiting Signature
                                </span>
                                <span v-else class="inline-flex items-center rounded border border-blue-200 bg-blue-50 px-2 py-0.5 text-[11px] font-bold text-blue-700">
                                    Awaiting Slip
                                </span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-center">
                                <button
                                    type="button"
                                    @click="openModal(doc)"
                                    class="rounded-lg border px-4 py-1.5 font-bold transition"
                                    :class="doc.requires_signature_from === 'department_head'
                                        ? 'border-amber-200 bg-amber-50 text-amber-700 hover:bg-amber-100'
                                        : 'border-indigo-100 bg-indigo-50 text-indigo-700 hover:bg-indigo-100'"
                                >
                                    {{ doc.requires_signature_from === 'department_head' ? 'Review & Sign' : 'Generate Slip' }}
                                </button>
                            </td>
                        </tr>
                        <tr v-if="documents.data.length === 0">
                            <td colspan="4" class="py-12 text-center text-slate-500">No documents are waiting for you.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Transition name="fade">
                <div v-if="selectedDoc" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 px-4 backdrop-blur-sm">
                    <div
                        role="dialog"
                        aria-modal="true"
                        aria-labelledby="referral-modal-title"
                        class="flex w-full max-w-3xl flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl"
                    >
                        <div class="flex items-center justify-between border-b border-slate-200 bg-slate-50 px-6 py-4">
                            <h2 id="referral-modal-title" class="flex items-center gap-2 text-lg font-bold text-slate-800">
                                <PenLine v-if="isSigningStage" class="h-5 w-5 text-amber-600" aria-hidden="true" />
                                <FileSignature v-else class="h-5 w-5 text-indigo-600" aria-hidden="true" />
                                {{ isSigningStage ? 'Sign Referral Slip' : 'Generate Referral Slip' }}: {{ selectedDoc.barcode }}
                            </h2>
                            <button type="button" @click="closeModal" class="text-slate-400 hover:text-slate-600" aria-label="Close">
                                <X class="h-5 w-5" aria-hidden="true" />
                            </button>
                        </div>

                        <div class="max-h-[65vh] overflow-y-auto bg-white p-6">
                            <div class="mb-5 rounded-xl border border-indigo-100 bg-indigo-50 p-4">
                                <p class="text-xs font-bold uppercase tracking-wide text-indigo-800">Background Summary</p>
                                <p class="mt-1 text-sm font-semibold text-slate-800">{{ selectedDoc.subject_matter }}</p>
                                <p class="mt-1 text-xs text-slate-600">From: {{ selectedDoc.source_name }}</p>
                            </div>

                            <!-- STAGE 2: Department Head chooses the signature type -->
                            <template v-if="isSigningStage">
                                <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
                                    <div>
                                        <p class="text-sm font-bold text-slate-800">Slip will dispatch to</p>
                                        <div class="mt-1 flex flex-wrap gap-1">
                                            <span
                                                v-for="target in slipTargets"
                                                :key="target.id"
                                                class="inline-flex items-center rounded border border-indigo-200 bg-indigo-50 px-2 py-0.5 text-[11px] font-bold text-indigo-700"
                                                :title="target.name"
                                            >
                                                {{ target.code || target.name }}
                                            </span>
                                        </div>
                                    </div>
                                    <a
                                        :href="route('referral-slips.preview', selectedDoc.id)"
                                        target="_blank"
                                        rel="noopener"
                                        class="inline-flex items-center gap-1.5 rounded-xl border border-violet-200 bg-violet-50 px-3 py-2 text-sm font-semibold text-violet-700 shadow-sm transition hover:bg-violet-100"
                                    >
                                        <FileText class="h-4 w-4" aria-hidden="true" /> Preview Slip PDF
                                    </a>
                                </div>

                                <fieldset>
                                    <legend class="mb-3 text-sm font-bold text-slate-800">Signature <span class="text-rose-500">*</span></legend>
                                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                        <label
                                            class="flex cursor-pointer items-start gap-3 rounded-xl border p-4 transition"
                                            :class="signForm.signature_mode === 'electronic' ? 'border-amber-400 bg-amber-50 shadow-sm' : 'border-slate-300 bg-white hover:border-amber-300'"
                                        >
                                            <input v-model="signForm.signature_mode" type="radio" value="electronic" name="signature_mode" class="mt-0.5 text-amber-600 focus:ring-amber-500" />
                                            <span>
                                                <span class="block text-sm font-bold text-slate-800">E-Signature</span>
                                                <span class="mt-0.5 block text-xs text-slate-500">Your uploaded digital signature is placed on the slip.</span>
                                            </span>
                                        </label>
                                        <label
                                            class="flex cursor-pointer items-start gap-3 rounded-xl border p-4 transition"
                                            :class="signForm.signature_mode === 'physical' ? 'border-amber-400 bg-amber-50 shadow-sm' : 'border-slate-300 bg-white hover:border-amber-300'"
                                        >
                                            <input v-model="signForm.signature_mode" type="radio" value="physical" name="signature_mode" class="mt-0.5 text-amber-600 focus:ring-amber-500" />
                                            <span>
                                                <span class="block text-sm font-bold text-slate-800">Physical Signature</span>
                                                <span class="mt-0.5 block text-xs text-slate-500">The slip prints with a blank line to be signed by hand.</span>
                                            </span>
                                        </label>
                                    </div>
                                    <p v-if="signForm.errors.signature_mode" class="mt-1 text-[10px] font-bold text-red-500" role="alert">{{ signForm.errors.signature_mode }}</p>
                                </fieldset>
                            </template>

                            <!-- STAGE 1: City Administrator generates the slip only -->
                            <template v-else>
                                <h3 class="mb-1 text-sm font-bold text-slate-800">Target Departments <span class="text-rose-500">*</span></h3>
                                <p class="mb-3 flex items-center gap-1 text-[11px]" :class="hasAiSuggestions ? 'text-emerald-600' : 'text-slate-500'">
                                    <Sparkles class="h-3 w-3" aria-hidden="true" />
                                    {{ hasAiSuggestions ? 'Pre-selected from the AI routing suggestions at intake. Adjust as needed.' : 'No AI routing suggestions for this document. Select the departments manually.' }}
                                </p>
                                <div class="mb-1 grid grid-cols-1 gap-3 sm:grid-cols-2">
                                    <label
                                        v-for="dept in departments"
                                        :key="dept.id"
                                        class="flex cursor-pointer items-start gap-3 rounded-xl border p-3 transition"
                                        :class="generateForm.errors.targets ? 'border-red-300 bg-red-50' : (generateForm.targets.includes(dept.id) ? 'border-indigo-400 bg-indigo-50 shadow-sm' : 'border-slate-300 bg-white shadow-sm hover:border-indigo-300 hover:bg-indigo-50/40')"
                                    >
                                        <input type="checkbox" v-model="generateForm.targets" :value="dept.id" class="mt-0.5 rounded text-indigo-600 focus:ring-indigo-500" />
                                        <div class="flex flex-col">
                                            <span class="text-sm font-bold text-slate-800">{{ dept.code || dept.name }}</span>
                                            <span v-if="aiSuggestionsMap[dept.id]" class="text-[10px] font-bold text-emerald-600" :title="aiSuggestionsMap[dept.id].reason">
                                                <Sparkles class="inline h-3 w-3" aria-hidden="true" /> {{ aiSuggestionsMap[dept.id].priority }} Match
                                            </span>
                                        </div>
                                    </label>
                                </div>
                                <p v-if="generateForm.errors.targets" class="mb-4 text-[10px] font-bold text-red-500">{{ generateForm.errors.targets }}</p>
                                <div v-else class="mb-6"></div>

                                <h3 class="mb-3 text-sm font-bold text-slate-800">Required Action <span class="text-rose-500">*</span></h3>
                                <div class="mb-1 grid grid-cols-1 gap-2 sm:grid-cols-2">
                                    <label
                                        v-for="action in actionOptions"
                                        :key="action.value"
                                        class="flex cursor-pointer items-center gap-2.5 rounded-lg border p-2.5 transition"
                                        :class="generateForm.errors.required_actions ? 'border-red-300 bg-red-50' : (generateForm.required_actions.includes(action.value) ? 'border-indigo-400 bg-indigo-50 shadow-sm' : 'border-slate-300 bg-white shadow-sm hover:border-indigo-300 hover:bg-indigo-50/40')"
                                    >
                                        <input v-model="generateForm.required_actions" type="checkbox" :value="action.value" class="rounded text-indigo-600 focus:ring-indigo-500" />
                                        <span class="text-xs font-semibold text-slate-700">{{ action.label }}</span>
                                    </label>
                                </div>
                                <p v-if="generateForm.errors.required_actions" class="mb-3 text-[10px] font-bold text-red-500">{{ generateForm.errors.required_actions }}</p>
                                <div v-else class="mb-3"></div>

                                <div v-if="generateForm.required_actions.includes('other')" class="mb-4">
                                    <label for="other_action" class="mb-1 block text-sm font-bold text-slate-700">Other Required Action <span class="text-rose-500">*</span></label>
                                    <input id="other_action" v-model="generateForm.other_action" type="text" maxlength="255" class="w-full rounded-xl border bg-white px-3 py-2 text-sm shadow-sm outline-none transition focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500" :class="generateForm.errors.other_action ? 'border-red-500' : 'border-slate-300'" placeholder="Specify the required action..." />
                                    <p v-if="generateForm.errors.other_action" class="mt-1 text-[10px] font-bold text-red-500">{{ generateForm.errors.other_action }}</p>
                                </div>

                                <div class="mb-4">
                                    <label for="concerned_personnel" class="mb-1 block text-sm font-bold text-slate-700">Concerned Personnel</label>
                                    <input id="concerned_personnel" v-model="generateForm.concerned_personnel" type="text" maxlength="255" class="w-full rounded-xl border bg-white px-3 py-2 text-sm shadow-sm outline-none transition focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500" :class="generateForm.errors.concerned_personnel ? 'border-red-500' : 'border-slate-300'" placeholder="Optional person or position responsible..." />
                                    <p v-if="generateForm.errors.concerned_personnel" class="mt-1 text-[10px] font-bold text-red-500">{{ generateForm.errors.concerned_personnel }}</p>
                                </div>

                                <label for="remarks" class="mb-1 block text-sm font-bold text-slate-700">Admin Remarks / Directives</label>
                                <textarea
                                    id="remarks"
                                    v-model="generateForm.remarks"
                                    rows="2"
                                    class="mb-1 w-full rounded-xl border bg-white px-3 py-2 text-sm shadow-sm outline-none transition-colors"
                                    :class="generateForm.errors.remarks ? 'border-red-500 focus:border-red-500 focus:ring-1 focus:ring-red-500' : 'border-slate-300 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500'"
                                    placeholder="Optional instructions for the target departments..."
                                ></textarea>
                                <p v-if="generateForm.errors.remarks" class="mb-4 text-[10px] font-bold text-red-500">{{ generateForm.errors.remarks }}</p>

                                <div v-if="generateForm.required_actions.length" class="mt-3 rounded-xl border border-indigo-100 bg-indigo-50 p-3">
                                    <p class="text-[10px] font-bold uppercase tracking-wide text-indigo-700">Slip Preview - Required Action</p>
                                    <p class="mt-1 text-xs font-semibold text-slate-700">
                                        {{ actionOptions.filter(action => generateForm.required_actions.includes(action.value)).map(action => action.label).join('; ') }}<span v-if="generateForm.other_action">: {{ generateForm.other_action }}</span>
                                    </p>
                                </div>
                            </template>
                        </div>

                        <div class="flex justify-end gap-3 rounded-b-2xl border-t bg-slate-50 px-6 py-4">
                            <button type="button" @click="closeModal" class="rounded-xl border border-slate-200 bg-white px-5 py-2 font-medium text-slate-700 transition hover:bg-slate-100">Cancel</button>
                            <button
                                v-if="isSigningStage"
                                type="button"
                                @click="submitSignature"
                                :disabled="signForm.processing || !signForm.signature_mode"
                                class="flex items-center gap-2 rounded-xl bg-amber-600 px-5 py-2 font-bold text-white transition hover:bg-amber-700 disabled:opacity-50"
                            >
                                <CheckCircle2 class="h-4 w-4" aria-hidden="true" /> Sign &amp; Return to Records
                            </button>
                            <button
                                v-else
                                type="button"
                                @click="submitGenerate"
                                :disabled="generateForm.processing"
                                class="flex items-center gap-2 rounded-xl bg-indigo-600 px-5 py-2 font-bold text-white transition hover:bg-indigo-700 disabled:opacity-50"
                            >
                                <Send class="h-4 w-4" aria-hidden="true" /> Generate &amp; Forward to Dept. Head
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
