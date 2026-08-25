<script setup lang="ts">
import DeleteConfirmationModal from '@/components/DeleteConfirmationModal.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { Pencil, Plus, Search, Trash2, RotateCcw } from 'lucide-vue-next';
import { Notyf } from 'notyf';
import 'notyf/notyf.min.css';
import { ref, watch } from 'vue';

const props = defineProps<{
    documenttypes: {
        data: Array<{
            id: number;
            document_code: string;
            document_type: string;
            state: number;
        }>;
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
        from: number;
        to: number;
        links: Array<{
            url: string | null;
            label: string;
            active: boolean;
        }>;
    };
    filters?: {
        search?: string;
    };
    flash?: {
        success?: string;
        error?: string;
    };
}>();

// Search state
const searchTerm = ref(props.filters?.search || '');
const isSearching = ref(false);

// Debounced search
let searchTimeout: ReturnType<typeof setTimeout>;

const performSearch = () => {
    clearTimeout(searchTimeout);

    searchTimeout = setTimeout(() => {
        isSearching.value = true;

        router.get(
            route('document-types.index'),
            {
                search: searchTerm.value,
            },
            {
                preserveState: true,
                preserveScroll: true,
                onFinish: () => {
                    isSearching.value = false;
                },
            },
        );
    }, 300);
};

// Watch for search changes
watch(searchTerm, performSearch);

// Clear search
const clearSearch = () => {
    searchTerm.value = '';
    router.get(route('document-types.index'));
};

// Pagination function
const goToPage = (url: string) => {
    if (!url) return;

    router.get(
        url,
        {
            search: searchTerm.value,
        },
        {
            preserveState: true,
            preserveScroll: false,
        },
    );
};

const notyf = new Notyf({
    duration: 3000,
    position: { x: 'right', y: 'top' },
});

watch(
    () => props.flash,
    (flash) => {
        if (flash?.success) {
            notyf.success(flash.success);
        }
        if (flash?.error) {
            notyf.error(flash.error);
        }
    },
    { immediate: true },
);

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Document Types Management',
        href: '/document-types',
    },
];

// Modal state
const showDialog = ref(false);
const isEdit = ref(false);
const editingId = ref<number | null>(null);

const showDeleteModal = ref(false);
const itemToDeleteUrl = ref('');
const itemToDeleteName = ref('');

// Form state
const form = useForm({
    document_code: '',
    document_type: '',
    state: 1, // Default to Active
});

// Open modal for new document type
function openAddDialog() {
    isEdit.value = false;
    editingId.value = null;
    form.reset();
    form.state = 1; 
    showDialog.value = true;
}

// Open modal for editing
function openEditDialog(docType: any) {
    isEdit.value = true;
    editingId.value = docType.id;
    form.document_code = docType.document_code;
    form.document_type = docType.document_type;
    form.state = docType.state;
    showDialog.value = true;
}

// Deactivate modal (Soft Delete)
function openDeleteModal(docType: { id: number; document_type: string }) {
    itemToDeleteUrl.value = route('document-types.destroy', docType.id);
    itemToDeleteName.value = docType.document_type;
    showDeleteModal.value = true;
}

// Quick Reactivate Action
function reactivateDocumentType(docType: any) {
    router.put(route('document-types.update', docType.id), {
        document_type: docType.document_type,
        document_code: docType.document_code,
        state: 1 // Force state back to 1
    }, {
        preserveScroll: true,
    });
}

// Submit form
function submitForm() {
    if (isEdit.value && editingId.value !== null) {
        form.put(route('document-types.update', editingId.value), {
            onSuccess: () => {
                showDialog.value = false;
                form.reset();
            },
        });
    } else {
        form.post(route('document-types.store'), {
            onSuccess: () => {
                showDialog.value = false;
                form.reset();
            },
        });
    }
}
</script>

<template>
    <Head title="Document Types" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <!-- Main Wrapper aligned with Dashboard theme -->
        <div class="flex flex-col gap-6 p-6 w-full mx-auto bg-slate-50/50 min-h-screen">
            
            <!-- Header & Actions Toolbar -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold text-slate-800 tracking-tight">System Document Types</h1>
                    <p class="text-sm text-slate-500 mt-1">Manage global document categories and their routing codes.</p>
                </div>
                
                <div class="flex flex-col sm:flex-row items-center gap-3">
                    <!-- Search -->
                    <div class="relative w-full sm:w-72">
                        <Search class="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-slate-400" />
                        <input
                            v-model="searchTerm"
                            type="text"
                            placeholder="Search document types..."
                            class="block w-full rounded-xl border border-slate-200 bg-white py-2 pl-9 pr-10 text-sm placeholder-slate-400 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 shadow-sm"
                        />
                        <button
                            v-if="searchTerm"
                            @click="clearSearch"
                            class="absolute top-1/2 right-3 -translate-y-1/2 text-slate-400 hover:text-slate-600"
                        >
                            ×
                        </button>
                    </div>

                    <!-- Create Button -->
                    <button
                        class="w-full sm:w-auto flex items-center justify-center gap-2 rounded-xl bg-emerald-600 px-4 py-2 font-medium text-white shadow-sm transition hover:bg-emerald-700"
                        @click="openAddDialog"
                    >
                        <Plus class="h-4 w-4" />
                        Create Document Type
                    </button>
                </div>
            </div>

            <!-- Table Card -->
            <div class="bg-white rounded-2xl border border-slate-100 shadow-[0_2px_10px_-3px_rgba(6,81,237,0.03)] overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm text-slate-700">
                        <thead class="bg-slate-50/80 text-xs text-slate-500 uppercase tracking-wider border-b border-slate-100">
                            <tr>
                                <th class="px-6 py-4 font-semibold">ID</th>
                                <th class="px-6 py-4 font-semibold">Document Type</th>
                                <th class="px-6 py-4 font-semibold">Code</th>
                                <th class="px-6 py-4 font-semibold">Status</th>
                                <th class="px-6 py-4 text-center font-semibold">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="docType in documenttypes.data" :key="docType.id" class="transition-colors hover:bg-slate-50/50" :class="{'opacity-60 bg-slate-50/30': docType.state === 0}">
                                <td class="px-6 py-4 font-mono text-xs text-slate-500">{{ docType.id }}</td>
                                <td class="px-6 py-4 font-medium text-slate-800">{{ docType.document_type }}</td>
                                <td class="px-6 py-4">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-md font-mono text-xs font-medium bg-slate-100 text-slate-700 border border-slate-200">
                                        {{ docType.document_code || 'N/A' }}
                                    </span>
                                </td>
                                <!-- Status Column -->
                                <td class="px-6 py-4">
                                    <span v-if="docType.state === 1" class="inline-flex items-center px-2 py-1 rounded-md text-xs font-medium bg-emerald-50 text-emerald-700 border border-emerald-100">
                                        <span class="w-1.5 h-1.5 bg-emerald-500 rounded-full mr-1.5"></span> Active
                                    </span>
                                    <span v-else class="inline-flex items-center px-2 py-1 rounded-md text-xs font-medium bg-slate-100 text-slate-500 border border-slate-200">
                                        <span class="w-1.5 h-1.5 bg-slate-400 rounded-full mr-1.5"></span> Inactive
                                    </span>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="flex justify-center gap-2">
                                        <!-- Edit Button -->
                                        <button
                                            class="rounded-lg bg-slate-50 p-2 text-slate-600 transition hover:bg-slate-100 border border-slate-200"
                                            @click="openEditDialog(docType)"
                                            title="Edit"
                                        >
                                            <Pencil class="h-4 w-4" />
                                        </button>
                                        
                                        <!-- Delete (Deactivate) Button -->
                                        <button
                                            v-if="docType.state === 1"
                                            class="rounded-lg bg-red-50 p-2 text-red-600 transition hover:bg-red-100 border border-red-100"
                                            @click="openDeleteModal(docType)"
                                            title="Deactivate"
                                        >
                                            <Trash2 class="h-4 w-4" />
                                        </button>

                                        <!-- Reactivate Button -->
                                        <button
                                            v-else
                                            class="rounded-lg bg-blue-50 p-2 text-blue-600 transition hover:bg-blue-100 border border-blue-100"
                                            @click="reactivateDocumentType(docType)"
                                            title="Reactivate"
                                        >
                                            <RotateCcw class="h-4 w-4" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <!-- Empty State -->
                    <div v-if="documenttypes.data.length === 0" class="py-16 text-center">
                        <div class="flex flex-col items-center">
                            <div class="w-12 h-12 bg-slate-50 rounded-full flex items-center justify-center mb-4">
                                <Search class="h-6 w-6 text-slate-400" />
                            </div>
                            <h3 class="text-base font-semibold text-slate-900">No document types found</h3>
                            <p class="mt-1 mb-6 text-sm text-slate-500 max-w-sm">
                                {{ searchTerm ? 'We couldn’t find anything matching your search. Try adjusting your keywords.' : 'Get started by creating your first document type.' }}
                            </p>
                            <button
                                v-if="!searchTerm"
                                @click="openAddDialog"
                                class="rounded-xl bg-emerald-600 px-5 py-2 font-medium text-white shadow-sm hover:bg-emerald-700 transition"
                            >
                                Create Document Type
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Pagination -->
                <div v-if="documenttypes.last_page > 1" class="flex items-center justify-between border-t border-slate-100 bg-slate-50/50 px-6 py-4">
                    <p class="text-sm text-slate-500">
                        Showing <span class="font-medium text-slate-700">{{ documenttypes.from }}</span>
                        to <span class="font-medium text-slate-700">{{ documenttypes.to }}</span> of
                        <span class="font-medium text-slate-700">{{ documenttypes.total }}</span> results
                    </p>
                    <div class="flex gap-1">
                        <button
                            v-for="(link, index) in documenttypes.links.slice(1, -1)"
                            :key="index"
                            @click="goToPage(String(link.url))"
                            :disabled="!link.url"
                            class="rounded-lg px-3.5 py-1.5 text-sm font-medium transition"
                            :class="[
                                link.active ? 'bg-emerald-600 text-white shadow-sm' : 'bg-white text-slate-600 border border-slate-200 hover:bg-slate-50',
                            ]"
                        >
                            {{ link.label }}
                        </button>
                    </div>
                </div>
            </div>

            <!-- Soft Delete Modal -->
            <DeleteConfirmationModal
                v-model:show="showDeleteModal"
                :deleteUrl="itemToDeleteUrl"
                :item-name="itemToDeleteName"
                title="Deactivate Document Type"
            />

            <!-- Form Modal -->
            <Transition name="fade">
                <div v-if="showDialog" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 backdrop-blur-sm">
                    <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl border border-slate-100">
                        <h2 class="mb-6 text-lg font-bold text-slate-800 tracking-tight">
                            {{ isEdit ? 'Edit Document Type' : 'Create Document Type' }}
                        </h2>
                        <form @submit.prevent="submitForm" class="space-y-5">
                            <div>
                                <label class="mb-1.5 block text-sm font-medium text-slate-700" for="document_type">Document Type Name</label>
                                <input
                                    v-model="form.document_type"
                                    id="document_type"
                                    type="text"
                                    class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 placeholder-slate-400 shadow-sm"
                                    required
                                    placeholder="e.g. Purchase Request"
                                />
                                <p v-if="form.errors.document_type" class="mt-1.5 text-xs text-red-500">{{ form.errors.document_type }}</p>
                            </div>
                            <div>
                                <label class="mb-1.5 block text-sm font-medium text-slate-700" for="document_code">Type Code</label>
                                <input
                                    v-model="form.document_code"
                                    id="document_code"
                                    type="text"
                                    class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-mono focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 placeholder-slate-400 shadow-sm"
                                    required
                                    placeholder="e.g. PR"
                                />
                                <p v-if="form.errors.document_code" class="mt-1.5 text-xs text-red-500">{{ form.errors.document_code }}</p>
                            </div>
                            
                            <div class="pt-2 flex justify-end gap-3">
                                <button
                                    type="button"
                                    class="rounded-xl bg-white border border-slate-200 px-5 py-2.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50"
                                    @click="showDialog = false"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    class="rounded-xl bg-emerald-600 px-5 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-emerald-700 disabled:opacity-50"
                                    :disabled="form.processing"
                                >
                                    {{ form.processing ? 'Saving...' : isEdit ? 'Update Document Type' : 'Create Document Type' }}
                                </button>
                            </div>
                        </form>
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