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

const notyf = new Notyf({ duration: 3000, position: { x: 'right', y: 'top' } });
watch(() => props.flash, (flash) => {
    if (flash?.success) notyf.success(flash.success);
    if (flash?.error) notyf.error(flash.error);
}, { immediate: true });

const breadcrumbs: BreadcrumbItem[] = [
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

// 👈 NEW: Routing Modal State
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
        <div class="flex flex-col gap-6 p-6 w-full mx-auto bg-slate-50/50 min-h-screen">
            
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
                            class="block w-full rounded-xl border border-slate-200 bg-white py-2 pl-9 pr-10 text-sm focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 shadow-sm"
                        />
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

            <div class="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm text-slate-700">
                        <thead class="bg-slate-50/80 text-xs text-slate-500 uppercase tracking-wider border-b border-slate-100">
                            <tr>
                                <th class="px-6 py-4 font-semibold">Barcode</th>
                                <th class="px-6 py-4 font-semibold">Subject Matter</th>
                                <th class="px-6 py-4 font-semibold">Source</th>
                                <th class="px-6 py-4 font-semibold">Target Dept</th>
                                <th class="px-6 py-4 text-center font-semibold">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="doc in documents.data" :key="doc.id" class="hover:bg-slate-50/50 transition-colors">
                                <td class="px-6 py-4 font-mono font-semibold">
                                    <Link :href="route('documents.show', doc.id)" class="text-emerald-600 hover:text-emerald-700 hover:underline">
                                        {{ doc.barcode }}
                                    </Link>
                                </td>
                                <td class="px-6 py-4">
                                    <p class="text-slate-800 font-medium truncate max-w-xs">{{ doc.subject_matter }}</p>
                                    <p class="text-xs text-slate-500 mt-1">{{ doc.document_type?.document_type || 'Unknown Type' }}</p>
                                </td>
                                <td class="px-6 py-4 text-slate-600">{{ doc.source_name }}</td>
                                <td class="px-6 py-4 text-slate-600">{{ doc.department?.name || 'N/A' }}</td>
                                <td class="px-6 py-4">
                                    <div class="flex justify-center gap-2">
                                        
                                        <!-- 👈 NEW: Start Transaction / Routing Button -->
                                        <button 
                                            @click="openRoutingModal(doc)" 
                                            class="rounded-lg bg-indigo-50 p-2 text-indigo-600 transition hover:bg-indigo-100 border border-indigo-100"
                                            title="Start Transaction"
                                        >
                                            <FastForward class="h-4 w-4" />
                                        </button>

                                        <!-- Edit Button -->
                                        <Link 
                                            :href="route('documents.edit', doc.id)" 
                                            class="rounded-lg bg-slate-50 p-2 text-slate-600 transition hover:bg-slate-100 hover:text-blue-600 border border-slate-200"
                                            title="Edit"
                                        >
                                            <Pencil class="h-4 w-4" />
                                        </Link>
                                        
                                        <!-- Soft Delete Button -->
                                        <button 
                                            @click="openDeleteModal(doc)" 
                                            class="rounded-lg bg-red-50 p-2 text-red-600 transition hover:bg-red-100 border border-red-100"
                                            title="Deactivate"
                                        >
                                            <Trash2 class="h-4 w-4" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="documents.data.length === 0">
                                <td colspan="5" class="py-12 text-center text-slate-500">No documents found. Start by registering a new document.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Global Soft Delete Modal -->
            <DeleteConfirmationModal
                v-model:show="showDeleteModal"
                :deleteUrl="itemToDeleteUrl"
                :item-name="itemToDeleteName"
                title="Deactivate Document"
            />

            <!-- 👈 NEW: Routing Modal -->
            <RoutingModal
                :show="showRoutingModal"
                :document="documentToRoute"
                :departments="departments"
                @close="showRoutingModal = false"
            />

        </div>
    </AppLayout>
</template>