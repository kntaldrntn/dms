<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import { FileSignature, Sparkles, X, CheckCircle2 } from 'lucide-vue-next';
import { Notyf } from 'notyf';
import 'notyf/notyf.min.css';

const props = defineProps<{
    documents: any;
    departments: Array<{ id: number; name: string; code: string }>;
    flash?: { success?: string; error?: string };
}>();

const page = usePage();
const authUser = computed(() => page.props.auth?.user as any);

const showModal = ref(false);
const selectedDoc = ref<any>(null);
const aiSuggestionsMap = ref<Record<number, { reason: string, priority: string }>>({});

// --- NOTIFICATIONS ---
const notyf = new Notyf({ duration: 4000, position: { x: 'right', y: 'top' } });
watch(() => props.flash, (flash) => {
    if (flash?.success) notyf.success(flash.success);
    if (flash?.error) notyf.error(flash.error);
}, { immediate: true, deep: true });

const form = useForm({
    targets: [] as number[],
    remarks: '',
    attach_signature: false,
});

function openModal(doc: any) {
    selectedDoc.value = doc;
    form.reset();
    form.clearErrors();

    let suggestions = doc.ai_routing_suggestions || [];
    if (typeof suggestions === 'string') {
        try { suggestions = JSON.parse(suggestions); } catch (e) { suggestions = []; }
    }

    const suggestionsMap: Record<number, { reason: string, priority: string }> = {};
    const suggestedIds: number[] = [];
    
    suggestions.forEach((s: any) => {
        const id = Number(s.department_id);
        suggestionsMap[id] = { reason: s.reason, priority: s.priority };
        suggestedIds.push(id);
    });

    aiSuggestionsMap.value = suggestionsMap;
    form.targets = suggestedIds; // Auto-tick AI suggestions

    showModal.value = true;
}

function submitSlip() {
    form.post(route('referral-slips.generate', selectedDoc.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            showModal.value = false;
        },
        onError: (errors) => {
            notyf.error('Please check the form for errors.');
        }
    });
}
</script>

<template>
    <Head title="Referral Slips" />
    <AppLayout>
        <div class="flex flex-col gap-6 p-6 w-full mx-auto bg-slate-100 min-h-screen">
            <h1 class="text-2xl font-bold text-slate-800">Admin Referral Slips</h1>
            <p class="text-sm text-slate-500 -mt-4">Generate and authorize routing slips for incoming documents.</p>

            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                <table class="w-full text-left text-sm text-slate-700">
                    <thead class="bg-slate-100 text-xs text-slate-600 uppercase">
                        <tr>
                            <th class="px-4 py-3 font-bold">Barcode</th>
                            <th class="px-4 py-3 font-bold">Subject Matter</th>
                            <th class="px-4 py-3 text-center font-bold">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                        <tr v-for="doc in documents.data" :key="doc.id" class="hover:bg-slate-50">
                            <td class="px-4 py-3 font-mono font-bold text-emerald-600">{{ doc.barcode }}</td>
                            <td class="px-4 py-3">{{ doc.subject_matter }}</td>
                            <td class="px-4 py-3 text-center">
                                <button @click="openModal(doc)" class="bg-indigo-50 text-indigo-700 px-4 py-1.5 rounded-lg font-bold hover:bg-indigo-100 border border-indigo-100">
                                    Review & Generate Slip
                                </button>
                            </td>
                        </tr>
                        <tr v-if="documents.data.length === 0">
                            <td colspan="3" class="py-12 text-center text-slate-500">No pending documents for Referral.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Generate Slip Modal -->
            <Transition name="fade">
                <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 backdrop-blur-sm px-4">
                    <div class="w-full max-w-2xl bg-white rounded-2xl shadow-xl flex flex-col">
                        <div class="px-6 py-4 border-b flex justify-between items-center">
                            <h2 class="text-lg font-bold text-slate-800 flex items-center gap-2">
                                <FileSignature class="w-5 h-5 text-indigo-600"/> Generate Slip: {{ selectedDoc?.barcode }}
                            </h2>
                            <button @click="showModal = false" class="text-slate-400 hover:text-slate-600"><X class="w-5 h-5"/></button>
                        </div>

                        <div class="p-6 overflow-y-auto max-h-[60vh]">
                            <h3 class="text-sm font-bold text-slate-800 mb-3">Target Departments</h3>
                            
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-1">
                                <label v-for="dept in departments" :key="dept.id" 
                                    class="flex items-start gap-3 p-3 rounded-xl border transition cursor-pointer" 
                                    :class="form.errors.targets ? 'border-red-300 bg-red-50' : (form.targets.includes(dept.id) ? 'bg-indigo-50 border-indigo-300' : 'bg-slate-50 border-slate-200 hover:bg-white')">
                                    <input type="checkbox" v-model="form.targets" :value="dept.id" class="mt-0.5 rounded text-indigo-600 focus:ring-indigo-500" />
                                    <div class="flex flex-col">
                                        <span class="text-sm font-bold text-slate-800">{{ dept.code || dept.name }}</span>
                                        <span v-if="aiSuggestionsMap[dept.id]" class="text-[10px] text-emerald-600 font-bold"><Sparkles class="w-3 h-3 inline"/> {{ aiSuggestionsMap[dept.id].priority }} Match</span>
                                    </div>
                                </label>
                            </div>
                            <p v-if="form.errors.targets" class="text-[10px] text-red-500 mb-4 font-bold">{{ form.errors.targets }}</p>
                            <div v-else class="mb-6"></div>

                            <label class="mb-1 block text-sm font-bold text-slate-700">Admin Remarks / Directives</label>
                            <textarea v-model="form.remarks" rows="2" 
                                class="w-full rounded-xl transition-colors mb-1"
                                :class="form.errors.remarks ? 'border-red-500 focus:border-red-500 focus:ring-1 focus:ring-red-500' : 'border-slate-300'"
                                placeholder="Optional instructions for the target departments..."></textarea>
                            <p v-if="form.errors.remarks" class="text-[10px] text-red-500 mb-4 font-bold">{{ form.errors.remarks }}</p>
                            <div v-else class="mb-4"></div>

                            <label class="flex items-center gap-3 p-3 rounded-xl border transition cursor-pointer" 
                                :class="form.errors.attach_signature ? 'border-red-300 bg-red-50' : (form.attach_signature ? 'bg-amber-50 border-amber-300' : 'bg-slate-50 border-slate-200 hover:bg-white')">
                                <input type="checkbox" v-model="form.attach_signature" class="rounded text-amber-600 focus:ring-amber-500 w-4 h-4" />
                                <div class="flex flex-col">
                                    <span class="text-sm font-bold text-slate-800 flex items-center gap-1.5">
                                        <FileSignature class="w-4 h-4 text-amber-600"/> Attach Authorized Signature
                                    </span>
                                    <span class="text-xs text-slate-500 mt-0.5">Applies your digital signature to officially approve this routing slip.</span>
                                </div>
                            </label>
                            <p v-if="form.errors.attach_signature" class="text-[10px] text-red-500 mt-1 font-bold">{{ form.errors.attach_signature }}</p>
                        </div>

                        <div class="px-6 py-4 border-t bg-slate-50 flex justify-end gap-3 rounded-b-2xl">
                            <button @click="showModal = false" class="px-5 py-2 bg-white border border-slate-200 rounded-xl font-medium text-slate-700 hover:bg-slate-100 transition">Cancel</button>
                            <button @click="submitSlip" :disabled="form.processing" class="px-5 py-2 bg-indigo-600 text-white rounded-xl font-bold hover:bg-indigo-700 flex items-center gap-2 transition disabled:opacity-50">
                                <CheckCircle2 class="w-4 h-4" /> Approve & Return to Records
                            </button>
                        </div>
                    </div>
                </div>
            </Transition>
        </div>
    </AppLayout>
</template>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity 0.2s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>