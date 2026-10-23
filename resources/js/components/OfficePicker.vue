<script setup lang="ts">
import { ChevronsLeft, ChevronsRight, X } from 'lucide-vue-next';
import { computed, watch } from 'vue';

type Office = { id: number; code: string; name: string };

/**
 * Two-panel office picker: offices on the right receive the document. Exactly one of them is the
 * Primary office (it acts on the document); the others get view-only copies.
 */
const props = defineProps<{
    offices: Office[];
    /** AI routing suggestions by office id, shown next to the office. */
    suggestions?: Record<number, { reason: string; priority: string }>;
    /** Confidential documents go to one office only. */
    single?: boolean;
}>();

const selected = defineModel<number[]>({ required: true });
const primary = defineModel<number | null>('primary', { required: true });

const byCode = (a: Office, b: Office) => (a.code || a.name).localeCompare(b.code || b.name);
const selectedOffices = computed(() =>
    selected.value.map((id) => props.offices.find((office) => office.id === id)).filter((office): office is Office => !!office),
);
const availableOffices = computed(() => props.offices.filter((office) => !selected.value.includes(office.id)).sort(byCode));

// The Primary office must always be one of the selected offices.
watch(
    selected,
    (ids) => {
        if (!ids.includes(primary.value ?? -1)) primary.value = ids[0] ?? null;
    },
    { deep: true, immediate: true },
);

const add = (office: Office) => {
    selected.value = props.single ? [office.id] : [...selected.value, office.id];
};
const remove = (office: Office) => {
    selected.value = selected.value.filter((id) => id !== office.id);
};
const addAll = () => {
    selected.value = [...selected.value, ...availableOffices.value.map((office) => office.id)];
};
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col">
        <p class="mb-3 text-xs text-slate-600">
            {{
                single
                    ? 'Confidential document: it goes to one Primary office only, with no copies.'
                    : 'Mark one office as Primary: only it acts on the document. The other offices get a view-only copy.'
            }}
        </p>

        <div class="grid min-h-0 flex-1 grid-cols-1 gap-4 md:grid-cols-2">
            <section class="flex min-h-0 flex-col rounded-md border border-slate-200" aria-labelledby="picker-available">
                <div class="flex items-center justify-between border-b border-slate-200 px-3 py-2">
                    <h3 id="picker-available" class="text-sm font-medium text-slate-700">Available offices</h3>
                    <button
                        v-if="!single"
                        type="button"
                        class="flex items-center gap-1 text-xs text-slate-500 hover:text-emerald-700"
                        @click="addAll"
                    >
                        Route to all <ChevronsRight class="h-3.5 w-3.5" aria-hidden="true" />
                    </button>
                </div>
                <ul class="max-h-80 flex-1 overflow-y-auto p-1">
                    <li v-for="office in availableOffices" :key="office.id">
                        <button
                            type="button"
                            class="flex w-full items-baseline gap-2 rounded px-2 py-1.5 text-left hover:bg-slate-50 focus:bg-slate-50 focus:outline-none"
                            @click="add(office)"
                        >
                            <span class="w-14 shrink-0 text-sm font-semibold text-slate-800">{{ office.code }}</span>
                            <span class="text-sm text-slate-600">{{ office.name }}</span>
                            <span v-if="suggestions?.[office.id]" class="ml-auto shrink-0 text-xs text-emerald-700">AI</span>
                        </button>
                    </li>
                </ul>
            </section>

            <section class="flex min-h-0 flex-col rounded-md border border-slate-200" aria-labelledby="picker-selected">
                <div class="flex items-center justify-between border-b border-slate-200 px-3 py-2">
                    <h3 id="picker-selected" class="text-sm font-medium text-slate-700">Route to</h3>
                    <button type="button" class="flex items-center gap-1 text-xs text-slate-500 hover:text-rose-600" @click="selected = []">
                        <ChevronsLeft class="h-3.5 w-3.5" aria-hidden="true" /> Remove all
                    </button>
                </div>
                <ul class="max-h-80 flex-1 overflow-y-auto p-1">
                    <li v-for="office in selectedOffices" :key="office.id" class="rounded px-2 py-1.5 hover:bg-slate-50">
                        <div class="flex items-baseline gap-2">
                            <span class="w-14 shrink-0 text-sm font-semibold text-slate-800">{{ office.code }}</span>
                            <span class="flex-1 text-sm text-slate-600">{{ office.name }}</span>
                            <button
                                type="button"
                                class="text-slate-400 hover:text-rose-600"
                                :aria-label="`Remove ${office.code}`"
                                @click="remove(office)"
                            >
                                <X class="h-4 w-4" aria-hidden="true" />
                            </button>
                        </div>
                        <label
                            class="ml-16 mt-0.5 flex items-center gap-1.5 text-xs"
                            :class="primary === office.id ? 'font-medium text-emerald-700' : 'text-slate-500'"
                        >
                            <input
                                v-model="primary"
                                type="radio"
                                name="primary_office"
                                :value="office.id"
                                class="h-3.5 w-3.5 text-emerald-600 focus:ring-emerald-600"
                            />
                            {{ primary === office.id ? 'Primary (acts on it)' : 'Copy (view only)' }}
                        </label>
                        <p v-if="suggestions?.[office.id]?.reason" class="ml-16 mt-0.5 text-xs text-slate-500">
                            AI: {{ suggestions[office.id].reason }}
                        </p>
                    </li>
                    <li v-if="selectedOffices.length === 0" class="p-6 text-center text-sm text-slate-400">No offices selected</li>
                </ul>
            </section>
        </div>
    </div>
</template>
