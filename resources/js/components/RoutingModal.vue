<script setup lang="ts">
import { ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { ChevronRight, ChevronsRight, ChevronLeft, ChevronsLeft, FastForward } from 'lucide-vue-next';

const props = defineProps<{
    show: boolean;
    document: any | null;
    departments: Array<{ id: number; name: string; code: string }>;
}>();

const emit = defineEmits(['close']);

const availableOffices = ref<Array<any>>([]);
const selectedOffices = ref<Array<any>>([]);

const form = useForm({
    routed_to: [] as number[]
});

// Watch for when the modal opens to populate the lists
watch(() => props.show, (isOpen) => {
    if (isOpen && props.document) {
        const suggestedIds = (props.document.ai_routing_suggestions || []).map((s: any) => s.department_id);
        
        selectedOffices.value = props.departments.filter(dept => suggestedIds.includes(dept.id));
        availableOffices.value = props.departments.filter(dept => !suggestedIds.includes(dept.id));
    }
});

const closeModal = () => emit('close');

const addOffice = (dept: any) => {
    selectedOffices.value.push(dept);
    availableOffices.value = availableOffices.value.filter(d => d.id !== dept.id);
};

const removeOffice = (dept: any) => {
    availableOffices.value.push(dept);
    selectedOffices.value = selectedOffices.value.filter(d => d.id !== dept.id);
    availableOffices.value.sort((a, b) => a.name.localeCompare(b.name));
};

const routeToAll = () => {
    selectedOffices.value = [...selectedOffices.value, ...availableOffices.value];
    availableOffices.value = [];
};

const removeAll = () => {
    availableOffices.value = [...availableOffices.value, ...selectedOffices.value];
    selectedOffices.value = [];
    availableOffices.value.sort((a, b) => a.name.localeCompare(b.name));
};

const submitRouting = () => {
    if (!props.document) return;
    
    form.routed_to = selectedOffices.value.map(dept => dept.id);
    
    form.post(route('documents.route', props.document.id), {
        preserveScroll: true,
        onSuccess: () => closeModal()
    });
};
</script>

<template>
    <Transition name="fade">
        <div v-if="show" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 backdrop-blur-sm px-4">
            <!-- Modal Container -->
            <div class="w-full max-w-4xl rounded-2xl bg-white p-6 shadow-xl border border-slate-100 max-h-[90vh] flex flex-col">
                
                <!-- Header -->
                <div class="flex justify-between items-center mb-6">
                    <h2 class="text-lg font-bold text-slate-800 tracking-tight flex items-center gap-2">
                        <FastForward class="h-5 w-5 text-indigo-500" />
                        Start Transaction: <span class="font-mono text-emerald-600">{{ document?.barcode }}</span>
                    </h2>
                    <button @click="closeModal" class="text-slate-400 hover:text-slate-600 text-xl font-medium leading-none">
                        &times;
                    </button>
                </div>

                <!-- Content: Dual Pane Layout -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 flex-1 overflow-hidden min-h-[400px]">
                    
                    <!-- LEFT COLUMN: Available Offices -->
                    <div class="flex flex-col border border-slate-200 rounded-xl overflow-hidden">
                        <div class="flex items-center justify-between p-3 bg-slate-50 border-b border-slate-200">
                            <h3 class="text-sm font-semibold text-slate-700">Office List</h3>
                            <button @click="routeToAll" class="text-xs flex items-center gap-1 text-slate-500 hover:text-emerald-600 transition font-medium">
                                Route to all <ChevronsRight class="w-4 h-4 bg-slate-800 text-white rounded-full p-0.5 ml-0.5" />
                            </button>
                        </div>
                        <div class="flex-1 overflow-y-auto p-2 space-y-1">
                            <div v-for="dept in availableOffices" :key="dept.id" class="flex items-center justify-between group hover:bg-slate-50 p-2 rounded-lg transition border border-transparent hover:border-slate-100">
                                <div class="flex items-center gap-3 text-xs">
                                    <span class="font-bold text-slate-800 w-12">{{ dept.code || dept.id }}</span>
                                    <span class="text-slate-600 font-medium">{{ dept.name }}</span>
                                </div>
                                <button @click="addOffice(dept)" class="opacity-0 group-hover:opacity-100 transition text-emerald-600 hover:text-emerald-700">
                                    <ChevronRight class="w-6 h-6 bg-slate-100 group-hover:bg-emerald-50 rounded-full p-1" />
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- RIGHT COLUMN: Selected Offices -->
                    <div class="flex flex-col border border-slate-200 rounded-xl overflow-hidden bg-slate-50/30">
                        <div class="flex items-center justify-between p-3 bg-slate-50 border-b border-slate-200">
                            <h3 class="text-sm font-semibold text-slate-700">Route To</h3>
                            <button @click="removeAll" class="text-xs flex items-center gap-1 text-slate-500 hover:text-red-600 transition font-medium">
                                Remove all <ChevronsLeft class="w-4 h-4 bg-slate-800 text-white rounded-full p-0.5 ml-0.5" />
                            </button>
                        </div>
                        <div class="flex-1 overflow-y-auto p-2 space-y-1.5">
                            <div v-for="dept in selectedOffices" :key="dept.id" class="flex items-center justify-between bg-emerald-50 border border-emerald-200 p-2 rounded-lg shadow-sm">
                                <div class="flex items-center gap-3 text-xs">
                                    <span class="font-bold text-emerald-800 w-12">{{ dept.code || dept.id }}</span>
                                    <span class="text-emerald-900 font-semibold">{{ dept.name }}</span>
                                </div>
                                <button @click="removeOffice(dept)" class="text-emerald-600 hover:text-red-600 transition">
                                    <ChevronLeft class="w-6 h-6 bg-white hover:bg-red-50 rounded-full p-1 shadow-sm" />
                                </button>
                            </div>
                            
                            <!-- Empty State -->
                            <div v-if="selectedOffices.length === 0" class="flex flex-col items-center justify-center h-full text-center p-6 opacity-60">
                                <ChevronsRight class="w-8 h-8 text-slate-300 mb-2" />
                                <p class="text-sm font-medium text-slate-500">No offices selected</p>
                                <p class="text-xs text-slate-400 mt-1">Select offices from the list to route this document.</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Footer Actions -->
                <div class="pt-5 flex justify-end gap-3 mt-4 border-t border-slate-100">
                    <button type="button" class="rounded-xl bg-white border border-slate-200 px-5 py-2.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50" @click="closeModal">
                        Cancel
                    </button>
                    <button 
                        @click="submitRouting" 
                        :disabled="selectedOffices.length === 0 || form.processing" 
                        class="rounded-xl bg-emerald-600 px-6 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-emerald-700 disabled:opacity-50 flex items-center gap-2"
                    >
                        <FastForward v-if="!form.processing" class="w-4 h-4" />
                        {{ form.processing ? 'Routing Document...' : 'Route Document' }}
                    </button>
                </div>

            </div>
        </div>
    </Transition>
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