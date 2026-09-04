<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/vue3';
import { Save, Search } from 'lucide-vue-next';
import { ref, onMounted, computed } from 'vue';
import { Notyf } from 'notyf';
import 'notyf/notyf.min.css';

const props = defineProps<{
    departments: Array<{ id: number; name: string }>;
    documentTypes: Array<{ id: number; document_code: string }>;
    existingStandards: Array<{ department_id: number; document_type_id: number; allocated_minutes: number }>;
    flash?: { success?: string; error?: string };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Settings', href: '#' },
    { title: 'Performance Standards', href: '/settings/performance-standards' },
];

const notyf = new Notyf({ duration: 3000, position: { x: 'right', y: 'top' } });

// Reactive grid state
const grid = ref<Record<number, Record<number, number>>>({});

// Frontend Search state (no backend debounce needed for this matrix)
const searchTerm = ref('');

// Filter departments based on search
const filteredDepartments = computed(() => {
    if (!searchTerm.value) return props.departments;
    const query = searchTerm.value.toLowerCase();
    return props.departments.filter(dept => dept.name.toLowerCase().includes(query));
});

const clearSearch = () => {
    searchTerm.value = '';
};

onMounted(() => {
    props.departments.forEach(dept => {
        grid.value[dept.id] = {};
        props.documentTypes.forEach(doc => {
            // Force both sides to be Numbers so strings vs ints don't fail the check
            const existing = props.existingStandards.find(
                s => Number(s.department_id) === Number(dept.id) && Number(s.document_type_id) === Number(doc.id)
            );
            grid.value[dept.id][doc.id] = existing ? Number(existing.allocated_minutes) : 7200;
        });
    });

    if (props.flash?.success) {
        notyf.success(props.flash.success);
    }
    if (props.flash?.error) {
        notyf.error(props.flash.error);
    }
});

const form = useForm({
    standards: [] as Array<{ department_id: number; document_type_id: number; allocated_minutes: number }>,
});

function saveStandards() {
    const flatStandards = [];
    for (const deptId in grid.value) {
        for (const docId in grid.value[deptId]) {
            flatStandards.push({
                department_id: Number(deptId),
                document_type_id: Number(docId),
                allocated_minutes: Number(grid.value[deptId][docId])
            });
        }
    }

    form.standards = flatStandards;
    form.post(route('performance-standards.store'), {
        preserveScroll: true,
        onSuccess: () => {
            notyf.success('Performance standards updated successfully.');
        },
    });
}
</script>

<template>
    <Head title="Performance Standards" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-6 w-full mx-auto bg-slate-50/50 min-h-screen max-w-[105rem]">
            
            <!-- Error Banner -->
            <div v-if="Object.keys(form.errors).length > 0" class="p-4 bg-red-50 text-red-600 rounded-xl border border-red-100 text-sm font-medium">
                Failed to save. Please ensure all inputs are valid numbers and try again.
            </div>

            <!-- Header & Actions Toolbar -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold text-slate-800 tracking-tight">Office Performance Standards Settings</h1>
                    <p class="text-sm text-slate-500 mt-1">Configure allocated processing minutes per department and document type (Default: 7200 mins = 5 days).</p>
                </div>
                
                <div class="flex flex-col sm:flex-row items-center gap-3">
                    <!-- Search -->
                    <div class="relative w-full sm:w-72">
                        <Search class="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-slate-400" />
                        <input
                            v-model="searchTerm"
                            type="text"
                            placeholder="Search departments..."
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

                    <!-- Save Button -->
                    <button
                        class="w-full sm:w-auto flex items-center justify-center gap-2 rounded-xl bg-emerald-600 px-4 py-2 font-medium text-white shadow-sm transition hover:bg-emerald-700 disabled:opacity-50"
                        @click="saveStandards"
                        :disabled="form.processing"
                    >
                        <Save class="h-4 w-4" />
                        {{ form.processing ? 'Saving...' : 'Save Changes' }}
                    </button>
                </div>
            </div>

            <!-- Table Card -->
            <div class="bg-white rounded-2xl border border-slate-100 shadow-[0_2px_10px_-3px_rgba(6,81,237,0.03)] overflow-hidden">
                <div class="overflow-auto max-h-[calc(100vh-240px)] custom-scrollbar">
                    <table class="w-full text-left text-sm text-slate-700 relative border-collapse min-w-max">
                        <thead class="bg-slate-50/80 text-[11px] text-slate-500 uppercase tracking-wider sticky top-0 z-20 shadow-sm backdrop-blur-sm border-b border-slate-100">
                            <tr>
                                <!-- Compacted Left Column Header -->
                                <th class="px-4 py-3 font-semibold border-r border-slate-100 bg-slate-50/90 min-w-[220px] max-w-[280px] sticky left-0 z-30">
                                    Department / Office
                                </th>
                                <!-- Compacted Dynamic Columns -->
                                <th v-for="doc in documentTypes" :key="doc.id" class="px-2 py-3 font-semibold border-slate-100 text-center min-w-[70px]" :title="doc.document_code">
                                    {{ doc.document_code }}
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="dept in filteredDepartments" :key="dept.id" class="transition-colors hover:bg-slate-50/50">
                                <!-- Compacted Left Column Data -->
                                <td class="px-4 py-2 text-xs font-medium text-slate-800 border-r border-slate-100 bg-white sticky left-0 z-10 shadow-[2px_0_5px_-2px_rgba(0,0,0,0.02)] truncate max-w-[280px]" :title="dept.name">
                                    {{ dept.name }}
                                </td>
                                <!-- Compacted Inputs -->
                                <td v-for="doc in documentTypes" :key="doc.id" class="px-1 py-1 text-center">
                                    <input 
                                        v-if="grid[dept.id]"
                                        v-model.number="grid[dept.id][doc.id]" 
                                        type="number" 
                                        min="0"
                                        class="w-16 rounded-md border border-slate-300 px-1 py-1 text-center text-xs font-mono focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 shadow-sm transition-colors hover:border-emerald-400"
                                    />
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <!-- Empty State -->
                    <div v-if="filteredDepartments.length === 0" class="py-16 text-center">
                        <div class="flex flex-col items-center">
                            <div class="w-12 h-12 bg-slate-50 rounded-full flex items-center justify-center mb-4">
                                <Search class="h-6 w-6 text-slate-400" />
                            </div>
                            <h3 class="text-base font-semibold text-slate-900">No departments found</h3>
                            <p class="mt-1 mb-6 text-sm text-slate-500 max-w-sm">
                                {{ searchTerm ? 'We couldn’t find anything matching your search. Try adjusting your keywords.' : 'No departments are currently available to configure.' }}
                            </p>
                        </div>
                    </div>

                </div>
            </div>

        </div>
    </AppLayout>
</template>

<style scoped>
.custom-scrollbar::-webkit-scrollbar {
    width: 8px;
    height: 8px;
}
.custom-scrollbar::-webkit-scrollbar-track {
    background: #f1f5f9; 
    border-radius: 4px;
}
.custom-scrollbar::-webkit-scrollbar-thumb {
    background: #cbd5e1; 
    border-radius: 4px;
}
.custom-scrollbar::-webkit-scrollbar-thumb:hover {
    background: #94a3b8; 
}
</style>