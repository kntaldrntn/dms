<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import DeleteConfirmationModal from '@/components/DeleteConfirmationModal.vue';
import RoutingModal from '@/components/RoutingModal.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { FilePlus, Search, Pencil, Trash2, FastForward } from 'lucide-vue-next';
import { ref, watch } from 'vue';
import { Notyf } from 'notyf';
import 'notyf/notyf.min.css';

const props = defineProps<{
    documents: any;
    departments: Array<{ id: number; name: string; code: string }>;
    filters?: { search?: string };
    flash?: { success?: string; error?: string };
}>();

const searchTerm = ref(props.filters?.search || '');
let searchTimeout: ReturnType<typeof setTimeout>;

const performSearch = () => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        router.get(route('documents.index'), { search: searchTerm.value }, {
            preserveState: true,
            preserveScroll: true,
        });
    }, 300);
};

watch(searchTerm, performSearch);

const clearSearch = () => {
    searchTerm.value = '';
    router.get(route('documents.index'));
};

const goToPage = (url: string) => {
    if (!url) return;
    router.get(
        url,
        { search: searchTerm.value },
        { preserveState: true, preserveScroll: false },
    );
};

const notyf = new Notyf({ duration: 3000, position: { x: 'right', y: 'top' } });
watch(() => props.flash, (flash) => {
    if (flash?.success) notyf.success(flash.success);
    if (flash?.error) notyf.error(flash.error);
}, { immediate: true });

const breadcrumbs = [
    { title: 'Documents', href: '/documents' },
];

// Soft Delete Modal State
const showDeleteModal = ref(false);
const itemToDeleteUrl = ref('');
const itemToDeleteName = ref('');

function openDeleteModal(doc: any) {
    itemToDeleteUrl.value = route('documents.destroy', doc.id);
    itemToDeleteName.value = `Document ${doc.barcode}`;
    showDeleteModal.value = true;
}

// Routing Modal State
const showRoutingModal = ref(false);
const documentToRoute = ref<any>(null);

function openRoutingModal(doc: any) {
    documentToRoute.value = doc;
    showRoutingModal.value = true;
}
</script>

<template>
    <Head title="Document Management" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <!-- Reverted to the exact standardized wrapper from Departments -->
        <div class="flex flex-col gap-6 p-6 w-full mx-auto bg-slate-100 min-h-screen">
            
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold text-slate-800 tracking-tight">System Documents</h1>
                    <p class="text-sm text-slate-500 mt-1">View, track, and manage all registered documents.</p>
                </div>
                
                <div class="flex flex-col sm:flex-row items-center gap-3">
                    <div class="relative w-full sm:w-72">
                        <Search class="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-slate-400" />
                        <input
                            v-model="searchTerm"
                            type="text"
                            placeholder="Search barcode or subject..."
                            class="block w-full rounded-xl border border-slate-300 bg-white py-2 pl-9 pr-10 text-sm focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 shadow-sm"
                        />
                        <button
                            v-if="searchTerm"
                            @click="clearSearch"
                            class="absolute top-1/2 right-3 -translate-y-1/2 text-slate-400 hover:text-slate-600"
                        >
                            ×
                        </button>
                    </div>
                    <Link
                        :href="route('documents.create')"
                        class="w-full sm:w-auto flex items-center justify-center gap-2 rounded-xl bg-emerald-600 px-4 py-2 font-medium text-white shadow-sm hover:bg-emerald-700"
                    >
                        <FilePlus class="h-4 w-4" />
                        New Document
                    </Link>
                </div>
            </div>

            <!-- Table Card: Cleaned up wrapper -->
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm text-slate-700">
                        <!-- Removed all explicit min-w clamps to restore fluid width -->
                        <thead class="bg-slate-100 text-xs text-slate-600 uppercase tracking-wider border-b border-slate-200">
                            <tr>
                                <th class="px-4 py-2.5 font-bold">Barcode</th>
                                <th class="px-4 py-2.5 font-bold">Subject Matter</th>
                                <th class="px-4 py-2.5 font-bold">Source</th>
                                <th class="px-4 py-2.5 font-bold">Target Dept</th>
                                <th class="px-4 py-2.5 text-center font-bold">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200">
                            <tr v-for="doc in documents.data" :key="doc.id" class="hover:bg-slate-50 transition-colors">
                                <!-- whitespace-nowrap keeps the barcode from stacking -->
                                <td class="px-4 py-2 font-mono font-semibold whitespace-nowrap">
                                    <Link :href="route('documents.show', doc.id)" class="text-emerald-600 hover:text-emerald-700 hover:underline">
                                        {{ doc.barcode }}
                                    </Link>
                                </td>
                                <td class="px-4 py-2">
                                    <!-- line-clamp-2 allows it to wrap nicely and take up available space naturally -->
                                    <p class="text-slate-800 font-medium line-clamp-2" :title="doc.subject_matter">{{ doc.subject_matter }}</p>
                                    <p class="text-xs text-slate-500 mt-0.5">{{ doc.document_type?.document_type || 'Unknown Type' }}</p>
                                </td>
                                <!-- whitespace-nowrap prevents these text blocks from getting squished randomly -->
                                <td class="px-4 py-2 text-slate-600 whitespace-nowrap">{{ doc.source_name }}</td>
                                <td class="px-4 py-2 text-slate-600 whitespace-nowrap">{{ doc.department?.name || 'N/A' }}</td>
                                <td class="px-4 py-2 whitespace-nowrap">
                                    <div class="flex justify-center gap-1.5">
                                        <button 
                                            @click="openRoutingModal(doc)" 
                                            class="rounded-md bg-indigo-50 p-1.5 text-indigo-600 transition hover:bg-indigo-100 border border-indigo-100"
                                            title="Start Transaction"
                                        >
                                            <FastForward class="h-4 w-4" />
                                        </button>

                                        <Link 
                                            :href="route('documents.edit', doc.id)" 
                                            class="rounded-md bg-slate-50 p-1.5 text-slate-600 transition hover:bg-slate-100 hover:text-blue-600 border border-slate-200"
                                            title="Edit"
                                        >
                                            <Pencil class="h-4 w-4" />
                                        </Link>
                                        
                                        <button 
                                            @click="openDeleteModal(doc)" 
                                            class="rounded-md bg-red-50 p-1.5 text-red-600 transition hover:bg-red-100 border border-red-100"
                                            title="Deactivate"
                                        >
                                            <Trash2 class="h-4 w-4" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="documents.data.length === 0">
                                <td colspan="5" class="py-12 text-center text-slate-500">
                                    <div class="flex flex-col items-center">
                                        <div class="w-12 h-12 bg-slate-50 rounded-full flex items-center justify-center mb-3 border border-slate-200">
                                            <Search class="h-6 w-6 text-slate-400" />
                                        </div>
                                        <h3 class="text-base font-semibold text-slate-900">No documents found</h3>
                                        <p class="mt-1 mb-5 text-sm text-slate-500 max-w-sm px-4">
                                            {{ searchTerm ? 'We couldn’t find anything matching your search. Try adjusting your keywords.' : 'Get started by registering your first document.' }}
                                        </p>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Reverted Pagination exactly to the standard -->
                <div v-if="documents.last_page > 1" class="flex items-center justify-between border-t border-slate-200 bg-slate-50 px-4 py-3">
                    <p class="text-sm text-slate-500">
                        Showing <span class="font-medium text-slate-700">{{ documents.from }}</span>
                        to <span class="font-medium text-slate-700">{{ documents.to }}</span> of
                        <span class="font-medium text-slate-700">{{ documents.total }}</span> results
                    </p>
                    <div class="flex gap-1">
                        <button
                            v-for="(link, index) in documents.links.slice(1, -1)"
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

            <DeleteConfirmationModal
                v-model:show="showDeleteModal"
                :deleteUrl="itemToDeleteUrl"
                :item-name="itemToDeleteName"
                title="Deactivate Document"
            />

            <RoutingModal
                :show="showRoutingModal"
                :document="documentToRoute"
                :departments="departments"
                @close="showRoutingModal = false"
            />

        </div>
    </AppLayout>
</template>