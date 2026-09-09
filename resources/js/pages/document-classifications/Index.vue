<script setup lang="ts">
import DeleteConfirmationModal from '@/components/DeleteConfirmationModal.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { Pencil, Plus, Search, Trash2, RotateCcw, X } from 'lucide-vue-next';
import { Notyf } from 'notyf';
import 'notyf/notyf.min.css';
import { ref, watch } from 'vue';

const props = defineProps<{
    classifications: {
        data: Array<{
            id: number;
            name: string;
            description: string | null;
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
            route('document-classifications.index'),
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
    router.get(route('document-classifications.index'));
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
        title: 'Document Classifications',
        href: '/document-classifications',
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
    name: '',
    description: '',
    state: 1,
});

// Open modal for new classification
function openAddDialog() {
    isEdit.value = false;
    editingId.value = null;
    form.reset();
    form.state = 1; 
    showDialog.value = true;
}

// Open modal for editing
function openEditDialog(classification: any) {
    isEdit.value = true;
    editingId.value = classification.id;
    form.name = classification.name;
    form.description = classification.description || '';
    form.state = classification.state;
    showDialog.value = true;
}

// Deactivate modal (Soft Delete)
function openDeleteModal(classification: { id: number; name: string }) {
    itemToDeleteUrl.value = route('document-classifications.destroy', classification.id);
    itemToDeleteName.value = classification.name;
    showDeleteModal.value = true;
}

// Quick Reactivate Action
function reactivateClassification(classification: any) {
    router.put(route('document-classifications.update', classification.id), {
        name: classification.name,
        description: classification.description,
        state: 1 // Force state back to 1 (active)
    }, {
        preserveScroll: true,
    });
}

// Submit form
function submitForm() {
    if (isEdit.value && editingId.value !== null) {
        form.put(route('document-classifications.update', editingId.value), {
            onSuccess: () => {
                showDialog.value = false;
                form.reset();
            },
        });
    } else {
        form.post(route('document-classifications.store'), {
            onSuccess: () => {
                showDialog.value = false;
                form.reset();
            },
        });
    }
}
</script>

<template>
    <Head title="Document Classifications" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <!-- Main Wrapper: Changed to solid bg-slate-100 for better contrast -->
        <div class="flex flex-col gap-6 p-6 w-full mx-auto bg-slate-100 min-h-screen">
            
            <!-- Header & Actions Toolbar -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold text-slate-800 tracking-tight">System Document Classifications</h1>
                    <p class="text-sm text-slate-500 mt-1">Manage confidentiality levels and sensitivity categories for system records.</p>
                </div>
                
                <div class="flex flex-col sm:flex-row items-center gap-3">
                    <!-- Search -->
                    <div class="relative w-full sm:w-72">
                        <Search class="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-slate-400" />
                        <input
                            v-model="searchTerm"
                            type="text"
                            placeholder="Search classifications..."
                            class="block w-full rounded-xl border border-slate-300 bg-white py-2 pl-9 pr-10 text-sm placeholder-slate-400 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 shadow-sm"
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
                        Create Classification
                    </button>
                </div>
            </div>

            <!-- Table Card: Darkened border and shadow -->
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm text-slate-700">
                        <!-- Header: Solid bg-slate-100, border-slate-200, tightened padding -->
                        <thead class="bg-slate-100 text-xs text-slate-600 uppercase tracking-wider border-b border-slate-200">
                            <tr>
                                <th class="px-4 py-2.5 font-bold w-24">ID</th>
                                <th class="px-4 py-2.5 font-bold">Classification Name</th>
                                <!-- <th class="px-4 py-2.5 font-bold">Description</th> -->
                                <th class="px-4 py-2.5 font-bold w-32">Status</th>
                                <th class="px-4 py-2.5 text-center font-bold w-40">Actions</th>
                            </tr>
                        </thead>
                        <!-- Body: Darkened row dividers -->
                        <tbody class="divide-y divide-slate-200">
                            <!-- Rows: Solid hover bg, adjusted inactive opacity, tightened padding -->
                            <tr v-for="classification in classifications.data" :key="classification.id" class="transition-colors hover:bg-slate-50" :class="{'opacity-75 bg-slate-50': classification.state === 0}">
                                <td class="px-4 py-2 font-mono text-sm text-slate-500">{{ classification.id }}</td>
                                <td class="px-4 py-2 font-medium text-slate-800">{{ classification.name }}</td>
                                <!-- <td class="px-4 py-2 text-slate-500">
                                    {{ classification.description || 'No description provided.' }}
                                </td> -->
                                
                                <!-- Status Column -->
                                <td class="px-4 py-2">
                                    <span v-if="classification.state === 1" class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-emerald-50 text-emerald-700 border border-emerald-100">
                                        <span class="w-1.5 h-1.5 bg-emerald-500 rounded-full mr-1.5"></span> Active
                                    </span>
                                    <span v-else class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-slate-100 text-slate-600 border border-slate-200">
                                        <span class="w-1.5 h-1.5 bg-slate-400 rounded-full mr-1.5"></span> Inactive
                                    </span>
                                </td>
                                <td class="px-4 py-2">
                                    <div class="flex justify-center gap-1.5">
                                        <!-- Edit Button -->
                                        <button
                                            class="rounded-md bg-slate-50 p-1.5 text-slate-600 transition hover:bg-slate-100 border border-slate-200"
                                            @click="openEditDialog(classification)"
                                            title="Edit"
                                        >
                                            <Pencil class="h-4 w-4" />
                                        </button>
                                        
                                        <!-- Delete (Deactivate) Button -->
                                        <button
                                            v-if="classification.state === 1"
                                            class="rounded-md bg-red-50 p-1.5 text-red-600 transition hover:bg-red-100 border border-red-100"
                                            @click="openDeleteModal(classification)"
                                            title="Deactivate"
                                        >
                                            <Trash2 class="h-4 w-4" />
                                        </button>

                                        <!-- Reactivate Button -->
                                        <button
                                            v-else
                                            class="rounded-md bg-blue-50 p-1.5 text-blue-600 transition hover:bg-blue-100 border border-blue-100"
                                            @click="reactivateClassification(classification)"
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
                    <div v-if="classifications.data.length === 0" class="py-12 text-center">
                        <div class="flex flex-col items-center">
                            <div class="w-12 h-12 bg-slate-50 rounded-full flex items-center justify-center mb-3 border border-slate-200">
                                <Search class="h-6 w-6 text-slate-400" />
                            </div>
                            <h3 class="text-base font-semibold text-slate-900">No classifications found</h3>
                            <p class="mt-1 mb-5 text-sm text-slate-500 max-w-sm">
                                {{ searchTerm ? 'We couldn’t find anything matching your search. Try adjusting your keywords.' : 'Get started by creating your first document classification.' }}
                            </p>
                            <button
                                v-if="!searchTerm"
                                @click="openAddDialog"
                                class="rounded-xl bg-emerald-600 px-5 py-2 font-medium text-white shadow-sm hover:bg-emerald-700 transition"
                            >
                                Create Classification
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Pagination: Solidified the background and border -->
                <div v-if="classifications.last_page > 1" class="flex items-center justify-between border-t border-slate-200 bg-slate-50 px-4 py-3">
                    <p class="text-sm text-slate-500">
                        Showing <span class="font-medium text-slate-700">{{ classifications.from }}</span>
                        to <span class="font-medium text-slate-700">{{ classifications.to }}</span> of
                        <span class="font-medium text-slate-700">{{ classifications.total }}</span> results
                    </p>
                    <div class="flex gap-1">
                        <button
                            v-for="(link, index) in classifications.links.slice(1, -1)"
                            :key="index"
                            @click="goToPage(String(link.url))"
                            :disabled="!link.url"
                            class="rounded-lg px-3 py-1 text-sm font-medium transition border"
                            :class="[
                                link.active ? 'bg-emerald-600 text-white border-emerald-600 shadow-sm' : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-50',
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
                title="Deactivate Classification"
            />

            <!-- Form Modal -->
            <Transition name="fade">
                <div v-if="showDialog" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 backdrop-blur-sm px-4">
                    <div class="relative w-full max-w-md rounded-2xl bg-white p-6 shadow-xl border border-slate-200">
                        
                        <!-- Standardized Close Button -->
                        <button
                            @click="showDialog = false"
                            class="absolute top-4 right-4 rounded-full p-1.5 text-slate-400 transition-colors hover:bg-slate-100 hover:text-slate-600"
                            title="Close"
                        >
                            <X class="h-5 w-5" />
                        </button>

                        <h2 class="mb-6 pr-8 text-lg font-bold text-slate-800 tracking-tight">
                            {{ isEdit ? 'Edit Classification' : 'Create Classification' }}
                        </h2>
                        
                        <form @submit.prevent="submitForm" class="space-y-4">
                            <div>
                                <label class="mb-1.5 block text-sm font-medium text-slate-700" for="name">Classification Name</label>
                                <input
                                    v-model="form.name"
                                    id="name"
                                    type="text"
                                    class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 placeholder-slate-400 shadow-sm"
                                    required
                                    placeholder="e.g. Confidential, Public"
                                />
                                <p v-if="form.errors.name" class="mt-1 text-xs text-red-500">{{ form.errors.name }}</p>
                            </div>
                            <!-- 
                            <div>
                                <label class="mb-1.5 block text-sm font-medium text-slate-700" for="description">Description</label>
                                <textarea
                                    v-model="form.description"
                                    id="description"
                                    rows="3"
                                    class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 placeholder-slate-400 shadow-sm"
                                    placeholder="Optional description of when to use this classification..."
                                ></textarea>
                                <p v-if="form.errors.description" class="mt-1 text-xs text-red-500">{{ form.errors.description }}</p>
                            </div> 
                            -->
                            
                            <div class="pt-3 flex justify-end gap-3">
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
                                    {{ form.processing ? 'Saving...' : isEdit ? 'Update' : 'Create' }}
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