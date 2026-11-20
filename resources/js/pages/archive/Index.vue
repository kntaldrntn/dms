<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Eye, Lock, RotateCcw, Search } from 'lucide-vue-next';
import { computed, reactive, ref } from 'vue';

interface Option {
    id: number;
    name: string;
}

interface ArchiveRow {
    id: number;
    barcode: string;
    received_at: string | null;
    document_type: { code: string; name: string } | null;
    transaction_type: string | null;
    source_type: string | null;
    source_location: string | null;
    source_name: string | null;
    subject_matter: string | null;
    details_hidden: boolean;
    status: string;
    holders: Array<{ code: string; name: string }>;
}

interface Filters {
    keyword?: string;
    document_type_id?: number | string;
    transaction_type_id?: number | string;
    classification_id?: number | string;
    delivery_method_id?: number | string;
    office_id?: number | string;
    source_type?: string;
    status?: string;
    from?: string;
    to?: string;
}

const props = defineProps<{
    documents: {
        data: ArchiveRow[];
        total: number;
        from: number | null;
        to: number | null;
        last_page: number;
        links: Array<{ url: string | null; label: string; active: boolean }>;
    };
    filters: Filters;
    perPage: number;
    perPageOptions: number[];
    options: {
        documentTypes: Option[];
        transactionTypes: Option[];
        classifications: Option[];
        deliveryMethods: Option[];
        offices: Array<{ id: number; code: string; name: string }>;
    };
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Archive', href: '/archive' }];

const blankFilters = (): Required<Record<keyof Filters, string>> => ({
    keyword: '',
    document_type_id: '',
    transaction_type_id: '',
    classification_id: '',
    delivery_method_id: '',
    office_id: '',
    source_type: '',
    status: '',
    from: '',
    to: '',
});

// Every field is a string in the form ('' = any), whatever type the server echoed back.
const form = reactive({
    ...blankFilters(),
    ...Object.fromEntries(Object.entries(props.filters).map(([key, value]) => [key, String(value ?? '')])),
});
const perPage = ref(props.perPage);
const searching = ref(false);

const page = usePage();
const errors = computed(() => (page.props.errors ?? {}) as Record<string, string>);

const activeFilterCount = computed(() => Object.keys(props.filters).length);

const visit = (query: Record<string, string | number>) => {
    router.get(route('archive.index'), query, {
        preserveState: true,
        preserveScroll: true,
        onStart: () => (searching.value = true),
        onFinish: () => (searching.value = false),
    });
};

// Only filled fields go in the address, so a shared or bookmarked link stays short and readable.
const filledQuery = () => {
    const query: Record<string, string | number> = Object.fromEntries(Object.entries(form).filter(([, value]) => value.trim() !== ''));
    if (perPage.value !== props.perPageOptions[0]) query.per_page = perPage.value;
    return query;
};

const search = () => visit(filledQuery());

const clearFilters = () => {
    Object.assign(form, blankFilters());
    search();
};

const goToPage = (url: string | null) => {
    if (url) router.get(url, {}, { preserveState: true, preserveScroll: true });
};

// Laravel's Previous/Next labels carry HTML entities; plain text avoids v-html.
const pageLabel = (label: string) => label.replace('&laquo;', '«').replace('&raquo;', '»');

const dateFormat = new Intl.DateTimeFormat('en-US', { month: 'short', day: 'numeric', year: 'numeric', timeZone: 'Asia/Manila' });
const formatDate = (iso: string | null) => (iso ? dateFormat.format(new Date(iso)) : '—');

const sourceLabel = (doc: ArchiveRow) => {
    if (doc.details_hidden) return doc.source_type ?? '—';
    const location = doc.source_location && doc.source_location !== 'N/A' ? ` (${doc.source_location})` : '';
    return [`${doc.source_type ?? ''}${location}`.trim(), doc.source_name].filter(Boolean).join(' · ') || '—';
};

const officeLabel = (doc: ArchiveRow) => (doc.status === 'Completed' ? '—' : doc.holders.map((office) => office.code).join(', ') || '—');

const numberFormat = new Intl.NumberFormat('en-US');

const fieldClass =
    'w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm placeholder-slate-400 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500';
const labelClass = 'mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-600';
</script>

<template>
    <Head title="Archive" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex min-h-screen w-full flex-col gap-6 bg-slate-100 p-6">
            <h1 class="sr-only">Archive</h1>

            <form
                class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"
                role="search"
                aria-label="Search the archive"
                @submit.prevent="search"
            >
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="sm:col-span-2">
                        <label for="archive-keyword" :class="labelClass">Keyword</label>
                        <div class="relative">
                            <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" aria-hidden="true" />
                            <input
                                id="archive-keyword"
                                v-model="form.keyword"
                                type="search"
                                maxlength="100"
                                placeholder="Barcode, subject matter or source name"
                                :class="[fieldClass, 'pl-9']"
                            />
                        </div>
                        <InputError class="mt-1 text-xs" :message="errors.keyword" />
                    </div>

                    <div>
                        <label for="archive-status" :class="labelClass">Status</label>
                        <select id="archive-status" v-model="form.status" :class="fieldClass">
                            <option value="">All</option>
                            <option value="ongoing">Ongoing</option>
                            <option value="completed">Completed</option>
                        </select>
                    </div>

                    <div>
                        <label for="archive-office" :class="labelClass">Office</label>
                        <select id="archive-office" v-model="form.office_id" :class="fieldClass">
                            <option value="">All</option>
                            <option v-for="office in options.offices" :key="office.id" :value="String(office.id)">
                                {{ office.code }} — {{ office.name }}
                            </option>
                        </select>
                        <InputError class="mt-1 text-xs" :message="errors.office_id" />
                    </div>

                    <div>
                        <label for="archive-document-type" :class="labelClass">Document Type</label>
                        <select id="archive-document-type" v-model="form.document_type_id" :class="fieldClass">
                            <option value="">All</option>
                            <option v-for="option in options.documentTypes" :key="option.id" :value="String(option.id)">{{ option.name }}</option>
                        </select>
                        <InputError class="mt-1 text-xs" :message="errors.document_type_id" />
                    </div>

                    <div>
                        <label for="archive-transaction-type" :class="labelClass">Transaction Type</label>
                        <select id="archive-transaction-type" v-model="form.transaction_type_id" :class="fieldClass">
                            <option value="">All</option>
                            <option v-for="option in options.transactionTypes" :key="option.id" :value="String(option.id)">{{ option.name }}</option>
                        </select>
                        <InputError class="mt-1 text-xs" :message="errors.transaction_type_id" />
                    </div>

                    <div>
                        <label for="archive-classification" :class="labelClass">Classification</label>
                        <select id="archive-classification" v-model="form.classification_id" :class="fieldClass">
                            <option value="">All</option>
                            <option v-for="option in options.classifications" :key="option.id" :value="String(option.id)">{{ option.name }}</option>
                        </select>
                        <InputError class="mt-1 text-xs" :message="errors.classification_id" />
                    </div>

                    <div>
                        <label for="archive-delivery-method" :class="labelClass">Delivery Method</label>
                        <select id="archive-delivery-method" v-model="form.delivery_method_id" :class="fieldClass">
                            <option value="">All</option>
                            <option v-for="option in options.deliveryMethods" :key="option.id" :value="String(option.id)">{{ option.name }}</option>
                        </select>
                        <InputError class="mt-1 text-xs" :message="errors.delivery_method_id" />
                    </div>

                    <div>
                        <label for="archive-source-type" :class="labelClass">Source</label>
                        <select id="archive-source-type" v-model="form.source_type" :class="fieldClass">
                            <option value="">All</option>
                            <option value="Internal">Internal</option>
                            <option value="External">External</option>
                        </select>
                    </div>

                    <div>
                        <label for="archive-from" :class="labelClass">Received From</label>
                        <input id="archive-from" v-model="form.from" type="date" :class="fieldClass" />
                        <InputError class="mt-1 text-xs" :message="errors.from" />
                    </div>

                    <div>
                        <label for="archive-to" :class="labelClass">Received To</label>
                        <input id="archive-to" v-model="form.to" type="date" :min="form.from || undefined" :class="fieldClass" />
                        <InputError class="mt-1 text-xs" :message="errors.to" />
                    </div>

                    <div class="flex items-end gap-2 sm:col-span-2">
                        <button
                            type="submit"
                            :disabled="searching"
                            class="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-5 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-emerald-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2 disabled:opacity-60"
                        >
                            <Search class="h-4 w-4" aria-hidden="true" />
                            {{ searching ? 'Searching…' : 'Search' }}
                        </button>
                        <button
                            v-if="activeFilterCount > 0"
                            type="button"
                            class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 shadow-sm transition hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2"
                            @click="clearFilters"
                        >
                            <RotateCcw class="h-4 w-4" aria-hidden="true" />
                            Clear filters
                        </button>
                    </div>
                </div>
            </form>

            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="flex flex-col gap-3 border-b border-slate-200 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-sm text-slate-600" aria-live="polite">
                        <template v-if="documents.total > 0">
                            Showing <span class="font-semibold text-slate-800">{{ documents.from }}–{{ documents.to }}</span> of
                            <span class="font-semibold text-slate-800">{{ numberFormat.format(documents.total) }}</span>
                            {{ documents.total === 1 ? 'document' : 'documents' }}
                        </template>
                        <template v-else>No documents found</template>
                    </p>
                    <div class="flex items-center gap-2 text-sm text-slate-600">
                        <label for="archive-per-page">Show</label>
                        <select
                            id="archive-per-page"
                            v-model.number="perPage"
                            class="rounded-lg border border-slate-300 bg-white py-1 pl-2 pr-8 text-sm shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                            @change="search"
                        >
                            <option v-for="size in perPageOptions" :key="size" :value="size">{{ size }}</option>
                        </select>
                        <span>per page</span>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm text-slate-700">
                        <thead class="border-b border-slate-200 bg-slate-100 text-xs uppercase tracking-wider text-slate-600">
                            <tr>
                                <th scope="col" class="px-4 py-2.5 font-bold">Barcode</th>
                                <th scope="col" class="whitespace-nowrap px-4 py-2.5 font-bold">Date Received</th>
                                <th scope="col" class="px-4 py-2.5 font-bold">Doc. Type</th>
                                <th scope="col" class="px-4 py-2.5 font-bold">Source</th>
                                <th scope="col" class="px-4 py-2.5 font-bold">Subject Matter</th>
                                <th scope="col" class="px-4 py-2.5 font-bold">Office</th>
                                <th scope="col" class="px-4 py-2.5 font-bold">Status</th>
                                <th scope="col" class="px-4 py-2.5 text-center font-bold">View</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200">
                            <tr v-for="doc in documents.data" :key="doc.id" class="align-top transition-colors hover:bg-slate-50">
                                <td class="whitespace-nowrap px-4 py-2.5 font-mono font-semibold text-slate-800">{{ doc.barcode }}</td>
                                <td class="whitespace-nowrap px-4 py-2.5">{{ formatDate(doc.received_at) }}</td>
                                <td class="px-4 py-2.5">
                                    <span v-if="doc.document_type" :title="doc.document_type.name">{{ doc.document_type.code }}</span>
                                    <span v-else class="text-slate-400">—</span>
                                </td>
                                <td class="min-w-40 px-4 py-2.5">{{ sourceLabel(doc) }}</td>
                                <td class="min-w-64 max-w-md px-4 py-2.5">
                                    <span v-if="doc.details_hidden" class="inline-flex items-center gap-1.5 italic text-slate-500">
                                        <Lock class="h-3.5 w-3.5" aria-hidden="true" />
                                        Confidential
                                    </span>
                                    <span v-else class="line-clamp-2" :title="doc.subject_matter ?? undefined">{{ doc.subject_matter || '—' }}</span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-2.5" :title="doc.holders.map((office) => office.name).join(', ') || undefined">
                                    {{ officeLabel(doc) }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-2.5">
                                    <span
                                        class="inline-flex items-center rounded border px-2 py-0.5 text-xs font-medium"
                                        :class="
                                            doc.status === 'Completed'
                                                ? 'border-emerald-100 bg-emerald-50 text-emerald-700'
                                                : 'border-amber-100 bg-amber-50 text-amber-700'
                                        "
                                    >
                                        {{ doc.status }}
                                    </span>
                                </td>
                                <td class="px-4 py-2.5 text-center">
                                    <Link
                                        :href="route('documents.show', doc.id)"
                                        class="inline-flex rounded-md border border-slate-200 bg-slate-50 p-1.5 text-slate-600 transition hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500"
                                        :aria-label="`View document ${doc.barcode}`"
                                        title="View"
                                    >
                                        <Eye class="h-4 w-4" aria-hidden="true" />
                                    </Link>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <div v-if="documents.data.length === 0" class="py-12 text-center">
                        <h2 class="text-base font-semibold text-slate-900">No documents found</h2>
                        <p class="mt-1 text-sm text-slate-500">
                            {{
                                activeFilterCount > 0
                                    ? 'Nothing matches these filters. Try fewer filters or a wider date range.'
                                    : 'No documents yet.'
                            }}
                        </p>
                    </div>
                </div>

                <nav
                    v-if="documents.last_page > 1"
                    aria-label="Archive pages"
                    class="flex flex-wrap items-center justify-end gap-1 border-t border-slate-200 bg-slate-50 px-4 py-3"
                >
                    <button
                        v-for="(link, index) in documents.links"
                        :key="index"
                        type="button"
                        :disabled="!link.url"
                        :aria-current="link.active ? 'page' : undefined"
                        class="rounded-lg border px-3 py-1 text-sm font-medium transition disabled:cursor-default disabled:opacity-50"
                        :class="
                            link.active
                                ? 'border-emerald-600 bg-emerald-600 text-white shadow-sm'
                                : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50'
                        "
                        @click="goToPage(link.url)"
                    >
                        {{ pageLabel(link.label) }}
                    </button>
                </nav>
            </div>
        </div>
    </AppLayout>
</template>
