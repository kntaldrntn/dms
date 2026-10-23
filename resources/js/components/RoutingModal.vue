<script setup lang="ts">
import OfficePicker from '@/components/OfficePicker.vue';
import { useForm } from '@inertiajs/vue3';
import { FastForward, X } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

const props = defineProps<{
    show: boolean;
    document: any | null;
    departments: Array<{ id: number; name: string; code: string }>;
}>();

const emit = defineEmits(['close']);

const selectedIds = ref<number[]>([]);
const aiSuggestionsMap = ref<Record<number, { reason: string; priority: string }>>({});
// Only the Primary office acts on the document; every other selected office gets a view-only copy.
const primaryId = ref<number | null>(null);
// Confidential documents go to the Primary office only, with no copies.
const singleOffice = computed(() => Boolean(props.document?.is_confidential));

const form = useForm({
    routed_to: [] as number[],
    primary_office_id: null as number | null,
    workflow_version: 0,
});

watch(
    () => props.show,
    (isOpen) => {
        if (!isOpen || !props.document) return;
        form.reset();
        form.clearErrors();
        form.workflow_version = props.document.workflow_version;

        let suggestions = props.document.ai_routing_suggestions || [];
        if (typeof suggestions === 'string') {
            try {
                suggestions = JSON.parse(suggestions);
            } catch {
                suggestions = [];
            }
        }
        const suggestionsMap: Record<number, { reason: string; priority: string }> = {};
        suggestions.forEach((suggestion: any) => {
            suggestionsMap[Number(suggestion.department_id)] = { reason: suggestion.reason, priority: suggestion.priority || 'Secondary' };
        });
        aiSuggestionsMap.value = suggestionsMap;

        // Pre-select the AI's suggested offices, its Primary pick first.
        const suggested = props.departments.filter((office) => suggestionsMap[office.id]).map((office) => office.id);
        selectedIds.value = singleOffice.value ? suggested.slice(0, 1) : suggested;
        primaryId.value = selectedIds.value.find((id) => suggestionsMap[id]?.priority === 'Primary') ?? selectedIds.value[0] ?? null;
    },
);

const closeModal = () => emit('close');

const submitRouting = () => {
    if (!props.document || selectedIds.value.length === 0) return;
    form.routed_to = [...selectedIds.value];
    form.primary_office_id = primaryId.value;
    form.post(route('documents.route', props.document.id), {
        preserveScroll: true,
        onSuccess: () => closeModal(),
    });
};
</script>

<template>
    <div v-if="show" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 px-4" @keydown.esc="closeModal">
        <div
            class="flex max-h-[90vh] w-full max-w-4xl flex-col rounded-lg border border-slate-200 bg-white"
            role="dialog"
            aria-modal="true"
            aria-labelledby="routing-title"
        >
            <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
                <h2 id="routing-title" class="flex items-center gap-2 text-base font-semibold text-slate-800">
                    <FastForward class="h-4 w-4 text-emerald-600" aria-hidden="true" />
                    Route <span class="font-mono text-emerald-700">{{ document?.barcode }}</span>
                </h2>
                <button
                    type="button"
                    class="rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600"
                    aria-label="Close"
                    @click="closeModal"
                >
                    <X class="h-5 w-5" aria-hidden="true" />
                </button>
            </div>

            <div class="flex min-h-[400px] flex-1 flex-col overflow-hidden p-4">
                <p
                    v-if="form.errors.primary_office_id || form.errors.routed_to || form.errors.workflow_version"
                    role="alert"
                    class="mb-3 text-xs font-medium text-red-600"
                >
                    {{ form.errors.primary_office_id || form.errors.routed_to || form.errors.workflow_version }}
                </p>
                <OfficePicker
                    v-model="selectedIds"
                    v-model:primary="primaryId"
                    :offices="departments"
                    :suggestions="aiSuggestionsMap"
                    :single="singleOffice"
                />
            </div>

            <div class="flex justify-end gap-2 border-t border-slate-200 px-4 py-3">
                <button
                    type="button"
                    class="rounded-md border border-slate-300 px-4 py-1.5 text-sm text-slate-700 hover:bg-slate-50"
                    @click="closeModal"
                >
                    Cancel
                </button>
                <button
                    type="button"
                    :disabled="selectedIds.length === 0 || form.processing"
                    class="rounded-md bg-emerald-600 px-4 py-1.5 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-50"
                    @click="submitRouting"
                >
                    {{ form.processing ? 'Routing...' : 'Route Document' }}
                </button>
            </div>
        </div>
    </div>
</template>
