<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import InputError from '@/components/InputError.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { AlertCircle, ArrowLeft, ArrowLeftRight, CheckCircle2, ChevronRight, Clock, FileSignature, FileText, FileUp, History, Link2, MapPin, Paperclip, ShieldCheck, UserRound } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    document: any;
    trails: any[];
    // Primary office first; the others hold view-only copies.
    holders: Array<{ id: number; code: string; name: string; pivot?: { copy_role: 'primary' | 'copy' } }>;
    canChangePrimary: boolean;
    /** Linked barcodes that resolve to a document the user may open (barcode => id). */
    linkedDocumentIds: Record<string, number>;
    offices?: Array<{ id: number; code: string; name: string }>;
}>();

const primaryHolder = computed(() => props.holders.find((office) => office.pivot?.copy_role === 'primary') ?? null);
const copyHolders = computed(() => props.holders.filter((office) => office.pivot?.copy_role === 'copy'));

const primaryForm = useForm({ primary_office_id: '' as number | '' });
function changePrimary() {
    primaryForm.post(route('documents.primary-office', props.document.id), { preserveScroll: true, onSuccess: () => primaryForm.reset() });
}

// `open` = this office still holds its copy (trail received but not yet released).
type Office = { code: string; name: string; open?: boolean };
type RouteStop = { key: string; offices: Office[]; action: string; receivedAt: string };

const officeOf = (department: any, open = false): Office => ({ code: department?.code || department?.name || '', name: department?.name || '', open });
const isCurrentStop = (stop: RouteStop) => stop.offices.some((office) => office.open);

// One stop per hand-off; trails received at the same moment (a multi-office dispatch) share a stop.
const routeStops = computed<RouteStop[]>(() => {
    const stops: RouteStop[] = [];
    for (const trail of props.trails) {
        const previous = stops[stops.length - 1];
        const office = officeOf(trail.office, !trail.released_at);
        if (previous && trail.received_at && previous.receivedAt === trail.received_at && previous.action === trail.received_action) {
            previous.offices.push(office);
            continue;
        }
        stops.push({ key: String(trail.id), offices: [office], action: trail.received_action || '', receivedAt: trail.received_at || '' });
    }
    return stops;
});

// Newest hand-off first; keep each entry's chronological index for transit-time math. Copies are created in the
// same moment as the Primary entry they were sent with, so they sit under it: the Primary office's own action
// (e.g. End Transaction) stays the top, latest row, and closing the copies is listed beneath it.
const latestFirstTrails = computed(() =>
    props.trails
        .map((trail, index) => ({ trail, index }))
        .sort(
            (a, b) =>
                String(b.trail.created_at).localeCompare(String(a.trail.created_at)) ||
                Number(a.trail.copy_role === 'copy') - Number(b.trail.copy_role === 'copy') ||
                b.trail.id - a.trail.id,
        ),
);

const isCompleted = computed(() => props.document.status === 'Completed');

// Where the document goes next, derived from its pending workflow step.
const nextStop = computed<{ offices: Office[]; action: string } | null>(() => {
    if (isCompleted.value) return null;
    const records: Office = { code: 'REC', name: 'Records' };
    switch (props.document.stage) {
        case 'adm_approval':
            return { offices: [{ code: 'ADM', name: 'Office of the City Administrator' }], action: 'City Administrator approval' };
        case 'ocm_approval':
            return { offices: [records], action: 'Return to Records after OCM approval' };
    }
    return null;
});

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Documents', href: '/documents' },
    { title: props.document.barcode, href: `/documents/${props.document.id}` },
];

const hasSignedOcmPdf = computed(() => props.trails.some(trail => Boolean(trail.signed_file_path)));

// linked_documents is cast to an array server-side; older rows may still hold a plain string.
const linkedBarcodeList = computed<string[]>(() => {
    const linked = props.document.linked_documents;
    if (Array.isArray(linked)) return linked.filter(Boolean);
    return typeof linked === 'string' ? linked.split(/[\s,]+/).filter(Boolean) : [];
});

// --- HELPER FUNCTIONS ---

const formatDateTime = (dateString: string) => {
    if (!dateString) return { date: '', time: '' };
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
    if (!parsed.date) return '';
    return `${parsed.date} ${parsed.time}`;
};

// Workflow timestamps (received_at, released_at, due_date) are saved as Manila wall-clock time, so the
// helpers above drop the trailing "Z". Framework timestamps (created_at/updated_at) are real UTC because
// config/app.php uses 'UTC'; convert those to Asia/Manila explicitly.
const formatUtcDateStr = (dateString: string) => {
    if (!dateString) return '';
    const date = new Date(dateString);
    if (isNaN(date.getTime())) return 'Invalid Date';
    const options = { timeZone: 'Asia/Manila' } as const;
    const day = date.toLocaleDateString('en-US', { ...options, month: 'short', day: '2-digit', year: 'numeric' });
    const time = date.toLocaleTimeString('en-US', { ...options, hour: '2-digit', minute: '2-digit' });
    return `${day} ${time}`;
};

const calculateDuration = (startStr: string, endStr: string) => {
    if (!startStr || !endStr) return '';
    const safeStart = startStr.replace(' ', 'T').replace('Z', '');
    const safeEnd = endStr.replace(' ', 'T').replace('Z', '');
    
    const start = new Date(safeStart).getTime();
    const end = new Date(safeEnd).getTime();
    const diffMs = end - start;
    
    if (isNaN(diffMs)) return '';
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
    if (!trail.released_at || !nextTrail || !nextTrail.received_at) return '';
    return calculateDuration(trail.released_at, nextTrail.received_at);
};

const formatRequiredAction = (action: string) => action.replaceAll('_', ' ').replace(/\b\w/g, letter => letter.toUpperCase());
</script>

<template>
    <Head :title="`Document - ${document.barcode}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex min-h-screen w-full flex-col gap-6 bg-slate-100 p-6">
            <!-- Page Header -->
            <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <h1 class="text-2xl font-bold tracking-tight text-slate-800">
                            Document <span class="font-mono text-emerald-600">{{ document.barcode }}</span>
                        </h1>
                        <span
                            v-if="document.status === 'Completed'"
                            class="inline-flex items-center rounded border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-[11px] font-bold text-emerald-700"
                        >
                            Completed
                        </span>
                        <span v-else class="inline-flex items-center rounded border border-blue-200 bg-blue-50 px-2 py-0.5 text-[11px] font-bold text-blue-700">
                            {{ document.status || 'Pending' }}
                        </span>
                        <span
                            v-if="document.is_delayed"
                            class="inline-flex items-center rounded border border-rose-200 bg-rose-50 px-1.5 py-0.5 text-[10px] font-bold text-rose-700"
                        >
                            <AlertCircle class="mr-1 h-3 w-3" aria-hidden="true" /> Delayed
                        </span>
                        <span
                            v-if="document.is_delinquent"
                            class="inline-flex items-center rounded border border-amber-200 bg-amber-50 px-1.5 py-0.5 text-[10px] font-bold text-amber-700"
                        >
                            <Clock class="mr-1 h-3 w-3" aria-hidden="true" /> Delinquent
                        </span>
                        <span
                            v-if="document.is_confidential"
                            class="inline-flex items-center rounded border border-amber-200 bg-amber-50 px-1.5 py-0.5 text-[10px] font-bold text-amber-800"
                        >
                            Confidential
                        </span>
                        <span
                            class="inline-flex items-center rounded border px-1.5 py-0.5 text-[10px] font-bold"
                            :class="document.route_mode === 'paperless' ? 'border-sky-200 bg-sky-50 text-sky-800' : 'border-slate-200 bg-slate-100 text-slate-600'"
                        >
                            {{ document.route_mode === 'paperless' ? 'Paperless route' : 'Paper route' }}
                        </span>
                    </div>
                    <p v-if="!isCompleted && (holders.length || document.department?.name)" class="mt-1 text-sm text-slate-500">
                        Currently with
                        <span class="font-semibold text-slate-700">{{ primaryHolder?.name ?? document.department?.name }}</span>
                        <span v-if="primaryHolder" class="ml-1 rounded border border-indigo-200 bg-indigo-50 px-1.5 py-0.5 text-[10px] font-bold text-indigo-700">Primary</span>
                        <template v-if="copyHolders.length">
                            <span class="mx-1">·</span> View-only copies:
                            <span class="font-semibold text-slate-700">{{ copyHolders.map((office) => office.code || office.name).join(', ') }}</span>
                        </template>
                    </p>
                    <form v-if="!isCompleted && canChangePrimary && offices" class="mt-2 flex flex-wrap items-center gap-2" @submit.prevent="changePrimary">
                        <label for="primary_office_id" class="text-xs font-medium text-slate-600">Change Primary office</label>
                        <select
                            id="primary_office_id"
                            v-model="primaryForm.primary_office_id"
                            aria-describedby="primary_office_hint"
                            class="rounded-lg border border-slate-300 px-2 py-1 text-xs focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                            required
                        >
                            <option value="" disabled>Select office...</option>
                            <option v-for="office in offices.filter((o) => o.id !== primaryHolder?.id)" :key="office.id" :value="office.id">
                                {{ office.code }}: {{ office.name }}
                            </option>
                        </select>
                        <button
                            type="submit"
                            :disabled="primaryForm.processing || !primaryForm.primary_office_id"
                            class="rounded-lg border border-indigo-200 bg-indigo-50 px-2.5 py-1 text-xs font-semibold text-indigo-700 transition hover:bg-indigo-100 disabled:opacity-50"
                        >
                            Change
                        </button>
                        <span id="primary_office_hint" class="text-[11px] text-slate-500">The current Primary keeps a view-only copy.</span>
                        <InputError class="w-full" :message="primaryForm.errors.primary_office_id" />
                    </form>
                </div>

                <Link
                    :href="route('documents.index')"
                    class="flex items-center gap-1.5 self-start rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-700 shadow-sm transition hover:bg-slate-50 sm:self-auto"
                >
                    <ArrowLeft class="h-4 w-4" aria-hidden="true" /> Back to List
                </Link>
            </div>

            <!-- Document Route: office-to-office path, current location, and next destination. -->
            <section aria-labelledby="document-route-heading" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="flex items-center gap-2 border-b border-slate-200 bg-slate-50 px-4 py-3">
                    <MapPin class="h-4 w-4 text-emerald-600" aria-hidden="true" />
                    <h2 id="document-route-heading" class="text-base font-bold tracking-tight text-slate-800">Document Route</h2>
                </div>
                <ol class="flex flex-wrap items-stretch gap-y-3 p-4" aria-label="Offices this document has passed through">
                    <li v-for="(stop, index) in routeStops" :key="stop.key" class="flex items-center">
                        <ChevronRight v-if="index > 0" class="mx-1 h-4 w-4 shrink-0 text-slate-400" aria-hidden="true" />
                        <div
                            class="flex min-w-[7rem] flex-col rounded-xl border px-3 py-2"
                            :class="isCurrentStop(stop) && !isCompleted
                                ? 'border-emerald-400 bg-emerald-50 shadow-sm ring-1 ring-emerald-200'
                                : 'border-slate-200 bg-white'"
                            :aria-current="isCurrentStop(stop) && !isCompleted ? 'step' : undefined"
                        >
                            <div class="flex flex-wrap items-center gap-1">
                                <!-- In a multi-office stop, offices still holding their copy are green; finished ones stay grey. -->
                                <span
                                    v-for="office in stop.offices"
                                    :key="office.code"
                                    class="text-sm font-bold"
                                    :class="office.open && stop.offices.length > 1 ? 'text-emerald-700' : 'text-slate-800'"
                                    :title="office.open ? `${office.name} (holding a copy)` : office.name"
                                >
                                    {{ office.code }}
                                </span>
                                <span
                                    v-if="isCurrentStop(stop) && !isCompleted"
                                    class="ml-1 rounded bg-emerald-600 px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wider text-white"
                                >
                                    Current
                                </span>
                            </div>
                            <span v-if="stop.action" class="text-[11px] text-slate-600">{{ stop.action }}</span>
                            <span v-if="stop.receivedAt" class="text-[10px] text-slate-400">{{ formatDateStr(stop.receivedAt) }}</span>
                        </div>
                    </li>

                    <li v-if="nextStop" class="flex items-center">
                        <ChevronRight class="mx-1 h-4 w-4 shrink-0 text-slate-300" aria-hidden="true" />
                        <div class="flex min-w-[7rem] flex-col rounded-xl border border-dashed border-slate-300 bg-slate-50 px-3 py-2">
                            <div class="flex flex-wrap items-center gap-1">
                                <span v-for="office in nextStop.offices" :key="office.code" class="text-sm font-bold text-slate-500" :title="office.name">{{ office.code }}</span>
                                <span class="ml-1 rounded border border-slate-300 px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wider text-slate-500">Next</span>
                            </div>
                            <span class="text-[11px] text-slate-500">{{ nextStop.action }}</span>
                        </div>
                    </li>

                    <li v-if="isCompleted" class="flex items-center">
                        <ChevronRight class="mx-1 h-4 w-4 shrink-0 text-slate-400" aria-hidden="true" />
                        <div class="flex items-center gap-1.5 rounded-xl border border-emerald-300 bg-emerald-50 px-3 py-2 text-sm font-bold text-emerald-700">
                            <CheckCircle2 class="h-4 w-4" aria-hidden="true" /> Completed
                        </div>
                    </li>
                </ol>
            </section>

            <!-- Detail panels: same three-panel arrangement as the legacy DTS view. -->
            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <!-- Panel 1: Document Details -->
                <section aria-labelledby="document-details-heading" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div class="flex items-center gap-2 border-b border-slate-200 bg-slate-50 px-4 py-3">
                        <FileText class="h-4 w-4 text-emerald-600" aria-hidden="true" />
                        <h2 id="document-details-heading" class="text-base font-bold tracking-tight text-slate-800">Document Details</h2>
                    </div>
                    <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5 p-4 text-sm">
                        <dt class="font-semibold text-slate-700">Barcode:</dt>
                        <dd class="break-words font-mono font-semibold text-emerald-600">{{ document.barcode }}</dd>
                        <template v-if="document.access_code">
                            <dt class="font-semibold text-slate-700">Access Code:</dt>
                            <dd class="font-mono text-slate-800">{{ document.access_code }}</dd>
                        </template>
                        <dt class="font-semibold text-slate-700">Date Start:</dt>
                        <dd class="text-slate-800">{{ formatUtcDateStr(document.created_at) }}</dd>
                        <template v-if="document.due_date">
                            <dt class="font-semibold text-slate-700">Date End:</dt>
                            <dd class="text-slate-800">{{ formatDateStr(document.due_date) }}</dd>
                        </template>
                        <template v-if="document.transaction_type?.name">
                            <dt class="font-semibold text-slate-700">Transaction Type:</dt>
                            <dd class="text-slate-800">{{ document.transaction_type.name }}</dd>
                        </template>
                        <template v-if="document.document_type?.document_type">
                            <dt class="font-semibold text-slate-700">Document Type:</dt>
                            <dd class="text-slate-800">{{ document.document_type.document_type }}</dd>
                        </template>
                        <template v-if="document.delivery_method?.delivery_method_name">
                            <dt class="font-semibold text-slate-700">Delivery Method:</dt>
                            <dd class="text-slate-800">{{ document.delivery_method.delivery_method_name }}</dd>
                        </template>
                        <dt class="font-semibold text-slate-700">Source Type:</dt>
                        <dd>
                            <span class="inline-flex items-center rounded border border-slate-200 bg-slate-50 px-2 py-0.5 text-[11px] font-bold text-slate-700">
                                {{ document.source_type }}
                            </span>
                        </dd>
                    </dl>
                </section>

                <!-- Panel 2: Source & Subject Matter -->
                <section aria-labelledby="document-source-heading" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div class="flex items-center gap-2 border-b border-slate-200 bg-slate-50 px-4 py-3">
                        <UserRound class="h-4 w-4 text-emerald-600" aria-hidden="true" />
                        <h2 id="document-source-heading" class="text-base font-bold tracking-tight text-slate-800">Source &amp; Subject</h2>
                    </div>
                    <dl class="flex flex-col gap-3 p-4 text-sm">
                        <div>
                            <dt class="font-semibold text-slate-700">Source:</dt>
                            <dd class="break-words text-slate-800">
                                {{ document.source_name }}<span v-if="document.gender" class="text-slate-500"> - {{ document.gender }}</span>
                                <span v-if="document.source_location" class="block text-xs text-slate-500">{{ document.source_location }}</span>
                            </dd>
                        </div>
                        <div>
                            <dt class="font-semibold text-slate-700">Subject Matter:</dt>
                            <dd class="mt-1 break-words rounded-xl border border-slate-200 bg-slate-50 p-3 leading-relaxed text-slate-800">
                                {{ document.subject_matter }}
                            </dd>
                        </div>
                    </dl>
                </section>

                <!-- Panel 3: Linked Documents & Attachments -->
                <section aria-labelledby="document-links-heading" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div class="flex items-center gap-2 border-b border-slate-200 bg-slate-50 px-4 py-3">
                        <Paperclip class="h-4 w-4 text-emerald-600" aria-hidden="true" />
                        <h2 id="document-links-heading" class="text-base font-bold tracking-tight text-slate-800">Links &amp; Attachments</h2>
                    </div>
                    <dl v-if="linkedBarcodeList.length || document.attached_file || hasSignedOcmPdf" class="flex flex-col gap-3 p-4 text-sm">
                        <div v-if="linkedBarcodeList.length">
                            <dt class="font-semibold text-slate-700">Document/s Linked:</dt>
                            <dd class="mt-1 flex flex-wrap gap-1">
                                <template v-for="barcode in linkedBarcodeList" :key="barcode">
                                    <!-- Opens the linked (parent) document; a barcode not registered here stays plain text. -->
                                    <Link
                                        v-if="linkedDocumentIds[barcode]"
                                        :href="route('documents.show', linkedDocumentIds[barcode])"
                                        class="inline-flex items-center gap-1 rounded border border-slate-400 bg-white px-2 py-0.5 font-mono text-xs font-semibold text-blue-700 transition hover:border-blue-600 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-600"
                                        :title="`Open document ${barcode}`"
                                    >
                                        <Link2 class="h-3 w-3" aria-hidden="true" /> {{ barcode }}
                                    </Link>
                                    <span
                                        v-else
                                        class="inline-flex items-center gap-1 rounded border border-slate-300 bg-white px-2 py-0.5 font-mono text-xs font-semibold text-slate-600"
                                        title="No registered document has this barcode"
                                    >
                                        <Link2 class="h-3 w-3" aria-hidden="true" /> {{ barcode }}
                                    </span>
                                </template>
                            </dd>
                        </div>
                        <div v-if="document.attached_file || hasSignedOcmPdf">
                            <dt class="font-semibold text-slate-700">Attachments:</dt>
                            <dd class="mt-1 flex flex-col items-start gap-1.5">
                                <a
                                    v-if="document.attached_file"
                                    :href="`/storage/${document.attached_file}`"
                                    target="_blank"
                                    rel="noopener"
                                    class="inline-flex items-center gap-1.5 rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-700 shadow-sm transition hover:bg-emerald-100"
                                >
                                    <FileUp class="h-3.5 w-3.5" aria-hidden="true" /> View Scanned Document
                                </a>
                                <a
                                    v-if="hasSignedOcmPdf"
                                    :href="route('documents.signed-pdf', document.id)"
                                    target="_blank"
                                    rel="noopener"
                                    class="inline-flex items-center gap-1.5 rounded-xl border border-violet-200 bg-violet-50 px-3 py-1.5 text-xs font-semibold text-violet-700 shadow-sm transition hover:bg-violet-100"
                                >
                                    <ShieldCheck class="h-3.5 w-3.5" aria-hidden="true" /> Preview OCM Approved PDF
                                </a>
                            </dd>
                        </div>
                    </dl>
                    <p v-else class="p-4 text-sm text-slate-400">No linked documents or attachments.</p>
                </section>
            </div>

            <!-- Routing Trail: legacy DTS column grouping, fixed widths + wrapping so it fits without sideways scroll. -->
            <section aria-labelledby="routing-trail-heading" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="flex items-center justify-between gap-4 border-b border-slate-200 bg-slate-50 px-4 py-3">
                    <div class="flex items-center gap-2">
                        <History class="h-4 w-4 text-emerald-600" aria-hidden="true" />
                        <h2 id="routing-trail-heading" class="text-base font-bold tracking-tight text-slate-800">Routing Trail</h2>
                    </div>
                    <span class="text-xs text-slate-500">
                        <span class="font-bold text-slate-800">{{ trails.length }}</span> {{ trails.length === 1 ? 'entry' : 'entries' }}
                    </span>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full min-w-[60rem] table-fixed border-collapse text-xs text-slate-700">
                        <colgroup>
                            <col class="w-[7%]" />
                            <col class="w-[10%]" />
                            <col class="w-[9%]" />
                            <col class="w-[10%]" />
                            <col class="w-[10%]" />
                            <col class="w-[9%]" />
                            <col class="w-[11%]" />
                            <col class="w-[7%]" />
                            <col class="w-[7%]" />
                            <col class="w-[7%]" />
                            <col />
                        </colgroup>
                        <thead>
                            <tr class="bg-[#1e4e79] text-[11px] uppercase tracking-wider text-white">
                                <th scope="colgroup" colspan="4" class="border-r border-white/20 px-2 py-2 font-bold">Received</th>
                                <th scope="colgroup" colspan="4" class="border-r border-white/20 px-2 py-2 font-bold">Released</th>
                                <th scope="colgroup" colspan="2" class="border-r border-white/20 px-2 py-2 font-bold">Duration</th>
                                <th scope="col" rowspan="2" class="px-2 py-2 align-middle font-bold">Remarks</th>
                            </tr>
                            <tr class="border-b border-slate-200 bg-slate-100 text-[11px] font-semibold text-slate-600">
                                <th scope="col" class="px-2 py-2">Office</th>
                                <th scope="col" class="px-2 py-2">By</th>
                                <th scope="col" class="px-2 py-2">Date/Time</th>
                                <th scope="col" class="border-r border-slate-200 px-2 py-2">Action</th>
                                <th scope="col" class="px-2 py-2">By</th>
                                <th scope="col" class="px-2 py-2">Date/Time</th>
                                <th scope="col" class="px-2 py-2">Action</th>
                                <th scope="col" class="border-r border-slate-200 px-2 py-2">Released To</th>
                                <th scope="col" class="px-2 py-2">Office</th>
                                <th scope="col" class="border-r border-slate-200 px-2 py-2">Transit</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-slate-200 text-center">
                            <tr v-for="{ trail, index } in latestFirstTrails" :key="trail.id" class="transition-colors hover:bg-slate-50">
                                <!-- Received -->
                                <td class="break-words px-2 py-2.5">
                                    <span
                                        class="inline-flex items-center rounded border border-emerald-200 bg-emerald-50 px-1.5 py-0.5 text-[11px] font-bold text-emerald-700"
                                        :title="trail.office?.name"
                                    >
                                        {{ trail.office?.code || trail.office?.name }}
                                    </span>
                                </td>
                                <td class="break-words px-2 py-2.5 font-medium text-slate-800">{{ trail.receiver?.name }}</td>
                                <td class="px-2 py-2.5">
                                    <div class="font-semibold text-slate-800">{{ formatDateTime(trail.received_at).date }}</div>
                                    <div class="text-[11px] text-slate-500">{{ formatDateTime(trail.received_at).time }}</div>
                                </td>
                                <td class="break-words border-r border-slate-200 px-2 py-2.5 text-left text-slate-600">{{ trail.received_action }}</td>

                                <!-- Released -->
                                <td class="break-words px-2 py-2.5 font-medium text-slate-800">{{ trail.releaser?.name }}</td>
                                <td class="px-2 py-2.5">
                                    <template v-if="trail.released_at">
                                        <div class="font-semibold text-slate-800">{{ formatDateTime(trail.released_at).date }}</div>
                                        <div class="text-[11px] text-slate-500">{{ formatDateTime(trail.released_at).time }}</div>
                                    </template>
                                    <span
                                        v-else
                                        class="inline-flex items-center rounded border border-blue-200 bg-blue-50 px-1.5 py-0.5 text-[10px] font-bold text-blue-700"
                                    >
                                        Pending
                                    </span>
                                </td>
                                <td class="break-words px-2 py-2.5 text-left text-slate-600">
                                    <div v-if="trail.released_action">{{ trail.released_action }}</div>
                                    <div v-if="trail.is_digitally_signed || trail.signature_mode === 'physical' || trail.trail_type === 'return'" class="mt-1 flex flex-wrap gap-1">
                                        <span
                                            v-if="trail.is_digitally_signed"
                                            class="inline-flex items-center rounded border border-amber-200 bg-amber-50 px-1.5 py-0.5 text-[10px] font-bold text-amber-700"
                                            :title="`Signed by ${trail.signer?.name}`"
                                        >
                                            <FileSignature class="mr-1 h-3 w-3" aria-hidden="true" /> E-Signed
                                        </span>
                                        <span
                                            v-else-if="trail.signature_mode === 'physical'"
                                            class="inline-flex items-center rounded border border-slate-300 bg-slate-50 px-1.5 py-0.5 text-[10px] font-bold text-slate-700"
                                            :title="trail.signer ? `Signed by hand by ${trail.signer.name}` : 'Signed by hand'"
                                        >
                                            <FileSignature class="mr-1 h-3 w-3" aria-hidden="true" /> Wet Signature
                                        </span>
                                        <span
                                            v-if="trail.trail_type === 'return'"
                                            class="inline-flex items-center rounded border border-rose-200 bg-rose-50 px-1.5 py-0.5 text-[10px] font-bold text-rose-700"
                                        >
                                            <ArrowLeftRight class="mr-1 h-3 w-3" aria-hidden="true" /> Returned
                                        </span>
                                    </div>
                                </td>
                                <!-- One badge per receiving office (a dispatch can release to many). -->
                                <td class="break-words border-r border-slate-200 px-2 py-2.5">
                                    <div v-if="trail.released_to_list?.length" class="flex flex-wrap justify-center gap-1">
                                        <span
                                            v-for="office in trail.released_to_list"
                                            :key="office.id"
                                            class="inline-flex items-center rounded border border-indigo-200 bg-indigo-50 px-1.5 py-0.5 text-[11px] font-bold text-indigo-700"
                                            :title="office.name"
                                        >
                                            {{ office.code || office.name }}
                                        </span>
                                    </div>
                                </td>

                                <!-- Duration -->
                                <td class="px-2 py-2.5 font-semibold text-[#1e4e79]">{{ getOfficeDuration(trail) }}</td>
                                <td class="border-r border-slate-200 px-2 py-2.5 text-slate-500">{{ getTransitDuration(trail, index) }}</td>

                                <!-- Remarks -->
                                <td class="break-words px-2 py-2.5 text-left">
                                    <div v-if="trail.remarks" class="text-slate-700">{{ trail.remarks }}</div>
                                    <div v-if="trail.required_actions?.length" class="mt-1 rounded-lg border border-indigo-100 bg-indigo-50 p-1.5 text-[10px] text-indigo-800">
                                        <span class="font-bold">Required:</span> {{ trail.required_actions.map(formatRequiredAction).join(', ') }}
                                        <span v-if="trail.other_action"> - {{ trail.other_action }}</span>
                                    </div>
                                    <div v-if="trail.concerned_personnel" class="mt-1 text-[10px] text-slate-600">
                                        <span class="font-bold">Personnel:</span> {{ trail.concerned_personnel }}
                                    </div>
                                </td>
                            </tr>

                            <tr v-if="!trails || trails.length === 0">
                                <td colspan="11" class="py-12 text-center text-sm text-slate-500">No routing history available for this document.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
