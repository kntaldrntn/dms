<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { ChevronsRight, ChevronsLeft, FastForward, X, ClipboardCheck, FileSignature, CheckSquare, Unlock, AlertTriangle } from 'lucide-vue-next';

const props = defineProps<{
    show: boolean;
    document: any | null;
    departments: Array<{ id: number; name: string; code: string }>;
}>();

const emit = defineEmits(['close']);

const availableOffices = ref<Array<any>>([]);
const selectedOffices = ref<Array<any>>([]);
const approvedTargets = ref<Array<any>>([]);
const aiSuggestionsMap = ref<Record<number, { reason: string, priority: string }>>({});
const isOverriding = ref(false); // NEW: Tracks if Records is modifying the Admin slip
// Only the Primary office acts on the document; every other selected office gets a view-only copy.
const primaryId = ref<number | null>(null);
// Confidential documents go to the Primary office only, with no copies.
const singleOffice = computed(() => Boolean(props.document?.is_confidential));

const form = useForm({
    routed_to: [] as number[],
    primary_office_id: null as number | null,
    workflow_version: 0,
});

function choosePrimary(ids: number[], suggestions: Record<number, { priority: string }>) {
    primaryId.value = ids.find((id) => suggestions[id]?.priority === 'Primary') ?? ids[0] ?? null;
}

watch(selectedOffices, (offices) => {
    if (!offices.some((office) => office.id === primaryId.value)) primaryId.value = offices[0]?.id ?? null;
}, { deep: true });

watch(() => props.show, (isOpen) => {
    if (isOpen && props.document) {
        form.reset();
        form.workflow_version = props.document.workflow_version;
        isOverriding.value = false; // Always lock by default when opened

        // 1. Check for an Approved Routing Slip from the City Administrator
        let rawSlip = props.document.approved_routing_slip;
        if (typeof rawSlip === 'string') {
            try { rawSlip = JSON.parse(rawSlip); } catch { rawSlip = []; }
        }
        
        // 2. Map AI Suggestions
        let suggestions = props.document.ai_routing_suggestions || [];
        if (typeof suggestions === 'string') {
            try { suggestions = JSON.parse(suggestions); } catch { suggestions = []; }
        }

        const suggestionsMap: Record<number, { reason: string, priority: string }> = {};
        suggestions.forEach((s: any) => {
            suggestionsMap[Number(s.department_id)] = { reason: s.reason, priority: s.priority || 'Secondary' }; 
        });
        aiSuggestionsMap.value = suggestionsMap;

        // 3. Populate Panels
        const initialSelected: any[] = [];
        const initialAvailable: any[] = [];

        if (rawSlip && rawSlip.length > 0) {
            approvedTargets.value = props.departments.filter(d => rawSlip.includes(d.id));
            form.routed_to = rawSlip.map((id: string | number) => Number(id)); // Lock default targets

            // Pre-load the dual panels with the Admin's choices just in case they click Override
            props.departments.forEach(dept => {
                if (rawSlip.includes(dept.id)) initialSelected.push(dept);
                else initialAvailable.push(dept);
            });
        } else {
            approvedTargets.value = [];

            // Standard dual-panel population via AI
            props.departments.forEach(dept => {
                if (suggestionsMap[Number(dept.id)]) initialSelected.push(dept);
                else initialAvailable.push(dept);
            });
        }

        selectedOffices.value = singleOffice.value ? initialSelected.slice(0, 1) : initialSelected;
        availableOffices.value = [...initialAvailable, ...initialSelected.slice(selectedOffices.value.length)]
            .sort((a, b) => (a.code || a.name).localeCompare(b.code || b.name));
        choosePrimary(approvedTargets.value.length ? approvedTargets.value.map((dept) => dept.id) : selectedOffices.value.map((dept) => dept.id), suggestionsMap);
    }
});

const closeModal = () => emit('close');

const addOffice = (dept: any) => {
    if (singleOffice.value && selectedOffices.value.length) removeAll();
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
    
    // If no slip exists OR if the Records Manager clicked Override, use the dual-panel selection
    if (approvedTargets.value.length === 0 || isOverriding.value) {
        if (selectedOffices.value.length === 0) return;
        form.routed_to = selectedOffices.value.map(dept => dept.id);
    }
    form.primary_office_id = primaryId.value;

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
                
                <!-- Dynamic Header -->
                <div class="flex justify-between items-center px-6 py-4 border-b border-slate-100 bg-white">
                    <h2 class="text-lg font-bold text-slate-800 flex items-center gap-2">
                        <template v-if="approvedTargets.length > 0 && !isOverriding">
                            <ClipboardCheck class="h-5 w-5 text-emerald-600" />
                            Execute Referral Slip:
                        </template>
                        <template v-else-if="isOverriding">
                            <Unlock class="h-5 w-5 text-amber-600" />
                            Override Routing:
                        </template>
                        <template v-else>
                            <FastForward class="h-5 w-5 text-indigo-600" />
                            Start Transaction: 
                        </template>
                        <span class="font-mono" :class="isOverriding ? 'text-amber-600' : 'text-emerald-600'">{{ document?.barcode }}</span>
                    </h2>
                    <button @click="closeModal" class="text-slate-400 hover:text-slate-600 transition bg-slate-50 hover:bg-slate-100 rounded-full p-1.5">
                        <X class="w-5 h-5" />
                    </button>
                </div>

                <!-- VIEW MODE: Official Referral Slip Display -->
                <div v-if="approvedTargets.length > 0 && !isOverriding" class="p-8 bg-slate-50/50 flex-1 overflow-y-auto">
                    <div class="bg-white border-2 border-emerald-100 rounded-xl p-6 shadow-sm relative">

                        <div class="flex items-center justify-between border-b border-slate-100 pb-4 mb-5">
                            <h3 class="text-base font-bold text-slate-800 flex items-center gap-2">
                                <FileSignature class="w-5 h-5 text-emerald-600"/>
                                City Administrator's Referral Slip
                            </h3>
                            <span class="text-xs font-bold uppercase tracking-wider bg-emerald-100 text-emerald-700 px-3 py-1 rounded-full">Approved for Dispatch</span>
                        </div>

                        <p class="text-sm text-slate-500 mb-4 font-medium">The following departments have been marked as Concerned Departments:</p>
                        
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                            <div v-for="dept in approvedTargets" :key="dept.id" class="flex items-start gap-2.5 p-3 bg-slate-50 border border-slate-200 rounded-lg">
                                <CheckSquare class="w-4 h-4 text-emerald-600 mt-0.5 shrink-0" />
                                <div class="flex flex-col flex-1">
                                    <span class="text-sm font-bold text-slate-800 leading-tight">{{ dept.code || dept.name }}</span>
                                    <span class="text-[10px] text-slate-500 mt-0.5 line-clamp-1" :title="dept.name">{{ dept.name }}</span>
                                    <label class="mt-1.5 flex items-center gap-1.5 text-[11px] font-semibold" :class="primaryId === dept.id ? 'text-indigo-700' : 'text-slate-500'">
                                        <input v-model="primaryId" type="radio" name="slip_primary" :value="dept.id" class="h-3.5 w-3.5 text-indigo-600 focus:ring-indigo-500" />
                                        {{ primaryId === dept.id ? 'Primary (acts on it)' : 'Copy (view only)' }}
                                    </label>
                                </div>
                            </div>
                        </div>
                        <p class="mt-3 text-xs text-slate-500">Only the Primary office acts on the document. The other offices get a view-only copy.</p>

                        <!-- NEW: Override Button -->
                        <div class="mt-6 pt-4 border-t border-slate-100 flex justify-end">
                            <button @click="isOverriding = true" class="text-xs font-bold text-amber-700 hover:text-amber-800 flex items-center gap-1.5 px-3 py-1.5 bg-amber-50 rounded-lg border border-amber-200 hover:bg-amber-100 transition shadow-sm">
                                <Unlock class="w-3.5 h-3.5"/> Override Admin Destinations
                            </button>
                        </div>
                    </div>
                </div>

                <!-- EDIT MODE: Dual Panels -->
                <div v-else class="flex flex-col p-6 flex-1 overflow-hidden min-h-[400px] bg-slate-50/30">

                    <!-- NEW: Override Warning Banner -->
                    <div v-if="isOverriding" class="mb-5 bg-amber-50 border border-amber-200 p-3 rounded-xl flex items-start gap-3 shadow-sm">
                        <AlertTriangle class="w-5 h-5 text-amber-600 shrink-0 mt-0.5" />
                        <div>
                            <h4 class="text-xs font-bold text-amber-900 uppercase tracking-wider">Manual Override Active</h4>
                            <p class="text-[11px] text-amber-700 mt-0.5">You are modifying the City Administrator's approved routing slip. The original selections have been pre-loaded below. Ensure you have verbal authorization before proceeding.</p>
                        </div>
                    </div>

                    <p class="mb-3 text-xs text-slate-600">
                        {{ singleOffice
                            ? 'Confidential document: it goes to one Primary office only, with no copies.'
                            : 'Mark one office as Primary: only it acts on the document. The other offices get a view-only copy.' }}
                    </p>
                    <p v-if="form.errors.primary_office_id || form.errors.routed_to" role="alert" class="mb-3 text-xs font-medium text-red-600">
                        {{ form.errors.primary_office_id || form.errors.routed_to }}
                    </p>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 flex-1 overflow-hidden">
                        <!-- Left Panel: Available -->
                        <div class="flex flex-col border border-slate-200 rounded-xl overflow-hidden bg-white shadow-sm">
                            <div class="flex items-center justify-between px-4 py-3 bg-slate-50/80 border-b border-slate-200">
                                <h3 class="text-sm font-bold text-slate-700">Available Offices</h3>
                                <button v-if="!singleOffice" @click="routeToAll" class="text-xs flex items-center gap-1.5 text-slate-500 hover:text-indigo-600 transition font-medium">
                                    Route to all <span class="bg-slate-700 text-white rounded-full p-0.5"><ChevronsRight class="w-3 h-3" /></span>
                                </button>
                            </div>
                            <div class="flex-1 overflow-y-auto p-2">
                                <div v-for="dept in availableOffices" :key="dept.id" @click="addOffice(dept)" class="flex items-center px-3 py-2.5 rounded-lg hover:bg-slate-50 cursor-pointer transition group">
                                    <span class="font-bold text-sm text-slate-800 w-16 shrink-0">{{ dept.code || dept.id }}</span>
                                    <span class="text-sm font-medium text-slate-600 group-hover:text-slate-900">{{ dept.name }}</span>
                                </div>
                            </div>
                        </div>

                        <!-- Right Panel: Selected -->
                        <div class="flex flex-col border border-slate-200 rounded-xl overflow-hidden bg-white shadow-sm" :class="isOverriding ? 'ring-2 ring-amber-400 border-amber-400' : ''">
                            <div class="flex items-center justify-between px-4 py-3 bg-slate-50/80 border-b border-slate-200">
                                <h3 class="text-sm font-bold text-slate-700" :class="isOverriding ? 'text-amber-800' : ''">Route To</h3>
                                <button @click="removeAll" class="text-xs flex items-center gap-1.5 text-slate-500 hover:text-rose-600 transition font-medium">
                                    Remove all <span class="bg-slate-700 text-white rounded-full p-0.5"><ChevronsLeft class="w-3 h-3" /></span>
                                </button>
                            </div>
                            <div class="flex-1 overflow-y-auto p-2">
                                <div v-for="dept in selectedOffices" :key="dept.id" @click="removeOffice(dept)" class="flex items-start px-3 py-2.5 rounded-xl cursor-pointer transition group border mb-1.5 bg-white border-transparent hover:bg-rose-50 hover:border-rose-100">
                                    <div class="flex flex-col flex-1">
                                        <div class="flex items-center gap-2">
                                            <span class="font-bold text-sm w-16 shrink-0 text-slate-800">{{ dept.code || dept.id }}</span>
                                            <span class="text-sm font-medium text-slate-600 group-hover:text-rose-700">{{ dept.name }}</span>
                                        </div>
                                        <label class="mt-1 ml-16 flex items-center gap-1.5 text-[11px] font-semibold" :class="primaryId === dept.id ? 'text-indigo-700' : 'text-slate-500'" @click.stop>
                                            <input v-model="primaryId" type="radio" name="primary_office" :value="dept.id" class="h-3.5 w-3.5 text-indigo-600 focus:ring-indigo-500" />
                                            {{ primaryId === dept.id ? 'Primary (acts on it)' : 'Copy (view only)' }}
                                        </label>
                                    </div>
                                    <button class="opacity-0 group-hover:opacity-100 transition mt-0.5 text-slate-400 hover:text-rose-600"><X class="w-4 h-4" /></button>
                                </div>
                                <div v-if="selectedOffices.length === 0" class="flex flex-col items-center justify-center h-full text-center p-6 opacity-60">
                                    <ChevronsRight class="w-8 h-8 text-slate-300 mb-2" />
                                    <p class="text-sm font-bold text-slate-400">No offices selected</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Footer Actions -->
                <div class="px-6 py-4 flex justify-end gap-3 border-t border-slate-100 bg-slate-50/80">
                    <button @click="closeModal" type="button" class="rounded-xl bg-white border border-slate-200 px-6 py-2.5 text-sm font-medium text-slate-700 transition hover:bg-slate-100 shadow-sm">
                        Cancel
                    </button>
                    <button 
                        @click="submitRouting" 
                        :disabled="(approvedTargets.length === 0 && selectedOffices.length === 0) || form.processing" 
                        class="rounded-xl px-6 py-2.5 text-sm font-bold text-white shadow-sm transition disabled:opacity-40 disabled:cursor-not-allowed flex items-center gap-2"
                        :class="isOverriding ? 'bg-amber-600 hover:bg-amber-700' : (approvedTargets.length > 0 ? 'bg-emerald-600 hover:bg-emerald-700' : 'bg-indigo-600 hover:bg-indigo-700')"
                    >
                        <template v-if="!form.processing">
                            <Unlock v-if="isOverriding" class="w-4 h-4 text-white" />
                            <ClipboardCheck v-else-if="approvedTargets.length > 0" class="w-4 h-4 text-white" />
                            <FastForward v-else class="w-4 h-4 text-white" />
                        </template>
                        {{ form.processing ? 'Routing...' : (isOverriding ? 'Dispatch Override Targets' : (approvedTargets.length > 0 ? 'Dispatch to Approved Offices' : 'Route Document')) }}
                    </button>
                </div>

            </div>
        </div>
    </Transition>
</template>
