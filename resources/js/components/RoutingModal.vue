<script setup lang="ts">
import { ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { ChevronsRight, ChevronsLeft, FastForward, X, Sparkles } from 'lucide-vue-next';

const props = defineProps<{
    show: boolean;
    document: any | null;
    departments: Array<{ id: number; name: string; code: string }>;
}>();

const emit = defineEmits(['close']);

const availableOffices = ref<Array<any>>([]);
const selectedOffices = ref<Array<any>>([]);
const aiSuggestionsMap = ref<Record<number, { reason: string, priority: string }>>({});

const form = useForm({
    routed_to: [] as number[]
});

// Auto-forwards to the right side ONLY when opening the modal
watch(() => props.show, (isOpen) => {
    if (isOpen && props.document) {
        
        let suggestions = props.document.ai_routing_suggestions || [];
        if (typeof suggestions === 'string') {
            try {
                suggestions = JSON.parse(suggestions);
            } catch (e) {
                suggestions = [];
            }
        }

        // Build a dictionary of reasons attached to their department IDs
        const suggestionsMap: Record<number, { reason: string, priority: string }> = {};
        const suggestedIds = suggestions.map((s: any) => {
            const id = Number(s.department_id);
            // Default to Secondary if the AI forgets
            suggestionsMap[id] = { 
                reason: s.reason, 
                priority: s.priority || 'Secondary' 
            }; 
            return id;
        });
        
        aiSuggestionsMap.value = suggestionsMap;

        selectedOffices.value = props.departments.filter(dept => suggestedIds.includes(Number(dept.id)));
        availableOffices.value = props.departments.filter(dept => !suggestedIds.includes(Number(dept.id)));
        
        availableOffices.value.sort((a, b) => (a.code || a.name).localeCompare(b.code || b.name));
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
    availableOffices.value.sort((a, b) => (a.code || a.name).localeCompare(b.code || b.name));
};

const routeToAll = () => {
    selectedOffices.value = [...selectedOffices.value, ...availableOffices.value];
    availableOffices.value = [];
};

const removeAll = () => {
    availableOffices.value = [...availableOffices.value, ...selectedOffices.value];
    selectedOffices.value = [];
    availableOffices.value.sort((a, b) => (a.code || a.name).localeCompare(b.code || b.name));
};

const submitRouting = () => {
    if (!props.document) return;
    
    // Explicitly triggers the route save ONLY when the user clicks Route Document
    form.routed_to = selectedOffices.value.map(dept => dept.id);
    
    form.post(route('documents.route', props.document.id), {
        preserveScroll: true,
        onSuccess: () => closeModal()
    });
};
</script>

<template>
    <Transition name="fade">
        <div v-if="show" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 backdrop-blur-sm px-4">
            
            <div class="w-full max-w-4xl rounded-2xl bg-white shadow-2xl flex flex-col overflow-hidden max-h-[90vh]">
                
                <!-- Header -->
                <div class="flex justify-between items-center px-6 py-4 border-b border-slate-100">
                    <h2 class="text-lg font-bold text-slate-800 flex items-center gap-2">
                        <FastForward class="h-5 w-5 text-indigo-600" />
                        Start Transaction: 
                        <span class="font-mono text-emerald-600">{{ document?.barcode }}</span>
                    </h2>
                    <button @click="closeModal" class="text-slate-400 hover:text-slate-600 transition">
                        <X class="w-5 h-5" />
                    </button>
                </div>

                <!-- Dual Panels -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 p-6 flex-1 overflow-hidden min-h-[500px]">
                    
                    <!-- LEFT COLUMN -->
                    <div class="flex flex-col border border-slate-200 rounded-xl overflow-hidden bg-white">
                        <div class="flex items-center justify-between px-4 py-3 bg-slate-50/50 border-b border-slate-200">
                            <h3 class="text-sm font-bold text-slate-700">Office List</h3>
                            <button @click="routeToAll" class="text-xs flex items-center gap-1.5 text-slate-500 hover:text-indigo-600 transition font-medium">
                                Route to all 
                                <span class="bg-slate-700 text-white rounded-full p-0.5"><ChevronsRight class="w-3 h-3" /></span>
                            </button>
                        </div>
                        <div class="flex-1 overflow-y-auto p-2">
                            <div 
                                v-for="dept in availableOffices" 
                                :key="dept.id" 
                                @click="addOffice(dept)"
                                class="flex items-center px-3 py-2.5 rounded-lg hover:bg-slate-50 cursor-pointer transition group"
                            >
                                <span class="font-bold text-sm text-slate-800 w-16 shrink-0">{{ dept.code || dept.id }}</span>
                                <span class="text-sm font-medium text-slate-600 group-hover:text-slate-900">{{ dept.name }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- RIGHT COLUMN -->
                    <div class="flex flex-col border border-slate-200 rounded-xl overflow-hidden bg-white">
                        <div class="flex items-center justify-between px-4 py-3 bg-slate-50/50 border-b border-slate-200">
                            <h3 class="text-sm font-bold text-slate-700">Route To</h3>
                            <button @click="removeAll" class="text-xs flex items-center gap-1.5 text-slate-500 hover:text-rose-600 transition font-medium">
                                Remove all 
                                <span class="bg-slate-700 text-white rounded-full p-0.5"><ChevronsLeft class="w-3 h-3" /></span>
                            </button>
                        </div>
                        <div class="flex-1 overflow-y-auto p-2">
                            
                            <!-- Dynamically Styled Selected Offices -->
                            <div 
                                v-for="dept in selectedOffices" 
                                :key="dept.id" 
                                @click="removeOffice(dept)"
                                :class="[
                                    'flex items-start px-3 py-2.5 rounded-xl cursor-pointer transition group border mb-1.5',
                                    aiSuggestionsMap[dept.id]?.priority === 'Primary' 
                                        ? 'bg-emerald-50/60 border-emerald-200 hover:bg-emerald-50' 
                                        : 'bg-white border-transparent hover:bg-slate-50 hover:border-slate-100'
                                ]"
                            >
                                <div class="flex flex-col flex-1">
                                    <div class="flex items-center gap-2">
                                        <span 
                                            class="font-bold text-sm w-16 shrink-0" 
                                            :class="aiSuggestionsMap[dept.id]?.priority === 'Primary' ? 'text-emerald-800' : 'text-slate-800'"
                                        >{{ dept.code || dept.id }}</span>
                                        
                                        <span 
                                            class="text-sm font-medium" 
                                            :class="aiSuggestionsMap[dept.id]?.priority === 'Primary' ? 'text-emerald-900' : 'text-slate-600 group-hover:text-slate-900'"
                                        >{{ dept.name }}</span>
                                        
                                        <!-- Highlight Badge for Primary Target -->
                                        <span 
                                            v-if="aiSuggestionsMap[dept.id]?.priority === 'Primary'" 
                                            class="ml-auto text-[9px] font-bold uppercase tracking-wider bg-emerald-100 text-emerald-700 px-2 py-0.5 rounded-full flex items-center gap-1"
                                        >
                                            <Sparkles class="w-2.5 h-2.5" /> Best Match
                                        </span>
                                    </div>
                                    
                                    <!-- AI Reason Block -->
                                    <p 
                                        v-if="aiSuggestionsMap[dept.id]" 
                                        class="text-[11px] mt-1 pl-[72px] pr-2 leading-tight" 
                                        :class="aiSuggestionsMap[dept.id].priority === 'Primary' ? 'text-emerald-700' : 'text-indigo-600/90'"
                                    >
                                        <Sparkles v-if="aiSuggestionsMap[dept.id].priority !== 'Primary'" class="w-3 h-3 inline mr-0.5 relative -top-[1px]" />
                                        {{ aiSuggestionsMap[dept.id].reason }}
                                    </p>
                                </div>
                                
                                <button 
                                    class="opacity-0 group-hover:opacity-100 transition mt-0.5" 
                                    :class="aiSuggestionsMap[dept.id]?.priority === 'Primary' ? 'text-emerald-500 hover:text-emerald-700' : 'text-slate-400 hover:text-rose-600'"
                                >
                                    <X class="w-4 h-4" />
                                </button>
                            </div>
                            
                            <!-- Empty State -->
                            <div v-if="selectedOffices.length === 0" class="flex flex-col items-center justify-center h-full text-center p-6 opacity-60">
                                <ChevronsRight class="w-8 h-8 text-slate-300 mb-2" />
                                <p class="text-sm font-bold text-slate-400">No offices selected</p>
                                <p class="text-xs font-medium text-slate-400 mt-1">Select offices from the list to route this document.</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Footer Actions -->
                <div class="px-6 py-4 flex justify-end gap-3 border-t border-slate-100 bg-slate-50/50">
                    <button @click="closeModal" type="button" class="rounded-xl bg-white border border-slate-200 px-6 py-2.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50 hover:text-slate-900 shadow-sm">
                        Cancel
                    </button>
                    <button 
                        @click="submitRouting" 
                        :disabled="selectedOffices.length === 0 || form.processing" 
                        class="rounded-xl bg-emerald-600 px-6 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-emerald-700 disabled:opacity-40 disabled:cursor-not-allowed flex items-center gap-2"
                    >
                        <FastForward v-if="!form.processing" class="w-4 h-4 text-white" />
                        {{ form.processing ? 'Routing...' : 'Route Document' }}
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
/* Scrollbar styling to match the image */
::-webkit-scrollbar {
    width: 6px;
}
::-webkit-scrollbar-track {
    background: transparent;
}
::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 10px;
}
::-webkit-scrollbar-thumb:hover {
    background: #94a3b8;
}
</style>