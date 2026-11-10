<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { CheckCircle2, FastForward, FileSignature, ScanBarcode, Search, Sparkles, X } from 'lucide-vue-next';
import { Notyf } from 'notyf';
import 'notyf/notyf.min.css';
import { computed, onMounted, ref, watch } from 'vue';

const props = defineProps<{
    documents: any;
    departments: Array<{ id: number; name: string }>;
    filters?: { barcode?: string };
    flash?: { success?: string; error?: string };
    // Set when arriving from the notification bell: that row is highlighted (and its Process dialog opens once received).
    focusDocument?: any | null;
    // View-only copies sent to this office (another office is Primary and acts on them).
    copies: any;
    // Set when arriving from the bell for a copy; that row is highlighted.
    focusCopy?: number | null;
}>();

// Receive first, act later (old DTS): the Primary office logs receipt, then processes it when it has acted on it.
const isReceived = (doc: any) => Boolean(doc.primary_entry?.received_by);
const receivingId = ref<number | null>(null);
function receive(doc: any) {
    receivingId.value = doc.id;
    router.post(
        route('transactions.receive', doc.id),
        {},
        {
            preserveScroll: true,
            onError: (errors) => notyf.error(String(Object.values(errors)[0] ?? 'The document could not be received.')),
            onFinish: () => (receivingId.value = null),
        },
    );
}

// Paper route: the receiving officer scans the barcode on the physical document; only then is it received.
const paperForm = useForm({ barcode: '' });
const paperInput = ref<HTMLInputElement | null>(null);
function receivePaper() {
    paperForm.post(route('transactions.receive-paper'), {
        preserveScroll: true,
        onSuccess: () => paperForm.reset(),
        onFinish: () => paperInput.value?.focus(),
    });
}
const awaitsPaper = (doc: any) => !isReceived(doc) && doc.receive_by_scan;

const receivingCopy = ref<number | null>(null);
function receiveCopy(doc: any) {
    receivingCopy.value = doc.id;
    router.post(route('transactions.receive-copy', doc.id), {}, { preserveScroll: true, onFinish: () => (receivingCopy.value = null) });
}

const searchTerm = ref(props.filters?.barcode || '');
let searchTimeout: ReturnType<typeof setTimeout>;

const performSearch = () => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        router.get(
            route('transactions.inbox'),
            { barcode: searchTerm.value },
            {
                preserveState: true,
                preserveScroll: true,
            },
        );
    }, 300);
};

watch(searchTerm, performSearch);

const clearSearch = () => {
    searchTerm.value = '';
    router.get(route('transactions.inbox'));
};

const notyf = new Notyf({ duration: 3000, position: { x: 'right', y: 'top' } });
watch(
    () => props.flash,
    (flash) => {
        if (flash?.success) notyf.success(flash.success);
        if (flash?.error) notyf.error(flash.error);
    },
    { immediate: true },
);

const breadcrumbs = [{ title: 'My Office Inbox', href: '/transactions/inbox' }];

// Processing Modal State
const showProcessModal = ref(false);
const selectedDocument = ref<any>(null);
const aiSuggestionsMap = ref<Record<number, { reason: string; priority: string }>>({});

const form = useForm({
    action: 'route',
    // The next Primary office; `copy_to` offices get view-only copies. Sent as routed_to + primary_office_id.
    routed_to: '' as number | '',
    copy_to: [] as number[],
    remarks: '',
    // Paper route: the Department Head signed the paper by hand; an optional scan of the signed page.
    head_signed: false,
    signed_scan: null as File | null,
    workflow_version: 0,
});
const copyOptions = computed(() => props.departments.filter((dept) => dept.id !== Number(form.routed_to)));

function openProcessModal(doc: any) {
    selectedDocument.value = doc;
    form.reset();
    form.workflow_version = doc.workflow_version;
    form.clearErrors();

    // Parse AI Suggestions for the Dropdown
    let suggestions = doc.ai_routing_suggestions || [];
    if (typeof suggestions === 'string') {
        try {
            suggestions = JSON.parse(suggestions);
        } catch {
            suggestions = [];
        }
    }

    const suggestionsMap: Record<number, { reason: string; priority: string }> = {};
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

onMounted(() => {
    if (props.focusDocument && isReceived(props.focusDocument)) openProcessModal(props.focusDocument);
    else paperInput.value?.focus();
});

function submitProcess() {
    form.transform((data) => ({
        action: data.action,
        remarks: data.remarks,
        head_signed: data.head_signed,
        ...(data.head_signed && data.signed_scan ? { signed_scan: data.signed_scan } : {}),
        workflow_version: data.workflow_version,
        ...(data.action === 'route'
            ? {
                  routed_to: [Number(data.routed_to), ...data.copy_to.filter((id) => id !== Number(data.routed_to))],
                  primary_office_id: Number(data.routed_to),
              }
            : {}),
    })).post(route('transactions.process', selectedDocument.value.id), {
        forceFormData: true,
        onSuccess: () => {
            showProcessModal.value = false;
            form.reset();
        },
    });
}
</script>

<template>
    <Head title="Office Inbox" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex min-h-screen w-full flex-col gap-6 bg-slate-100 p-6">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <!-- Receive a paper document: scanning the barcode on the physical copy is the proof it arrived -->
                <form class="w-full rounded-2xl border border-emerald-200 bg-white p-3 shadow-sm lg:max-w-xl" @submit.prevent="receivePaper">
                    <label for="receive_paper_barcode" class="mb-1.5 block text-sm font-semibold text-slate-800">Receive a paper document</label>
                    <div class="flex gap-2">
                        <div class="relative flex-1">
                            <ScanBarcode class="absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-emerald-500" aria-hidden="true" />
                            <input
                                id="receive_paper_barcode"
                                ref="paperInput"
                                v-model="paperForm.barcode"
                                type="text"
                                autocomplete="off"
                                placeholder="Scan the barcode on the physical document"
                                aria-describedby="receive_paper_hint"
                                :aria-invalid="Boolean(paperForm.errors.barcode)"
                                class="block w-full rounded-xl border border-slate-300 bg-white py-2.5 pl-10 pr-3 font-mono text-sm shadow-sm focus:border-emerald-500 focus:ring-2 focus:ring-emerald-200"
                            />
                        </div>
                        <button
                            type="submit"
                            :disabled="paperForm.processing || !paperForm.barcode.trim()"
                            class="rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2 disabled:opacity-50"
                        >
                            {{ paperForm.processing ? 'Receiving...' : 'Receive' }}
                        </button>
                    </div>
                    <p v-if="paperForm.errors.barcode" role="alert" class="mt-1.5 text-xs text-red-600">{{ paperForm.errors.barcode }}</p>
                    <p id="receive_paper_hint" class="mt-1.5 text-xs text-slate-500">
                        Paper documents are received only when the paper is in your hands.
                    </p>
                </form>

                <div class="relative w-full lg:w-80">
                    <label for="inbox_search" class="sr-only">Search your inbox by barcode</label>
                    <Search class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" aria-hidden="true" />
                    <input
                        id="inbox_search"
                        v-model="searchTerm"
                        type="text"
                        placeholder="Search by barcode..."
                        class="block w-full rounded-xl border border-slate-300 bg-white py-2.5 pl-9 pr-10 font-mono text-sm shadow-sm focus:border-emerald-500 focus:ring-2 focus:ring-emerald-200"
                    />
                    <button
                        v-if="searchTerm"
                        type="button"
                        aria-label="Clear search"
                        @click="clearSearch"
                        class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600"
                    >
                        ×
                    </button>
                </div>
            </div>

            <!-- Primary documents: this office receives them, then acts (route or end). Same plain style as the copies list. -->
            <section aria-labelledby="inbox-heading" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-200 bg-slate-50 px-4 py-3">
                    <h2 id="inbox-heading" class="text-sm font-bold text-slate-800">Documents for your action</h2>
                    <p class="text-xs text-slate-500">
                        Your office is the Primary office. Receive the document first (paper: scan its barcode when the paper arrives), then process
                        it once you have acted on it.
                    </p>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm text-slate-700">
                        <thead class="border-b border-slate-200 bg-slate-100 text-xs uppercase tracking-wider text-slate-600">
                            <tr>
                                <th scope="col" class="px-4 py-2.5 font-bold">Barcode</th>
                                <th scope="col" class="px-4 py-2.5 font-bold">Subject Matter</th>
                                <th scope="col" class="px-4 py-2.5 font-bold">Source</th>
                                <th scope="col" class="px-4 py-2.5 text-center font-bold">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200">
                            <tr
                                v-for="doc in documents.data"
                                :key="doc.id"
                                class="transition-colors"
                                :class="focusDocument?.id === doc.id ? 'bg-indigo-50' : 'hover:bg-slate-50'"
                            >
                                <td class="whitespace-nowrap px-4 py-2 font-mono font-semibold text-emerald-600">{{ doc.barcode }}</td>
                                <td class="px-4 py-2">
                                    <!-- Confidential: the server sent no details; work from the paper copy -->
                                    <p v-if="doc.details_hidden" class="font-medium text-amber-800">Confidential document</p>
                                    <p v-else class="line-clamp-2 font-medium text-slate-800" :title="doc.subject_matter">{{ doc.subject_matter }}</p>
                                    <p class="mt-0.5 text-xs text-slate-500">
                                        {{ doc.document_type?.document_type || 'Unknown Type' }}
                                        <span
                                            v-if="doc.is_delayed"
                                            class="font-medium text-rose-700"
                                            title="Past the category limit (Simple 3 / Complex 7 / Highly Technical 20 days)"
                                            >· Delayed</span
                                        >
                                        <span
                                            v-if="doc.is_delinquent"
                                            class="font-medium text-amber-700"
                                            title="Held by your office longer than its performance standard"
                                            >· Delinquent</span
                                        >
                                    </p>
                                </td>
                                <td class="whitespace-nowrap px-4 py-2 text-slate-600">{{ doc.source_name }}</td>
                                <td class="whitespace-nowrap px-4 py-2 text-center">
                                    <div class="flex items-center justify-center gap-2">
                                        <a
                                            v-if="!doc.details_hidden"
                                            :href="route('documents.show', doc.id)"
                                            class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 transition hover:bg-slate-50"
                                            >View</a
                                        >
                                        <template v-if="isReceived(doc)">
                                            <span
                                                class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-700"
                                                :title="`Received by ${doc.primary_entry?.receiver?.name ?? 'your office'}`"
                                            >
                                                Received
                                            </span>
                                            <button
                                                type="button"
                                                class="rounded-lg border border-indigo-100 bg-indigo-50 px-3 py-1.5 text-xs font-semibold text-indigo-700 transition hover:bg-indigo-100"
                                                @click="openProcessModal(doc)"
                                            >
                                                Process
                                            </button>
                                        </template>
                                        <span
                                            v-else-if="awaitsPaper(doc)"
                                            class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-1.5 text-xs font-semibold text-amber-800"
                                            title="On its way as paper. Scan the barcode on the physical document when it arrives to receive it."
                                        >
                                            Incoming paper
                                        </span>
                                        <button
                                            v-else
                                            type="button"
                                            :disabled="receivingId === doc.id"
                                            class="rounded-lg border border-indigo-100 bg-indigo-50 px-3 py-1.5 text-xs font-semibold text-indigo-700 transition hover:bg-indigo-100 disabled:opacity-50"
                                            @click="receive(doc)"
                                        >
                                            {{ receivingId === doc.id ? 'Saving...' : 'Receive' }}
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="documents.data.length === 0">
                                <td colspan="4" class="py-6 text-center text-xs text-slate-500">
                                    {{ searchTerm ? 'No matching document found in your office.' : 'No documents are waiting for your office.' }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- View-only copies: another office is Primary; this office can only receive and view -->
            <section aria-labelledby="copies-heading" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-200 bg-slate-50 px-4 py-3">
                    <h2 id="copies-heading" class="text-sm font-bold text-slate-800">Copies for your information (view only)</h2>
                    <p class="text-xs text-slate-500">Another office is the Primary office and acts on these documents.</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm text-slate-700">
                        <thead class="border-b border-slate-200 bg-slate-100 text-xs uppercase tracking-wider text-slate-600">
                            <tr>
                                <th scope="col" class="px-4 py-2.5 font-bold">Barcode</th>
                                <th scope="col" class="px-4 py-2.5 font-bold">Subject Matter</th>
                                <th scope="col" class="px-4 py-2.5 font-bold">Primary Office</th>
                                <th scope="col" class="px-4 py-2.5 text-center font-bold">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200">
                            <tr
                                v-for="doc in copies.data"
                                :key="doc.id"
                                class="transition-colors"
                                :class="focusCopy === doc.id ? 'bg-indigo-50' : 'hover:bg-slate-50'"
                            >
                                <td class="whitespace-nowrap px-4 py-2 font-mono font-semibold text-emerald-600">{{ doc.barcode }}</td>
                                <td class="px-4 py-2">
                                    <p class="line-clamp-2 font-medium text-slate-800" :title="doc.subject_matter">{{ doc.subject_matter }}</p>
                                    <p class="mt-0.5 text-xs text-slate-500">{{ doc.document_type?.document_type || 'Unknown Type' }}</p>
                                </td>
                                <td class="whitespace-nowrap px-4 py-2" :title="doc.department?.name">
                                    {{ doc.department?.code || doc.department?.name }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-2 text-center">
                                    <div class="flex items-center justify-center gap-2">
                                        <a
                                            :href="route('documents.show', doc.id)"
                                            class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 transition hover:bg-slate-50"
                                            >View</a
                                        >
                                        <span
                                            v-if="doc.copy_received"
                                            class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-700"
                                            >Received</span
                                        >
                                        <button
                                            v-else
                                            type="button"
                                            :disabled="receivingCopy === doc.id"
                                            class="rounded-lg border border-indigo-100 bg-indigo-50 px-3 py-1.5 text-xs font-semibold text-indigo-700 transition hover:bg-indigo-100 disabled:opacity-50"
                                            @click="receiveCopy(doc)"
                                        >
                                            {{ receivingCopy === doc.id ? 'Saving...' : 'Receive copy' }}
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="copies.data.length === 0">
                                <td colspan="4" class="py-6 text-center text-xs text-slate-500">No copies sent to your office.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- Process Transaction Modal -->
            <Transition name="fade">
                <div v-if="showProcessModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 px-4 backdrop-blur-sm">
                    <div class="relative flex max-h-[90vh] w-full max-w-2xl flex-col rounded-2xl border border-slate-200 bg-white shadow-xl">
                        <button
                            @click="showProcessModal = false"
                            class="absolute right-4 top-4 z-10 rounded-full p-1.5 text-slate-400 transition hover:bg-slate-100"
                        >
                            <X class="h-5 w-5" />
                        </button>

                        <div class="border-b border-slate-100 px-6 py-5">
                            <h2 class="text-lg font-bold tracking-tight text-slate-800">Process Document</h2>
                            <p class="mt-0.5 text-xs text-slate-500">
                                Received by {{ selectedDocument?.primary_entry?.receiver?.name ?? 'your office' }}. Record the action your office
                                took.
                            </p>
                        </div>

                        <div class="flex-1 overflow-y-auto p-6">
                            <!-- Document Details Summary -->
                            <div class="mb-6 rounded-xl border border-slate-200 bg-slate-50 p-4">
                                <h3 class="mb-4 text-xs font-bold uppercase tracking-wider text-slate-500">Document Details</h3>
                                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <div>
                                        <label class="mb-1 block text-xs font-medium text-slate-500">Barcode</label>
                                        <input
                                            type="text"
                                            disabled
                                            :value="selectedDocument?.barcode"
                                            class="w-full cursor-not-allowed rounded-lg border border-slate-200 bg-slate-100 px-3 py-2 font-mono text-sm text-slate-600 opacity-75"
                                        />
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-xs font-medium text-slate-500">Source Name</label>
                                        <input
                                            type="text"
                                            disabled
                                            :value="selectedDocument?.source_name"
                                            class="w-full cursor-not-allowed rounded-lg border border-slate-200 bg-slate-100 px-3 py-2 text-sm text-slate-600 opacity-75"
                                        />
                                    </div>
                                    <div class="sm:col-span-2">
                                        <label class="mb-1 block text-xs font-medium text-slate-500">Subject Matter</label>
                                        <textarea
                                            disabled
                                            rows="2"
                                            class="w-full cursor-not-allowed resize-none rounded-lg border border-slate-200 bg-slate-100 px-3 py-2 text-sm text-slate-600 opacity-75"
                                            :value="
                                                selectedDocument?.details_hidden
                                                    ? 'Confidential document: see the paper copy.'
                                                    : selectedDocument?.subject_matter
                                            "
                                        ></textarea>
                                    </div>
                                </div>
                            </div>

                            <form @submit.prevent="submitProcess" id="processForm" class="space-y-6">
                                <!-- Required Remarks Moved to Top -->
                                <div>
                                    <label class="mb-1.5 block text-sm font-medium text-slate-700" for="remarks"
                                        >Official Remarks <span class="text-red-500">*</span></label
                                    >
                                    <textarea
                                        v-model="form.remarks"
                                        id="remarks"
                                        rows="3"
                                        class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm shadow-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                                        placeholder="Detail the action taken or instructions for the next office..."
                                        required
                                    ></textarea>
                                </div>

                                <!-- Action Selector -->
                                <div>
                                    <label class="mb-3 block text-sm font-bold text-slate-800">What would you like to do with this document?</label>
                                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                        <label
                                            class="relative flex cursor-pointer rounded-xl border p-4 shadow-sm transition-colors focus-within:ring-2 focus-within:ring-indigo-500"
                                            :class="
                                                form.action === 'route'
                                                    ? 'border-indigo-500 bg-indigo-50/50'
                                                    : 'border-slate-200 hover:border-indigo-300'
                                            "
                                        >
                                            <input type="radio" v-model="form.action" value="route" class="sr-only" />
                                            <div class="flex items-center gap-3">
                                                <div
                                                    class="flex h-10 w-10 items-center justify-center rounded-lg"
                                                    :class="form.action === 'route' ? 'bg-indigo-600 text-white' : 'bg-slate-100 text-slate-500'"
                                                >
                                                    <FastForward class="h-5 w-5" />
                                                </div>
                                                <div>
                                                    <p class="text-sm font-semibold text-slate-900">Route</p>
                                                    <p class="text-xs text-slate-500">Send it to another office</p>
                                                </div>
                                            </div>
                                        </label>

                                        <label
                                            class="relative flex cursor-pointer rounded-xl border p-4 shadow-sm transition-colors focus-within:ring-2 focus-within:ring-emerald-500"
                                            :class="
                                                form.action === 'end'
                                                    ? 'border-emerald-500 bg-emerald-50/50'
                                                    : 'border-slate-200 hover:border-emerald-300'
                                            "
                                        >
                                            <input type="radio" v-model="form.action" value="end" class="sr-only" />
                                            <div class="flex items-center gap-3">
                                                <div
                                                    class="flex h-10 w-10 items-center justify-center rounded-lg"
                                                    :class="form.action === 'end' ? 'bg-emerald-600 text-white' : 'bg-slate-100 text-slate-500'"
                                                >
                                                    <CheckCircle2 class="h-5 w-5" />
                                                </div>
                                                <div>
                                                    <p class="text-sm font-semibold text-slate-900">End Transaction</p>
                                                    <p class="text-xs text-slate-500">Complete the document; copies close with it</p>
                                                </div>
                                            </div>
                                        </label>
                                    </div>
                                </div>

                                <!-- Dynamic Field: Target Department (with AI highlighting) -->
                                <div v-if="form.action === 'route'">
                                    <label class="mb-1.5 block text-sm font-medium text-slate-700" for="routed_to"
                                        >Next Primary Office <span class="text-red-500">*</span></label
                                    >
                                    <select
                                        v-model="form.routed_to"
                                        id="routed_to"
                                        class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm shadow-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                                        :required="form.action === 'route'"
                                    >
                                        <option value="" disabled>Select target office...</option>
                                        <option v-for="dept in departments" :key="dept.id" :value="dept.id">
                                            {{ dept.name }} {{ aiSuggestionsMap[dept.id]?.priority === 'Primary' ? '✨ (AI Recommended)' : '' }}
                                        </option>
                                    </select>
                                    <p
                                        v-if="aiSuggestionsMap[Number(form.routed_to)]"
                                        class="mt-1.5 flex items-start gap-1 text-xs font-medium text-indigo-600"
                                    >
                                        <Sparkles class="relative top-0.5 h-3.5 w-3.5 shrink-0" />
                                        {{ aiSuggestionsMap[Number(form.routed_to)].reason }}
                                    </p>
                                    <p
                                        v-if="form.errors.routed_to || form.errors.primary_office_id"
                                        role="alert"
                                        class="mt-1.5 text-xs font-medium text-red-600"
                                    >
                                        {{ form.errors.routed_to || form.errors.primary_office_id }}
                                    </p>

                                    <fieldset v-if="!selectedDocument?.is_confidential" class="mt-4">
                                        <legend class="mb-1.5 text-sm font-medium text-slate-700">Send view-only copies to (optional)</legend>
                                        <p class="mb-2 text-xs text-slate-500">
                                            Copy offices are notified and can receive and view the document, but only the Primary office acts on it.
                                        </p>
                                        <div class="grid max-h-40 gap-1 overflow-y-auto rounded-xl border border-slate-200 p-2 sm:grid-cols-2">
                                            <label v-for="dept in copyOptions" :key="dept.id" class="flex items-center gap-2 text-xs text-slate-700">
                                                <input
                                                    v-model="form.copy_to"
                                                    type="checkbox"
                                                    :value="dept.id"
                                                    class="h-3.5 w-3.5 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                                                />
                                                {{ dept.name }}
                                            </label>
                                        </div>
                                    </fieldset>
                                    <p v-else class="mt-3 text-xs text-amber-700">
                                        Confidential document: it goes to the Primary office only, with no copies.
                                    </p>
                                </div>

                                <!-- Workflow Modifiers -->
                                <div class="space-y-3 pt-2">
                                    <!-- Paper route: record the Department Head's wet signature (no e-signature image is applied) -->
                                    <label
                                        class="flex cursor-pointer items-start gap-3 rounded-xl border p-3 transition"
                                        :class="form.head_signed ? 'border-amber-200 bg-amber-50/50' : 'border-slate-200 bg-white hover:bg-slate-50'"
                                    >
                                        <div class="mt-0.5 flex h-5 items-center">
                                            <input
                                                v-model="form.head_signed"
                                                type="checkbox"
                                                aria-describedby="head_signed_hint"
                                                class="h-4 w-4 rounded border-slate-300 text-amber-600 focus:ring-amber-500"
                                            />
                                        </div>
                                        <div class="flex flex-col">
                                            <span class="flex items-center gap-1.5 text-sm font-bold text-slate-800">
                                                <FileSignature class="h-4 w-4 text-amber-600" aria-hidden="true" />
                                                Signed by the Department Head (wet signature)
                                            </span>
                                            <span id="head_signed_hint" class="mt-0.5 text-xs text-slate-500">
                                                Tick this once your Department Head has signed the paper copy. It is recorded in the trail under their
                                                name.
                                            </span>
                                        </div>
                                    </label>
                                    <p v-if="form.errors.head_signed" role="alert" class="text-xs font-medium text-red-600">
                                        {{ form.errors.head_signed }}
                                    </p>
                                    <div v-if="form.head_signed">
                                        <label for="process_signed_scan" class="mb-1.5 block text-sm font-medium text-slate-700">
                                            Scan of the signed page <span class="font-normal text-slate-400">(optional)</span>
                                        </label>
                                        <input
                                            id="process_signed_scan"
                                            type="file"
                                            accept=".pdf,.jpg,.jpeg,.png"
                                            class="w-full rounded-xl border border-slate-300 bg-white py-1.5 pl-1.5 pr-3 text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-1 file:text-xs file:font-medium file:text-slate-700"
                                            @change="(e: any) => (form.signed_scan = e.target.files[0] ?? null)"
                                        />
                                        <p v-if="form.errors.signed_scan" role="alert" class="mt-1.5 text-xs font-medium text-red-600">
                                            {{ form.errors.signed_scan }}
                                        </p>
                                    </div>
                                </div>
                            </form>
                        </div>

                        <div class="flex justify-end gap-3 rounded-b-2xl border-t border-slate-100 bg-slate-50 px-6 py-4">
                            <button
                                type="button"
                                @click="showProcessModal = false"
                                class="rounded-xl border border-slate-200 bg-white px-5 py-2.5 text-sm font-medium text-slate-700 transition hover:bg-slate-100"
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                form="processForm"
                                class="rounded-xl px-5 py-2.5 text-sm font-medium text-white shadow-sm transition disabled:opacity-50"
                                :class="form.action === 'end' ? 'bg-emerald-600 hover:bg-emerald-700' : 'bg-indigo-600 hover:bg-indigo-700'"
                                :disabled="form.processing"
                            >
                                {{ form.processing ? 'Saving...' : form.action === 'end' ? 'End Transaction' : 'Route' }}
                            </button>
                        </div>
                    </div>
                </div>
            </Transition>
        </div>
    </AppLayout>
</template>

<style scoped>
.fade-enter-active,
.fade-leave-active {
    transition: opacity 0.2s ease;
}
.fade-enter-from,
.fade-leave-to {
    opacity: 0;
}
</style>
